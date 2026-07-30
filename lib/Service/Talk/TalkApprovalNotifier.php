<?php

/**
 * Hermiq TalkApprovalNotifier.
 *
 * Posts a pending approval request into the agent's bound Talk room AS THE BOT,
 * and records which message carries it — the two things that make the request
 * decidable by a reaction.
 *
 * Why not reuse the existing Note-to-self approval channel: a Note-to-self
 * conversation is a room the bot is not in, so spreed would never dispatch a
 * reaction on it to us. A reaction can only be acted on where the bot lives.
 *
 * Additive by construction. The notification and Note-to-self channels are
 * untouched and remain the guaranteed delivery; this is a bonus surface that
 * exists only when an agent has a bound room, and its failure is never allowed
 * to affect the approval being raised.
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts an approval request where it can be decided by reaction.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-an-approval-request-posted-to-talk-records-where-it-landed
 */
class TalkApprovalNotifier
{
    /**
     * Constructor.
     *
     * @param TalkBridge          $bridge          Talk availability and bot posting.
     * @param TalkAgentBinding    $agentBinding    Resolves the agent's bound room.
     * @param TalkApprovalBinding $approvalBinding Records which message carries the request.
     * @param LoggerInterface     $logger          PSR-3 logger.
     */
    public function __construct(
        private readonly TalkBridge $bridge,
        private readonly TalkAgentBinding $agentBinding,
        private readonly TalkApprovalBinding $approvalBinding,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Post an approval request into the agent's room, if it has one.
     *
     * Best-effort throughout: an approval MUST be raised whether or not this
     * succeeds, because the inbox is the authoritative surface.
     *
     * @param ObjectEntity $approval    The pending approval.
     * @param string       $displayName What the approval is about, for the message.
     *
     * @return bool True when the request was posted and bound.
     *
     * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-an-approval-request-posted-to-talk-records-where-it-landed
     */
    public function postRequest(ObjectEntity $approval, string $displayName): bool
    {
        try {
            if ($this->bridge->isAvailable() === false) {
                return false;
            }

            $agentId = (string) ($approval->getObject()['agentId'] ?? '');
            if ($agentId === '') {
                return false;
            }

            $roomToken = $this->agentBinding->roomForAgent(agentId: $agentId);
            if ($roomToken === null) {
                // No room bound to this agent — the notification and inbox
                // still carry the request. Not an error.
                return false;
            }

            $messageId = $this->bridge->postToRoomReturningId(
                roomToken: $roomToken,
                message: $this->requestText(displayName: $displayName)
            );

            if ($messageId === null) {
                return false;
            }

            return $this->approvalBinding->bind(
                approval: $approval,
                roomToken: $roomToken,
                messageId: $messageId
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkApprovalNotifier] Could not post the approval request to Talk (the approval is unaffected)',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end postRequest()

    /**
     * The request message.
     *
     * States both emoji explicitly. A reviewer who has to guess which reaction
     * means what will open the inbox instead, which defeats the point.
     *
     * @param string $displayName What the approval is about.
     *
     * @return string The message body.
     *
     * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
     */
    private function requestText(string $displayName): string
    {
        $body = "React 👍 to approve or 👎 to deny. Only the reviewer's reaction counts, "
            ."and a decision cannot be undone.";

        return sprintf("⏸️ **Approval needed** for “%s”.\n\n%s", $displayName, $body);

    }//end requestText()
}//end class
