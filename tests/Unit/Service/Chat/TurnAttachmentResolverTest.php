<?php

/**
 * TurnAttachmentResolver reads every attachment of a turn in the Files of the
 * person who sent it, never as anyone else, and answers a file that person
 * cannot read exactly like a missing one (chat-attachments-and-images, task 3).
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\Chat\AttachmentRefusedException;
use OCA\Hermiq\Service\Chat\TurnAttachmentResolver;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TurnAttachmentResolver.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
 */
class TurnAttachmentResolverTest extends TestCase {

	/**
	 * The uids whose Files were opened, in order.
	 *
	 * @var array<int, string>
	 */
	private array $opened = [];

	/**
	 * A file double.
	 *
	 * @param int    $id       The file id.
	 * @param string $name     The name.
	 * @param string $mime     The mime type.
	 * @param int    $size     The size in bytes.
	 * @param bool   $readable Whether its owner-side permissions allow reading.
	 *
	 * @return File
	 */
	private function file(int $id, string $name, string $mime = 'application/pdf', int $size = 184233, bool $readable = true): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getMimetype')->willReturn($mime);
		$file->method('getSize')->willReturn($size);
		$file->method('isReadable')->willReturn($readable);
		return $file;
	}//end file()

	/**
	 * The resolver over a set of people's Files.
	 *
	 * @param array<string, array<int|string, \OCP\Files\Node>> $filesByUser Per uid: nodes by id and by path.
	 *
	 * @return TurnAttachmentResolver
	 */
	private function resolver(array $filesByUser): TurnAttachmentResolver {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($filesByUser): Folder {
				$this->opened[] = $uid;
				$nodes = ($filesByUser[$uid] ?? []);
				$folder = $this->createMock(Folder::class);
				$folder->method('getById')->willReturnCallback(
					static function (int $id) use ($nodes): array {
						if (isset($nodes[$id]) === true) {
							return [$nodes[$id]];
						}

						return [];
					}
				);
				$folder->method('get')->willReturnCallback(
					static function (string $path) use ($nodes): \OCP\Files\Node {
						if (isset($nodes[$path]) === true) {
							return $nodes[$path];
						}

						throw new NotFoundException($path);
					}
				);
				return $folder;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		return new TurnAttachmentResolver($root, $l10n);
	}//end resolver()

	/**
	 * A file the speaker can read becomes a reference with its name, type, size
	 * and origin, and nothing else: no bytes and no text on the turn.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function testAReadableFileBecomesAReference(): void {
		$resolver = $this->resolver(['anne' => [48213 => $this->file(48213, 'offerte-dakrenovatie-2026.pdf')]]);

		$resolved = $resolver->resolve(requested: [['fileId' => 48213, 'origin' => 'files', 'name' => 'ignored.exe']], speaker: 'anne');

		$this->assertSame(
			[['fileId' => 48213, 'name' => 'offerte-dakrenovatie-2026.pdf', 'mimeType' => 'application/pdf', 'size' => 184233, 'origin' => 'files']],
			$resolved,
			'The name, type and size come from Files, never from the request.'
		);
		$this->assertSame(['anne'], $this->opened);

	}//end testAReadableFileBecomesAReference()

	/**
	 * A participant naming a file id only a colleague can read is refused with the
	 * sentence a missing file gets, and only the participant's own Files are opened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#scenario-a-participant-cannot-attach-a-colleagues-private-file-by-id
	 */
	public function testAColleaguesPrivateFileIsNotAvailable(): void {
		$resolver = $this->resolver(['anne' => [48213 => $this->file(48213, 'prive.pdf')], 'bram' => []]);

		try {
			$resolver->resolve(requested: [['fileId' => 48213]], speaker: 'bram');
			$this->fail('Bram attached a file only Anne can read.');
		} catch (AttachmentRefusedException $refusal) {
			$this->assertSame('This file is not available to you', $refusal->getMessage());
		}

		$this->assertSame(['bram'], $this->opened, 'Only the speaker\'s Files are ever opened.');

		$missing = $this->resolver(['bram' => []]);
		try {
			$missing->resolve(requested: [['fileId' => 99999]], speaker: 'bram');
			$this->fail('A missing file was accepted.');
		} catch (AttachmentRefusedException $refusal) {
			$this->assertSame('This file is not available to you', $refusal->getMessage(), 'Missing and foreign read the same, so ids cannot be probed.');
		}

	}//end testAColleaguesPrivateFileIsNotAvailable()

	/**
	 * A file shared to the speaker without read permission, a folder and a
	 * malformed id are all refused the same way.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function testUnreadableFoldersAndBadIdsAreRefused(): void {
		$folder = $this->createMock(Folder::class);
		$resolver = $this->resolver(
			['anne' => [7 => $this->file(7, 'zicht.pdf', readable: false), 8 => $folder]]
		);

		foreach ([[['fileId' => 7]], [['fileId' => 8]], [['fileId' => 'abc']], [['fileId' => -3]], [['name' => 'only-a-name.pdf']], ['not-an-entry']] as $requested) {
			try {
				$resolver->resolve(requested: $requested, speaker: 'anne');
				$this->fail('Accepted ' . json_encode($requested));
			} catch (AttachmentRefusedException $refusal) {
				$this->assertSame('This file is not available to you', $refusal->getMessage());
			}
		}

	}//end testUnreadableFoldersAndBadIdsAreRefused()

	/**
	 * The companion sends what the upload route answered ({ path, name }); the
	 * path is looked up in the speaker's own Files, and marked as an upload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function testACompanionUploadIsFoundByItsPath(): void {
		$file = $this->file(501, 'foto-dak.jpg', 'image/jpeg', 2048);
		$resolver = $this->resolver(['anne' => ['Hermiq/Attachments/2026-10/foto-dak.jpg' => $file]]);

		$resolved = $resolver->resolve(
			requested: [['path' => '/Hermiq/Attachments/2026-10/foto-dak.jpg', 'name' => 'foto-dak.jpg']],
			speaker: 'anne'
		);

		$this->assertSame(
			[['fileId' => 501, 'name' => 'foto-dak.jpg', 'mimeType' => 'image/jpeg', 'size' => 2048, 'origin' => 'upload']],
			$resolved
		);

	}//end testACompanionUploadIsFoundByItsPath()

	/**
	 * No attachments resolve to none without opening anyone's Files; more than the
	 * cap is refused before any lookup; the same file twice is kept once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function testEmptyCapAndDuplicates(): void {
		$resolver = $this->resolver(['anne' => [5 => $this->file(5, 'a.pdf')]]);
		$this->assertSame([], $resolver->resolve(requested: [], speaker: 'anne'));
		$this->assertSame([], $this->opened);

		$this->assertCount(1, $resolver->resolve(requested: [['fileId' => 5], ['fileId' => '5']], speaker: 'anne'));

		$this->opened = [];
		try {
			$resolver->resolve(requested: array_fill(0, TurnAttachmentResolver::MAX_ATTACHMENTS + 1, ['fileId' => 5]), speaker: 'anne');
			$this->fail('More than the cap was accepted.');
		} catch (AttachmentRefusedException $refusal) {
			$this->assertStringContainsString((string)TurnAttachmentResolver::MAX_ATTACHMENTS, $refusal->getMessage());
		}

		$this->assertSame([], $this->opened);

	}//end testEmptyCapAndDuplicates()

	/**
	 * A redaction refusal on an attached file becomes a refusal a person reads,
	 * naming the file; a refusal about another document is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#scenario-a-feature-that-requires-redaction-refuses-an-unredacted-attachment
	 */
	public function testARedactionRefusalNamesTheFile(): void {
		$resolver = $this->resolver([]);
		$attached = [['fileId' => 4711, 'name' => 'bezwaarschrift-2026-0412.pdf', 'mimeType' => 'application/pdf', 'size' => 10, 'origin' => 'files']];
		$cause = new RedactionRequiredException(featureSlug: 'chat-companion', documentReference: '4711', reason: "the recorded redaction state is 'none'");

		$refusal = $resolver->redactionRefusal(refusal: $cause, attachments: $attached);

		$this->assertInstanceOf(AttachmentRefusedException::class, $refusal);
		$this->assertStringContainsString('bezwaarschrift-2026-0412.pdf', $refusal->getMessage());
		$this->assertStringContainsString('redact', $refusal->getMessage());
		$this->assertSame(422, $refusal->getCode());
		$this->assertSame($cause, $refusal->getPrevious());

		$other = new RedactionRequiredException(featureSlug: 'chat-companion', documentReference: '9', reason: 'x');
		$this->assertNull($resolver->redactionRefusal(refusal: $other, attachments: $attached));

	}//end testARedactionRefusalNamesTheFile()
}//end class
