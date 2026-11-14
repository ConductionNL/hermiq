<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\KnowledgeBase;

use OCA\Hermiq\Service\KnowledgeBase\SourceSharingInspector;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\IUser;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The owner's notice: which sources only the owner can read.
 */
#[CoversClass(SourceSharingInspector::class)]
class SourceSharingInspectorTest extends TestCase {

	/**
	 * Nodes by file id in alice's Files.
	 *
	 * @var array<int, Node>
	 */
	private array $nodes = [];

	private Folder $home;

	protected function setUp(): void {
		$this->home = $this->createMock(Folder::class);
		$this->home->method('getPath')->willReturn('/alice/files');
		$this->home->method('getById')->willReturnCallback(fn (int $id): array => isset($this->nodes[$id]) ? [$this->nodes[$id]] : []);
	}//end setUp()

	/**
	 * A node owned by $owner on a mount of $mountType under $parent.
	 */
	private function node(string $class, string $path, ?Folder $parent, string $owner = 'alice', string $mountType = ''): Node {
		$node = $this->createMock($class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($owner);
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getMountType')->willReturn($mountType);
		$node->method('getOwner')->willReturn($user);
		$node->method('getMountPoint')->willReturn($mount);
		$node->method('getPath')->willReturn($path);
		$node->method('getParent')->willReturn($parent ?? $this->home);

		return $node;
	}//end node()

	/**
	 * The inspector over alice's Files, with the nodes in $shared shared with a group.
	 *
	 * @param array<int, Node> $shared
	 */
	private function inspector(array $shared = []): SourceSharingInspector {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($this->home);
		$shares = $this->createMock(IShareManager::class);
		$shares->method('getSharesBy')->willReturnCallback(
			function (string $uid, int $type, ?Node $node) use ($shared): array {
				if ($type === IShare::TYPE_CIRCLE) {
					throw new \RuntimeException('no provider');
				}

				return ($uid === 'alice' && $type === IShare::TYPE_GROUP && in_array($node, $shared, true) === true) ? [$this->createMock(IShare::class)] : [];
			}
		);

		return new SourceSharingInspector(rootFolder: $root, shareManager: $shares, logger: $this->createMock(LoggerInterface::class));
	}//end inspector()

	public function testAnUnsharedFolderInTheOwnersFilesIsOnlyTheirs(): void {
		$inkoop = $this->node(class: Folder::class, path: '/alice/files/Inkoop', parent: null);
		$this->nodes[10] = $this->node(class: Folder::class, path: '/alice/files/Inkoop/Privé', parent: $inkoop);

		$sources = $this->inspector()->inspect(knowledgeBase: ['sources' => [['type' => 'folder', 'fileId' => 10, 'path' => 'Inkoop/Privé']]], ownerUid: 'alice');

		self::assertSame([['type' => 'folder', 'fileId' => 10, 'path' => 'Inkoop/Privé', 'missing' => false, 'onlyYou' => true]], $sources);
	}//end testAnUnsharedFolderInTheOwnersFilesIsOnlyTheirs()

	public function testAFolderSharedHigherUpIsReadByOthers(): void {
		$team = $this->node(class: Folder::class, path: '/alice/files/Team Inkoop', parent: null);
		$this->nodes[11] = $this->node(class: Folder::class, path: '/alice/files/Team Inkoop/Modelcontracten', parent: $team);

		$sources = $this->inspector(shared: [$team])->inspect(knowledgeBase: ['sources' => [['type' => 'folder', 'fileId' => 11]]], ownerUid: 'alice');

		self::assertFalse($sources[0]['onlyYou']);
		self::assertFalse($sources[0]['missing']);
	}//end testAFolderSharedHigherUpIsReadByOthers()

	public function testATeamFolderAndSomeoneElsesShareAreNotOnlyTheOwners(): void {
		$this->nodes[12] = $this->node(class: Folder::class, path: '/alice/files/Team', parent: null, mountType: 'group');
		$this->nodes[13] = $this->node(class: Folder::class, path: '/alice/files/Van Bob', parent: null, owner: 'bob', mountType: 'shared');

		$sources = $this->inspector()->inspect(knowledgeBase: ['sources' => [['type' => 'folder', 'fileId' => 12], ['type' => 'folder', 'fileId' => 13]]], ownerUid: 'alice');

		self::assertSame([false, false], array_column($sources, 'onlyYou'));
	}//end testATeamFolderAndSomeoneElsesShareAreNotOnlyTheOwners()

	public function testAGoneFileIsMissingAndObjectSourcesPassThrough(): void {
		$sources = $this->inspector()->inspect(
			knowledgeBase: ['sources' => [['type' => 'file', 'fileId' => 99], ['type' => 'objects', 'register' => 'woo', 'schema' => 'besluit'], 'junk']],
			ownerUid: 'alice'
		);

		self::assertSame(
			[
				['type' => 'file', 'fileId' => 99, 'missing' => true, 'onlyYou' => false],
				['type' => 'objects', 'register' => 'woo', 'schema' => 'besluit'],
			],
			$sources
		);
	}//end testAGoneFileIsMissingAndObjectSourcesPassThrough()

	public function testNoSourcesIsAnEmptyList(): void {
		self::assertSame([], $this->inspector()->inspect(knowledgeBase: ['sources' => 'nope'], ownerUid: 'alice'));
	}//end testNoSourcesIsAnEmptyList()
}//end class
