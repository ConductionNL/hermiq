<?php

/**
 * Unit tests for the SeedPairedEvalDataset repair step (skill-evals).
 *
 * The dataset is only meaningful if it LINKS to the skill it measures, so the
 * step refuses to seed when `woo-request-triage` is absent rather than writing a
 * dataset whose `skillRefs` points at nothing. Both branches are pinned here,
 * along with the elevation: a repair step runs during `occ upgrade` with no
 * session, OpenRegister refuses an 'Anonymous' write, and the step reports that
 * with `$output->warning()` — which does not fail an upgrade. A saved-row count
 * cannot tell the fixed world from the broken one; the elevation flag can.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
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
 * @spec openspec/specs/repair-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedPairedEvalDataset;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the skill-evals SeedPairedEvalDataset repair step.
 *
 * @spec openspec/specs/repair-steps/spec.md
 */
class SeedPairedEvalDatasetTest extends TestCase {

	/**
	 * A stateful ObjectService double recording saves and identity elevation.
	 *
	 * @param array<string, array<int, mixed>> $bySchema Schema slug → rows.
	 * @param bool $failWrites Whether saveObject() should throw.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $bySchema, bool $failWrites = false): ObjectService {
		return new class($bySchema, $failWrites) extends ObjectService {
			private ?string $schema = null;

			/**
			 * @var array<int, array{schema: string, object: array, elevated: bool}>
			 */
			public array $saved = [];

			/**
			 * How many times the step asked for a system identity.
			 *
			 * @var int
			 */
			public int $elevations = 0;

			/**
			 * Whether a system identity is active right now.
			 *
			 * @var bool
			 */
			private bool $elevated = false;

			/**
			 * @param array<string, array<int, mixed>> $bySchema Schema slug → rows.
			 * @param bool $failWrites Whether saveObject() should throw.
			 */
			public function __construct(
				private array $bySchema,
				private bool $failWrites,
			) {
			}

			/**
			 * Record the elevation AND run the callable — the real method
			 * (`SystemOperationContext::run()`) invokes and returns.
			 *
			 * @param callable $operation The work to run elevated.
			 *
			 * @return mixed Whatever $operation returns.
			 */
			public function runAsSystem(callable $operation): mixed {
				$this->elevations++;
				$this->elevated = true;
				try {
					return $operation();
				} finally {
					$this->elevated = false;
				}
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->bySchema[$this->schema] ?? []);
			}

			public function saveObject(
				array|ObjectEntity $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?\OCP\IUser $currentUser = null,
				// openregister#2211 (insert-only saves) added this. A double that
				// drifts from the real signature is a FATAL, not a failed
				// assertion.
				bool $failIfExists = false,
				bool $_unowned = false,
			): ObjectEntity {
				if ($this->failWrites === true) {
					throw new RuntimeException('register unavailable');
				}

				$payload = is_array($object) ? $object : $object->getObject();
				$this->saved[] = [
					'schema' => (string)$schema,
					'object' => $payload,
					'elevated' => $this->elevated,
				];

				$entity = new ObjectEntity();
				$entity->setUuid('new-' . count($this->saved));
				$entity->setObject($payload);
				return $entity;
			}
		};

	}//end objectService()

	/**
	 * An object with the given payload.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $payload): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid($uuid);
		$e->setObject($payload);
		return $e;
	}//end object()

	/**
	 * A container resolving ObjectService to the given double.
	 *
	 * @param ObjectService $objectService The object service double.
	 *
	 * @return ContainerInterface
	 */
	private function container(ObjectService $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class) => match ($class) {
				ObjectService::class => $objectService,
				default => throw new RuntimeException("Unexpected service: {$class}"),
			}
		);

		return $container;
	}//end container()

	/**
	 * The step under test, wired to the given object service.
	 *
	 * @param ObjectService $objectService The object service double.
	 *
	 * @return SeedPairedEvalDataset
	 */
	private function step(ObjectService $objectService): SeedPairedEvalDataset {
		return new SeedPairedEvalDataset(
			container: $this->container(objectService: $objectService),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end step()

	/**
	 * The linked skill, present under its seeded name.
	 *
	 * @return ObjectEntity
	 */
	private function linkedSkill(): ObjectEntity {
		return $this->object('skill-uuid-1', ['name' => 'woo-request-triage']);
	}//end linkedSkill()

	/**
	 * The step names itself for `occ maintenance:repair` output.
	 *
	 * @return void
	 */
	public function testItNamesItself(): void {
		$step = $this->step(objectService: $this->objectService([]));

		$this->assertNotSame('', trim($step->getName()));

	}//end testItNamesItself()

	/**
	 * With the linked skill present, the dataset is seeded and REFERS to it.
	 *
	 * @return void
	 */
	public function testSeedsTheDatasetLinkedToTheSkill(): void {
		$objectService = $this->objectService(
			[
				'evaldataset' => [],
				'agentskill' => [$this->linkedSkill()],
			]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(1, $objectService->saved);

		$seeded = $objectService->saved[0]['object'];
		$this->assertSame('evaldataset', $objectService->saved[0]['schema']);
		$this->assertSame('woo-triage-paired-eval', $seeded['name']);
		$this->assertSame(['skill-uuid-1'], $seeded['skillRefs'], 'A paired eval that links to nothing measures nothing.');
		$this->assertCount(3, $seeded['cases']);

	}//end testSeedsTheDatasetLinkedToTheSkill()

	/**
	 * The write happens INSIDE the system identity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/repair-steps/spec.md
	 */
	public function testTheSeedRunsUnderTheSystemIdentity(): void {
		$objectService = $this->objectService(
			[
				'evaldataset' => [],
				'agentskill' => [$this->linkedSkill()],
			]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertSame(1, $objectService->elevations);
		$this->assertCount(1, $objectService->saved);
		$this->assertTrue($objectService->saved[0]['elevated']);

	}//end testTheSeedRunsUnderTheSystemIdentity()

	/**
	 * Every seeded case declares an expectation the runner can evaluate.
	 *
	 * @return void
	 */
	public function testEverySeededCaseIsRunnable(): void {
		$dataset = SeedPairedEvalDataset::seedDataset(linkedSkillUuid: 'skill-uuid-1');

		$this->assertSame(['skill-uuid-1'], $dataset['skillRefs']);

		foreach ($dataset['cases'] as $case) {
			$this->assertNotSame('', trim((string)$case['prompt']));
			$this->assertContains($case['expectationType'], ['contains', 'notContains', 'rubric']);

			if ($case['expectationType'] === 'rubric') {
				$this->assertNotSame('', trim((string)$case['rubric']));
				$this->assertGreaterThan(0.0, $case['rubricPassThreshold']);
				continue;
			}

			$this->assertNotSame('', trim((string)$case['expectedSubstring']));
		}

	}//end testEverySeededCaseIsRunnable()

	/**
	 * With the linked skill ABSENT, nothing is written.
	 *
	 * Seeding a dataset whose `skillRefs` points at nothing would present a
	 * broken eval as a working one.
	 *
	 * @return void
	 */
	public function testNothingIsSeededWhenTheLinkedSkillIsMissing(): void {
		$objectService = $this->objectService(
			[
				'evaldataset' => [],
				'agentskill' => [$this->object('other-1', ['name' => 'some-other-skill'])],
			]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $objectService->saved);

	}//end testNothingIsSeededWhenTheLinkedSkillIsMissing()

	/**
	 * A re-run writes nothing once the dataset exists.
	 *
	 * @return void
	 */
	public function testReRunIsIdempotent(): void {
		$objectService = $this->objectService(
			[
				'evaldataset' => [$this->object('dataset-1', ['name' => 'woo-triage-paired-eval'])],
				'agentskill' => [$this->linkedSkill()],
			]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $objectService->saved);

	}//end testReRunIsIdempotent()

	/**
	 * A failing write warns and returns — it never aborts the repair pass.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsReportedAndNotThrown(): void {
		$objectService = $this->objectService(
			[
				'evaldataset' => [],
				'agentskill' => [$this->linkedSkill()],
			],
			failWrites: true
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step(objectService: $objectService)->run(output: $output);

		$this->assertCount(0, $objectService->saved);

	}//end testAFailingWriteIsReportedAndNotThrown()

	/**
	 * The step no-ops gracefully (never throws) when OpenRegister is absent.
	 *
	 * @return void
	 */
	public function testNoopsWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister not installed'));

		$step = new SeedPairedEvalDataset(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step->run(output: $output);

		$this->addToAssertionCount(1);

	}//end testNoopsWhenOpenRegisterUnavailable()
}//end class
