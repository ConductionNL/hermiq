<?php

/**
 * Hermiq SourceSharingInspector.
 *
 * Tells the owner of a knowledge base which of its file and folder sources
 * only they can read, so the detail page can warn that other people who use
 * the agent get no answers from them. The check reads the node's shares in
 * Files (on the node and every folder above it) and its mount; it runs no
 * search.
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

use OCA\Hermiq\Kernel\Contracts\SourceSharingInspectorInterface;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Which knowledge base sources only the owner can read.
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003
 */
class SourceSharingInspector implements SourceSharingInspectorInterface {

	/**
	 * The share types that let another person read a node in their own Files.
	 */
	private const READER_SHARE_TYPES = [
		IShare::TYPE_USER,
		IShare::TYPE_GROUP,
		IShare::TYPE_CIRCLE,
		IShare::TYPE_ROOM,
	];

	/**
	 * Constructor.
	 *
	 * @param IRootFolder     $rootFolder   The owner's Files.
	 * @param IShareManager   $shareManager The shares on a node.
	 * @param LoggerInterface $logger       Where an unreadable share type is noted.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IShareManager $shareManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The knowledge base's sources, each `file` and `folder` one marked with `onlyYou` and `missing`.
	 *
	 * `onlyYou` is true when the source sits in the owner's own Files storage and
	 * neither it nor a folder above it is shared with a user, group, team or room.
	 * `missing` is true when the owner can no longer open it. `objects` sources
	 * are returned unchanged.
	 *
	 * @param array<string, mixed> $knowledgeBase The knowledge base.
	 * @param string               $ownerUid      The owner, the person on the detail page.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003
	 */
	public function inspect(array $knowledgeBase, string $ownerUid): array {
		$sources = ($knowledgeBase['sources'] ?? []);
		if (is_array($sources) === false) {
			return [];
		}

		$inspected = [];
		foreach (array_values(array_filter($sources, 'is_array')) as $source) {
			if (in_array(($source['type'] ?? ''), ['file', 'folder'], true) === false) {
				$inspected[] = $source;
				continue;
			}

			$node = $this->open(fileId: (int)($source['fileId'] ?? 0), ownerUid: $ownerUid);
			$source['missing'] = ($node === null);
			$source['onlyYou'] = ($node !== null && $this->onlyOwnerReads(node: $node, ownerUid: $ownerUid) === true);
			$inspected[] = $source;
		}

		return $inspected;
	}//end inspect()

	/**
	 * Whether only the owner can read a node.
	 *
	 * @param Node   $node     The node, opened in the owner's Files.
	 * @param string $ownerUid The owner.
	 *
	 * @return bool
	 */
	private function onlyOwnerReads(Node $node, string $ownerUid): bool {
		try {
			if ($node->getOwner()?->getUID() !== $ownerUid || $node->getMountPoint()->getMountType() !== '') {
				// Someone else's share, a team folder or external storage: others read it by design.
				return false;
			}
		} catch (Throwable $e) {
			$this->logger->info('[SourceSharingInspector] The owner of a source could not be read: '.$e->getMessage());
			return false;
		}

		$home = $this->rootFolder->getUserFolder($ownerUid)->getPath();
		for ($current = $node; $current !== null; $current = $this->parentBelow(node: $current, home: $home)) {
			if ($this->isShared(node: $current, ownerUid: $ownerUid) === true) {
				return false;
			}
		}

		return true;
	}//end onlyOwnerReads()

	/**
	 * The parent of a node while it is still inside the owner's Files, or null at the top.
	 *
	 * @param Node   $node The node.
	 * @param string $home The path of the owner's Files root.
	 *
	 * @return Folder|null
	 */
	private function parentBelow(Node $node, string $home): ?Folder {
		if ($node->getPath() === $home) {
			return null;
		}

		try {
			$parent = $node->getParent();
		} catch (Throwable) {
			return null;
		}

		if (str_starts_with($parent->getPath(), $home) === false) {
			return null;
		}

		return $parent;
	}//end parentBelow()

	/**
	 * Whether the owner shared a node with another reader.
	 *
	 * @param Node   $node     The node.
	 * @param string $ownerUid The owner.
	 *
	 * @return bool
	 */
	private function isShared(Node $node, string $ownerUid): bool {
		foreach (self::READER_SHARE_TYPES as $type) {
			try {
				if ($this->shareManager->getSharesBy($ownerUid, $type, $node, false, 1) !== []) {
					return true;
				}
			} catch (Throwable $e) {
				// A share type whose app is not installed has no provider.
				$this->logger->debug('[SourceSharingInspector] Share type '.$type.' could not be read: '.$e->getMessage());
			}
		}

		return false;
	}//end isShared()

	/**
	 * Open a node by id in the owner's Files, or null.
	 *
	 * @param int    $fileId   The file id.
	 * @param string $ownerUid The owner.
	 *
	 * @return Node|null
	 */
	private function open(int $fileId, string $ownerUid): ?Node {
		if ($fileId < 1 || $ownerUid === '') {
			return null;
		}

		try {
			return ($this->rootFolder->getUserFolder($ownerUid)->getById($fileId)[0] ?? null);
		} catch (Throwable) {
			return null;
		}
	}//end open()
}//end class
