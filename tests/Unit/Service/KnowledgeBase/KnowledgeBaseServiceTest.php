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

use OCA\Hermiq\Service\Files\FolderPath;
use OCA\Hermiq\Service\KnowledgeBase\KnowledgeBaseException;
use OCA\Hermiq\Service\KnowledgeBase\KnowledgeBaseService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Uploads land in the knowledge base's folder in the owner's Files and become file sources.
 */
#[CoversClass(KnowledgeBaseService::class)]
#[UsesClass(KnowledgeBaseException::class)]
#[UsesClass(FolderPath::class)]
class KnowledgeBaseServiceTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder $rootFolder;

	/**
	 * Temporary upload files.
	 *
	 * @var array<int, string>
	 */
	private array $tmp = [];

	protected function setUp(): void {
		$this->objects    = $this->createMock(ObjectService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->tmp as $path) {
			@unlink($path);
		}
	}//end tearDown()

	private function service(): KnowledgeBaseService {
		return new KnowledgeBaseService(objects: $this->objects, rootFolder: $this->rootFolder, logger: $this->createMock(LoggerInterface::class));
	}//end service()

	private function knowledgeBase(string $owner, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setOwner($owner);
		$entity->setObject($data);
		$this->objects->method('find')->willReturn($entity);

		return $entity;
	}//end knowledgeBase()

	private function upload(string $name, string $bytes): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'kb');
		file_put_contents($path, $bytes);
		$this->tmp[] = $path;

		return ['name' => $name, 'tmp_name' => $path, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK];
	}//end upload()

	public function testTheOwnersTwoPdfsLandInTheFolderAndBecomeFileSources(): void {
		$this->knowledgeBase(owner: 'alice', data: [
			'name' => 'Inkoopbeleid',
			'sources' => [['type' => 'folder', 'fileId' => 5, 'path' => 'Team Inkoop/Modelcontracten']],
		]);

		$written = [];
		$folder  = $this->createMock(Folder::class);
		$folder->method('getNonExistingName')->willReturnArgument(0);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $bytes) use (&$written): File {
				$written[$name] = $bytes;
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(count($written) + 100);
				$file->method('getPath')->willReturn('/alice/files/Hermiq/Knowledge bases/Inkoopbeleid/'.$name);
				return $file;
			}
		);

		$created = [];
		$home    = $this->createMock(Folder::class);
		$home->method('nodeExists')->willReturn(false);
		$home->method('newFolder')->willReturnCallback(
			function (string $segment) use (&$created, $folder): Folder {
				$created[] = $segment;
				return $folder;
			}
		);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturnCallback(
			function (string $segment) use (&$created, $folder): Folder {
				$created[] = $segment;
				return $folder;
			}
		);
		$home->method('getRelativePath')->willReturnCallback(static fn (string $path): string => substr($path, strlen('/alice/files')));
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($home);

		$saved = null;
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options) use (&$saved): ObjectEntity {
				$saved = ['object' => $object, 'register' => $options->target->register, 'schema' => $options->target->schema, 'uuid' => $options->uuid];
				return new ObjectEntity();
			}
		);

		$sources = $this->service()->upload(
			id: 'kb-1',
			callerUid: 'alice',
			uploads: [$this->upload(name: 'inkoopvoorwaarden-2026.pdf', bytes: '%PDF-1'), $this->upload(name: '../../etc/model.pdf', bytes: '%PDF-2')]
		);

		self::assertSame(['Hermiq', 'Knowledge bases', 'Inkoopbeleid'], $created);
		self::assertSame(['inkoopvoorwaarden-2026.pdf' => '%PDF-1', 'model.pdf' => '%PDF-2'], $written);
		self::assertSame(
			[
				['type' => 'folder', 'fileId' => 5, 'path' => 'Team Inkoop/Modelcontracten'],
				['type' => 'file', 'fileId' => 101, 'path' => '/Hermiq/Knowledge bases/Inkoopbeleid/inkoopvoorwaarden-2026.pdf'],
				['type' => 'file', 'fileId' => 102, 'path' => '/Hermiq/Knowledge bases/Inkoopbeleid/model.pdf'],
			],
			$sources
		);
		self::assertSame($sources, $saved['object']['sources']);
		self::assertSame('Hermiq/Knowledge bases/Inkoopbeleid', $saved['object']['folderPath']);
		self::assertSame('hermiq', $saved['register']);
		self::assertSame('agentknowledgebase', $saved['schema']);
		self::assertSame('kb-1', $saved['uuid']);
	}//end testTheOwnersTwoPdfsLandInTheFolderAndBecomeFileSources()

	public function testANonOwnerUploadIsNotFoundAndWritesNothing(): void {
		$this->knowledgeBase(owner: 'alice', data: ['name' => 'Inkoopbeleid']);
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(KnowledgeBaseException::class);
		$this->expectExceptionCode(404);

		$this->service()->upload(id: 'kb-1', callerUid: 'bob', uploads: [$this->upload(name: 'a.pdf', bytes: 'x')]);
	}//end testANonOwnerUploadIsNotFoundAndWritesNothing()

	public function testAMissingKnowledgeBaseIsNotFound(): void {
		$this->objects->method('find')->willThrowException(new \RuntimeException('gone'));

		$this->expectException(KnowledgeBaseException::class);
		$this->expectExceptionCode(404);

		$this->service()->owned(id: 'kb-1', callerUid: 'alice');
	}//end testAMissingKnowledgeBaseIsNotFound()

	public function testAnUploadWithNoWholeFileIsRefused(): void {
		$this->knowledgeBase(owner: 'alice', data: ['name' => 'Inkoopbeleid']);
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(KnowledgeBaseException::class);
		$this->expectExceptionCode(400);
		$this->expectExceptionMessage('No document was uploaded.');

		$this->service()->upload(id: 'kb-1', callerUid: 'alice', uploads: [['name' => 'a.pdf', 'error' => UPLOAD_ERR_PARTIAL]]);
	}//end testAnUploadWithNoWholeFileIsRefused()

	public function testADocumentOverTheCapIsRefused(): void {
		$this->knowledgeBase(owner: 'alice', data: ['name' => 'Inkoopbeleid']);

		$this->expectException(KnowledgeBaseException::class);
		$this->expectExceptionMessage('A document is larger than 100 MB.');

		$this->service()->upload(id: 'kb-1', callerUid: 'alice', uploads: [['name' => 'a.pdf', 'tmp_name' => '/nonexistent', 'size' => KnowledgeBaseService::MAX_BYTES + 1, 'error' => UPLOAD_ERR_OK]]);
	}//end testADocumentOverTheCapIsRefused()

	public function testTheFolderPathIsCleanedAndDefaultsUnderTheName(): void {
		self::assertSame(['Team Inkoop', 'Kennisbank'], KnowledgeBaseService::folderPathOf(knowledgeBase: ['folderPath' => '/Team Inkoop/../Kennisbank/./']));
		self::assertSame(['Hermiq', 'Knowledge bases', 'Beleid 2026'], KnowledgeBaseService::folderPathOf(knowledgeBase: ['name' => 'Beleid / 2026']));
		self::assertSame(['Hermiq', 'Knowledge bases', 'Knowledge base'], KnowledgeBaseService::folderPathOf(knowledgeBase: ['name' => '..']));
	}//end testTheFolderPathIsCleanedAndDefaultsUnderTheName()
}//end class
