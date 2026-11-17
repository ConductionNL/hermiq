<?php

/**
 * Tests for KnowledgeBaseScopeResolver: each person's scope is what they may read.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\KnowledgeBase
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md#task-5-the-person-scoped-read-and-the-unshared-source-notice
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\KnowledgeBase;

use OCA\Hermiq\Service\KnowledgeBase\KnowledgeBaseScopeResolver;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The resolver over two people's Files: the owner and a colleague.
 */
#[CoversClass(KnowledgeBaseScopeResolver::class)]
class KnowledgeBaseScopeResolverTest extends TestCase {

	/**
	 * A knowledge base with a private folder (10), a shared file (20) and an objects source.
	 *
	 * @var array<string, mixed>
	 */
	private const KNOWLEDGE_BASE = [
		'name' => 'Inkoopbeleid',
		'sources' => [
			['type' => 'folder', 'fileId' => 10, 'path' => 'Inkoop/Privé'],
			['type' => 'file', 'fileId' => 20, 'path' => 'Team Inkoop/inkoopvoorwaarden-2026.pdf'],
			['type' => 'objects', 'register' => 'woo', 'schema' => 'besluit'],
			'not a source',
		],
	];

	/**
	 * The owner reads the folder's files, nested ones too, and the shared file.
	 *
	 * @return void
	 */
	public function testTheOwnerReadsTheFolderAndTheSharedFile(): void {
		$resolver = $this->resolver();

		$this->assertSame([11, 13, 20], $resolver->fileIds(knowledgeBases: [self::KNOWLEDGE_BASE], speaker: 'anna'));
	}//end testTheOwnerReadsTheFolderAndTheSharedFile()

	/**
	 * A colleague gets nothing from the owner's private folder, only the shared file.
	 *
	 * @return void
	 */
	public function testAColleagueGetsNothingFromThePrivateFolder(): void {
		$resolver = $this->resolver();

		$this->assertSame([20], $resolver->fileIds(knowledgeBases: [self::KNOWLEDGE_BASE], speaker: 'bert'));
		$this->assertSame([], $resolver->fileIds(knowledgeBases: [self::KNOWLEDGE_BASE], speaker: ''));
	}//end testAColleagueGetsNothingFromThePrivateFolder()

	/**
	 * A source of the wrong kind of node, or one that cannot be opened, adds nothing.
	 *
	 * @return void
	 */
	public function testMismatchedAndUnopenableSourcesAddNothing(): void {
		$resolver = $this->resolver();
		$knowledgeBase = [
			'sources' => [
				['type' => 'file', 'fileId' => 10],
				['type' => 'folder', 'fileId' => 20],
				['type' => 'file', 'fileId' => 0],
				['type' => 'file', 'fileId' => 99],
				['type' => 'file', 'fileId' => 20],
			],
		];

		$this->assertSame([20], $resolver->fileIds(knowledgeBases: [$knowledgeBase, ['sources' => 'broken']], speaker: 'anna'));
	}//end testMismatchedAndUnopenableSourcesAddNothing()

	/**
	 * The objects sources become distinct register and schema pairs.
	 *
	 * @return void
	 */
	public function testObjectSourcesBecomeRegisterSchemaPairs(): void {
		$resolver = $this->resolver();
		$second = ['sources' => [['type' => 'objects', 'register' => 'woo', 'schema' => 'besluit'], ['type' => 'objects', 'register' => 'woo', 'schema' => ' ']]];

		$this->assertSame([['register' => 'woo', 'schema' => 'besluit']], $resolver->objectScopes(knowledgeBases: [self::KNOWLEDGE_BASE, $second]));
	}//end testObjectSourcesBecomeRegisterSchemaPairs()

	/**
	 * A folder stops at the per-knowledge-base limit.
	 *
	 * @return void
	 */
	public function testAFolderStopsAtTheLimit(): void {
		$many = [];
		for ($id = 1000; $id < 1000 + KnowledgeBaseScopeResolver::MAX_FILES + 20; $id++) {
			$many[] = $this->file(id: $id);
		}

		$folder = $this->folder(id: 30, children: $many);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($this->userFolder(nodes: [30 => $folder]));
		$resolver = new KnowledgeBaseScopeResolver($root, $this->createMock(LoggerInterface::class));

		$ids = $resolver->fileIds(knowledgeBases: [['sources' => [['type' => 'folder', 'fileId' => 30]]]], speaker: 'anna');

		$this->assertCount(KnowledgeBaseScopeResolver::MAX_FILES, $ids);
		$this->assertSame(1000, $ids[0]);
	}//end testAFolderStopsAtTheLimit()

	/**
	 * The resolver: anna owns folder 10 (files 11, nested 13, unreadable 12); file 20 is shared with bert.
	 *
	 * @return KnowledgeBaseScopeResolver
	 */
	private function resolver(): KnowledgeBaseScopeResolver {
		$nested = $this->folder(id: 14, children: [$this->file(id: 13)]);
		$private = $this->folder(id: 10, children: [$this->file(id: 11), $this->file(id: 12, readable: false), $nested]);
		$shared = $this->file(id: 20);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			fn (string $uid): Folder => match ($uid) {
				'anna' => $this->userFolder(nodes: [10 => $private, 20 => $shared]),
				'bert' => $this->userFolder(nodes: [20 => $shared]),
				default => throw new RuntimeException('no such user'),
			}
		);

		return new KnowledgeBaseScopeResolver($root, $this->createMock(LoggerInterface::class));
	}//end resolver()

	/**
	 * A person's Files, answering getById() from a map.
	 *
	 * @param array<int, Node> $nodes The nodes by id.
	 *
	 * @return Folder
	 */
	private function userFolder(array $nodes): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			static function (int $id) use ($nodes): array {
				if ($id === 99) {
					throw new RuntimeException('storage offline');
				}

				return isset($nodes[$id]) === true ? [$nodes[$id]] : [];
			}
		);

		return $folder;
	}//end userFolder()

	/**
	 * A folder node.
	 *
	 * @param int          $id       Its id.
	 * @param array<Node>  $children Its listing.
	 *
	 * @return Folder
	 */
	private function folder(int $id, array $children): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('isReadable')->willReturn(true);
		$folder->method('getDirectoryListing')->willReturn($children);

		return $folder;
	}//end folder()

	/**
	 * A file node.
	 *
	 * @param int  $id       Its id.
	 * @param bool $readable Whether the person may read it.
	 *
	 * @return File
	 */
	private function file(int $id, bool $readable = true): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('isReadable')->willReturn($readable);

		return $file;
	}//end file()
}//end class
