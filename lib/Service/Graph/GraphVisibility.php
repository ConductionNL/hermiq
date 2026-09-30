<?php

/**
 * Hermiq GraphVisibility.
 *
 * Decides whether the acting user may see a knowledge-graph node or relation, from
 * the record its sourceRef points at, through that record's own authorization:
 *
 * - object: OpenRegister finds it WITH RBAC while the acting user holds the session;
 * - file: the acting user's own folder holds the file id;
 * - mail: the mail read service's account-scoped lookup finds the message;
 * - conversation: the acting user owns the session or is on its roster.
 *
 * The graph's own objects are never the authority: a node's label can say what a
 * protected record is about. Anything that cannot be resolved (record gone, service
 * down, unknown user, incomplete pointer) is not visible, logged at debug, and never
 * thrown to the caller.
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
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Record-derived visibility for graph entries.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
 */
class GraphVisibility {

	/**
	 * The schemas a conversation reference may live in, newest first.
	 */
	private const SESSION_SCHEMAS = ['agentsession', 'conversation'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister's object service.
	 * @param IRootFolder $rootFolder The root folder.
	 * @param MailReadService $mail The mail read service.
	 * @param ActingUserScope $actingUser Impersonate-and-restore.
	 * @param LoggerInterface $logger The logger.
	 * @param ConversationParticipation $participation The roster rule.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IRootFolder $rootFolder,
		private readonly MailReadService $mail,
		private readonly ActingUserScope $actingUser,
		private readonly LoggerInterface $logger,
		private readonly ConversationParticipation $participation = new ConversationParticipation(),
	) {
	}//end __construct()

	/**
	 * Whether the acting user can currently read the record a sourceRef points at.
	 *
	 * @param string $sourceType object, file, mail or conversation.
	 * @param array<string, mixed> $sourceRef The typed pointer.
	 * @param string $uid The acting user id.
	 *
	 * @return bool True only when the record is readable now.
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-an-edge-is-visible-only-when-both-endpoints-are
	 */
	public function isVisible(string $sourceType, array $sourceRef, string $uid): bool {
		if ($uid === '') {
			return false;
		}

		try {
			return match ($sourceType) {
				'object' => $this->objectVisible(ref: $sourceRef, uid: $uid),
				'file' => $this->fileVisible(ref: $sourceRef, uid: $uid),
				'mail' => $this->mail->canRead($uid, (int)($sourceRef['messageId'] ?? 0)),
				'conversation' => $this->conversationVisible(ref: $sourceRef, uid: $uid),
				default => false,
			};
		} catch (Throwable $e) {
			$this->logger->debug(
				'Hermiq graph: a source record could not be resolved; treated as not visible',
				['sourceType' => $sourceType, 'exception' => $e]
			);

			return false;
		}

	}//end isVisible()

	/**
	 * An OpenRegister object, found with RBAC while the acting user holds the session.
	 *
	 * @param array<string, mixed> $ref The pointer.
	 * @param string $uid The acting user id.
	 *
	 * @return bool Whether it is readable.
	 */
	private function objectVisible(array $ref, string $uid): bool {
		$register = (string)($ref['register'] ?? '');
		$schema = (string)($ref['schema'] ?? '');
		$uuid = (string)($ref['uuid'] ?? '');
		if ($register === '' || $schema === '' || $uuid === '') {
			return false;
		}

		return (bool)$this->actingUser->run(
			uid: $uid,
			work: fn (): bool => $this->objectService->find(id: $uuid, register: $register, schema: $schema) !== null
		);

	}//end objectVisible()

	/**
	 * A file, found by id in the acting user's own folder.
	 *
	 * @param array<string, mixed> $ref The pointer.
	 * @param string $uid The acting user id.
	 *
	 * @return bool Whether it is readable.
	 */
	private function fileVisible(array $ref, string $uid): bool {
		$fileId = (int)($ref['fileId'] ?? 0);
		if ($fileId <= 0) {
			return false;
		}

		return $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId) !== null;

	}//end fileVisible()

	/**
	 * A conversation: the acting user owns it or is on its roster.
	 *
	 * @param array<string, mixed> $ref The pointer.
	 * @param string $uid The acting user id.
	 *
	 * @return bool Whether it is readable.
	 */
	private function conversationVisible(array $ref, string $uid): bool {
		$uuid = (string)($ref['conversationUuid'] ?? '');
		if ($uuid === '') {
			return false;
		}

		foreach (self::SESSION_SCHEMAS as $schema) {
			$session = $this->objectService->find(id: $uuid, register: 'hermiq', schema: $schema, _rbac: false);
			if ($session !== null) {
				return $this->participation->mayTakeTurn(conversationData: $session->getObject(), userId: $uid);
			}
		}

		return false;

	}//end conversationVisible()
}//end class
