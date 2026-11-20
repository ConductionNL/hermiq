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
			/**
			 * The schema the next read is for.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → rows.
			 * @param bool                                    $fail     Whether every read throws.
			 */
			public function __construct(private array $bySchema, private bool $fail) {
			}

			/**
			 * Ignore the register: every row is hermiq's.
			 *
			 * @param mixed $register The register.
			 *
			 * @return static
			 */
			public function setRegister(mixed $register): static {
				return $this;
			}

			/**
			 * Remember the schema the next read is for.
			 *
			 * @param mixed $schema The schema slug.
			 *
			 * @return static
			 */
			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			/**
			 * The rows of the remembered schema, or a failed read.
			 *
			 * @param array $config        Ignored: the filters narrow the read, the rows are filtered again after it.
			 * @param bool  $_rbac         Ignored.
			 * @param bool  $_multitenancy Ignored.
			 *
			 * @return array
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->fail === true) {
					throw new RuntimeException('register unavailable');
				}

				return ($this->bySchema[$this->schema] ?? []);
			}
		};

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable('2026-10-15T12:00:00Z'))->getTimestamp());

		return new BiasCheckEvidence(objectService: $objects, time: $time);
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
			self::row(uuid: 'bias-1', data: ['name' => 'Bias check: public services — Helper', 'templateKey' => 'bias-public-services']),
			self::row(uuid: 'plain-1', data: ['name' => 'Smoke test']),
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
	private static function evalRun(
		string $uuid,
		string $agentId,
		string $datasetId,
		string $startedAt,
		float $passRate,
		string $status = 'completed'
	): ObjectEntity {
		$data = ['agentId' => $agentId, 'datasetId' => $datasetId, 'startedAt' => $startedAt, 'passRate' => $passRate, 'status' => $status];

		return self::row(uuid: $uuid, data: $data);
	}//end evalRun()

	/**
	 * The factsheet reads the latest completed bias run: "95% on 2026-09-01".
	 *
	 * @return void
	 */
	public function testTheLatestCompletedBiasRunIsTheFactsheetLine(): void {
		$evidence = $this->evidence(
			bySchema: [
				'evaldataset' => self::datasets(),
				'evalrun'     => [
					self::evalRun(uuid: 'r-old', agentId: 'agent-1', datasetId: 'bias-1', startedAt: '2026-08-01T06:00:00Z', passRate: 0.9),
					self::evalRun(uuid: 'r-new', agentId: 'agent-1', datasetId: 'bias-1', startedAt: '2026-09-01T06:00:00Z', passRate: 0.95),
					self::evalRun(uuid: 'r-plain', agentId: 'agent-1', datasetId: 'plain-1', startedAt: '2026-09-20T06:00:00Z', passRate: 0.5),
					self::evalRun(uuid: 'r-failed', agentId: 'agent-1', datasetId: 'bias-1', startedAt: '2026-09-25T06:00:00Z', passRate: 0.0, status: 'failed'),
					self::evalRun(uuid: 'r-other', agentId: 'agent-2', datasetId: 'bias-1', startedAt: '2026-09-30T06:00:00Z', passRate: 0.7),
				],
			]
		);

		$latest = $evidence->latestFor(agentId: 'agent-1');

		self::assertSame(expected: 'r-new', actual: $latest['runId']);
		self::assertSame(expected: 'bias-1', actual: $latest['datasetId']);
		self::assertSame(expected: 0.95, actual: $latest['passRate']);
		self::assertSame(expected: '2026-09-01', actual: $latest['date']);
	}//end testTheLatestCompletedBiasRunIsTheFactsheetLine()

	/**
	 * An agent without a bias run has none: the factsheet says "No bias check run".
	 *
	 * @return void
	 */
	public function testAnAgentWithoutABiasRunHasNone(): void {
		$run      = self::evalRun(uuid: 'r-plain', agentId: 'agent-1', datasetId: 'plain-1', startedAt: '2026-09-20T06:00:00Z', passRate: 1.0);
		$evidence = $this->evidence(bySchema: ['evaldataset' => self::datasets(), 'evalrun' => [$run]]);

		self::assertNull(actual: $evidence->latestFor(agentId: 'agent-1'));
		self::assertNull(actual: $evidence->latestFor(agentId: ''));
	}//end testAnAgentWithoutABiasRunHasNone()

	/**
	 * Every scheduled agent checked within 45 days: satisfied.
	 *
	 * @return void
	 */
	public function testEveryScheduledAgentCheckedRecentlyIsSatisfied(): void {
		$evidence = $this->evidence(
			bySchema: [
				'evaldataset'  => self::datasets(),
				'evalschedule' => [self::row(uuid: 's-1', data: ['agentId' => 'agent-1', 'datasetId' => 'bias-1', 'enabled' => true])],
				'evalrun'      => [self::evalRun(uuid: 'r-1', agentId: 'agent-1', datasetId: 'bias-1', startedAt: '2026-10-01T06:00:00Z', passRate: 0.95)],
			]
		);

		self::assertSame(expected: 'satisfied', actual: $evidence->evidence()['status']);
	}//end testEveryScheduledAgentCheckedRecentlyIsSatisfied()

	/**
	 * One of two scheduled agents checked within 45 days: partial; the other's check is older.
	 *
	 * @return void
	 */
	public function testSomeScheduledAgentsCheckedRecentlyIsPartial(): void {
		$evidence = $this->evidence(
			bySchema: [
				'evaldataset'  => self::datasets(),
				'evalschedule' => [
					self::row(uuid: 's-1', data: ['agentId' => 'agent-1', 'datasetId' => 'bias-1', 'enabled' => true]),
					self::row(uuid: 's-2', data: ['agentId' => 'agent-2', 'datasetId' => 'bias-1', 'enabled' => true]),
				],
				'evalrun'      => [
					self::evalRun(uuid: 'r-1', agentId: 'agent-1', datasetId: 'bias-1', startedAt: '2026-10-01T06:00:00Z', passRate: 0.95),
					self::evalRun(uuid: 'r-2', agentId: 'agent-2', datasetId: 'bias-1', startedAt: '2026-08-01T06:00:00Z', passRate: 0.95),
				],
			]
		);

		$status = $evidence->evidence();
		self::assertSame(expected: 'partial', actual: $status['status']);
		self::assertSame(expected: '1 of 2 agent(s) with a scheduled bias check had one in the last 45 days.', actual: $status['detail']);
	}//end testSomeScheduledAgentsCheckedRecentlyIsPartial()

	/**
	 * Scheduled agents without a recent check, and no bias schedule at all, are both unevidenced.
	 *
	 * @return void
	 */
	public function testNoRecentCheckOrNoScheduleIsUnevidenced(): void {
		$stale = $this->evidence(
			bySchema: [
				'evaldataset'  => self::datasets(),
				'evalschedule' => [self::row(uuid: 's-1', data: ['agentId' => 'agent-1', 'datasetId' => 'bias-1', 'enabled' => true])],
				'evalrun'      => [self::evalRun(uuid: 'r-1', agentId: 'agent-1', datasetId: 'bias-1', startedAt: '2026-08-30T06:00:00Z', passRate: 0.95)],
			]
		);
		self::assertSame(expected: 'unevidenced', actual: $stale->evidence()['status']);

		$unscheduled = $this->evidence(
			bySchema: [
				'evaldataset'  => self::datasets(),
				'evalschedule' => [
					self::row(uuid: 's-plain', data: ['agentId' => 'agent-1', 'datasetId' => 'plain-1', 'enabled' => true]),
					self::row(uuid: 's-off', data: ['agentId' => 'agent-2', 'datasetId' => 'bias-1', 'enabled' => false]),
				],
			]
		);
		self::assertSame(expected: ['status' => 'unevidenced', 'detail' => 'No agent has a scheduled bias check yet.'], actual: $unscheduled->evidence());
	}//end testNoRecentCheckOrNoScheduleIsUnevidenced()

	/**
	 * A register that cannot be read gives no evidence and no factsheet line, never an error.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterGivesNothing(): void {
		$evidence = $this->evidence(bySchema: [], fail: true);

		self::assertSame(expected: 'unevidenced', actual: $evidence->evidence()['status']);
		self::assertNull(actual: $evidence->latestFor(agentId: 'agent-1'));
	}//end testAnUnreadableRegisterGivesNothing()
}//end class
