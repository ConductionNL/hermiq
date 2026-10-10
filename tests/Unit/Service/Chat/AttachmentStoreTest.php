<?php

/**
 * AttachmentStore writes an upload into the person's own Files under
 * Hermiq/Attachments/<yyyy-mm>/ and refuses a refused type or a file over the
 * cap before anything is written (chat-attachments-and-images, task 1).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\Chat\AttachmentRefusedException;
use OCA\Hermiq\Service\Chat\AttachmentStore;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AttachmentStore.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
 */
class AttachmentStoreTest extends TestCase {

	/**
	 * A temporary upload on disk.
	 *
	 * @var string
	 */
	private string $tmp = '';

	/**
	 * Write a small upload.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tmp = (string)tempnam(sys_get_temp_dir(), 'hq-att');
		file_put_contents($this->tmp, '%PDF-1.4 offerte');
	}//end setUp()

	/**
	 * Remove the upload.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->tmp !== '' && file_exists($this->tmp) === true) {
			unlink($this->tmp);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The store over a user folder double.
	 *
	 * @param Folder $userFolder The person's Files.
	 * @param int    $capMb      The admin's size cap in MB.
	 *
	 * @return AttachmentStore
	 */
	private function store(Folder $userFolder, int $capMb = 20): AttachmentStore {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('anna')->willReturn($userFolder);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => ($key === AttachmentStore::CAP_KEY ? $capMb : $default)
		);

		$mime = $this->createMock(IMimeTypeDetector::class);
		$mime->method('detectPath')->willReturnCallback(
			static fn (string $path): string => match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
				'pdf' => 'application/pdf',
				'png' => 'image/png',
				'md' => 'text/markdown',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'mp4' => 'video/mp4',
				default => 'application/octet-stream',
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		return new AttachmentStore(rootFolder: $root, appConfig: $config, mimeTypeDetector: $mime, l10n: $l10n);
	}//end store()

	/**
	 * A folder chain Hermiq/Attachments/<month> that does not exist yet.
	 *
	 * @param Folder $month The month folder that will be created.
	 *
	 * @return Folder The user folder.
	 */
	private function userFolderCreating(Folder $month): Folder {
		$attachments = $this->createMock(Folder::class);
		$attachments->method('nodeExists')->willReturn(false);
		$attachments->expects($this->once())->method('newFolder')->with(gmdate('Y-m'))->willReturn($month);

		$hermiq = $this->createMock(Folder::class);
		$hermiq->method('nodeExists')->willReturn(false);
		$hermiq->expects($this->once())->method('newFolder')->with('Attachments')->willReturn($attachments);

		$user = $this->createMock(Folder::class);
		$user->method('nodeExists')->willReturn(false);
		$user->expects($this->once())->method('newFolder')->with('Hermiq')->willReturn($hermiq);
		$user->method('getRelativePath')->willReturnCallback(
			static fn (string $path): string => substr($path, strlen('/anna/files'))
		);

		return $user;
	}//end userFolderCreating()

	/**
	 * A PDF lands in the person's Files and the answer carries the contract's fields.
	 *
	 * @return void
	 */
	public function testAPdfIsWrittenIntoTheirFiles(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(48213);
		$file->method('getName')->willReturn('offerte-dakrenovatie-2026.pdf');
		$file->method('getPath')->willReturn('/anna/files/Hermiq/Attachments/' . gmdate('Y-m') . '/offerte-dakrenovatie-2026.pdf');

		$month = $this->createMock(Folder::class);
		$month->method('getNonExistingName')->with('offerte-dakrenovatie-2026.pdf')->willReturn('offerte-dakrenovatie-2026.pdf');
		$month->expects($this->once())->method('newFile')
			->with('offerte-dakrenovatie-2026.pdf', '%PDF-1.4 offerte')
			->willReturn($file);

		$result = $this->store($this->userFolderCreating($month))->store(
			uid: 'anna',
			upload: ['name' => 'offerte-dakrenovatie-2026.pdf', 'tmp_name' => $this->tmp, 'size' => 16, 'error' => UPLOAD_ERR_OK]
		);

		self::assertSame(
			[
				'path' => '/Hermiq/Attachments/' . gmdate('Y-m') . '/offerte-dakrenovatie-2026.pdf',
				'name' => 'offerte-dakrenovatie-2026.pdf',
				'fileId' => 48213,
				'mimeType' => 'application/pdf',
				'size' => 16,
			],
			$result
		);
	}//end testAPdfIsWrittenIntoTheirFiles()

	/**
	 * A name already taken gets Nextcloud's conflict suffix, and a path in the name is dropped.
	 *
	 * @return void
	 */
	public function testATakenNameGetsTheConflictSuffix(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(7);
		$file->method('getName')->willReturn('notes (2).md');
		$file->method('getPath')->willReturn('/anna/files/Hermiq/Attachments/' . gmdate('Y-m') . '/notes (2).md');

		$month = $this->createMock(Folder::class);
		$month->expects($this->once())->method('getNonExistingName')->with('notes.md')->willReturn('notes (2).md');
		$month->expects($this->once())->method('newFile')->with('notes (2).md')->willReturn($file);

		$result = $this->store($this->userFolderCreating($month))->store(
			uid: 'anna',
			upload: ['name' => '../../secret/notes.md', 'tmp_name' => $this->tmp, 'size' => 16, 'error' => UPLOAD_ERR_OK]
		);

		self::assertSame('notes (2).md', $result['name']);
		self::assertSame('text/markdown', $result['mimeType']);
	}//end testATakenNameGetsTheConflictSuffix()

	/**
	 * Existing folders are reused, not created again.
	 *
	 * @return void
	 */
	public function testExistingFoldersAreReused(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(9);
		$file->method('getName')->willReturn('foto.png');
		$file->method('getPath')->willReturn('/anna/files/Hermiq/Attachments/' . gmdate('Y-m') . '/foto.png');

		$month = $this->createMock(Folder::class);
		$month->method('getNonExistingName')->willReturnArgument(0);
		$month->expects($this->once())->method('newFile')->willReturn($file);

		$attachments = $this->createMock(Folder::class);
		$attachments->method('nodeExists')->willReturn(true);
		$attachments->method('get')->with(gmdate('Y-m'))->willReturn($month);
		$attachments->expects($this->never())->method('newFolder');

		$hermiq = $this->createMock(Folder::class);
		$hermiq->method('nodeExists')->willReturn(true);
		$hermiq->method('get')->with('Attachments')->willReturn($attachments);

		$user = $this->createMock(Folder::class);
		$user->method('nodeExists')->willReturn(true);
		$user->method('get')->with('Hermiq')->willReturn($hermiq);
		$user->expects($this->never())->method('newFolder');
		$user->method('getRelativePath')->willReturn('/Hermiq/Attachments/' . gmdate('Y-m') . '/foto.png');

		$result = $this->store($user)->store(
			uid: 'anna',
			upload: ['name' => 'foto.png', 'tmp_name' => $this->tmp, 'size' => 16, 'error' => UPLOAD_ERR_OK]
		);

		self::assertSame(9, $result['fileId']);
	}//end testExistingFoldersAreReused()

	/**
	 * A file over the cap is refused with the cap in the message, and nothing is written.
	 *
	 * @return void
	 */
	public function testAFileOverTheCapIsRefusedAndNothingIsWritten(): void {
		$user = $this->createMock(Folder::class);
		$user->expects($this->never())->method('newFolder');
		$user->expects($this->never())->method('newFile');

		$this->expectException(AttachmentRefusedException::class);
		$this->expectExceptionMessage('This file is larger than 20 MB.');

		$this->store($user)->store(
			uid: 'anna',
			upload: ['name' => 'opname.pdf', 'tmp_name' => $this->tmp, 'size' => (35 * 1024 * 1024), 'error' => UPLOAD_ERR_OK]
		);
	}//end testAFileOverTheCapIsRefusedAndNothingIsWritten()

	/**
	 * The cap is the admin's setting.
	 *
	 * @return void
	 */
	public function testTheCapIsTheAdminsSetting(): void {
		$user = $this->createMock(Folder::class);
		$user->expects($this->never())->method('newFolder');

		$this->expectException(AttachmentRefusedException::class);
		$this->expectExceptionMessage('This file is larger than 1 MB.');

		$this->store($user, 1)->store(
			uid: 'anna',
			upload: ['name' => 'scan.pdf', 'tmp_name' => $this->tmp, 'size' => (2 * 1024 * 1024), 'error' => UPLOAD_ERR_OK]
		);
	}//end testTheCapIsTheAdminsSetting()

	/**
	 * A video is a refused type; nothing is written.
	 *
	 * @return void
	 */
	public function testARefusedTypeIsRefusedAndNothingIsWritten(): void {
		$user = $this->createMock(Folder::class);
		$user->expects($this->never())->method('newFolder');

		$this->expectException(AttachmentRefusedException::class);
		$this->expectExceptionMessage('Hermiq cannot read files of this type.');

		$this->store($user)->store(
			uid: 'anna',
			upload: ['name' => 'vergadering.mp4', 'tmp_name' => $this->tmp, 'size' => 16, 'error' => UPLOAD_ERR_OK]
		);
	}//end testARefusedTypeIsRefusedAndNothingIsWritten()

	/**
	 * Office documents are accepted (for the text fallback); unknown binaries are not.
	 *
	 * @return void
	 */
	public function testTheAllowedListCoversTextImagesPdfAndOffice(): void {
		self::assertTrue(AttachmentStore::isAllowedType('application/pdf'));
		self::assertTrue(AttachmentStore::isAllowedType('image/webp'));
		self::assertTrue(AttachmentStore::isAllowedType('text/csv'));
		self::assertTrue(AttachmentStore::isAllowedType('application/json'));
		self::assertTrue(AttachmentStore::isAllowedType('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
		self::assertTrue(AttachmentStore::isAllowedType('application/vnd.oasis.opendocument.text'));
		self::assertFalse(AttachmentStore::isAllowedType('image/svg+xml'));
		self::assertFalse(AttachmentStore::isAllowedType('application/octet-stream'));
		self::assertFalse(AttachmentStore::isAllowedType('video/mp4'));
	}//end testTheAllowedListCoversTextImagesPdfAndOffice()
}//end class
