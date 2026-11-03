<?php

/**
 * SessionController lists the caller's sessions about one record, returns each
 * session's subject, and returns a session's pending approvals with its
 * messages (session-about-a-subject, T02 to T04).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-the-session-list-can-be-narrowed-to-one-record
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use DateTime;
use OCA\Hermiq\Controller\SessionController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Chat\SessionCollaborators;
use OCA\Hermiq\Service\Chat\SessionPendingApprovals;
use OCA\Hermiq\Service\Chat\SessionSubject;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the subject filter, the subject field and the pending approvals.
 *
 * @covers \OCA\Hermiq\Controller\SessionController
 * @uses   \OCA\Hermiq\Service\Chat\SessionCollaborators
 * @uses   \OCA\Hermiq\Service\Chat\SessionSubject
 * @uses   \OCA\Hermiq\Service\Talk\ConversationParticipation
 *
 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-the-session-list-can-be-narrowed-to-one-record
 */
class SessionControllerSubjectTest extends TestCase {

	/**
	 * The stored sessions, by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $sessions = [];

	/**
	 * The pending-approvals double.
	 *
	 * @var SessionPendingApprovals&MockObject
	 */
	private SessionPendingApprovals $pendingApprovals;

	/**
	 * Store the sessions: alice's two about case-1 and one about case-2, bob's
	 * own about case-1, and bob's about case-1 that alice takes part in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$about = static fn (string $uuid): array => ['subject' => ['objectUuid' => $uuid, 'register' => 'zaken', 'schema' => 'zaak', 'appId' => 'dossiq']];
		$this->store(uuid: 's-old', data: ['userId' => 'alice', 'agentId' => 'agent-1', 'metadata' => $about('case-1')], updated: '2026-10-01');
		$this->store(uuid: 's-new', data: ['userId' => 'alice', 'agentId' => 'agent-1', 'metadata' => $about('case-1')], updated: '2026-10-20');
		$this->store(uuid: 's-other', data: ['userId' => 'alice', 'agentId' => 'agent-1', 'metadata' => $about('case-2')], updated: '2026-10-30');
		$this->store(uuid: 's-bob', data: ['userId' => 'bob', 'agentId' => 'agent-1', 'metadata' => $about('case-1')], updated: '2026-10-31');
		$this->store(uuid: 's-shared', data: ['userId' => 'bob', 'agentId' => 'agent-1', 'participants' => ['alice'], 'metadata' => $about('case-1')], updated: '2026-10-10');
		$this->pendingApprovals = $this->createMock(SessionPendingApprovals::class);
	}//end setUp()

	/**
	 * Store one session.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $data    The payload.
	 * @param string               $updated The last update.
	 *
	 * @return void
	 */
	private function store(string $uuid, array $data, string $updated): void {
		$session = new ObjectEntity();
		$session->setUuid($uuid);
		$session->setObject($data);
		$session->setUpdated(new DateTime($updated));
		$this->sessions[$uuid] = $session;
	}//end store()

	/**
	 * The controller acting as $uid with the given query parameters.
	 *
	 * The object store answers a userId or participants filter with EVERY
	 * stored session, as a too-wide match would, so the test proves the
	 * controller's own read check is what keeps another user's sessions out.
	 *
	 * @param string               $uid    The signed-in caller.
	 * @param array<string, mixed> $params The query parameters.
	 *
	 * @return SessionController
	 */
	private function controllerAs(string $uid, array $params = []): SessionController {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => ($this->sessions[$id] ?? null));
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => (isset($config['filters']['sessionId']) === true ? [] : array_values($this->sessions))
		);
		$objectService->method('searchObjectsPaginated')->willReturn(['total' => 0]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new SessionController(
			request: $request,
			engine: $this->createMock(Engine::class),
			objectService: $objectService,
			userSession: $userSession,
			logger: new NullLogger(),
			collaborators: new SessionCollaborators(
				sessionRoom: $this->createMock(TalkSessionRoom::class),
				agentAccess: $this->createMock(AgentAccessService::class),
				subject: new SessionSubject(objectService: $objectService),
				pendingApprovals: $this->pendingApprovals
			)
		);
	}//end controllerAs()

	/**
	 * The uuids in a list response.
	 *
	 * @param array<string, mixed> $data The response data.
	 *
	 * @return array<int, string>
	 */
	private function uuids(array $data): array {
		return array_column($data['results'], 'uuid');
	}//end uuids()

	/**
	 * `subject` lists only the caller's readable sessions about the record,
	 * latest activity first, including one they take part in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#scenario-the-latest-session-about-a-case
	 */
	public function testTheSubjectFilterListsTheCallersSessionsAboutTheRecord(): void {
		$data = $this->controllerAs(uid: 'alice', params: ['subject' => 'case-1'])->index()->getData();

		$this->assertSame(['s-new', 's-shared', 's-old'], $this->uuids(data: $data));
		$this->assertSame(3, $data['total']);
	}//end testTheSubjectFilterListsTheCallersSessionsAboutTheRecord()

	/**
	 * With `limit=1` the answer is the most recently active session about the record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#scenario-the-latest-session-about-a-case
	 */
	public function testLimitOneIsTheLatestSessionAboutTheRecord(): void {
		$data = $this->controllerAs(uid: 'alice', params: ['subject' => 'case-1', 'limit' => 1])->index()->getData();

		$this->assertSame(['s-new'], $this->uuids(data: $data));
		$this->assertSame('case-1', $data['results'][0]['subject']['objectUuid']);
	}//end testLimitOneIsTheLatestSessionAboutTheRecord()

	/**
	 * Another user's session about the same record, which the caller does not
	 * take part in, is never listed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#scenario-another-users-session-about-the-same-case
	 */
	public function testAnotherUsersSessionAboutTheRecordIsNotListed(): void {
		$this->assertNotContains('s-bob', $this->uuids(data: $this->controllerAs(uid: 'alice', params: ['subject' => 'case-1'])->index()->getData()));
		$this->assertSame([], $this->uuids(data: $this->controllerAs(uid: 'mallory', params: ['subject' => 'case-1'])->index()->getData()));
	}//end testAnotherUsersSessionAboutTheRecordIsNotListed()

	/**
	 * Without `subject` the list is the caller's readable sessions as before,
	 * and each session carries its subject.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-session-may-name-the-record-it-is-about
	 */
	public function testWithoutSubjectTheListIsUnchangedAndCarriesSubjects(): void {
		$data = $this->controllerAs(uid: 'alice')->index()->getData();

		$this->assertSame(['s-other', 's-new', 's-shared', 's-old'], $this->uuids(data: $data));
		$this->assertSame(
			['objectUuid' => 'case-2', 'register' => 'zaken', 'schema' => 'zaak', 'appId' => 'dossiq'],
			$data['results'][0]['subject']
		);
	}//end testWithoutSubjectTheListIsUnchangedAndCarriesSubjects()

	/**
	 * A session without a subject serialises `subject: null`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-session-may-name-the-record-it-is-about
	 */
	public function testASessionWithoutASubjectShowsNull(): void {
		$this->store(uuid: 's-plain', data: ['userId' => 'alice', 'agentId' => 'agent-1'], updated: '2026-10-05');

		$data = $this->controllerAs(uid: 'alice')->show(uuid: 's-plain')->getData();

		$this->assertArrayHasKey('subject', $data);
		$this->assertNull($data['subject']);
	}//end testASessionWithoutASubjectShowsNull()

	/**
	 * The messages carry the session's pending approvals, asked for the caller
	 * and the session's own payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#scenario-a-reopened-session-with-a-pending-proposal
	 */
	public function testMessagesCarryThePendingApprovalsForTheCaller(): void {
		$card = ['id' => 'appr-1', 'kind' => 'toolcall', 'resolveUrl' => '/index.php/apps/hermiq/api/approvals/appr-1/resolve'];
		$this->pendingApprovals->expects($this->once())->method('forSession')
			->with($this->sessions['s-new']->getObject(), 'alice')
			->willReturn([$card]);

		$response = $this->controllerAs(uid: 'alice')->messages(uuid: 's-new');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([$card], $response->getData()['pendingApprovals']);
	}//end testMessagesCarryThePendingApprovalsForTheCaller()

	/**
	 * A caller who may not read the session gets 403 and its approvals are never read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
	 */
	public function testAStrangerGetsNoPendingApprovals(): void {
		$this->pendingApprovals->expects($this->never())->method('forSession');

		$response = $this->controllerAs(uid: 'mallory')->messages(uuid: 's-new');

		$this->assertSame(403, $response->getStatus());
		$this->assertArrayNotHasKey('pendingApprovals', $response->getData());
	}//end testAStrangerGetsNoPendingApprovals()
}//end class
