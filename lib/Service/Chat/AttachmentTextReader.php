<?php

/**
 * Hermiq AttachmentTextReader.
 *
 * The fallback for the attachments a model cannot read natively (design D6). A
 * text file is read as text; a PDF or an office file is turned into text by the
 * AttachmentTextSource when one is wired; an image has no text fallback. Every
 * file gets a notice in plain language that names it and says what was done, so
 * nothing is dropped silently. Text is capped like the readFile tool.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
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

namespace OCA\Hermiq\Service\Chat;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the attachments a model cannot take natively as text, with a notice per file.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */
class AttachmentTextReader {

	/**
	 * The most text one attachment adds to the turn, the readFile tool's budget.
	 *
	 * @var int
	 */
	public const MAX_TEXT_BYTES = 20000;

	/**
	 * Types read straight from Files as text, besides every `text/*` type.
	 *
	 * @var list<string>
	 */
	private const TEXT_TYPES = ['application/json', 'application/xml', 'application/x-yaml'];

	/**
	 * Office types the text source turns into text.
	 *
	 * @var list<string>
	 */
	private const OFFICE_PREFIXES = [
		'application/vnd.openxmlformats-officedocument.',
		'application/vnd.oasis.opendocument.',
		'application/msword',
		'application/vnd.ms-excel',
		'application/vnd.ms-powerpoint',
	];

	/**
	 * Constructor.
	 *
	 * @param IRootFolder               $rootFolder Reads a text file as the speaker.
	 * @param IL10N                     $l10n       The notices in the person's language.
	 * @param LoggerInterface           $logger     Logger.
	 * @param AttachmentTextSource|null $textSource Extracts a PDF's or office file's text;
	 *                                              null while OpenRegister offers no public
	 *                                              facade, and such a file is left out.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ?AttachmentTextSource $textSource = null,
	) {
	}//end __construct()

	/**
	 * The text to add to the turn and the notices for the answer.
	 *
	 * @param array<int, array<string, mixed>> $attachments The attachments not sent natively.
	 * @param string                           $speaker     The uid they were resolved for.
	 *
	 * @return array{text: string, notices: list<string>} The text block and the notices.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function read(array $attachments, string $speaker): array {
		$text = '';
		$notices = [];

		foreach ($attachments as $attachment) {
			$name = (string)($attachment['name'] ?? '');
			$result = $this->readOne(attachment: $attachment, name: $name, speaker: $speaker);
			array_push($notices, ...$result['notices']);
			if ($result['text'] !== null) {
				$text .= "\n\n--- " . $this->l10n->t('Attached file %s', [$name]) . " ---\n" . $result['text']
					. "\n--- " . $this->l10n->t('End of %s', [$name]) . ' ---';
			}
		}

		return ['text' => $text, 'notices' => $notices];
	}//end read()

	/**
	 * The text and notices for one attachment.
	 *
	 * @param array<string, mixed> $attachment The attachment.
	 * @param string               $name       Its file name.
	 * @param string               $speaker    The uid.
	 *
	 * @return array{text: string|null, notices: list<string>} Its text, or null when left out.
	 */
	private function readOne(array $attachment, string $name, string $speaker): array {
		$mimeType = strtolower((string)($attachment['mimeType'] ?? ''));
		$fileId = (int)($attachment['fileId'] ?? 0);

		if (str_starts_with($mimeType, 'image/') === true) {
			return ['text' => null, 'notices' => [$this->l10n->t('This model cannot see images. %s was not sent.', [$name])]];
		}

		if (str_starts_with($mimeType, 'text/') === true || in_array($mimeType, self::TEXT_TYPES, true) === true) {
			$used = $this->l10n->t('hermiq used the text of %s.', [$name]);
			return $this->capped(text: $this->readFromFiles(fileId: $fileId, speaker: $speaker), name: $name, used: $used);
		}

		if ($mimeType === 'application/pdf') {
			$used = $this->l10n->t('This model does not read PDFs directly. hermiq used the text of %s instead.', [$name]);
			return $this->capped(text: $this->extract(fileId: $fileId, speaker: $speaker), name: $name, used: $used);
		}

		if ($this->isOffice(mimeType: $mimeType) === true) {
			$used = $this->l10n->t('hermiq used the text of %s.', [$name]);
			return $this->capped(text: $this->extract(fileId: $fileId, speaker: $speaker), name: $name, used: $used);
		}

		return ['text' => null, 'notices' => [$this->l10n->t('hermiq cannot read %s. It was not sent.', [$name])]];
	}//end readOne()

	/**
	 * Cap the text and word the notices; no text means the file was left out.
	 *
	 * @param string|null $text The file's text.
	 * @param string      $name The file name.
	 * @param string      $used The notice for text that was used.
	 *
	 * @return array{text: string|null, notices: list<string>} The capped text and notices.
	 */
	private function capped(?string $text, string $name, string $used): array {
		if ($text === null || trim($text) === '') {
			return ['text' => null, 'notices' => [$this->l10n->t('hermiq could not read the text of %s. It was not sent.', [$name])]];
		}

		if (mb_strlen($text) <= self::MAX_TEXT_BYTES) {
			return ['text' => $text, 'notices' => [$used]];
		}

		return [
			'text' => mb_substr($text, 0, self::MAX_TEXT_BYTES),
			'notices' => [
				$used,
				$this->l10n->t('The text of %1$s was cut to its first %2$d characters.', [$name, self::MAX_TEXT_BYTES]),
			],
		];
	}//end capped()

	/**
	 * Whether the type is an office document the text source reads.
	 *
	 * @param string $mimeType The type.
	 *
	 * @return bool True for an office type.
	 */
	private function isOffice(string $mimeType): bool {
		foreach (self::OFFICE_PREFIXES as $prefix) {
			if (str_starts_with($mimeType, $prefix) === true) {
				return true;
			}
		}

		return false;
	}//end isOffice()

	/**
	 * The text source's text, or null when none is wired or it has none.
	 *
	 * @param int    $fileId  The file id.
	 * @param string $speaker The uid.
	 *
	 * @return string|null The text.
	 */
	private function extract(int $fileId, string $speaker): ?string {
		if ($this->textSource === null || $this->textSource->isAvailable() === false) {
			return null;
		}

		try {
			return $this->textSource->extractText(fileId: $fileId, userId: $speaker);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[AttachmentTextReader] Text extraction failed; the attachment is left out',
				context: ['fileId' => $fileId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end extract()

	/**
	 * A text file's content read as the speaker, or null when it cannot be read now.
	 *
	 * @param int    $fileId  The file id.
	 * @param string $speaker The uid.
	 *
	 * @return string|null The content.
	 */
	private function readFromFiles(int $fileId, string $speaker): ?string {
		if ($fileId < 1 || $speaker === '') {
			return null;
		}

		try {
			$node = ($this->rootFolder->getUserFolder($speaker)->getById($fileId)[0] ?? null);
			if ($node instanceof File === false || $node->isReadable() === false) {
				return null;
			}

			return $node->getContent();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[AttachmentTextReader] A text attachment could not be read; it is left out',
				context: ['fileId' => $fileId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end readFromFiles()
}//end class
