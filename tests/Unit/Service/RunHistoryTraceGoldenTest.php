<?php

/**
 * Golden tests for RunHistoryService::getRunTrace.
 *
 * Pins the exact trace record, and every branch of the gate-wait reconstruction:
 * a killswitch skip counting as a gate, a break at the first non-gate status, a gate
 * entry without a timestamp, a missing or unparseable run start, a negative span
 * clamped to zero, and an entry without a timestamp sorting first.
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
 * @spec openspec/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\RunHistoryService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the run trace read.
 *
 * @spec openspec/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp
 */
class RunHistoryTraceGoldenTest extends TestCase {

	/**
	 * An AuditTrail run entry.
	 *
	 * @param string $uuid The entry UUID.
	 * @param array<string, mixed>|null $context The changed payload.
	 * @param string|null $created The created timestamp, or null for none.
	 *
	 * @return AuditTrail
	 */
	private function entry(string $uuid, ?array $context, ?string $created): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUuid($uuid);
		$entry->setAction('run');
		$entry->setUser('bob');
		$entry->setChanged($context);
		if ($created !== null) {
			$entry->setCreated(new \DateTime($created));
		}

		return $entry;
	}//end entry()

	/**
	 * The trace of one run among the given entries.
	 *
	 * @param array<int, AuditTrail> $logs The schedule's run entries, in any order.
	 * @param string $runId The run to trace.
	 *
	 * @return array<string, mixed>|null
	 */
	private function trace(array $logs, string $runId): ?array {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->expects($this->once())->method('findAll')
			->with($this->anything(), $this->anything(), ['object_uuid' => 'sched-9', 'action' => 'run'])
			->willReturn($logs);

		return (new RunHistoryService($mapper, $this->createMock(IURLGenerator::class)))->getRunTrace('sched-9', $runId);
	}//end trace()

	/**
	 * The full record, with a gate wait spanning a killswitch skip and an approval
	 * wait, stopping at the earlier ok run, read from entries in shuffled order.
	 *
	 * @return void
	 */
	public function testTheFullRecordWithAGateWait(): void {
		$trace = $this->trace(
			[
				$this->entry(
					'run-2',
					[
						'status' => 'ok',
						'agentId' => 'agent-1',
						'startedAt' => '2026-01-01T10:00:00+00:00',
						'endedAt' => '2026-01-01T10:00:05+00:00',
						'durationMs' => 5000,
						'toolStepsAvailable' => false,
						'steps' => ['a' => ['seq' => 7, 'type' => 'tool'], 'b' => ['seq' => 8, 'type' => 'llm']],
						'summary' => 'done',
						'prompt' => 'p',
						'dryRun' => true,
						'replayOf' => 'run-0',
					],
					'2026-01-01T10:00:06+00:00'
				),
				$this->entry('skip-2', ['status' => 'awaiting_approval'], '2026-01-01T09:30:00+00:00'),
				$this->entry('run-1', ['status' => 'ok'], '2026-01-01T08:00:00+00:00'),
				$this->entry('skip-1', ['status' => 'skipped_killswitch'], '2026-01-01T09:00:00+00:00'),
				$this->entry('nodate', ['status' => 'awaiting_approval'], null),
			],
			'run-2'
		);

		$this->assertSame(
			[
				'id' => 'run-2',
				'scheduleId' => 'sched-9',
				'status' => 'ok',
				'agentId' => 'agent-1',
				'startedAt' => '2026-01-01T10:00:00+00:00',
				'endedAt' => '2026-01-01T10:00:05+00:00',
				'durationMs' => 5000,
				'toolStepsAvailable' => false,
				'steps' => [
					[
						'type' => 'gate_wait',
						'name' => 'Awaiting approval',
						'startedAt' => '2026-01-01T09:00:00+00:00',
						'endedAt' => '2026-01-01T10:00:00+00:00',
						'durationMs' => 3600000,
						'outcome' => 'approved',
						'seq' => 0,
					],
					['seq' => 1, 'type' => 'tool'],
					['seq' => 2, 'type' => 'llm'],
				],
				'summary' => 'done',
				'prompt' => 'p',
				'dryRun' => true,
				'replayOf' => 'run-0',
				'user' => 'bob',
				'created' => '2026-01-01T10:00:06+00:00',
			],
			$trace
		);
	}//end testTheFullRecordWithAGateWait()

	/**
	 * A sparse entry: no context, no timestamp, steps that are not a list. The
	 * undated gate entry before it sorts first, so there is nothing before the run.
	 *
	 * @return void
	 */
	public function testASparseEntryDefaultsEveryField(): void {
		$trace = $this->trace(
			[
				$this->entry('later', ['status' => 'awaiting_approval'], '2026-01-01T09:00:00+00:00'),
				$this->entry('run-1', ['steps' => 'nope'], null),
			],
			'run-1'
		);

		$this->assertSame(
			[
				'id' => 'run-1',
				'scheduleId' => 'sched-9',
				'status' => null,
				'agentId' => null,
				'startedAt' => null,
				'endedAt' => null,
				'durationMs' => null,
				'toolStepsAvailable' => false,
				'steps' => [],
				'summary' => null,
				'prompt' => null,
				'dryRun' => false,
				'replayOf' => null,
				'user' => 'bob',
				'created' => null,
			],
			$trace
		);
	}//end testASparseEntryDefaultsEveryField()

	/**
	 * Each way the gate wait is not synthesised, and the clamp of a negative span.
	 *
	 * @return void
	 */
	public function testEachGateWaitBranch(): void {
		$gateThenRun = function (?string $gateCreated, array $runContext): array {
			$trace = $this->trace(
				[
					$this->entry('gate', ['status' => 'awaiting_approval'], $gateCreated),
					$this->entry('run', $runContext, '2026-01-01T12:00:00+00:00'),
				],
				'run'
			);
			return $trace['steps'];
		};

		$this->assertSame([], $gateThenRun(null, ['startedAt' => '2026-01-01T10:00:00+00:00']), 'undated gate');
		$this->assertSame([], $gateThenRun('2026-01-01T09:00:00+00:00', []), 'no run start');
		$this->assertSame([], $gateThenRun('2026-01-01T09:00:00+00:00', ['startedAt' => '']), 'empty run start');
		$this->assertSame([], $gateThenRun('2026-01-01T09:00:00+00:00', ['startedAt' => 5]), 'non-string run start');
		$this->assertSame([], $gateThenRun('2026-01-01T09:00:00+00:00', ['startedAt' => 'not a date']), 'unparseable run start');

		$clamped = $gateThenRun('2026-01-01T11:00:00+00:00', ['startedAt' => '2026-01-01T10:00:00+00:00']);
		$this->assertSame(0, $clamped[0]['durationMs']);
		$this->assertSame('2026-01-01T11:00:00+00:00', $clamped[0]['startedAt']);

		$this->assertNull(
			$this->trace([$this->entry('other', ['status' => 'ok'], '2026-01-01T09:00:00+00:00')], 'missing')
		);
	}//end testEachGateWaitBranch()
}//end class
