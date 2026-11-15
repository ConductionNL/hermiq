<?php

/**
 * Hermiq KnowledgeBaseScopeResolver.
 *
 * Resolves a knowledge base's sources into the file ids the person in the
 * session may read: each `file` source that opens as that person, and every
 * readable file under each `folder` source. Sources are opened by file id in
 * the person's own Files, so a folder only the owner can read gives a
 * colleague nothing. `objects` sources are returned as register and schema
 * pairs; files are never copied.
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

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The scope of a knowledge base for one person.
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003
 */
class KnowledgeBaseScopeResolver {

	/**
	 * The most files one knowledge base resolves to per turn.
	 */
	public const MAX_FILES = 500;

	/**
	 * Constructor.
	 *
	 * @param IRootFolder     $rootFolder The Files of the person in the session.
	 * @param LoggerInterface $logger     Where an unopenable source is noted.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The file ids of the knowledge bases' sources that a person may read.
	 *
	 * @param array<int, array<string, mixed>> $knowledgeBases The knowledge base objects.
	 * @param string                           $speaker        The uid of the person in the session.
	 *
	 * @return array<int, int> Distinct file ids, in source order, at most MAX_FILES per knowledge base.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-agent-answers-from-its-attached-knowledge-bases-req-kb-002
	 */
	public function fileIds(array $knowledgeBases, string $speaker): array {
		if ($speaker === '') {
			return [];
		}

		$ids = [];
		foreach ($knowledgeBases as $knowledgeBase) {
			$files = [];
			foreach (self::sources(knowledgeBase: $knowledgeBase) as $source) {
				$this->collectSource(source: $source, speaker: $speaker, files: $files);
			}

			array_push($ids, ...$files);
		}

		return array_values(array_unique($ids));
	}//end fileIds()

	/**
	 * The register and schema pairs of the knowledge bases' `objects` sources.
	 *
	 * @param array<int, array<string, mixed>> $knowledgeBases The knowledge base objects.
	 *
	 * @return array<int, array{register: string, schema: string}>
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-agent-answers-from-its-attached-knowledge-bases-req-kb-002
	 */
	public function objectScopes(array $knowledgeBases): array {
		$scopes = [];
		foreach ($knowledgeBases as $knowledgeBase) {
			foreach (self::sources(knowledgeBase: $knowledgeBase) as $source) {
				$scope = self::objectScope(source: $source);
				if ($scope !== null) {
					$scopes[$scope['register'].'/'.$scope['schema']] = $scope;
				}
			}
		}

		return array_values($scopes);
	}//end objectScopes()

	/**
	 * The register and schema of an `objects` source, or null for any other source.
	 *
	 * @param array<string, mixed> $source The source.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private static function objectScope(array $source): ?array {
		if (($source['type'] ?? '') !== 'objects') {
			return null;
		}

		$scope = [
			'register' => trim((string)($source['register'] ?? '')),
			'schema' => trim((string)($source['schema'] ?? '')),
		];
		if (in_array('', $scope, true) === true) {
			return null;
		}

		return $scope;
	}//end objectScope()

	/**
	 * A knowledge base's sources that are arrays.
	 *
	 * @param array<string, mixed> $knowledgeBase The knowledge base.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function sources(array $knowledgeBase): array {
		$sources = ($knowledgeBase['sources'] ?? []);
		if (is_array($sources) === false) {
			return [];
		}

		return array_values(array_filter($sources, 'is_array'));
	}//end sources()

	/**
	 * Add the readable files of one `file` or `folder` source.
	 *
	 * @param array<string, mixed> $source  The source.
	 * @param string               $speaker The person.
	 * @param array<int, int>      $files   The file ids so far, by reference.
	 *
	 * @return void
	 */
	private function collectSource(array $source, string $speaker, array &$files): void {
		$type = (string)($source['type'] ?? '');
		$node = $this->readableSourceNode(source: $source, speaker: $speaker);
		if ($type === 'file' && $node instanceof File) {
			$this->add(node: $node, files: $files);
		} elseif ($type === 'folder' && $node instanceof Folder) {
			$this->collectFolder(folder: $node, files: $files);
		}
	}//end collectSource()

	/**
	 * The readable node of a `file` or `folder` source in the person's own Files, or null.
	 *
	 * @param array<string, mixed> $source  The source.
	 * @param string               $speaker The person.
	 *
	 * @return Node|null
	 */
	private function readableSourceNode(array $source, string $speaker): ?Node {
		if (in_array(($source['type'] ?? ''), ['file', 'folder'], true) === false) {
			return null;
		}

		$node = $this->open(fileId: (int)($source['fileId'] ?? 0), speaker: $speaker);
		if ($node === null || $node->isReadable() === false) {
			return null;
		}

		return $node;
	}//end readableSourceNode()

	/**
	 * Add every readable file under a folder.
	 *
	 * @param Folder          $folder The folder.
	 * @param array<int, int> $files  The file ids so far, by reference.
	 *
	 * @return void
	 */
	private function collectFolder(Folder $folder, array &$files): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if (count($files) >= self::MAX_FILES) {
				return;
			}

			if ($node->isReadable() === false) {
				continue;
			}

			if ($node instanceof Folder) {
				$this->collectFolder(folder: $node, files: $files);
				continue;
			}

			if ($node instanceof File) {
				$this->add(node: $node, files: $files);
			}
		}
	}//end collectFolder()

	/**
	 * Add one file's id, within the limit.
	 *
	 * @param File            $node  The file.
	 * @param array<int, int> $files The file ids so far, by reference.
	 *
	 * @return void
	 */
	private function add(File $node, array &$files): void {
		$id = (int)$node->getId();
		if ($id > 0 && count($files) < self::MAX_FILES && in_array($id, $files, true) === false) {
			$files[] = $id;
		}
	}//end add()

	/**
	 * Open a node by id in the person's own Files, or null.
	 *
	 * @param int    $fileId  The file id.
	 * @param string $speaker The person.
	 *
	 * @return Node|null
	 */
	private function open(int $fileId, string $speaker): ?Node {
		if ($fileId < 1) {
			return null;
		}

		try {
			return ($this->rootFolder->getUserFolder($speaker)->getById($fileId)[0] ?? null);
		} catch (Throwable $e) {
			$this->logger->info('[KnowledgeBaseScopeResolver] A source could not be opened as the speaker: '.$e->getMessage(), ['fileId' => $fileId]);
			return null;
		}
	}//end open()
}//end class
