<?php

/**
 * Hermiq SessionPendingApprovals.
 *
 * The approvals a session's agent waits on, in the shape the stream's `final`
 * event uses (`{id, kind, title, app, reach, reason, agentId, resolveUrl}`), so
 * a reopened session shows the same approval cards a live turn shows.
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
 * @spec openspec/changes/session-about-a-subject/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCA\Hermiq\Service\Approval\ApprovalInbox;
use OCA\Hermiq\Service\ToolAccessRequestService;

/**
 * Lists a session agent's pending tool-access requests and the tool-call
 * approvals the caller reviews.
 *
 * @spec openspec/changes/session-about-a-subject/tasks.md
 */
class SessionPendingApprovals {

	/**
	 * Constructor.
	 *
	 * @param ToolAccessRequestService $accessRequests The agent's pending tool-access requests.
	 * @param ApprovalInbox            $approvals      The approvals routed to a reviewer.
	 *
	 * @spec openspec/changes/session-about-a-subject/tasks.md
	 */
	public function __construct(
		private readonly ToolAccessRequestService $accessRequests,
		private readonly ApprovalInbox $approvals,
	) {
	}//end __construct()

	/**
	 * The approvals the session's agent waits on: its tool-access requests,
	 * then its tool-call approvals that the caller is the reviewer of.
	 *
	 * The caller has already been allowed to read the session.
	 *
	 * @param array<string, mixed> $session The session payload.
	 * @param string               $uid     The caller.
	 *
	 * @return array<int, array<string, mixed>> The approvals.
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
	 */
	public function forSession(array $session, string $uid): array {
		$agentId = ($session['agentId'] ?? null);
		if (is_string($agentId) === false || $agentId === '') {
			return [];
		}

		return array_merge(
			$this->accessRequests->pendingApprovals(agentId: $agentId),
			$this->toolCalls(agentId: $agentId, uid: $uid)
		);
	}//end forSession()

	/**
	 * The agent's pending tool-call approvals routed to the caller as reviewer.
	 *
	 * @param string $agentId The session's agent.
	 * @param string $uid     The caller.
	 *
	 * @return array<int, array<string, mixed>> The approval cards.
	 */
	private function toolCalls(string $agentId, string $uid): array {
		$cards = [];
		foreach ($this->approvals->listPendingForReviewer(uid: $uid) as $record) {
			if (($record['sourceType'] ?? '') === 'toolcall' && ($record['agentId'] ?? '') === $agentId) {
				$cards[] = $this->card(record: $record);
			}
		}

		return $cards;
	}//end toolCalls()

	/**
	 * One tool-call approval as an approval card.
	 *
	 * @param array<string, mixed> $record A record from ApprovalInbox::listPendingForReviewer().
	 *
	 * @return array<string, mixed> The card.
	 */
	private function card(array $record): array {
		$toolId = (string)($record['toolId'] ?? '');
		$tool = ($record['preview']['tools'][0] ?? []);
		$app = substr($toolId, 0, max(0, (int)strpos($toolId, '.')));
		$title = trim((string)($tool['title'] ?? ''));
		if ($title === '') {
			$title = ucfirst(strtr((string)($tool['name'] ?? $toolId), '_.', '  '));
		}

		return [
			'id' => (string)$record['id'],
			'kind' => 'toolcall',
			'title' => $title,
			'app' => $app,
			'reach' => (string)($tool['reach'] ?? ''),
			'reason' => (string)($record['prompt'] ?? ''),
			'agentId' => (string)$record['agentId'],
			'resolveUrl' => '/index.php/apps/hermiq/api/approvals/' . rawurlencode((string)$record['id']) . '/resolve',
		];
	}//end card()
}//end class
