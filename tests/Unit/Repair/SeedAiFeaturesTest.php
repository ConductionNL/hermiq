<?php

/**
 * Unit tests for the SeedAiFeatures repair step (ai-feature-governance-register).
 *
 * Covers the fresh-install seed (three AiFeature governance rows, all
 * `lifecycle: disabled` so nothing is usable before a DPO acknowledges it),
 * idempotency by slug, the OpenRegister-absent no-op, and — the reason this file
 * exists now — that the seed writes run INSIDE OpenRegister's system identity.
 *
 * A repair step executes during `occ upgrade`, where there is no session, so
 * OpenRegister resolves the actor as 'Anonymous' and REFUSES the write. The
 * refusal is silent: the step reports it with `$output->warning()`, which does
 * not fail an upgrade, so the upgrade prints "Update successful" while nothing
 * was written. An assertion that only counts saved rows cannot see that, because
 * a test double writes happily as anyone — hence the explicit
 * `testSeedsRunUnderTheSystemIdentity` below, which asserts the ELEVATION and not
 * just its result.
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

use OCA\Hermiq\Repair\SeedAiFeatures;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the ai-feature-governance-register SeedAiFeatures repair step.
 *
 * @spec openspec/specs/repair-steps/spec.md
 */
class SeedAiFeaturesTest extends TestCase {

	/**
	 * A stateful ObjectService double keyed by schema, recording every
	 * saveObject() call and every runAsSystem() entry.
	 *
	 * Mirrors SeedSkillCreatorTest's double, plus the identity recording.
	 *
	 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $bySchema): ObjectService {
		return new class($bySchema) extends ObjectService {
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
			 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
			 */
			public function __construct(
				private array $bySchema,
			) {
			}

			/**
			 * Record the elevation AND run the callable.
			 *
			 * ⚠️ Running it is not optional. A double that returns null without
			 * invoking the callable makes a CORRECT step look broken — the seed
			 * body simply never executes and every assertion fails against an
			 * empty store. The real method (`SystemOperationContext::run()`)
			 * invokes and returns.
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
				// assertion: PHP refuses to declare the class and the whole
				// suite dies before it runs.
				bool $failIfExists = false,
				bool $_unowned = false,
			): ObjectEntity {
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
	 * @return SeedAiFeatures
	 */
	private function step(ObjectService $objectService): SeedAiFeatures {
		return new SeedAiFeatures(
			container: $this->container(objectService: $objectService),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end step()

	/**
	 * The step names itself for `occ maintenance:repair` output.
	 *
	 * @return void
	 */
	public function testItNamesItself(): void {
		$step = $this->step(objectService: $this->objectService(['agentaifeature' => []]));

		$this->assertNotSame('', trim($step->getName()));

	}//end testItNamesItself()

	/**
	 * A fresh install seeds all three governance rows, every one DISABLED.
	 *
	 * `lifecycle: disabled` is the whole point of the seed: the feature exists as
	 * a governance record to be acknowledged, not as something switched on by an
	 * upgrade.
	 *
	 * @return void
	 */
	public function testFreshInstallSeedsEveryFeatureDisabled(): void {
		$objectService = $this->objectService(['agentaifeature' => []]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(3, $objectService->saved);

		$slugs = array_map(static fn (array $row): string => (string)$row['object']['slug'], $objectService->saved);
		$this->assertSame(
			['autonomous-agent-run', 'skill-code-execution', 'chat-companion'],
			$slugs
		);

		foreach ($objectService->saved as $row) {
			$this->assertSame('agentaifeature', $row['schema']);
			$this->assertSame('disabled', $row['object']['lifecycle'], 'A seeded AI feature must never arrive enabled.');
			$this->assertSame('', $row['object']['tenantId'], 'The seed is fleet-wide, not tenant-scoped.');
			$this->assertNotSame('', trim((string)$row['object']['name']));
			$this->assertNotSame('', trim((string)$row['object']['description']));
			$this->assertContains($row['object']['riskCategory'], ['high', 'limited']);
		}

	}//end testFreshInstallSeedsEveryFeatureDisabled()

	/**
	 * Every write happens INSIDE the system identity.
	 *
	 * This is the assertion the row count cannot make. `occ upgrade` has no
	 * session; without the elevation OpenRegister answers "User 'Anonymous' does
	 * not have permission to 'create'" and the step reports it with a warning,
	 * which does not fail the upgrade. A double writes happily as anyone, so
	 * "three rows were saved" is true in both the fixed and the broken world —
	 * only the elevation flag tells them apart.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/repair-steps/spec.md
	 */
	public function testSeedsRunUnderTheSystemIdentity(): void {
		$objectService = $this->objectService(['agentaifeature' => []]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertSame(1, $objectService->elevations, 'The step must establish a system identity exactly once.');
		$this->assertNotSame([], $objectService->saved);

		foreach ($objectService->saved as $row) {
			$this->assertTrue($row['elevated'], 'A seed write outside the system identity is refused as Anonymous.');
		}

	}//end testSeedsRunUnderTheSystemIdentity()

	/**
	 * A re-run seeds only what is missing, matched by slug.
	 *
	 * @return void
	 */
	public function testReRunSeedsOnlyTheMissingFeatures(): void {
		$objectService = $this->objectService(
			[
				'agentaifeature' => [
					$this->object('existing-1', ['slug' => 'autonomous-agent-run']),
					$this->object('existing-2', ['slug' => 'chat-companion']),
				],
			]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(1, $objectService->saved);
		$this->assertSame('skill-code-execution', $objectService->saved[0]['object']['slug']);

	}//end testReRunSeedsOnlyTheMissingFeatures()

	/**
	 * A fully seeded instance writes nothing at all on a re-run.
	 *
	 * @return void
	 */
	public function testASeededInstanceIsIdempotent(): void {
		$objectService = $this->objectService(
			[
				'agentaifeature' => [
					$this->object('existing-1', ['slug' => 'autonomous-agent-run']),
					$this->object('existing-2', ['slug' => 'skill-code-execution']),
					$this->object('existing-3', ['slug' => 'chat-companion']),
				],
			]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $objectService->saved);

	}//end testASeededInstanceIsIdempotent()

	/**
	 * A row that is not an ObjectEntity is not a match.
	 *
	 * `findAll()` is typed loosely on the real service, and a non-entity element
	 * must not be read as "the slug already exists" — that would silently skip a
	 * seed forever.
	 *
	 * @return void
	 */
	public function testNonEntityRowsAreNotTreatedAsExisting(): void {
		$objectService = $this->objectService(['agentaifeature' => ['not-an-entity']]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(3, $objectService->saved);

	}//end testNonEntityRowsAreNotTreatedAsExisting()

	/**
	 * A failing write is reported and the remaining features are still attempted.
	 *
	 * A repair step must never abort the rest of the repair pass.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsReportedAndDoesNotAbortTheRest(): void {
		$objectService = new class extends ObjectService {
			/**
			 * Slugs the step tried to save.
			 *
			 * @var array<int, string>
			 */
			public array $attempted = [];

			/**
			 * 🔴 DECLARE A CONSTRUCTOR EVEN WITH NOTHING TO CONSTRUCT.
			 *
			 * Without one this subclass INHERITS the parent's, and the parent is
			 * a different class in each environment: the test stub takes no
			 * arguments, while the real OpenRegister ObjectService takes 38. A
			 * standalone run therefore passes and CI — where the real app is
			 * installed alongside hermiq — raises `ArgumentCountError: Too few
			 * arguments … 0 passed … and exactly 38 expected`. Declaring an empty
			 * one overrides both.
			 *
			 * @return void
			 */
			public function __construct() {
			}

			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return [];
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
				bool $failIfExists = false,
				bool $_unowned = false,
			): ObjectEntity {
				$payload = is_array($object) ? $object : $object->getObject();
				$this->attempted[] = (string)($payload['slug'] ?? '');
				throw new RuntimeException('register unavailable');
			}
		};

		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly(3))->method('warning');

		$this->step(objectService: $objectService)->run(output: $output);

		$this->assertSame(
			['autonomous-agent-run', 'skill-code-execution', 'chat-companion'],
			$objectService->attempted,
			'One failed write must not stop the seeds that follow it.'
		);

	}//end testAFailingWriteIsReportedAndDoesNotAbortTheRest()

	/**
	 * The step no-ops gracefully (never throws) when OpenRegister is absent.
	 *
	 * @return void
	 */
	public function testNoopsWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister not installed'));

		$step = new SeedAiFeatures(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step->run(output: $output);

		$this->addToAssertionCount(1);

	}//end testNoopsWhenOpenRegisterUnavailable()
}//end class
