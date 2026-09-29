<?php

/**
 * Unit tests for AnalyticsService::compareRuns() (observability-compare-two-runs).
 *
 * Two runs are compared only when both belong to agents the caller may see in the
 * run list; a run of any other agent is answered exactly like a run that does not exist.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AnalyticsService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for comparing two runs on the run list's boundary.
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 */
class RunCompareTest extends TestCase {

	/**
	 * A run audit entry with steps.
	 *
	 * @param string              $uuid    The run (audit entry) uuid.
	 * @param string              $agentId The agent that ran.
	 * @param string              $status  The run status.
	 * @param array<int, string>  $tools   The tool names called, in order.
	 * @param string              $action  `run` (schedule) or `agent-run` (flow).
	 *
	 * @return AuditTrail
	 */
	private function runEntry(string $uuid, string $agentId, string $status, array $tools, string $action = 'run'): AuditTrail {
		$steps = [];
		foreach ($tools as $i => $tool) {
			$steps[] = ['seq' => ($i + 1), 'type' => 'tool', 'name' => $tool, 'outcome' => 'ok', 'durationMs' => 100];
		}

		$a = new AuditTrail();
		$a->setUuid($uuid);
		$a->setAction($action);
		$a->setObjectUuid('schedule-1');
		$a->setCreated(new \DateTime('2026-09-28 02:00:00'));
		$a->setChanged(
			[
				'agentId'      => $agentId,
				'status'       => $status,
				'durationMs'   => 1000,
				'summary'      => 'Checked the cases',
				'steps'        => $steps,
				'agentVersion' => 'v-' . $uuid,
			]
		);
		return $a;
	}//end runEntry()

	/**
	 * The service with a visible agent set and the audit entries the mapper holds.
	 *
	 * @param array<int, string>     $visibleAgents The agent uuids the caller may see.
	 * @param array<int, AuditTrail> $runs          Every run audit entry.
	 *
	 * @return AnalyticsService
	 */
	private function service(array $visibleAgents, array $runs): AnalyticsService {
		$page = [];
		foreach ($visibleAgents as $agentId) {
			$agent = new ObjectEntity();
			$agent->setUuid($agentId);
			$agent->setObject(['name' => 'Agent ' . $agentId]);
			$page[] = $agent;
		}

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('searchObjectsPaginated')
			->willReturn(['results' => $page, 'total' => count($page)]);

		// The mapper answers every filter with every entry, so the service must match
		// the uuid itself rather than trust the filter to have done it.
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findAll')->willReturn($runs);

		return new AnalyticsService($objectService, $mapper);
	}//end service()

	/**
	 * Two visible runs come back with both records, both step lists and the aligned comparison.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
	 */
	public function testTwoVisibleRunsAreCompared(): void {
		$left  = $this->runEntry('run-a', 'agentA', 'ok', ['Search contacts', 'Read file', 'Send email']);
		$right = $this->runEntry('run-b', 'agentA', 'ok', ['Search contacts', 'Read file', 'Read file', 'Send email'], 'agent-run');

		$result = $this->service(['agentA'], [$left, $right])->compareRuns(leftId: 'run-a', rightId: 'run-b');

		$this->assertNotNull($result['left']);
		$this->assertNotNull($result['right']);
		$this->assertSame('run-a', $result['left']['id']);
		$this->assertSame('schedule', $result['left']['trigger']);
		$this->assertSame('flow', $result['right']['trigger']);
		$this->assertSame('v-run-a', $result['left']['agentVersion']);
		$this->assertNull($result['left']['model'], 'A run that recorded no model says so, it is not guessed.');
		$this->assertCount(3, $result['left']['steps']);
		$this->assertCount(4, $result['right']['steps']);
		$this->assertTrue($result['sameAgent']);
		$this->assertSame(1, $result['comparison']['differences']);
		$this->assertSame(
			['same', 'same', 'only-right', 'same'],
			array_column($result['comparison']['steps'], 'mark')
		);

	}//end testTwoVisibleRunsAreCompared()

	/**
	 * A run of an agent the caller may not see is answered as missing, on that side only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
	 */
	public function testARunOfAnInvisibleAgentIsMissing(): void {
		$mine    = $this->runEntry('run-a', 'agentA', 'ok', ['Read file']);
		$private = $this->runEntry('run-p', 'agentPrivate', 'ok', ['Read file']);

		$result = $this->service(['agentA'], [$mine, $private])->compareRuns(leftId: 'run-a', rightId: 'run-p');

		$this->assertNotNull($result['left']);
		$this->assertNull($result['right'], 'The private agent\'s run must read as absent.');
		$this->assertNull($result['comparison']);

	}//end testARunOfAnInvisibleAgentIsMissing()

	/**
	 * An unknown id and a dry run are both missing; two agents are flagged as different.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
	 */
	public function testUnknownAndDryRunsAreMissingAndAgentsAreNamed(): void {
		$dry = $this->runEntry('run-dry', 'agentA', 'ok', ['Read file']);
		$dry->setChanged(array_merge($dry->getChanged(), ['dryRun' => true]));
		$a = $this->runEntry('run-a', 'agentA', 'ok', ['Read file']);
		$b = $this->runEntry('run-b', 'agentB', 'error', ['Read file']);

		$service = $this->service(['agentA', 'agentB'], [$dry, $a, $b]);

		$missing = $service->compareRuns(leftId: 'nope', rightId: 'run-dry');
		$this->assertNull($missing['left']);
		$this->assertNull($missing['right']);

		$cross = $service->compareRuns(leftId: 'run-a', rightId: 'run-b');
		$this->assertFalse($cross['sameAgent']);
		$this->assertSame('Agent agentB', $cross['right']['agentName']);

	}//end testUnknownAndDryRunsAreMissingAndAgentsAreNamed()
}//end class
