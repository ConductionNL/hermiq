<?php

/**
 * Hermiq: the nearest stored questions, through OpenRegister's vector facade.
 *
 * Resolves OpenRegister's VectorSearchFacade lazily, so hermiq loads without
 * it, and says whether it can answer. Hermiq never computes or stores an
 * embedding itself: the stored question is an OpenRegister object, and
 * OpenRegister's own pipeline indexes it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AnswerCache
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AnswerCache;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The guarded seam to OpenRegister's semantic search, for the answer cache.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-similar-questions-match-only-through-openregisters-vector-facade-req-acache-003
 */
class SimilarQuestionSearch {

	/**
	 * The facade OpenRegister publishes.
	 */
	public const FACADE_CLASS = 'OCA\\OpenRegister\\Service\\Search\\VectorSearchFacade';

	/**
	 * The resolved facade, false once resolution failed.
	 *
	 * @var object|false|null
	 */
	private object|false|null $facade = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Where the facade is resolved, on first use.
	 * @param LoggerInterface    $logger      Where a missing facade or a failed search is noted.
	 * @param string             $facadeClass The facade class name.
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-6
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $facadeClass=self::FACADE_CLASS,
	) {
	}//end __construct()

	/**
	 * Whether similar questions can be matched here: the facade exists and says it can answer.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#scenario-no-vector-search-no-similarity-layer
	 */
	public function isAvailable(): bool {
		$facade = $this->facade();
		if ($facade === null || method_exists($facade, 'isAvailable') === false || method_exists($facade, 'searchSemantic') === false) {
			return false;
		}

		try {
			return $facade->isAvailable() === true;
		} catch (Throwable) {
			return false;
		}
	}//end isAvailable()

	/**
	 * The passages nearest to a question, as the calling user; empty when nothing can be searched.
	 *
	 * @param string $question The question, redacted as it was stored.
	 * @param int    $limit    At most this many rows.
	 *
	 * @return array<int, array<string, mixed>> The facade's rows.
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-similar-questions-match-only-through-openregisters-vector-facade-req-acache-003
	 */
	public function nearest(string $question, int $limit): array {
		if (trim($question) === '' || $limit < 1 || $this->isAvailable() === false) {
			return [];
		}

		try {
			$rows = $this->facade()?->searchSemantic(query: $question, limit: $limit);
		} catch (Throwable $e) {
			$this->logger->warning('[AnswerCache] similar question search failed: '.$e->getMessage());
			return [];
		}

		return array_values(array_filter((array)$rows, 'is_array'));
	}//end nearest()

	/**
	 * The facade, resolved once; null when OpenRegister does not offer it.
	 *
	 * @return object|null
	 */
	private function facade(): ?object {
		if ($this->facade === null) {
			$this->facade = $this->resolve();
		}

		if ($this->facade === false) {
			return null;
		}

		return $this->facade;
	}//end facade()

	/**
	 * Resolve the facade from the container, guarded.
	 *
	 * @return object|false
	 */
	private function resolve(): object|false {
		if (class_exists($this->facadeClass) === false) {
			return false;
		}

		try {
			$facade = $this->container->get($this->facadeClass);
		} catch (Throwable $e) {
			$this->logger->info('[AnswerCache] The OpenRegister vector facade could not be resolved: '.$e->getMessage());
			return false;
		}

		if (is_object($facade) === false) {
			return false;
		}

		return $facade;
	}//end resolve()
}//end class
