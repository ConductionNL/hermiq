<?php

/**
 * Hermiq TurnAttachmentResolver.
 *
 * Reads the files a turn names in the Files of the person who sent the turn, and
 * never as anyone else: not as the session owner, not as the agent's acting
 * user. A file that person cannot read gets the same answer as a missing one, so
 * a turn cannot be used to find out which file ids exist. What comes back is a
 * reference only (fileId, name, mimeType, size, origin), taken from Files and
 * not from the request (chat-attachments-and-images, D3).
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use Throwable;

/**
 * Resolves a turn's attachments as its speaker.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
 */
class TurnAttachmentResolver {

	/**
	 * The most files one turn may carry.
	 *
	 * @var int
	 */
	public const MAX_ATTACHMENTS = 10;

	/**
	 * Where an attachment came from.
	 *
	 * @var array<int, string>
	 */
	public const ORIGINS = ['upload', 'files', 'generated'];

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder Every person's Files.
	 * @param IL10N       $l10n       Refusals in the person's language.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Resolve what a turn names into references, as the speaker.
	 *
	 * Each entry names a file by `fileId` (the Chat page's Files picker) or by
	 * `path` (what the upload route answered, which the companion sends back).
	 * Anything else in the entry, a name or a type, is ignored: those come from
	 * Files.
	 *
	 * @param array<int|string, mixed> $requested The entries the request sent.
	 * @param string                   $speaker   The uid of the person who sent the turn.
	 *
	 * @return array<int, array{fileId: int, name: string, mimeType: string, size: int, origin: string}>
	 *
	 * @throws AttachmentRefusedException When an entry names nothing the speaker can read, or there are too many.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function resolve(array $requested, string $speaker): array {
		if ($requested === []) {
			return [];
		}

		if (count($requested) > self::MAX_ATTACHMENTS) {
			throw new AttachmentRefusedException(
				$this->l10n->t('You can attach at most %s files to one message.', [self::MAX_ATTACHMENTS]),
				400
			);
		}

		$userFolder = $this->userFolder(speaker: $speaker);

		$resolved = [];
		foreach ($requested as $entry) {
			$reference = $this->resolveOne(userFolder: $userFolder, entry: $entry);
			// The same file twice is one attachment.
			$resolved[$reference['fileId']] = $reference;
		}

		return array_values($resolved);
	}//end resolve()

	/**
	 * Turn a redaction refusal on one of this turn's files into a refusal a person
	 * reads, naming the file; null when the refusal is about another document.
	 *
	 * @param RedactionRequiredException $refusal     The refusal from the feature's checks.
	 * @param array<int, array<string, mixed>> $attachments The turn's resolved attachments.
	 *
	 * @return AttachmentRefusedException|null
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#scenario-a-feature-that-requires-redaction-refuses-an-unredacted-attachment
	 */
	public function redactionRefusal(RedactionRequiredException $refusal, array $attachments): ?AttachmentRefusedException {
		foreach ($attachments as $attachment) {
			if ((string)($attachment['fileId'] ?? '') !== $refusal->documentReference) {
				continue;
			}

			return new AttachmentRefusedException(
				$this->l10n->t(
					'%s has not been redacted, and this assistant only reads redacted documents.',
					[(string)($attachment['name'] ?? '')]
				),
				422,
				$refusal
			);
		}

		return null;
	}//end redactionRefusal()

	/**
	 * The speaker's Files, or the not-available refusal when they have none.
	 *
	 * @param string $speaker The uid of the person who sent the turn.
	 *
	 * @return Folder
	 *
	 * @throws AttachmentRefusedException When the speaker has no Files.
	 */
	private function userFolder(string $speaker): Folder {
		try {
			return $this->rootFolder->getUserFolder($speaker);
		} catch (Throwable) {
			throw $this->notAvailable();
		}
	}//end userFolder()

	/**
	 * Resolve one entry in the speaker's Files.
	 *
	 * @param Folder $userFolder The speaker's Files.
	 * @param mixed  $entry      One entry from the request.
	 *
	 * @return array{fileId: int, name: string, mimeType: string, size: int, origin: string}
	 *
	 * @throws AttachmentRefusedException When the entry names nothing the speaker can read.
	 */
	private function resolveOne(Folder $userFolder, mixed $entry): array {
		if (is_array($entry) === false) {
			throw $this->notAvailable();
		}

		$byPath = isset($entry['fileId']) === false && is_string($entry['path'] ?? null) === true;
		$node = $this->findNode(userFolder: $userFolder, entry: $entry);

		if ($node instanceof File === false || $node->isReadable() === false) {
			throw $this->notAvailable();
		}

		$origin = (string)($entry['origin'] ?? '');
		if (in_array($origin, self::ORIGINS, true) === false) {
			$origin = 'files';
			if ($byPath === true) {
				$origin = 'upload';
			}
		}

		return [
			'fileId' => (int)$node->getId(),
			'name' => $node->getName(),
			'mimeType' => $node->getMimetype(),
			'size' => (int)$node->getSize(),
			'origin' => $origin,
		];
	}//end resolveOne()

	/**
	 * Look the entry up by file id, else by path; null when neither finds a node.
	 *
	 * @param Folder               $userFolder The speaker's Files.
	 * @param array<string, mixed> $entry      One entry from the request.
	 *
	 * @return Node|null
	 */
	private function findNode(Folder $userFolder, array $entry): ?Node {
		try {
			if (isset($entry['fileId']) === true) {
				$fileId = filter_var($entry['fileId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
				if ($fileId === false) {
					return null;
				}

				return ($userFolder->getById($fileId)[0] ?? null);
			}

			if (is_string($entry['path'] ?? null) === true && trim($entry['path']) !== '') {
				return $userFolder->get(ltrim($entry['path'], '/'));
			}
		} catch (Throwable) {
			return null;
		}

		return null;
	}//end findNode()

	/**
	 * The one answer for a file that is missing and for one the speaker cannot read.
	 *
	 * @return AttachmentRefusedException
	 */
	private function notAvailable(): AttachmentRefusedException {
		return new AttachmentRefusedException($this->l10n->t('This file is not available to you'), 400);
	}//end notAvailable()
}//end class
