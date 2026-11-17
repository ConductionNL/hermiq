<?php

/**
 * Who may read an agent and administer its organisation, and the fallbacks
 * of the availability service when OpenRegister or the audit trail fails.
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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Agent\AgentAvailabilityService
 * @uses   \OCA\Hermiq\Service\AgentAccessService
 */
class AgentAvailabilityAccessTest extends TestCase {

	/**
	 * OpenRegister object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Organisation mapper double.
	 *
	 * @var OrganisationMapper&MockObject
	 */
	private OrganisationMapper $organisationMapper;

	/**
	 * Audit trail mapper double.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The stored private agent: owned by alice in org-1, ivy invited.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $agent;

	/**
	 * Service under test.
	 *
	 * @var AgentAvailabilityService
	 */
	private AgentAvailabilityService $service;

	/**
	 * Wire the service to doubles around the stored agent; root is the instance admin, olga owns org-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->agent = new ObjectEntity();
		$this->agent->setUuid('agent-1');
		$this->agent->setOwner('alice');
		$this->agent->setOrganisation('org-1');
		$this->agent->setObject(['name' => 'Permit reminder', 'active' => true, 'isPrivate' => true, 'invitedUsers' => ['ivy']]);

		$agent = $this->agent;
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willReturnCallback(
			static function (int|string $id) use ($agent): ?ObjectEntity {
				if ($id === 'broken') {
					throw new RuntimeException('register offline');
				}

				return ($id === 'agent-1') ? $agent : null;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$groupManager->method('isInGroup')->willReturn(false);

		$organisation = new Organisation();
		$organisation->setOwner('olga');
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationMapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($organisation): Organisation {
				if ($uuid !== 'org-1') {
					throw new RuntimeException('no such organisation');
				}

				return $organisation;
			}
		);

		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger           = $this->createMock(LoggerInterface::class);

		$this->service = new AgentAvailabilityService(
			objectService: $this->objectService,
			agentAccess: new AgentAccessService($this->objectService, $this->logger, $groupManager),
			groupManager: $groupManager,
			organisationMapper: $this->organisationMapper,
			auditTrailMapper: $this->auditTrailMapper,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * The owner, an invited user, the organisation owner and an instance admin read a private agent; others do not.
	 *
	 * @return void
	 */
	public function testWhoReadsAPrivateAgent(): void {
		foreach (['alice', 'ivy', 'olga', 'root'] as $uid) {
			$this->assertSame($this->agent, $this->service->readableAgent(agentId: 'agent-1', uid: $uid), $uid);
		}

		foreach (['bob', ''] as $uid) {
			$this->assertNull($this->service->readableAgent(agentId: 'agent-1', uid: $uid), "'$uid'");
		}
	}//end testWhoReadsAPrivateAgent()

	/**
	 * An unknown, empty or unreadable agent id reads as no agent; an empty id is not even looked up.
	 *
	 * @return void
	 */
	public function testAMissingAgentIsNoAgent(): void {
		$this->assertNull($this->service->readableAgent(agentId: 'agent-2', uid: 'alice'));
		$this->assertNull($this->service->readableAgent(agentId: 'broken', uid: 'alice'));

		$this->objectService->expects($this->never())->method('find');
		$this->assertNull($this->service->readableAgent(agentId: '', uid: 'alice'));
	}//end testAMissingAgentIsNoAgent()

	/**
	 * An empty uid administers and modifies nothing, not even what an empty owner would match.
	 *
	 * @return void
	 */
	public function testAnEmptyUidMayDoNothing(): void {
		$ownerless = new ObjectEntity();
		$ownerless->setOwner('');
		$ownerless->setOrganisation('org-1');

		$this->organisationMapper->expects($this->never())->method('findByUuid');
		$this->assertFalse($this->service->mayModify(agent: $ownerless, uid: ''));
		$this->assertFalse($this->service->mayAdministerOrganisation(organisation: 'org-1', uid: ''));
	}//end testAnEmptyUidMayDoNothing()

	/**
	 * Only the owner of a known organisation administers it; no organisation or an unknown one is refused.
	 *
	 * @return void
	 */
	public function testOnlyTheOwnerOfAKnownOrganisationAdministersIt(): void {
		$this->assertTrue($this->service->mayAdministerOrganisation(organisation: 'org-1', uid: 'olga'));
		$this->assertFalse($this->service->mayAdministerOrganisation(organisation: 'org-1', uid: 'bob'));
		$this->assertFalse($this->service->mayAdministerOrganisation(organisation: 'org-9', uid: 'olga'), 'a lookup that fails refuses');
		$this->assertFalse($this->service->mayAdministerOrganisation(organisation: '', uid: 'olga'));
		$this->assertTrue($this->service->mayAdministerOrganisation(organisation: '', uid: 'root'), 'an instance admin administers any');
	}//end testOnlyTheOwnerOfAKnownOrganisationAdministersIt()

	/**
	 * The active organisation is the mapper's, or empty when it has none or fails.
	 *
	 * @return void
	 */
	public function testTheActiveOrganisationFallsBackToEmpty(): void {
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturnCallback(
			static function (string $uid): ?string {
				return match ($uid) {
					'alice' => 'org-1',
					'bob' => null,
					default => throw new RuntimeException('session gone'),
				};
			}
		);

		$this->assertSame('org-1', $this->service->activeOrganisation(uid: 'alice'));
		$this->assertSame('', $this->service->activeOrganisation(uid: 'bob'));
		$this->assertSame('', $this->service->activeOrganisation(uid: 'carol'));
	}//end testTheActiveOrganisationFallsBackToEmpty()

	/**
	 * A failing schedule count is logged and counts as none.
	 *
	 * @return void
	 */
	public function testAFailingScheduleCountIsZero(): void {
		$this->objectService->method('searchObjectsPaginated')->willThrowException(new RuntimeException('index broken'));
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq could not count the schedules of an agent: index broken', $this->arrayHasKey('exception'));

		$this->assertSame(0, $this->service->scheduleCount(agentId: 'agent-1'));
	}//end testAFailingScheduleCountIsZero()

	/**
	 * A failing audit write is logged and does not undo the switch.
	 *
	 * @return void
	 */
	public function testAFailingAuditDoesNotUndoTheSwitch(): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->agent->setObject($object);
				return $this->agent;
			}
		);
		$this->auditTrailMapper->method('recordObjectEvent')->willThrowException(new RuntimeException('audit table locked'));
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq could not record an agent switch: audit table locked', $this->arrayHasKey('exception'));

		$stored = $this->service->switchAgent(agentId: 'agent-1', active: false, reason: 'Holiday', actorUid: 'alice');

		$this->assertFalse($stored->getObject()['active']);
		$this->assertSame('Holiday', $stored->getObject()['availabilityReason']);
	}//end testAFailingAuditDoesNotUndoTheSwitch()
}//end class
