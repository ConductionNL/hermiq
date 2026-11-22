<?php

/**
 * Hermiq VectorFacadeProbe.
 *
 * Resolves OpenRegister's VectorSearchFacade lazily and says whether it can
 * search a set of files: a knowledge base is searched only through a file
 * scope, never across everything the person may read. A facade that offers
 * either `searchHybridInFiles(query, fileIds, limit)` or a `fileIds`
 * argument on `searchHybrid()` has the scope; `indexStatus(fileIds)` gives
 * each file's indexing state. Without the facade, or without the scope,
 * knowledge bases are not searched and the turn goes on without them.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\KnowledgeBase
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\KnowledgeBase;

use OCA\Hermiq\Service\Engine\VectorFacadeLocator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * The guarded seam to OpenRegister's vector search, for knowledge bases.
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-the-vector-facade-offers-a-file-scope-and-an-indexing-state-req-kb-005
 */
class VectorFacadeProbe {

	/**
	 * The facade OpenRegister publishes.
	 */
	public const FACADE_CLASS = 'OCA\\OpenRegister\\Service\\Search\\VectorSearchFacade';

	/**
	 * A dedicated file-scoped search method.
	 */
	public const FILE_SCOPED_METHOD = 'searchHybridInFiles';

	/**
	 * The per-file indexing state method.
	 */
	public const INDEX_STATUS_METHOD = 'indexStatus';

	/**
	 * The indexing states a file can be in.
	 */
	public const STATES = ['indexed', 'pending', 'failed', 'unsupported'];

	/**
	 * Finds the facade once and says whether it has a backend.
	 *
	 * @var VectorFacadeLocator
	 */
	private readonly VectorFacadeLocator $locator;

	/**
	 * Whether the missing scope was logged already.
	 *
	 * @var boolean
	 */
	private bool $loggedMissingScope = false;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Where the facade is resolved, on first use.
	 * @param LoggerInterface    $logger      Where a missing facade or scope is noted.
	 * @param string             $facadeClass The facade class name.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-the-vector-facade-offers-a-file-scope-and-an-indexing-state-req-kb-005
	 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $facadeClass=self::FACADE_CLASS,
	) {
		$this->locator = new VectorFacadeLocator(
			container: $this->container,
			logger: $this->logger,
			facadeClass: $this->facadeClass,
			caller: 'VectorFacadeProbe'
		);
	}//end __construct()

	/**
	 * Whether knowledge bases can be searched on this server.
	 *
	 * @return bool True when the facade is there, available and file-scoped.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-the-vector-facade-offers-a-file-scope-and-an-indexing-state-req-kb-005
	 */
	public function canSearchFiles(): bool {
		$facade = $this->facade();
		if ($facade === null || $this->isAvailable(facade: $facade) === false) {
			return false;
		}

		if ($this->scopeShape(facade: $facade) !== null) {
			return true;
		}

		if ($this->loggedMissingScope === false) {
			$this->loggedMissingScope = true;
			$this->logger->info('[VectorFacadeProbe] The OpenRegister vector facade has no file scope; knowledge bases are not searched.');
		}

		return false;
	}//end canSearchFiles()

	/**
	 * Search only the given files, as the calling user.
	 *
	 * @param string          $query   The question.
	 * @param array<int, int> $fileIds The files in scope.
	 * @param int             $limit   At most this many rows.
	 *
	 * @return array<int, array<string, mixed>> The facade's rows, empty when nothing can be searched.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-agent-answers-from-its-attached-knowledge-bases-req-kb-002
	 */
	public function searchFiles(string $query, array $fileIds, int $limit): array {
		if ($fileIds === [] || $limit < 1 || trim($query) === '' || $this->canSearchFiles() === false) {
			return [];
		}

		$facade = $this->facade();
		try {
			if ($this->scopeShape(facade: $facade) === self::FILE_SCOPED_METHOD) {
				$rows = $facade->{self::FILE_SCOPED_METHOD}($query, array_values($fileIds), $limit);
			} else {
				$rows = $facade->searchHybrid(query: $query, limit: $limit, fileIds: array_values($fileIds));
			}
		} catch (Throwable $e) {
			$this->logger->warning('[VectorFacadeProbe] Knowledge base search failed: '.$e->getMessage());
			return [];
		}

		return array_values(array_filter((array)$rows, 'is_array'));
	}//end searchFiles()

	/**
	 * Each file's indexing state; empty when the facade cannot tell.
	 *
	 * @param array<int, int> $fileIds The files.
	 *
	 * @return array<int, string> File id to `indexed`, `pending`, `failed` or `unsupported`.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-the-vector-facade-offers-a-file-scope-and-an-indexing-state-req-kb-005
	 */
	public function indexStatus(array $fileIds): array {
		$facade = $this->facade();
		if ($fileIds === [] || $facade === null || method_exists($facade, self::INDEX_STATUS_METHOD) === false) {
			return [];
		}

		try {
			$states = (array)$facade->{self::INDEX_STATUS_METHOD}(array_values($fileIds));
		} catch (Throwable $e) {
			$this->logger->warning('[VectorFacadeProbe] The indexing state could not be read: '.$e->getMessage());
			return [];
		}

		return self::knownStates(states: $states);
	}//end indexStatus()

	/**
	 * The states the facade reported that are one of STATES, by file id.
	 *
	 * @param array<int|string, mixed> $states The facade's answer.
	 *
	 * @return array<int, string>
	 */
	private static function knownStates(array $states): array {
		$known = [];
		foreach ($states as $fileId => $state) {
			if (in_array($state, self::STATES, true) === true) {
				$known[(int)$fileId] = $state;
			}
		}

		return $known;
	}//end knownStates()

	/**
	 * The facade, resolved once; null when OpenRegister does not offer it.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
	 */
	private function facade(): ?object {
		return $this->locator->facade();
	}//end facade()

	/**
	 * Whether the facade says an embedding provider is configured.
	 *
	 * @param object $facade The facade.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
	 */
	private function isAvailable(object $facade): bool {
		return $this->locator->hasBackend(facade: $facade);
	}//end isAvailable()

	/**
	 * Which file scope the facade offers: the dedicated method, the argument, or none.
	 *
	 * @param object $facade The facade.
	 *
	 * @return string|null `searchHybridInFiles`, `fileIds`, or null.
	 */
	private function scopeShape(object $facade): ?string {
		if (method_exists($facade, self::FILE_SCOPED_METHOD) === true) {
			return self::FILE_SCOPED_METHOD;
		}

		if (method_exists($facade, 'searchHybrid') === false) {
			return null;
		}

		foreach ((new ReflectionMethod($facade, 'searchHybrid'))->getParameters() as $parameter) {
			if ($parameter->getName() === 'fileIds') {
				return 'fileIds';
			}
		}

		return null;
	}//end scopeShape()
}//end class
