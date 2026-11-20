<?php

/**
 * Unit tests for BiasCheckEvidence (reuse-scheduled-bias-evals).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Eval
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

namespace OCA\Hermiq\Tests\Unit\Service\Eval;

use DateTimeImmutable;
use OCA\Hermiq\Service\Eval\BiasCheckEvidence;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Eval\BiasCheckEvidence
 */
class BiasCheckEvidenceTest extends TestCase {

	/**
	 * The evidence over the given rows, at 2026-10-15 12:00 UTC.
	 *
	 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → rows.
	 * @param bool                                    $fail     Whether every read throws.
	 *
	 * @return BiasCheckEvidence
	 */
	private function evidence(array $bySchema, bool $fail = false): BiasCheckEvidence {
		$objects = new class($bySchema, $fail) extends ObjectService {
			private string $schema = '';

			/**
			 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → rows.
			 * @param bool                                    $fail     Whether every read throws.
			 */
			public function __construct(private array $bySchema, private bool $fail) {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->fail === true) {
					throw new RuntimeException('register unavailable');
				}

				// The filters narrow the read; the rows are filtered again after it.
				return ($this->bySchema[$this->schema] ?? []);
			}
		};

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable('2026-10-15T12:00:00Z'))->getTimestamp());

		return new BiasCheckEvidence($objects, $time);
	}//end evidence()

	/**
	 * An object with the given uuid and data.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private static function row(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end row()

	/**
	 * The datasets: one bias copy, one ordinary dataset.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private static function datasets(): array {
		return [
			self::row('bias-1', ['name' => 'Bias check: public services — Helper', 'templateKey' => 'bias-public-services']),
			self::row('plain-1', ['name' => 'Smoke test']),
		];
	}//end datasets()

	/**
	 * A completed run.
	 *
	 * @param string $uuid      The run uuid.
	 * @param string $agentId   The agent.
	 * @param string $datasetId The dataset.
	 * @param string $startedAt When it started.
	 * @param float  $passRate  The pass rate.
	 * @param string $status    The status.
	 *
	 * @return ObjectEntity
	 */
	private static function evalRun(string $uuid, string $agentId, string $datasetId, string $startedAt, float $passRate, string $status = 'completed'): ObjectEntity {
		return self::row($uuid, ['agentId' => $agentId, 'datasetId' => $datasetId, 'startedAt' => $startedAt, 'passRate' => $passRate, 'status' => $status]);
	}//end evalRun()

	/**
	 * The factsheet reads the latest completed bias run: "95% on 2026-09-01".
	 *
	 * @return void
	 */
	public function testTheLatestCompletedBiasRunIsTheFactsheetLine(): void {
		$evidence = $this->evidence(
			[
				'evaldataset' => self::datasets(),
				'evalrun'     => [
					self::evalRun('r-old', 'agent-1', 'bias-1', '2026-08-01T06:00:00Z', 0.9),
					self::evalRun('r-new', 'agent-1', 'bias-1', '2026-09-01T06:00:00Z', 0.95),
					self::evalRun('r-plain', 'agent-1', 'plain-1', '2026-09-20T06:00:00Z', 0.5),
					self::evalRun('r-failed', 'agent-1', 'bias-1', '2026-09-25T06:00:00Z', 0.0, 'failed'),
					self::evalRun('r-other', 'agent-2', 'bias-1', '2026-09-30T06:00:00Z', 0.7),
				],
			]
		);

		$latest = $evidence->latestFor(agentId: 'agent-1');

		self::assertSame('r-new', $latest['runId']);
		self::assertSame('bias-1', $latest['datasetId']);
		self::assertSame(0.95, $latest['passRate']);
		self::assertSame('2026-09-01', $latest['date']);
	}//end testTheLatestCompletedBiasRunIsTheFactsheetLine()

	/**
	 * An agent without a bias run has none: the factsheet says "No bias check run".
	 *
	 * @return void
	 */
	public function testAnAgentWithoutABiasRunHasNone(): void {
		$evidence = $this->evidence(['evaldataset' => self::datasets(), 'evalrun' => [self::evalRun('r-plain', 'agent-1', 'plain-1', '2026-09-20T06:00:00Z', 1.0)]]);

		self::assertNull($evidence->latestFor(agentId: 'agent-1'));
		self::assertNull($evidence->latestFor(agentId: ''));
	}//end testAnAgentWithoutABiasRunHasNone()

	/**
	 * Every scheduled agent checked within 45 days: satisfied.
	 *
	 * @return void
	 */
	public function testEveryScheduledAgentCheckedRecentlyIsSatisfied(): void {
		$evidence = $this->evidence(
			[
				'evaldataset'  => self::datasets(),
				'evalschedule' => [self::row('s-1', ['agentId' => 'agent-1', 'datasetId' => 'bias-1', 'enabled' => true])],
				'evalrun'      => [self::evalRun('r-1', 'agent-1', 'bias-1', '2026-10-01T06:00:00Z', 0.95)],
			]
		);

		self::assertSame('satisfied', $evidence->evidence()['status']);
	}//end testEveryScheduledAgentCheckedRecentlyIsSatisfied()

	/**
	 * One of two scheduled agents checked within 45 days: partial; the other's check is older.
	 *
	 * @return void
	 */
	public function testSomeScheduledAgentsCheckedRecentlyIsPartial(): void {
		$evidence = $this->evidence(
			[
				'evaldataset'  => self::datasets(),
				'evalschedule' => [
					self::row('s-1', ['agentId' => 'agent-1', 'datasetId' => 'bias-1', 'enabled' => true]),
					self::row('s-2', ['agentId' => 'agent-2', 'datasetId' => 'bias-1', 'enabled' => true]),
				],
				'evalrun'      => [
					self::evalRun('r-1', 'agent-1', 'bias-1', '2026-10-01T06:00:00Z', 0.95),
					self::evalRun('r-2', 'agent-2', 'bias-1', '2026-08-01T06:00:00Z', 0.95),
				],
			]
		);

		$status = $evidence->evidence();
		self::assertSame('partial', $status['status']);
		self::assertSame('1 of 2 agent(s) with a scheduled bias check had one in the last 45 days.', $status['detail']);
	}//end testSomeScheduledAgentsCheckedRecentlyIsPartial()

	/**
	 * Scheduled agents without a recent check, and no bias schedule at all, are both unevidenced.
	 *
	 * @return void
	 */
	public function testNoRecentCheckOrNoScheduleIsUnevidenced(): void {
		$stale = $this->evidence(
			[
				'evaldataset'  => self::datasets(),
				'evalschedule' => [self::row('s-1', ['agentId' => 'agent-1', 'datasetId' => 'bias-1', 'enabled' => true])],
				'evalrun'      => [self::evalRun('r-1', 'agent-1', 'bias-1', '2026-08-30T06:00:00Z', 0.95)],
			]
		);
		self::assertSame('unevidenced', $stale->evidence()['status']);

		$unscheduled = $this->evidence(
			[
				'evaldataset'  => self::datasets(),
				'evalschedule' => [
					self::row('s-plain', ['agentId' => 'agent-1', 'datasetId' => 'plain-1', 'enabled' => true]),
					self::row('s-off', ['agentId' => 'agent-2', 'datasetId' => 'bias-1', 'enabled' => false]),
				],
			]
		);
		self::assertSame(['status' => 'unevidenced', 'detail' => 'No agent has a scheduled bias check yet.'], $unscheduled->evidence());
	}//end testNoRecentCheckOrNoScheduleIsUnevidenced()

	/**
	 * A register that cannot be read gives no evidence and no factsheet line, never an error.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterGivesNothing(): void {
		$evidence = $this->evidence([], fail: true);

		self::assertSame('unevidenced', $evidence->evidence()['status']);
		self::assertNull($evidence->latestFor(agentId: 'agent-1'));
	}//end testAnUnreadableRegisterGivesNothing()
}//end class
