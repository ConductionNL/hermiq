<?php

/**
 * Hermiq TalkApprovalBinding.
 *
 * Binds an `Approval` to the Talk message that carries its request, and
 * resolves a reacted-to message back to the approval it decides.
 *
 * The resolve is a FILTER QUERY on the top-level `talkMessageId` property. That
 * placement is load-bearing for the same measured reason as the chat bridge's
 * `talkRoomToken`: OpenRegister's dot-path filters on nested JSON return zero
 * rows SILENTLY, so a nested key would mean every reaction resolves nothing and
 * decides nothing — with unit tests green throughout, because in-memory doubles
 * return what a real filter would not.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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
 * @spec openspec/changes/talk-approval-reactions/tasks.md#1-bind-an-approval-to-the-message-that-carries-it
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves an approval from the Talk message carrying it.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-an-approval-request-posted-to-talk-records-where-it-landed
 */
class TalkApprovalBinding {

	/**
	 * OpenRegister register slug.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for approval objects.
	 *
	 * @var string
	 */
	private const APPROVAL_SCHEMA = 'approval';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read/write.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record which Talk message carries an approval request.
	 *
	 * Best-effort by contract: the inbox remains the authoritative surface, so
	 * a failure to record MUST NOT prevent the approval from being raised.
	 *
	 * 🔴 Takes the approval the caller already holds rather than re-reading it
	 * by uuid. It used to re-fetch, and a just-created approval is not reliably
	 * findable from inside the same request that created it — so the fetch
	 * missed, the method returned false through a branch that logged NOTHING,
	 * and the request was posted to Talk with no record of which message
	 * carried it. Every reaction on that message then resolved to no approval
	 * and was silently discarded. The entity in hand is both fresher and
	 * unmissable; a read that can fail is not worth reintroducing here.
	 *
	 * @param ObjectEntity $approval The approval to bind.
	 * @param string $roomToken The room the request was posted into.
	 * @param string $messageId The id of the posted message.
	 *
	 * @return bool True when the binding was persisted.
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-an-approval-request-posted-to-talk-records-where-it-landed
	 */
	public function bind(ObjectEntity $approval, string $roomToken, string $messageId): bool {
		$approvalUuid = (string)$approval->getUuid();

		if ($approvalUuid === '' || $roomToken === '' || $messageId === '') {
			$this->logger->warning(
				message: '[TalkApprovalBinding] Refusing to bind an approval on incomplete inputs; reactions on this message cannot decide it',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'approval' => $approvalUuid,
					'roomToken' => $roomToken,
					'messageId' => $messageId,
				]
			);
			return false;
		}

		try {
			// The saveObject call is PUT-semantic — carry every field forward.
			$data = $approval->getObject();
			$data['talkRoomToken'] = $roomToken;
			$data['talkMessageId'] = $messageId;

			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER_SLUG,
				schema: self::APPROVAL_SCHEMA,
				uuid: $approvalUuid
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TalkApprovalBinding] Could not bind the approval to its Talk message (the approval is unaffected)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'approval' => $approvalUuid,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end bind()

	/**
	 * Find the approval a reacted-to message decides.
	 *
	 * The register cannot express uniqueness on `talkMessageId`, so more than
	 * one row MAY match; resolution is deterministic (most recent wins) rather
	 * than assuming a single result.
	 *
	 * @param string $messageId The reacted-to message id.
	 *
	 * @return ObjectEntity|null The approval, or null when the message carries none.
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
	 */
	public function findByMessageId(string $messageId): ?ObjectEntity {
		if ($messageId === '') {
			return null;
		}

		try {
			$matches = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::APPROVAL_SCHEMA)
				->findAll(
					config: [
						'filters' => ['talkMessageId' => $messageId],
						'sort' => ['created' => 'DESC'],
						'limit' => 1,
					]
				);

			foreach ($matches as $match) {
				if ($match instanceof ObjectEntity) {
					return $match;
				}
			}

			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TalkApprovalBinding] Could not resolve the approval for a reacted-to message',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'messageId' => $messageId,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try

	}//end findByMessageId()

	/**
	 * Record how a decision arrived, for the audit record.
	 *
	 * @param string $approvalUuid The decided approval.
	 * @param string $via The surface — `inbox` or `reaction`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
	 */
	public function recordDecidedVia(string $approvalUuid, string $via): void {
		try {
			$approval = $this->objectService->find(
				id: $approvalUuid,
				register: self::REGISTER_SLUG,
				schema: self::APPROVAL_SCHEMA
			);

			if (($approval instanceof ObjectEntity) === false) {
				return;
			}

			$data = $approval->getObject();
			$data['decidedVia'] = $via;

			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER_SLUG,
				schema: self::APPROVAL_SCHEMA,
				uuid: $approvalUuid
			);
		} catch (Throwable $e) {
			// Provenance is an audit nicety; never fail a decision over it.
			$this->logger->warning(
				message: '[TalkApprovalBinding] Could not record decision provenance',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'approval' => $approvalUuid,
					'error' => $e->getMessage(),
				]
			);
		}//end try

	}//end recordDecidedVia()
}//end class
