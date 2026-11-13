<?php

/**
 * DomainTools: each domain tool answers what its page answers, under the same guard.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Mcp;

use OCA\Hermiq\Kernel\Contracts\AgentCatalogInterface;
use OCA\Hermiq\Kernel\Contracts\ApprovalServiceInterface;
use OCA\Hermiq\Kernel\Contracts\LiteracyRequirementInterface;
use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillServiceInterface;
use OCA\Hermiq\Mcp\DomainToolDescriptors;
use OCA\Hermiq\Service\Mcp\DomainTools;
use OCA\Hermiq\Service\Schedule\OwnedSchedule;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Mcp\DomainTools
 * @uses   \OCA\Hermiq\Service\Schedule\OwnedSchedule
 */
final class DomainToolsTest extends TestCase {

	private AgentCatalogInterface&MockObject $agents;

	private ApprovalServiceInterface&MockObject $approvals;

	private ScheduleServiceInterface&MockObject $schedules;

	private SkillServiceInterface&MockObject $skills;

	private ObjectService&MockObject $objects;

	private ?LiteracyRequirementInterface $literacy = null;

	protected function setUp(): void {
		$this->agents = $this->createMock(AgentCatalogInterface::class);
		$this->approvals = $this->createMock(ApprovalServiceInterface::class);
		$this->schedules = $this->createMock(ScheduleServiceInterface::class);
		$this->skills = $this->createMock(SkillServiceInterface::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
	}

	private function tools(): DomainTools {
		$logger = $this->createMock(LoggerInterface::class);

		return new DomainTools(
			$this->agents,
			$this->approvals,
			$this->schedules,
			$this->skills,
			new OwnedSchedule($this->objects, $logger),
			$this->objects,
			$logger,
			$this->literacy
		);
	}

	private static function entity(string $uuid, ?string $owner, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject($data);
		return $entity;
	}

	public function testListAgentsIsTheCataloguePageForTheCallerWithABoundedPage(): void {
		$this->agents->expects($this->exactly(2))->method('page')
			->willReturnCallback(static fn (string $uid, int $limit, int $offset): array => ['results' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset, 'uid' => $uid]);

		$this->assertSame(['results' => [], 'total' => 0, 'limit' => 20, 'offset' => 0, 'uid' => 'alice'], $this->tools()->invoke('alice', DomainToolDescriptors::LIST_AGENTS, []));
		$this->assertSame(['results' => [], 'total' => 0, 'limit' => 50, 'offset' => 0, 'uid' => 'alice'], $this->tools()->invoke('alice', DomainToolDescriptors::LIST_AGENTS, ['limit' => 900, 'offset' => -3]));
	}

	public function testAgentStatusShowsTheRowAndOnlyTheCallersOwnSchedules(): void {
		$agent = self::entity('agent-1', 'bob');
		$this->objects->method('find')->willReturn($agent);
		$this->agents->method('row')->with($agent, 'alice')->willReturn(['uuid' => 'agent-1', 'name' => 'Inbox']);
		$this->objects->method('searchObjectsPaginated')->willReturn([
			'results' => [
				self::entity('s-1', 'alice', ['enabled' => true, 'lastStatus' => 'ok', 'lastError' => null, 'nextRun' => '2026-11-14T08:00:00+00:00']),
				self::entity('s-2', 'bob', ['lastStatus' => 'error']),
			],
		]);

		$this->assertSame(
			[
				'agent' => ['uuid' => 'agent-1', 'name' => 'Inbox'],
				'schedules' => [['scheduleId' => 's-1', 'enabled' => true, 'lastStatus' => 'ok', 'lastError' => null, 'nextRun' => '2026-11-14T08:00:00+00:00']],
			],
			$this->tools()->invoke('alice', DomainToolDescriptors::AGENT_STATUS, ['agentId' => 'agent-1'])
		);
	}

	public function testAnAgentTheCallerMayNotSeeIsNotFound(): void {
		$this->objects->method('find')->willReturn(self::entity('agent-1', 'bob'));
		$this->agents->method('row')->willReturn(null);
		$this->objects->expects($this->never())->method('searchObjectsPaginated');

		$this->assertSame(['error' => ['code' => 'not_found', 'message' => 'Agent not found.']], $this->tools()->invoke('alice', DomainToolDescriptors::AGENT_STATUS, ['agentId' => 'agent-1']));
	}

	public function testAnAgentThatDoesNotLoadIsNotFoundAndNoIdIsInvalid(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('absent'));

		$this->assertSame('not_found', $this->tools()->invoke('alice', DomainToolDescriptors::AGENT_STATUS, ['agentId' => 'x'])['error']['code']);
		$this->assertSame('invalid_arguments', $this->tools()->invoke('alice', DomainToolDescriptors::AGENT_STATUS, [])['error']['code']);
	}

	public function testPendingApprovalsAreTheCallersAsReviewer(): void {
		$approval = self::entity('ap-1', 'bob');
		$this->approvals->expects($this->once())->method('listPendingForReviewer')->with('alice')->willReturn([$approval]);

		$this->assertSame(['results' => [$approval], 'total' => 1], $this->tools()->invoke('alice', DomainToolDescriptors::PENDING_APPROVALS, []));
	}

	public function testRunAgentNowRunsTheCallersOwnScheduleAndReportsTheStatus(): void {
		$schedule = self::entity('s-1', 'alice', ['lastStatus' => 'ok', 'nextRun' => '2026-11-14T08:00:00+00:00']);
		$this->objects->method('find')->willReturn($schedule);
		$this->schedules->expects($this->once())->method('runNow')->with($this->identicalTo($schedule));

		$this->assertSame(
			['scheduleId' => 's-1', 'status' => 'ok', 'error' => null, 'nextRun' => '2026-11-14T08:00:00+00:00'],
			$this->tools()->invoke('alice', DomainToolDescriptors::RUN_AGENT_NOW, ['scheduleId' => 's-1'])
		);
	}

	public function testASchedulOfSomebodyElseIsNotRunAndReadsAsNotFound(): void {
		$this->objects->method('find')->willReturn(self::entity('s-1', 'bob'));
		$this->schedules->expects($this->never())->method('runNow');

		$this->assertSame(['error' => ['code' => 'not_found', 'message' => 'Schedule not found.']], $this->tools()->invoke('alice', DomainToolDescriptors::RUN_AGENT_NOW, ['scheduleId' => 's-1']));
	}

	public function testARunByHandStillNeedsTheCourseWhenTheOrganisationRequiresIt(): void {
		$this->objects->method('find')->willReturn(self::entity('s-1', 'alice'));
		$literacy = $this->createMock(LiteracyRequirementInterface::class);
		$literacy->method('refusal')->with('alice')->willReturn(['message' => 'Finish the AI course first.', 'errorCode' => 'x', 'courseUrl' => '/c']);
		$this->literacy = $literacy;
		$this->schedules->expects($this->never())->method('runNow');

		$this->assertSame(['error' => ['code' => 'literacy_required', 'message' => 'Finish the AI course first.']], $this->tools()->invoke('alice', DomainToolDescriptors::RUN_AGENT_NOW, ['scheduleId' => 's-1']));
	}

	public function testAFailingRunIsAnEnvelopeNotAThrow(): void {
		$this->objects->method('find')->willReturn(self::entity('s-1', 'alice'));
		$this->schedules->method('runNow')->willThrowException(new RuntimeException('engine down'));

		$this->assertSame(['error' => ['code' => 'tool_failed', 'message' => 'The tool call failed.']], $this->tools()->invoke('alice', DomainToolDescriptors::RUN_AGENT_NOW, ['scheduleId' => 's-1']));
		$this->assertSame('invalid_arguments', $this->tools()->invoke('alice', DomainToolDescriptors::RUN_AGENT_NOW, ['scheduleId' => ['x']])['error']['code']);
	}

	public function testSearchSkillsMatchesEveryWordInNameOrDescription(): void {
		$this->skills->method('listSkills')->willReturn([
			self::entity('k-1', null, ['name' => 'Woo request triage', 'description' => 'Sorts incoming requests']),
			self::entity('k-2', null, ['name' => 'Minutes', 'description' => 'Writes meeting minutes']),
		]);

		$this->assertSame(
			['results' => [['uuid' => 'k-1', 'name' => 'Woo request triage', 'description' => 'Sorts incoming requests']], 'total' => 1],
			$this->tools()->invoke('alice', DomainToolDescriptors::SEARCH_SKILLS, ['query' => 'woo  INCOMING'])
		);
		$this->assertSame(2, $this->tools()->invoke('alice', DomainToolDescriptors::SEARCH_SKILLS, [])['total']);
	}

	public function testAnUnknownIdIsAnEnvelope(): void {
		$this->assertSame('unknown_tool', $this->tools()->invoke('alice', 'hermiq.nope', [])['error']['code']);
	}

	public function testEveryDescriptorHasAnObjectInputSchemaAndItsIdIsListed(): void {
		$this->assertSame(DomainToolDescriptors::IDS, array_column(DomainToolDescriptors::ALL, 'id'));
		foreach (DomainToolDescriptors::ALL as $descriptor) {
			$this->assertSame('object', $descriptor['inputSchema']['type'], $descriptor['id']);
			$this->assertIsArray($descriptor['inputSchema']['properties'], $descriptor['id']);
			foreach ($descriptor['inputSchema']['required'] as $required) {
				$this->assertArrayHasKey($required, $descriptor['inputSchema']['properties'], $descriptor['id']);
			}
		}
	}
}
