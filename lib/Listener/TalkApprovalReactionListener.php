<?php

/**
 * Hermiq TalkApprovalReactionListener.
 *
 * Resolves a pending approval from a 👍/👎 reaction on the agent's message in
 * Talk, so a gated run can be released from a phone.
 *
 * 🔴 The load-bearing rule here is AUTHORIZATION, not plumbing. A reaction is a
 * public, one-click act available to every participant of a room. The approval
 * gate exists to require a specific person's judgement (EU AI Act Art. 14), so
 * if any bystander's 👍 released a gated run this listener would make Hermiq's
 * oversight mechanism weaker than not having it at all. The reactor is
 * therefore checked against the approval's resolved reviewer — using the SAME
 * `ApprovalService::isReviewer()` the inbox uses, never a second opinion — and
 * a reaction from anyone else is ignored.
 *
 * Unlike the chat bridge's turn, the decision runs INLINE: applying an approval
 * is a small object write, not a model call, so a background hop would add
 * latency and a failure mode to a governance action whose value is immediacy.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/talk-approval-reactions/tasks.md#2-reaction-listener
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Talk\TalkApprovalBinding;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides an approval from a Talk reaction.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
 */
class TalkApprovalReactionListener implements IEventListener {

	/**
	 * The emoji that approves.
	 *
	 * @var string
	 */
	private const APPROVE = '👍';

	/**
	 * The emoji that denies.
	 *
	 * @var string
	 */
	private const DENY = '👎';

	/**
	 * Constructor.
	 *
	 * @param TalkBridge $bridge Talk availability and room posting.
	 * @param TalkApprovalBinding $approvalBinding Resolves an approval from a message id.
	 * @param ApprovalService $approvalService Applies the decision — the same path the inbox uses.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly TalkBridge $bridge,
		private readonly TalkApprovalBinding $approvalBinding,
		private readonly ApprovalService $approvalService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a spreed reaction invocation.
	 *
	 * Never throws: this runs inside the reacting user's request, so an
	 * exception here would surface as a failed reaction for someone who did
	 * nothing wrong.
	 *
	 * @param Event $event The spreed BotInvokeEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
	 */
	public function handle(Event $event): void {
		try {
			$this->handleReaction(event: $event);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[TalkApprovalReactionListener] Reaction handling failed (the reactor is unaffected)',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Resolve, authorize and apply.
	 *
	 * @param Event $event The spreed BotInvokeEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-only-the-reviewer-may-decide-by-reaction
	 */
	private function handleReaction(Event $event): void {
		$payload = $this->readPayload(event: $event);
		if ($payload === null) {
			return;
		}

		$decision = $this->readDecision(payload: $payload);
		if ($decision === null) {
			return;
		}

		$approval = $this->approvalBinding->findByMessageId(messageId: $decision['messageId']);
		if (($approval instanceof ObjectEntity) === false) {
			return;
		}

		// 🔴 The check this whole listener rests on. Same question the inbox asks.
		if ($this->fromReviewer(approval: $approval, reactor: $decision['reactor']) === false) {
			return;
		}

		$this->decide(approval: $approval, decision: $decision);

	}//end handleReaction()

	/**
	 * Whether the reactor reviews this approval; a reaction from anyone else is logged and ignored.
	 *
	 * @param ObjectEntity $approval The approval the reaction targets.
	 * @param string       $reactor  The reacting user's bare uid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-only-the-reviewer-may-decide-by-reaction
	 */
	private function fromReviewer(ObjectEntity $approval, string $reactor): bool {
		if ($this->approvalService->isReviewer($approval, $reactor) === true) {
			return true;
		}

		$this->logger->info(
			message: '[TalkApprovalReactionListener] Reaction from a non-reviewer ignored',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'approval' => (string)$approval->getUuid(),
				'reactor' => $reactor,
			]
		);
		return false;

	}//end fromReviewer()

	/**
	 * Read the actionable parts of a reaction, or null when it is not one.
	 *
	 * Any emoji other than 👍/👎 yields null: an emoji is a lossy signal and
	 * this is a governance decision, so an unrecognised one is ignored rather
	 * than guessed at.
	 *
	 * @param array $payload The reaction invocation payload.
	 *
	 * @return array{messageId: string, reactor: string, emoji: string, undo: bool}|null
	 *                                                                                   The decision inputs, or null.
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
	 */
	private function readDecision(array $payload): ?array {
		$undo = ((string)($payload['type'] ?? '') === 'Undo');

		// 🔴 The two invocation types carry the SAME Like envelope at different
		// depths: a `Like` IS the envelope, while an `Undo` wraps the undone
		// Like in its `object`. Normalising to the envelope first is what makes
		// the two reads below correct for both.
		//
		// Reading `content` from the top level and the note id from
		// `object.object` — one field per shape — meant NEITHER type ever
		// produced a decision: a 👍 had no message id, an un-react had no
		// emoji, and both returned null. The whole reaction path was inert
		// while its unit tests passed on a payload no spreed version sends.
		$like = $payload;
		if ($undo === true) {
			$like = ($payload['object'] ?? []);
		}

		$emoji = trim((string)($like['content'] ?? ''));
		$messageId = (string)($like['object']['id'] ?? '');
		// The reactor is the top-level actor in both shapes — the nested Like's
		// actor is the message's author, not the person reacting to it.
		$reactor = $this->bareUid(actorId: (string)($payload['actor']['id'] ?? ''));

		if ($messageId === '' || $reactor === '') {
			return null;
		}

		if ($emoji !== self::APPROVE && $emoji !== self::DENY) {
			return null;
		}

		return [
			'messageId' => $messageId,
			'reactor' => $reactor,
			'emoji' => $emoji,
			'undo' => $undo,
		];

	}//end readDecision()

	/**
	 * Apply an authorized reaction, or explain why it changed nothing.
	 *
	 * @param ObjectEntity $approval The approval the reaction targets.
	 * @param array $decision The decision inputs from `readDecision()`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-decision-is-confirmed-in-the-room-and-is-not-reversible-by-un-reacting
	 */
	private function decide(ObjectEntity $approval, array $decision): void {
		$data = $approval->getObject();
		$roomToken = (string)($data['talkRoomToken'] ?? '');

		if ($decision['undo'] === true) {
			$this->bridge->postToRoom(
				roomToken: $roomToken,
				message: '↩️ Removing a reaction does not undo a decision — an approval is a governance record, not a toggle.'
			);
			return;
		}

		if ((string)($data['status'] ?? '') !== 'pending') {
			$this->bridge->postToRoom(
				roomToken: $roomToken,
				message: sprintf('ℹ️ That request was already %s — this reaction changed nothing.', (string)($data['status'] ?? 'decided'))
			);
			return;
		}

		$this->applyDecision(approval: $approval, decision: $decision);

	}//end decide()

	/**
	 * Apply an authorized decision and confirm it in the room.
	 *
	 * @param ObjectEntity $approval The pending approval.
	 * @param array        $decision The decision inputs from `readDecision()`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-decision-is-confirmed-in-the-room-and-is-not-reversible-by-un-reacting
	 */
	private function applyDecision(ObjectEntity $approval, array $decision): void {
		$data = $approval->getObject();
		$roomToken = (string)($data['talkRoomToken'] ?? '');
		$agentId = $this->confirmingAgent(data: $data);

		$this->bridge->postToRoom(
			roomToken: $roomToken,
			message: $this->recordDecision(approval: $approval, decision: $decision),
			agentId: $agentId
		);

	}//end applyDecision()

	/**
	 * The agent that confirms the decision: the one that asked, or null for the shared bot.
	 *
	 * Confirm under the same agent that asked, so the exchange reads as one
	 * conversation with one agent rather than two different speakers. With no agent
	 * on the approval, fall back to the shared bot rather than skipping the
	 * confirmation, which is the reviewer's only feedback that their reaction landed.
	 *
	 * @param array<string, mixed> $data The approval payload.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-decision-is-confirmed-in-the-room-and-is-not-reversible-by-un-reacting
	 */
	private function confirmingAgent(array $data): ?string {
		$agentId = (string)($data['agentId'] ?? '');
		if ($agentId === '') {
			return null;
		}

		return $agentId;

	}//end confirmingAgent()

	/**
	 * Approve or deny, record that it was decided by reaction, and answer the confirmation.
	 *
	 * @param ObjectEntity $approval The pending approval.
	 * @param array        $decision The decision inputs from `readDecision()`.
	 *
	 * @return string The confirmation to post.
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-decision-is-confirmed-in-the-room-and-is-not-reversible-by-un-reacting
	 */
	private function recordDecision(ObjectEntity $approval, array $decision): string {
		$uuid = (string)$approval->getUuid();
		if ($decision['emoji'] === self::APPROVE) {
			$this->approvalService->approve(approval: $approval, deciderUid: $decision['reactor']);
			$this->approvalBinding->recordDecidedVia(approvalUuid: $uuid, via: 'reaction');
			return '✅ Approved — the run has been released.';
		}

		$this->approvalService->deny(approval: $approval, deciderUid: $decision['reactor'], reason: 'Denied by reaction in Talk');
		$this->approvalBinding->recordDecidedVia(approvalUuid: $uuid, via: 'reaction');
		return '🚫 Denied — the run will not execute.';

	}//end recordDecision()

	/**
	 * Read a reaction payload belonging to our bot, or null.
	 *
	 * Guard and call live together deliberately: split apart, static analysis
	 * cannot see that the method is checked before it is used.
	 *
	 * @param Event $event The spreed BotInvokeEvent.
	 *
	 * @return array|null The reaction payload, or null.
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-the-reaction-path-is-inert-without-talk
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) See TalkBotInvokeListener::readPayload():
	 * the URL predicate is static so the real rule stays in the path under test.
	 * On THIS listener that matters most — the guard fronts the approval
	 * decision, and its unit tests once passed against a payload shape spreed
	 * never sends while the feature was completely inert in production.
	 */
	private function readPayload(Event $event): ?array {
		// 🔴 Loosened from an exact match on one constant to "is this ANY Hermiq
		// bot", because after per-agent bots there is no single URL to compare
		// against. What must NOT loosen with it is anything downstream: the
		// approval is still resolved by the message id recorded when it was
		// posted, and the reviewer check below is untouched. A URL from another
		// app, or the bare prefix with no agent, still yields null and no action
		// — `agentIdFromBotUrl()` is deliberately strict for exactly this reason.
		if (method_exists($event, 'getBotUrl') === false || TalkBridge::isHermiqBotUrl((string)$event->getBotUrl()) === false) {
			return null;
		}

		if (method_exists($event, 'getMessage') === false || $this->bridge->isAvailable() === false) {
			return null;
		}

		$payload = $event->getMessage();
		if (is_array($payload) === false) {
			return null;
		}

		// `Like` is a reaction added, `Undo` a reaction removed. Everything else
		// (messages, joins, leaves) belongs to another listener.
		$type = (string)($payload['type'] ?? '');
		if ($type !== 'Like' && $type !== 'Undo') {
			return null;
		}

		return $payload;
	}//end readPayload()

	/**
	 * Strip spreed's `users/` actor prefix.
	 *
	 * @param string $actorId The actor id from the payload.
	 *
	 * @return string The bare uid.
	 *
	 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-only-the-reviewer-may-decide-by-reaction
	 */
	private function bareUid(string $actorId): string {
		if (str_starts_with($actorId, 'users/') === true) {
			return substr($actorId, strlen('users/'));
		}

		return $actorId;
	}//end bareUid()
}//end class
