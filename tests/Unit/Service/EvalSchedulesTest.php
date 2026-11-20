<?php

/**
 * EvalSchedules: storage and the owner rule (reuse-scheduled-bias-evals, task 3).
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Service\EvalSchedules;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * EvalSchedules.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalSchedulesTest extends TestCase {

	/**
	 * The saves, as [data, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string}>
	 */
	private array $saved = [];

	/**
	 * A store over doubles.
	 *
	 * @param string $datasetOwner The dataset's owner; '' when the dataset is absent.
	 * @param string $agentOwner   The agent's owner; '' when the agent is absent.
	 *
	 * @return EvalSchedules
	 */
	private function store(string $datasetOwner = 'fenna', string $agentOwner = 'fenna'): EvalSchedules {
		$dataset = new ObjectEntity();
		$dataset->setUuid('ds-1');
		$dataset->setOwner($datasetOwner);

		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('find')->willReturn($dataset);
		if ($datasetOwner === '') {
			$objects = $this->createMock(originalClassName: ObjectService::class);
			$objects->method('find')->willThrowException(new RuntimeException(message: 'not found'));
		}

		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntity {
				$this->saved[] = [$object, (string)$options->uuid];
				return new ObjectEntity();
			}
		);

		$agent = new Agent();
		$agent->setOwner($agentOwner);
		$agents = $this->createMock(originalClassName: AgentMapper::class);
		$agents->method('findByUuid')->willReturn($agent);
		if ($agentOwner === '') {
			$agents = $this->createMock(originalClassName: AgentMapper::class);
			$agents->method('findByUuid')->willThrowException(new RuntimeException(message: 'not found'));
		}

		$custody = $this->createMock(originalClassName: SeedCustodyServiceInterface::class);
		$custody->method('actsAsOwner')->willReturnCallback(static fn (?string $owner, string $uid): bool => $owner === $uid);

		return new EvalSchedules(objectService: $objects, agentMapper: $agents, seedCustody: $custody);
	}//end store()

	/**
	 * A schedule of fenna's.
	 *
	 * @param string $owner The schedule's owner.
	 *
	 * @return ObjectEntity
	 */
	private static function schedule(string $owner = 'fenna'): ObjectEntity {
		$schedule = new ObjectEntity();
		$schedule->setUuid('sched-1');
		$schedule->setOwner($owner);
		$schedule->setObject(['datasetId' => 'ds-1', 'agentId' => 'agent-1']);
		return $schedule;
	}//end schedule()

	/**
	 * The owner of the schedule, the dataset and the agent gets both targets.
	 *
	 * @return void
	 */
	public function testTheOwnerOfAllThreeGetsTheTargets(): void {
		$targets = $this->store()->targets(schedule: self::schedule());

		$this->assertNotNull(actual: $targets);
		$this->assertSame(expected: 'ds-1', actual: $targets['dataset']->getUuid());
		$this->assertSame(expected: 'fenna', actual: $targets['agent']->getOwner());
	}//end testTheOwnerOfAllThreeGetsTheTargets()

	/**
	 * Someone else's agent or dataset is never a target.
	 *
	 * @return void
	 */
	public function testSomeoneElsesAgentOrDatasetIsNeverATarget(): void {
		$this->assertNull(actual: $this->store(agentOwner: 'mallory')->targets(schedule: self::schedule()));
		$this->assertNull(actual: $this->store(datasetOwner: 'mallory')->targets(schedule: self::schedule()));
		$this->assertNull(actual: $this->store()->targets(schedule: self::schedule(owner: '')));
	}//end testSomeoneElsesAgentOrDatasetIsNeverATarget()

	/**
	 * A dataset or agent that is gone is no target either.
	 *
	 * @return void
	 */
	public function testAMissingDatasetOrAgentIsNoTarget(): void {
		$this->assertNull(actual: $this->store(datasetOwner: '')->targets(schedule: self::schedule()));
		$this->assertNull(actual: $this->store(agentOwner: '')->targets(schedule: self::schedule()));
	}//end testAMissingDatasetOrAgentIsNoTarget()

	/**
	 * A save stores the payload under the schedule's own uuid, without the read-only keys.
	 *
	 * @return void
	 */
	public function testASaveKeepsTheUuidAndDropsReadOnlyKeys(): void {
		$this->store()->save(schedule: self::schedule(), data: ['@self' => ['x' => 1], 'id' => 'sched-1', 'enabled' => true]);

		$this->assertSame(expected: [[['enabled' => true], 'sched-1']], actual: $this->saved);
	}//end testASaveKeepsTheUuidAndDropsReadOnlyKeys()
}//end class
