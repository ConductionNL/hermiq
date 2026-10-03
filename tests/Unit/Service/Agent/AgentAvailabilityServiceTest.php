<?php

/**
 * Unit tests for switching an agent off and on.
 *
 * Who may switch (the owner, an instance admin, the owner of the agent's
 * organisation), what is recorded (who, when, why, on the agent and in the
 * audit trail), and that the written payload is one the real Agent schema
 * fragment accepts.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AgentAvailabilityService;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCP\IGroupManager;

/**
 * Tests AgentAvailabilityService.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */
class AgentAvailabilityServiceTest extends TestCase {

	/**
	 * OpenRegister object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Group manager double (instance admin check).
	 *
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * Organisation mapper double (organisation owner check).
	 *
	 * @var OrganisationMapper&MockObject
	 */
	private $organisationMapper;

	/**
	 * Every payload handed to saveObject().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Every audit entry written.
	 *
	 * @var array<int, array{action: string, context: array<string, mixed>}>
	 */
	private array $audits = [];

	/**
	 * Service under test.
	 *
	 * @var AgentAvailabilityService
	 */
	private AgentAvailabilityService $service;

	/**
	 * Wire the service to doubles around a stored agent owned by alice in org-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setOwner('alice');
		$agent->setOrganisation('org-1');
		$agent->setObject(['name' => 'Permit reminder', 'active' => true, 'isPrivate' => true]);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willReturnCallback(
			static fn (int|string $id): ?ObjectEntity => ($id === 'agent-1') ? $agent : null
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object) use ($agent): ObjectEntity {
				$this->saved[] = $object;
				$agent->setObject($object);
				return $agent;
			}
		);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');

		$organisation = new Organisation();
		$organisation->setOwner('olga');
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationMapper->method('findByUuid')->willReturn($organisation);

		$auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []): AuditTrail {
				$this->audits[] = ['action' => $action, 'context' => $context];
				return new AuditTrail();
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new AgentAvailabilityService(
			objectService: $this->objectService,
			agentAccess: new AgentAccessService($this->objectService, $logger, $this->groupManager),
			groupManager: $this->groupManager,
			organisationMapper: $this->organisationMapper,
			auditTrailMapper: $auditTrailMapper,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * The owner, an instance admin and the organisation owner may switch; the
	 * agent then carries who, when and why, and one audit entry records it.
	 *
	 * @return void
	 */
	public function testOwnerAdminAndOrganisationOwnerMaySwitch(): void {
		foreach (['alice', 'root', 'olga'] as $uid) {
			$this->saved = [];
			$this->audits = [];

			$result = $this->service->switchAgent(agentId: 'agent-1', active: false, reason: 'Sends reminders for closed permits', actorUid: $uid);

			$data = $result->getObject();
			$this->assertFalse($data['active'], "{$uid} may switch the agent off.");
			$this->assertSame($uid, $data['availabilityChangedBy']);
			$this->assertSame('Sends reminders for closed permits', $data['availabilityReason']);
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)$data['availabilityChangedAt']);
			$this->assertCount(1, $this->audits);
			$this->assertSame('agent.availability', $this->audits[0]['action']);
			$this->assertFalse($this->audits[0]['context']['active']);
			$this->assertSame($uid, $this->audits[0]['context']['changedBy']);
		}

	}//end testOwnerAdminAndOrganisationOwnerMaySwitch()

	/**
	 * Anybody else gets 403 and nothing is written.
	 *
	 * @return void
	 */
	public function testAnyOtherUserIsRefusedWith403(): void {
		try {
			$this->service->switchAgent(agentId: 'agent-1', active: false, reason: 'no', actorUid: 'mallory');
			$this->fail('A colleague without rights must be refused.');
		} catch (RuntimeException $e) {
			$this->assertSame(403, $e->getCode());
		}

		$this->assertSame([], $this->saved);
		$this->assertSame([], $this->audits);

	}//end testAnyOtherUserIsRefusedWith403()

	/**
	 * Switching off needs a reason (400); switching on does not.
	 *
	 * @return void
	 */
	public function testSwitchingOffNeedsAReasonSwitchingOnDoesNot(): void {
		try {
			$this->service->switchAgent(agentId: 'agent-1', active: false, reason: '   ', actorUid: 'alice');
			$this->fail('Switching off without a reason must be refused.');
		} catch (RuntimeException $e) {
			$this->assertSame(400, $e->getCode());
		}

		$this->assertSame([], $this->saved);

		$result = $this->service->switchAgent(agentId: 'agent-1', active: true, reason: '', actorUid: 'alice');
		$this->assertTrue($result->getObject()['active']);
		$this->assertNull($result->getObject()['availabilityReason']);

	}//end testSwitchingOffNeedsAReasonSwitchingOnDoesNot()

	/**
	 * An unknown agent is a 404.
	 *
	 * @return void
	 */
	public function testUnknownAgentIs404(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);

		$this->service->switchAgent(agentId: 'nope', active: false, reason: 'x', actorUid: 'alice');

	}//end testUnknownAgentIs404()

	/**
	 * The payload written is one the real Agent schema fragment accepts, and a
	 * non-boolean `active` is one it refuses.
	 *
	 * @return void
	 */
	public function testSavedPayloadValidatesAgainstTheAgentSchema(): void {
		$this->service->switchAgent(agentId: 'agent-1', active: false, reason: 'Paused', actorUid: 'alice');

		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->Agent;
		$validator = new Validator();

		$payload = $this->saved[0];
		$this->assertTrue($validator->validate(json_decode((string)json_encode($payload)), $schema)->isValid());

		$payload['active'] = 'no';
		$this->assertFalse($validator->validate(json_decode((string)json_encode($payload)), $schema)->isValid());

		$payload['active'] = true;
		$payload['maxToolCalls'] = 101;
		$this->assertFalse($validator->validate(json_decode((string)json_encode($payload)), $schema)->isValid(), 'maxToolCalls stays within 1 to 100.');

	}//end testSavedPayloadValidatesAgainstTheAgentSchema()

	/**
	 * The fresh read the tool loop uses: a switched-off agent reads as off, an
	 * unknown one as on (the run path already refused a missing agent).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
	 */
	public function testIsOnReadsTheStoredAgent(): void {
		$this->assertTrue($this->service->isOn(agentId: 'agent-1'));
		$this->service->switchAgent(agentId: 'agent-1', active: false, reason: 'Paused', actorUid: 'alice');
		$this->assertFalse($this->service->isOn(agentId: 'agent-1'));
		$this->assertTrue($this->service->isOn(agentId: 'nope'));

	}//end testIsOnReadsTheStoredAgent()

	/**
	 * The schedule count the delete confirmation names.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
	 */
	public function testScheduleCountAsksForTheAgentsSchedules(): void {
		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->with($this->callback(static fn (array $query): bool => $query['agentId'] === 'agent-1'))
			->willReturn(['total' => 2]);

		$this->assertSame(2, $this->service->scheduleCount(agentId: 'agent-1'));

	}//end testScheduleCountAsksForTheAgentsSchedules()
}//end class
