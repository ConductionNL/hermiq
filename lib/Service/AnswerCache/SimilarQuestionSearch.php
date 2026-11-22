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

use OCA\Hermiq\Service\Engine\VectorFacadeLocator;
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
	 * Finds the facade once and says whether it has a backend.
	 *
	 * @var VectorFacadeLocator
	 */
	private readonly VectorFacadeLocator $locator;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Where the facade is resolved, on first use.
	 * @param LoggerInterface    $logger      Where a missing facade or a failed search is noted.
	 * @param string             $facadeClass The facade class name.
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-6
	 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
	 */
	public function __construct(
		ContainerInterface $container,
		private readonly LoggerInterface $logger,
		string $facadeClass=self::FACADE_CLASS,
	) {
		$this->locator = new VectorFacadeLocator(
			container: $container,
			logger: $logger,
			facadeClass: $facadeClass,
			caller: 'AnswerCache'
		);
	}//end __construct()

	/**
	 * Whether similar questions can be matched here: the facade exists and says it can answer.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#scenario-no-vector-search-no-similarity-layer
	 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
	 */
	public function isAvailable(): bool {
		$facade = $this->locator->facade();
		if ($facade === null || method_exists($facade, 'searchSemantic') === false) {
			return false;
		}

		return $this->locator->hasBackend(facade: $facade);
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
			$rows = $this->locator->facade()?->searchSemantic(query: $question, limit: $limit);
		} catch (Throwable $e) {
			$this->logger->warning('[AnswerCache] similar question search failed: '.$e->getMessage());
			return [];
		}

		return array_values(array_filter((array)$rows, 'is_array'));
	}//end nearest()
}//end class
