<?php

/**
 * Hermiq AttachmentTextReader: the text fallback and its notices.
 *
 * Drives the real reader; Files, the translator and the text source are doubles
 * (chat-attachments-and-images task 6).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Chat
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\Chat\AttachmentTextReader;
use OCA\Hermiq\Service\Chat\AttachmentTextSource;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the text fallback.
 */
class AttachmentTextReaderTest extends TestCase {

	/**
	 * The uid each Files read was made as.
	 *
	 * @var list<string>
	 */
	public array $readAs = [];

	/**
	 * The (fileId, uid) pairs the text source was asked for.
	 *
	 * @var list<array{0: int, 1: string}>
	 */
	public array $extracted = [];

	/**
	 * A text source that answers like OpenRegister's facade would.
	 *
	 * @param bool        $available Whether an extractor is configured.
	 * @param string|null $text      The text it returns.
	 *
	 * @return AttachmentTextSource The source.
	 */
	private function source(bool $available, ?string $text): AttachmentTextSource {
		$test = $this;
		return new class($test, $available, $text) implements AttachmentTextSource {
			/**
			 * Constructor.
			 *
			 * @param AttachmentTextReaderTest $test      The test, to record calls on.
			 * @param bool                     $available Whether available.
			 * @param string|null              $text      The text.
			 */
			public function __construct(
				private AttachmentTextReaderTest $test,
				private bool $available,
				private ?string $text,
			) {
			}

			/**
			 * Whether available.
			 *
			 * @return bool
			 */
			public function isAvailable(): bool {
				return $this->available;
			}

			/**
			 * The text, recording who asked.
			 *
			 * @param int    $fileId The file id.
			 * @param string $userId The uid.
			 *
			 * @return string|null
			 */
			public function extractText(int $fileId, string $userId): ?string {
				$this->test->extracted[] = [$fileId, $userId];
				return $this->text;
			}
		};
	}//end source()

	/**
	 * The reader over Files holding one file with the given content.
	 *
	 * @param AttachmentTextSource|null $source  The text source, null when absent.
	 * @param string                    $content The bytes of every file.
	 *
	 * @return AttachmentTextReader The reader.
	 */
	private function reader(?AttachmentTextSource $source, string $content = ''): AttachmentTextReader {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getContent')->willReturn($content);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$file]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($folder): Folder {
				$this->readAs[] = $uid;
				return $folder;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		return new AttachmentTextReader(rootFolder: $root, l10n: $l10n, logger: new NullLogger(), textSource: $source);
	}//end reader()

	/**
	 * A PDF a model cannot read is sent as its text, read as the speaker, and the person is told.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#scenario-a-pdf-is-read-as-text-by-a-model-without-pdf-support
	 */
	public function testAPdfIsSentAsItsTextAndThePersonIsTold(): void {
		$result = $this->reader($this->source(true, 'Totale kosten: EUR 412.000'))->read(
			attachments: [['fileId' => 31, 'name' => 'jaarverslag-2025.pdf', 'mimeType' => 'application/pdf']],
			speaker: 'controller'
		);

		$this->assertStringContainsString('jaarverslag-2025.pdf', $result['text']);
		$this->assertStringContainsString('Totale kosten: EUR 412.000', $result['text']);
		$this->assertSame([[31, 'controller']], $this->extracted);
		$this->assertSame(
			['This model does not read PDFs directly. hermiq used the text of jaarverslag-2025.pdf instead.'],
			$result['notices']
		);
	}//end testAPdfIsSentAsItsTextAndThePersonIsTold()

	/**
	 * Without OpenRegister's text facade the PDF is left out, and the notice says so.
	 *
	 * @return void
	 */
	public function testWithoutTheFacadeThePdfIsLeftOutAndThePersonIsTold(): void {
		$attachment = [['fileId' => 31, 'name' => 'jaarverslag-2025.pdf', 'mimeType' => 'application/pdf']];

		foreach ([null, $this->source(false, 'never read')] as $source) {
			$result = $this->reader($source)->read(attachments: $attachment, speaker: 'controller');

			$this->assertSame('', $result['text']);
			$this->assertSame(['hermiq could not read the text of jaarverslag-2025.pdf. It was not sent.'], $result['notices']);
		}

		$this->assertSame([], $this->extracted, 'An unavailable source is never asked.');
	}//end testWithoutTheFacadeThePdfIsLeftOutAndThePersonIsTold()

	/**
	 * A PDF the source has no text for is left out, and the notice says so.
	 *
	 * @return void
	 */
	public function testAPdfWithoutTextIsLeftOut(): void {
		$result = $this->reader($this->source(true, null))->read(
			attachments: [['fileId' => 31, 'name' => 'scan.pdf', 'mimeType' => 'application/pdf']],
			speaker: 'controller'
		);

		$this->assertSame('', $result['text']);
		$this->assertSame(['hermiq could not read the text of scan.pdf. It was not sent.'], $result['notices']);
	}//end testAPdfWithoutTextIsLeftOut()

	/**
	 * An image a model cannot see is not sent, and not silently.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#scenario-an-image-is-not-silently-dropped
	 */
	public function testAnImageIsNotSilentlyDropped(): void {
		$result = $this->reader($this->source(true, 'never read'))->read(
			attachments: [['fileId' => 9, 'name' => 'plattegrond.png', 'mimeType' => 'image/png']],
			speaker: 'controller'
		);

		$this->assertSame('', $result['text']);
		$this->assertSame(['This model cannot see images. plattegrond.png was not sent.'], $result['notices']);
		$this->assertSame([], $this->extracted);
	}//end testAnImageIsNotSilentlyDropped()

	/**
	 * A text file is read as the speaker and capped like readFile, and the cut is told.
	 *
	 * @return void
	 */
	public function testATextFileIsReadAsTheSpeakerAndCapped(): void {
		$content = str_repeat('a', AttachmentTextReader::MAX_TEXT_BYTES + 50);

		$result = $this->reader(null, $content)->read(
			attachments: [['fileId' => 4, 'name' => 'notulen.txt', 'mimeType' => 'text/plain']],
			speaker: 'griffier'
		);

		$this->assertSame(['griffier'], $this->readAs);
		$this->assertStringContainsString(str_repeat('a', AttachmentTextReader::MAX_TEXT_BYTES), $result['text']);
		$this->assertStringNotContainsString(str_repeat('a', AttachmentTextReader::MAX_TEXT_BYTES + 1), $result['text']);
		$this->assertSame(
			[
				'hermiq used the text of notulen.txt.',
				'The text of notulen.txt was cut to its first 20000 characters.',
			],
			$result['notices']
		);
	}//end testATextFileIsReadAsTheSpeakerAndCapped()

	/**
	 * No attachments, no text and no notice.
	 *
	 * @return void
	 */
	public function testNoAttachmentsGiveNothing(): void {
		$this->assertSame(['text' => '', 'notices' => []], $this->reader(null)->read(attachments: [], speaker: 'controller'));
	}//end testNoAttachmentsGiveNothing()

	/**
	 * An office document goes through the text source as the speaker, and the person is told.
	 *
	 * @return void
	 */
	public function testAnOfficeDocumentIsSentAsTheSourcesText(): void {
		$result = $this->reader($this->source(true, 'Besluit: akkoord'))->read(
			attachments: [
				[
					'fileId' => 12,
					'name' => 'raadsvoorstel.docx',
					'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				],
			],
			speaker: 'griffier'
		);

		$this->assertStringContainsString('Besluit: akkoord', $result['text']);
		$this->assertSame([[12, 'griffier']], $this->extracted);
		$this->assertSame(['hermiq used the text of raadsvoorstel.docx.'], $result['notices']);
	}//end testAnOfficeDocumentIsSentAsTheSourcesText()

	/**
	 * A type hermiq has no reader for is left out, and the person is told.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeIsLeftOutAndThePersonIsTold(): void {
		$result = $this->reader($this->source(true, 'never read'))->read(
			attachments: [['fileId' => 5, 'name' => 'archief.zip', 'mimeType' => 'application/zip']],
			speaker: 'griffier'
		);

		$this->assertSame('', $result['text']);
		$this->assertSame(['hermiq cannot read archief.zip. It was not sent.'], $result['notices']);
		$this->assertSame([], $this->extracted);
	}//end testAnUnknownTypeIsLeftOutAndThePersonIsTold()

	/**
	 * A text source that throws leaves the file out instead of failing the turn.
	 *
	 * @return void
	 */
	public function testAFailingSourceLeavesTheFileOut(): void {
		$failing = new class() implements AttachmentTextSource {
			/**
			 * Available.
			 *
			 * @return bool
			 */
			public function isAvailable(): bool {
				return true;
			}

			/**
			 * Always fails.
			 *
			 * @param int    $fileId The file id.
			 * @param string $userId The uid.
			 *
			 * @return string|null
			 *
			 * @throws RuntimeException Always.
			 */
			public function extractText(int $fileId, string $userId): ?string {
				throw new RuntimeException('extractor down');
			}
		};

		$result = $this->reader($failing)->read(
			attachments: [['fileId' => 31, 'name' => 'scan.pdf', 'mimeType' => 'application/pdf']],
			speaker: 'controller'
		);

		$this->assertSame('', $result['text']);
		$this->assertSame(['hermiq could not read the text of scan.pdf. It was not sent.'], $result['notices']);
	}//end testAFailingSourceLeavesTheFileOut()

	/**
	 * A text file without an id, not readable, missing or failing to read is left out, and the person is told.
	 *
	 * @return void
	 */
	public function testATextFileThatCannotBeReadIsLeftOut(): void {
		$unreadable = $this->createMock(File::class);
		$unreadable->method('isReadable')->willReturn(false);
		$failing = $this->createMock(File::class);
		$failing->method('isReadable')->willReturn(true);
		$failing->method('getContent')->willThrowException(new RuntimeException('storage gone'));

		$cases = [
			'no file id' => [0, [$unreadable]],
			'not readable' => [4, [$unreadable]],
			'not found' => [4, []],
			'read fails' => [4, [$failing]],
		];
		foreach ($cases as $label => [$fileId, $nodes]) {
			$folder = $this->createMock(Folder::class);
			$folder->method('getById')->willReturn($nodes);
			$root = $this->createMock(IRootFolder::class);
			$root->method('getUserFolder')->willReturn($folder);
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(
				static fn (string $text, array $params = []): string => vsprintf($text, $params)
			);
			$reader = new AttachmentTextReader(rootFolder: $root, l10n: $l10n, logger: new NullLogger());

			$result = $reader->read(
				attachments: [['fileId' => $fileId, 'name' => 'notulen.txt', 'mimeType' => 'text/plain']],
				speaker: 'griffier'
			);

			$this->assertSame('', $result['text'], $label);
			$this->assertSame(['hermiq could not read the text of notulen.txt. It was not sent.'], $result['notices'], $label);
		}
	}//end testATextFileThatCannotBeReadIsLeftOut()
}//end class
