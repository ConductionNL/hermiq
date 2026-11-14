<?php

/**
 * SessionPendingApprovals lists a session agent's tool-access requests and the
 * tool-call approvals the caller reviews, as approval cards
 * (session-about-a-subject, T04).
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
 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\Approval\ApprovalInbox;
use OCA\Hermiq\Service\Chat\SessionPendingApprovals;
use OCA\Hermiq\Service\ToolAccessRequestService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SessionPendingApprovals.
 *
 * @covers \OCA\Hermiq\Service\Chat\SessionPendingApprovals
 *
 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
 */
class SessionPendingApprovalsTest extends TestCase {

	/**
	 * A pending tool-call approval as ApprovalInbox::listPendingForReviewer() lists it.
	 *
	 * @param string $id       The approval id.
	 * @param string $agentId  The agent.
	 * @param string $source   The sourceType.
	 *
	 * @return array<string, mixed>
	 */
	private function record(string $id, string $agentId, string $source = 'toolcall'): array {
		return [
			'id' => $id,
			'agentId' => $agentId,
			'prompt' => 'Delete case 12',
			'sourceType' => $source,
			'toolId' => 'dossiq.case_delete',
			'preview' => ['kind' => 'toolcall', 'tools' => [['id' => 'dossiq.case_delete', 'name' => 'case_delete', 'reach' => 'instance']]],
		];
	}//end record()

	/**
	 * The service over a reviewer inbox that only `bob` sees.
	 *
	 * @param array<int, array<string, mixed>> $accessRequests The agent's tool-access cards.
	 * @param array<int, array<string, mixed>> $bobsInbox      What listPendingForReviewer('bob') returns.
	 *
	 * @return SessionPendingApprovals
	 */
	private function service(array $accessRequests, array $bobsInbox): SessionPendingApprovals {
		$requests = $this->createMock(ToolAccessRequestService::class);
		$requests->method('pendingApprovals')->willReturnCallback(
			static fn (?string $agentId): array => ($agentId === 'agent-1' ? $accessRequests : [])
		);
		$approvals = $this->createMock(ApprovalInbox::class);
		$approvals->method('listPendingForReviewer')->willReturnCallback(
			static fn (string $uid): array => ($uid === 'bob' ? $bobsInbox : [])
		);

		return new SessionPendingApprovals(accessRequests: $requests, approvals: $approvals);
	}//end service()

	/**
	 * The reviewer sees the agent's tool-access requests, then its tool-call
	 * approvals as cards that resolve through the approval's own route.
	 *
	 * @return void
	 */
	public function testTheReviewerSeesAccessRequestsAndToolCallCards(): void {
		$access = ['id' => 'req-1', 'kind' => 'tool-access', 'resolveUrl' => '/x'];
		$cards = $this->service(accessRequests: [$access], bobsInbox: [$this->record(id: 'appr/1', agentId: 'agent-1')])
			->forSession(session: ['agentId' => 'agent-1'], uid: 'bob');

		$this->assertSame(
			[
				$access,
				[
					'id' => 'appr/1',
					'kind' => 'toolcall',
					'title' => 'Case delete',
					'app' => 'dossiq',
					'reach' => 'instance',
					'reason' => 'Delete case 12',
					'agentId' => 'agent-1',
					'resolveUrl' => '/index.php/apps/hermiq/api/approvals/appr%2F1/resolve',
				],
			],
			$cards
		);
	}//end testTheReviewerSeesAccessRequestsAndToolCallCards()

	/**
	 * A caller who is not the reviewer sees no tool-call approvals, even with
	 * the session open.
	 *
	 * @return void
	 */
	public function testACallerWhoIsNotTheReviewerSeesNoToolCalls(): void {
		$cards = $this->service(accessRequests: [], bobsInbox: [$this->record(id: 'appr-1', agentId: 'agent-1')])
			->forSession(session: ['agentId' => 'agent-1', 'userId' => 'mallory'], uid: 'mallory');

		$this->assertSame([], $cards);
	}//end testACallerWhoIsNotTheReviewerSeesNoToolCalls()

	/**
	 * Approvals of another agent, and approvals of another source type, stay out.
	 *
	 * @return void
	 */
	public function testOnlyTheSessionAgentsToolCallsAreShown(): void {
		$cards = $this->service(
			accessRequests: [],
			bobsInbox: [
				$this->record(id: 'appr-other', agentId: 'agent-2'),
				$this->record(id: 'appr-run', agentId: 'agent-1', source: 'schedule'),
				$this->record(id: 'appr-mine', agentId: 'agent-1'),
			]
		)->forSession(session: ['agentId' => 'agent-1'], uid: 'bob');

		$this->assertSame(['appr-mine'], array_column($cards, 'id'));
	}//end testOnlyTheSessionAgentsToolCallsAreShown()

	/**
	 * A tool the catalogue does not describe is titled from its id; an
	 * undotted id names no app.
	 *
	 * @return void
	 */
	public function testAnUndescribedToolIsTitledFromItsId(): void {
		$record = $this->record(id: 'appr-1', agentId: 'agent-1');
		$record['toolId'] = 'delete_object';
		$record['preview'] = null;
		$cards = $this->service(accessRequests: [], bobsInbox: [$record])
			->forSession(session: ['agentId' => 'agent-1'], uid: 'bob');

		$this->assertSame(['Delete object', '', ''], [$cards[0]['title'], $cards[0]['app'], $cards[0]['reach']]);
	}//end testAnUndescribedToolIsTitledFromItsId()

	/**
	 * A tool whose preview carries a human title shows that title on the card;
	 * a blank title falls back to the formatted name.
	 *
	 * @return void
	 */
	public function testACardPrefersTheToolsHumanTitle(): void {
		$titled = $this->record(id: 'appr-1', agentId: 'agent-1');
		$titled['toolId'] = 'dossiq.createTask';
		$titled['preview']['tools'][0] = ['id' => 'dossiq.createTask', 'name' => 'dossiq.createTask', 'title' => 'Create a task', 'reach' => 'organisation'];
		$blank = $titled;
		$blank['id'] = 'appr-2';
		$blank['preview']['tools'][0]['title'] = ' ';

		$cards = $this->service(accessRequests: [], bobsInbox: [$titled, $blank])
			->forSession(session: ['agentId' => 'agent-1'], uid: 'bob');

		$this->assertSame(['Create a task', 'Dossiq createTask'], array_column($cards, 'title'));
	}//end testACardPrefersTheToolsHumanTitle()

	/**
	 * A session without an agent has no approvals and asks no one.
	 *
	 * @return void
	 */
	public function testASessionWithoutAnAgentHasNone(): void {
		$requests = $this->createMock(ToolAccessRequestService::class);
		$requests->expects($this->never())->method('pendingApprovals');
		$approvals = $this->createMock(ApprovalInbox::class);
		$approvals->expects($this->never())->method('listPendingForReviewer');
		$service = new SessionPendingApprovals(accessRequests: $requests, approvals: $approvals);

		$this->assertSame([], $service->forSession(session: [], uid: 'bob'));
		$this->assertSame([], $service->forSession(session: ['agentId' => ''], uid: 'bob'));
		$this->assertSame([], $service->forSession(session: ['agentId' => ['agent-1']], uid: 'bob'));
	}//end testASessionWithoutAnAgentHasNone()
}//end class
