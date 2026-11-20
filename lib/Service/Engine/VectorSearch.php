<?php

/**
 * Hermiq vector search through OpenRegister's public facade (vector-rag).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reaches OpenRegister's `VectorSearchFacade` lazily, the way `TalkBridge` reaches
 * spreed: a `class_exists()` probe, then the container at call time. Hermiq boots and
 * answers on an OpenRegister that predates the facade; every method here then says so
 * and the caller stays on its keyword path.
 *
 * Three tiers, each with its own log line so a degraded answer is never mistaken for
 * a vector one: the facade is not present, it is present but reports no embedding
 * backend, or a call to it failed.
 *
 * @spec openspec/changes/vector-rag/specs/vector-rag/spec.md
 */
class VectorSearch {

	/**
	 * The published facade class on OpenRegister.
	 *
	 * @var string
	 */
	public const FACADE_CLASS = 'OCA\\OpenRegister\\Service\\Search\\VectorSearchFacade';

	/**
	 * Vector search is live.
	 *
	 * @var string
	 */
	public const TIER_VECTOR = 'vector-live';

	/**
	 * Retrieval runs on the keyword path.
	 *
	 * @var string
	 */
	public const TIER_KEYWORD = 'keyword-degraded';

	/**
	 * Why the keyword tier: OpenRegister has no vector facade.
	 *
	 * @var string
	 */
	public const REASON_NOT_PRESENT = 'facade-not-present';

	/**
	 * Why the keyword tier: the facade reports no embedding backend.
	 *
	 * @var string
	 */
	public const REASON_NO_BACKEND = 'no-embedding-backend';

	/**
	 * The resolved facade; false once resolution failed, null before it was tried.
	 *
	 * @var object|false|null
	 */
	private object|false|null $facade = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Resolves the facade at call time.
	 * @param LoggerInterface    $logger      Says which tier a request ran on.
	 * @param string             $facadeClass The facade class; tests point it at a stub.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $facadeClass=self::FACADE_CLASS,
	) {
	}//end __construct()

	/**
	 * The retrieval tier that is live now, and why.
	 *
	 * @return array{tier: string, reason: string}
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-4-1
	 */
	public function tier(): array {
		$facade = $this->facade();
		if ($facade === null) {
			return ['tier' => self::TIER_KEYWORD, 'reason' => self::REASON_NOT_PRESENT];
		}

		if ($this->isAvailable(facade: $facade) === false) {
			return ['tier' => self::TIER_KEYWORD, 'reason' => self::REASON_NO_BACKEND];
		}

		return ['tier' => self::TIER_VECTOR, 'reason' => ''];
	}//end tier()

	/**
	 * Semantic or hybrid rows from the facade, or null to stay on the keyword path.
	 *
	 * The caller gates on views first: this is never asked with an empty view set.
	 *
	 * @param string                 $mode  `semantic` or `hybrid`.
	 * @param string                 $query The question.
	 * @param int                    $limit At most this many rows.
	 * @param array<int, int|string> $views The resolved view filters, passed through.
	 *
	 * @return array<int, array<string, mixed>>|null The facade's rows, or null on the keyword tier.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-2-1
	 * @spec openspec/changes/vector-rag/tasks.md#task-2-3
	 */
	public function search(string $mode, string $query, int $limit, array $views): ?array {
		$facade = $this->usableFacade(purpose: $mode . ' retrieval');
		if ($facade === null) {
			return null;
		}

		$method = 'searchSemantic';
		if ($mode === 'hybrid') {
			$method = 'searchHybrid';
		}

		try {
			$rows = $facade->{$method}(query: $query, limit: $limit, views: array_values($views));
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[VectorSearch] the OpenRegister vector facade failed; degrading to keyword search',
				context: ['mode' => $mode, 'error' => $e->getMessage()]
			);
			return null;
		}

		return array_values(array_filter((array)$rows, 'is_array'));
	}//end search()

	/**
	 * Embeddings of the texts, in order, or null when no embedding can be made now.
	 *
	 * @param array<int, string> $texts The texts.
	 *
	 * @return array<int, array<int, float>>|null The vectors, or null.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-3-1
	 */
	public function embedTexts(array $texts): ?array {
		if ($texts === []) {
			return [];
		}

		$facade = $this->usableFacade(purpose: 'tool search');
		if ($facade === null) {
			return null;
		}

		try {
			$vectors = $facade->embedTexts(texts: array_values($texts));
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[VectorSearch] embedding failed; tool search uses substring matching',
				context: ['error' => $e->getMessage()]
			);
			return null;
		}

		if (is_array($vectors) === false || count($vectors) !== count($texts)) {
			return null;
		}

		return array_values($vectors);
	}//end embedTexts()

	/**
	 * The id of the model the facade embeds with, so cached vectors are not mixed across models.
	 *
	 * An OpenRegister whose facade does not name its model answers `default`.
	 *
	 * @return string The model id.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-3-2
	 */
	public function modelId(): string {
		$facade = $this->facade();
		if ($facade === null || method_exists($facade, 'embeddingModelId') === false) {
			return 'default';
		}

		try {
			$model = (string)$facade->embeddingModelId();
		} catch (Throwable) {
			return 'default';
		}

		if ($model === '') {
			return 'default';
		}

		return $model;
	}//end modelId()

	/**
	 * The facade when it is present and has a backend, logging which tier stops it otherwise.
	 *
	 * @param string $purpose What it was asked for, for the log line.
	 *
	 * @return object|null The facade, or null.
	 */
	private function usableFacade(string $purpose): ?object {
		$facade = $this->facade();
		if ($facade === null) {
			$this->logger->info(
				message: '[VectorSearch] OpenRegister has no vector facade; ' . $purpose . ' uses the keyword path',
				context: ['facade' => $this->facadeClass]
			);
			return null;
		}

		if ($this->isAvailable(facade: $facade) === false) {
			$this->logger->info(
				message: '[VectorSearch] the OpenRegister vector facade has no embedding backend; ' . $purpose . ' uses the keyword path',
				context: []
			);
			return null;
		}

		return $facade;
	}//end usableFacade()

	/**
	 * The facade, resolved once.
	 *
	 * @return object|null The facade, or null when OpenRegister has none.
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
	 * Resolve the facade behind a class probe.
	 *
	 * @return object|false The facade, or false.
	 */
	private function resolve(): object|false {
		if (class_exists($this->facadeClass) === false) {
			return false;
		}

		try {
			$facade = $this->container->get($this->facadeClass);
		} catch (Throwable $e) {
			$this->logger->info(
				message: '[VectorSearch] the OpenRegister vector facade could not be resolved',
				context: ['error' => $e->getMessage()]
			);
			return false;
		}

		if (is_object($facade) === false) {
			return false;
		}

		return $facade;
	}//end resolve()

	/**
	 * Whether the facade reports an embedding backend.
	 *
	 * @param object $facade The facade.
	 *
	 * @return bool
	 */
	private function isAvailable(object $facade): bool {
		try {
			return method_exists($facade, 'isAvailable') === true && $facade->isAvailable() === true;
		} catch (Throwable) {
			return false;
		}
	}//end isAvailable()
}//end class
