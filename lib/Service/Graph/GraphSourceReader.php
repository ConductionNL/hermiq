<?php

/**
 * Hermiq GraphSourceReader.
 *
 * Reads the current text of the record a graph sourceRef points at, as the user who
 * holds the session: an object with OpenRegister RBAC, a file from that user's own
 * folder, a mail message through the mail read service, a conversation's turns only
 * for its owner or a listed participant. Anything the user cannot read, or that
 * cannot be resolved, is null, never an exception: extraction then writes nothing
 * from it, and context hydration falls back to the node's label.
 *
 * The caller holds the session as the acting user (ActingUserScope); this class
 * never switches identity itself.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Graph
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
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Live, user-scoped reads of graph source records.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */
class GraphSourceReader {

	/**
	 * The most characters one record yields.
	 */
	public const MAX_TEXT = 20000;

	/**
	 * The largest file read, in bytes.
	 */
	private const MAX_FILE_BYTES = 1048576;

	/**
	 * Where a conversation's turns live, newest shape first: session schema, turn schema, link field.
	 */
	private const TRANSCRIPTS = [
		['agentsession', 'agentsessionturn', 'sessionId'],
		['conversation', 'message', 'conversationId'],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister's object service.
	 * @param IRootFolder $rootFolder The root folder.
	 * @param MailReadService $mail The mail read service.
	 * @param LoggerInterface $logger The logger.
	 * @param ConversationParticipation $participation The roster rule.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IRootFolder $rootFolder,
		private readonly MailReadService $mail,
		private readonly LoggerInterface $logger,
		private readonly ConversationParticipation $participation = new ConversationParticipation(),
	) {
	}//end __construct()

	/**
	 * The record's current text and organisation, or null when the user cannot read it.
	 *
	 * @param string $sourceType object, file, mail or conversation.
	 * @param array<string, mixed> $ref The pointer.
	 * @param string $uid The acting user (who holds the session).
	 *
	 * @return array{text: string, organisation: string|null}|null
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#scenario-extraction-cannot-read-beyond-its-user
	 */
	public function read(string $sourceType, array $ref, string $uid): ?array {
		try {
			$read = match ($sourceType) {
				'object' => $this->object(ref: $ref),
				'file' => $this->file(ref: $ref, uid: $uid),
				'mail' => $this->mailMessage(ref: $ref, uid: $uid),
				'conversation' => $this->conversation(ref: $ref, uid: $uid),
				default => null,
			};
		} catch (Throwable $e) {
			$this->logger->debug('Hermiq graph: a source record could not be read', ['sourceType' => $sourceType, 'exception' => $e]);
			$read = null;
		}

		if ($read === null || trim($read['text']) === '') {
			return null;
		}

		$read['text'] = mb_substr($read['text'], 0, self::MAX_TEXT);

		return $read;

	}//end read()

	/**
	 * An object's current data, found with RBAC as the session user.
	 *
	 * @param array<string, mixed> $ref The pointer.
	 *
	 * @return array{text: string, organisation: string|null}|null
	 */
	private function object(array $ref): ?array {
		$object = $this->objectService->find(
			id: (string)($ref['uuid'] ?? ''),
			register: (string)($ref['register'] ?? ''),
			schema: (string)($ref['schema'] ?? '')
		);
		if ($object === null) {
			return null;
		}

		$data = $object->getObject();
		unset($data['@self']);

		return [
			'text' => (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'organisation' => $object->getOrganisation(),
		];

	}//end object()

	/**
	 * A small file's content, from the user's own folder.
	 *
	 * @param array<string, mixed> $ref The pointer.
	 * @param string $uid The acting user.
	 *
	 * @return array{text: string, organisation: string|null}|null
	 */
	private function file(array $ref, string $uid): ?array {
		$file = $this->rootFolder->getUserFolder($uid)->getFirstNodeById((int)($ref['fileId'] ?? 0));
		if (($file instanceof File) === false || $file->getSize() > self::MAX_FILE_BYTES) {
			return null;
		}

		return ['text' => $file->getContent(), 'organisation' => null];

	}//end file()

	/**
	 * A mail message's subject and plain body.
	 *
	 * @param array<string, mixed> $ref The pointer.
	 * @param string $uid The acting user.
	 *
	 * @return array{text: string, organisation: string|null}|null
	 */
	private function mailMessage(array $ref, string $uid): ?array {
		$message = $this->mail->readMessage(uid: $uid, arguments: ['id' => (int)($ref['messageId'] ?? 0)]);
		if (isset($message['error']) === true) {
			return null;
		}

		return ['text' => trim((string)($message['subject'] ?? '') . "\n" . (string)($message['body'] ?? '')), 'organisation' => null];

	}//end mailMessage()

	/**
	 * A conversation's turns, for its owner or a listed participant only.
	 *
	 * @param array<string, mixed> $ref The pointer.
	 * @param string $uid The acting user.
	 *
	 * @return array{text: string, organisation: string|null}|null
	 */
	private function conversation(array $ref, string $uid): ?array {
		$uuid = (string)($ref['conversationUuid'] ?? '');
		foreach (self::TRANSCRIPTS as [$sessionSchema, $turnSchema, $link]) {
			$session = null;
			if ($uuid !== '') {
				$session = $this->objectService->find(id: $uuid, register: 'hermiq', schema: $sessionSchema, _rbac: false);
			}

			if ($session === null) {
				continue;
			}

			if ($this->participation->mayTakeTurn(conversationData: $session->getObject(), userId: $uid) === false) {
				return null;
			}

			return ['text' => $this->transcript(schema: $turnSchema, link: $link, uuid: $uuid), 'organisation' => $session->getOrganisation()];
		}

		return null;

	}//end conversation()

	/**
	 * The turns of one conversation as "role: content" lines.
	 *
	 * @param string $schema The turn schema.
	 * @param string $link The field that names the conversation.
	 * @param string $uuid The conversation uuid.
	 *
	 * @return string The transcript.
	 */
	private function transcript(string $schema, string $link, string $uuid): string {
		$turns = $this->objectService
			->setRegister('hermiq')
			->setSchema($schema)
			->findAll(config: ['filters' => [$link => $uuid], 'limit' => 200], _rbac: false);

		$lines = [];
		foreach ($turns as $turn) {
			$data = $turn->getObject();
			$lines[] = (string)($data['role'] ?? '') . ': ' . (string)($data['content'] ?? '');
		}

		return implode("\n", $lines);

	}//end transcript()
}//end class
