<?php

/**
 * Unit tests for BiasCheckEvidence (reuse-scheduled-bias-evals).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\BiasCheckEvidence;
use OCA\Hermiq\Tests\Unit\Support\InMemoryEvalObjects;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The latest completed bias run per agent, and the recency status over every
 * agent with a scheduled bias check.
 *
 * @covers \OCA\Hermiq\Service\BiasCheckEvidence
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */
class BiasCheckEvidenceTest extends TestCase {

	/**
	 * 2026-10-01T12:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1790856000;

	/**
	 * The object store.
	 *
	 * @var InMemoryEvalObjects
	 */
	private InMemoryEvalObjects $objects;

	/**
	 * A bias copy (ds-bias), and a dataset that is not a bias check (ds-other).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryEvalObjects();
		$this->objects->put(schema: 'evaldataset', uuid: 'ds-bias', data: ['name' => 'Bias check — A', 'templateKey' => 'bias-public-services']);
		$this->objects->put(schema: 'evaldataset', uuid: 'ds-other', data: ['name' => 'Woo triage']);
	}//end setUp()

	/**
	 * The evidence over the store at NOW.
	 *
	 * @return BiasCheckEvidence
	 */
	private function evidence(): BiasCheckEvidence {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new BiasCheckEvidence(objectService: $this->objects, time: $time);
	}//end evidence()

	/**
	 * Store a run.
	 *
	 * @param string $uuid      The run.
	 * @param string $agentId   The agent.
	 * @param string $datasetId The dataset.
	 * @param string $startedAt When it started.
	 * @param float  $passRate  Its pass rate.
	 * @param string $status    Its status.
	 *
	 * @return void
	 */
	private function storeRun(string $uuid, string $agentId, string $datasetId, string $startedAt, float $passRate, string $status = 'completed'): void {
		$this->objects->put(
			schema: 'evalrun',
			uuid: $uuid,
			data: ['agentId' => $agentId, 'datasetId' => $datasetId, 'startedAt' => $startedAt, 'passRate' => $passRate, 'status' => $status]
		);
	}//end storeRun()

	/**
	 * Store an enabled schedule of the bias dataset for an agent.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return void
	 */
	private function scheduled(string $agentId): void {
		$this->objects->put(schema: 'evalschedule', uuid: 'sched-' . $agentId, data: ['agentId' => $agentId, 'datasetId' => 'ds-bias', 'enabled' => true]);
	}//end scheduled()

	/**
	 * The factsheet reads the latest completed bias run, not a newer run of another dataset or a blocked one.
	 *
	 * @return void
	 */
	public function testTheLatestCompletedBiasRunIsTheOne(): void {
		$this->storeRun(uuid: 'r1', agentId: 'agent-a', datasetId: 'ds-bias', startedAt: '2026-08-01T06:00:00+00:00', passRate: 0.9);
		$this->storeRun(uuid: 'r2', agentId: 'agent-a', datasetId: 'ds-bias', startedAt: '2026-09-01T06:00:00+00:00', passRate: 0.95);
		$this->storeRun(uuid: 'r3', agentId: 'agent-a', datasetId: 'ds-other', startedAt: '2026-09-20T06:00:00+00:00', passRate: 0.5);
		$this->storeRun(uuid: 'r4', agentId: 'agent-a', datasetId: 'ds-bias', startedAt: '2026-09-25T06:00:00+00:00', passRate: 0.0, status: 'blocked_budget');
		$this->storeRun(uuid: 'r5', agentId: 'agent-b', datasetId: 'ds-bias', startedAt: '2026-09-28T06:00:00+00:00', passRate: 0.7);

		$this->assertSame(
			['runId' => 'r2', 'datasetId' => 'ds-bias', 'passRate' => 0.95, 'date' => '2026-09-01T06:00:00+00:00'],
			$this->evidence()->latestFor(agentId: 'agent-a')
		);
		$this->assertNull($this->evidence()->latestFor(agentId: 'agent-c'), 'No bias check run.');
	}//end testTheLatestCompletedBiasRunIsTheOne()

	/**
	 * Every scheduled agent checked in the last 45 days is satisfied; some is partial; none is unevidenced.
	 *
	 * @return void
	 */
	public function testRecencyIsSatisfiedPartialOrUnevidenced(): void {
		$this->assertSame('unevidenced', $this->evidence()->recency()['status'], 'No agent has a scheduled bias check.');

		$this->scheduled(agentId: 'agent-a');
		$this->scheduled(agentId: 'agent-b');
		$this->storeRun(uuid: 'r1', agentId: 'agent-a', datasetId: 'ds-bias', startedAt: '2026-09-01T06:00:00+00:00', passRate: 0.95);
		$this->storeRun(uuid: 'r2', agentId: 'agent-b', datasetId: 'ds-bias', startedAt: '2026-07-01T06:00:00+00:00', passRate: 0.95);

		$this->assertSame(
			['status' => 'partial', 'detail' => '1 of 2 agent(s) with a scheduled bias check had one in the last 45 days.'],
			$this->evidence()->recency()
		);

		$this->storeRun(uuid: 'r3', agentId: 'agent-b', datasetId: 'ds-bias', startedAt: '2026-08-17T12:00:00+00:00', passRate: 0.9);
		$this->assertSame('satisfied', $this->evidence()->recency()['status'], 'Exactly 45 days ago still counts.');

		$this->objects->objects['evalrun'] = [];
		$this->assertSame(
			['status' => 'unevidenced', 'detail' => '0 of 2 agent(s) with a scheduled bias check had one in the last 45 days.'],
			$this->evidence()->recency()
		);
	}//end testRecencyIsSatisfiedPartialOrUnevidenced()

	/**
	 * A schedule of a dataset that is not a bias check does not count.
	 *
	 * @return void
	 */
	public function testAScheduleOfAnotherDatasetDoesNotCount(): void {
		$this->objects->put(schema: 'evalschedule', uuid: 's', data: ['agentId' => 'agent-a', 'datasetId' => 'ds-other', 'enabled' => true]);

		$this->assertSame(['status' => 'unevidenced', 'detail' => 'No agent has a scheduled bias check.'], $this->evidence()->recency());
	}//end testAScheduleOfAnotherDatasetDoesNotCount()
}//end class
