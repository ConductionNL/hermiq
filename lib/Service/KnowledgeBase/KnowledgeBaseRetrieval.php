<?php

/**
 * Hermiq KnowledgeBaseRetrieval.
 *
 * The knowledge base half of a turn's retrieval: the agent's attached
 * knowledge bases are read, their sources resolved to the files the person in
 * the session may read, and those files searched through the vector facade's
 * file scope. Rows are file rows the existing source loop already formats.
 * Without the facade or its scope nothing is searched and the turn goes on.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Searches an agent's knowledge bases for one turn.
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-agent-answers-from-its-attached-knowledge-bases-req-kb-002
 */
class KnowledgeBaseRetrieval {

	/**
	 * The knowledge base schema.
	 */
	public const SCHEMA = 'agentknowledgebase';

	/**
	 * The most knowledge bases one agent searches per turn.
	 */
	public const MAX_KNOWLEDGE_BASES = 10;

	/**
	 * Constructor.
	 *
	 * @param ObjectService              $objects The knowledge base objects.
	 * @param KnowledgeBaseScopeResolver $scope   The files the person may read.
	 * @param VectorFacadeProbe          $probe   The file-scoped vector search.
	 * @param LoggerInterface            $logger  Where an unreadable knowledge base is noted.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-agent-answers-from-its-attached-knowledge-bases-req-kb-002
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly KnowledgeBaseScopeResolver $scope,
		private readonly VectorFacadeProbe $probe,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The file rows the agent's knowledge bases give for a question.
	 *
	 * @param array<string, mixed> $agentData The agent.
	 * @param string               $query     The question.
	 * @param string               $speaker   The uid of the person in the session.
	 * @param int                  $limit     At most this many rows.
	 *
	 * @return array<int, array<string, mixed>> File rows of files in scope only.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-agent-answers-from-its-attached-knowledge-bases-req-kb-002
	 */
	public function rows(array $agentData, string $query, string $speaker, int $limit): array {
		$refs = self::refs(agentData: $agentData);
		if ($refs === [] || $speaker === '' || $this->probe->canSearchFiles() === false) {
			return [];
		}

		$fileIds = $this->scope->fileIds(knowledgeBases: $this->load(refs: $refs), speaker: $speaker);
		if ($fileIds === []) {
			return [];
		}

		return self::inScope(rows: $this->probe->searchFiles(query: $query, fileIds: $fileIds, limit: $limit), fileIds: $fileIds);
	}//end rows()

	/**
	 * The rows about the files in scope, typed as file rows: the scope is kept
	 * even if a facade ignored it.
	 *
	 * @param array<int, array<string, mixed>> $rows    The facade's rows.
	 * @param array<int, int>                  $fileIds The files in scope.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function inScope(array $rows, array $fileIds): array {
		$kept = [];
		foreach ($rows as $row) {
			if (in_array(self::fileIdOf(row: $row), $fileIds, true) === true) {
				$kept[] = ['entity_type' => 'file'] + $row;
			}
		}

		return $kept;
	}//end inScope()

	/**
	 * The agent's knowledge base uuids, distinct and capped.
	 *
	 * @param array<string, mixed> $agentData The agent.
	 *
	 * @return array<int, string>
	 */
	private static function refs(array $agentData): array {
		$refs = ($agentData['knowledgeBaseRefs'] ?? []);
		if (is_array($refs) === false) {
			return [];
		}

		$refs = array_filter(array_map(static fn ($ref): string => trim((string)$ref), array_filter($refs, 'is_scalar')));

		return array_slice(array_values(array_unique($refs)), 0, self::MAX_KNOWLEDGE_BASES);
	}//end refs()

	/**
	 * Read the knowledge bases; one that cannot be read is skipped.
	 *
	 * @param array<int, string> $refs The uuids.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function load(array $refs): array {
		$knowledgeBases = [];
		foreach ($refs as $ref) {
			try {
				$found = $this->objects->find(
					id: $ref,
					options: new ObjectFindOptions(register: 'hermiq', schema: self::SCHEMA),
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$this->logger->info('[KnowledgeBaseRetrieval] Knowledge base '.$ref.' could not be read: '.$e->getMessage());
				continue;
			}

			if ($found instanceof ObjectEntity) {
				$knowledgeBases[] = $found->getObject();
			}
		}

		return $knowledgeBases;
	}//end load()

	/**
	 * The file id a facade row is about.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return int
	 */
	private static function fileIdOf(array $row): int {
		$metadata = ($row['metadata'] ?? []);
		if (is_array($metadata) === true && isset($metadata['file_id']) === true) {
			return (int)$metadata['file_id'];
		}

		return (int)($row['entity_id'] ?? 0);
	}//end fileIdOf()
}//end class
