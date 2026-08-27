<?php

/**
 * Unit tests for the SeedCourseRecommendationFeature repair step
 * (ai-course-recommendations).
 *
 * The seeded `course-recommendations` AiFeature is an EU AI Act Annex III §3
 * high-risk governance record. It MUST arrive `lifecycle: disabled` — an upgrade
 * that switched on a high-risk education recommender without a DPO
 * acknowledgement is the failure this seed exists to prevent, so the disabled
 * state is asserted rather than assumed.
 *
 * The elevation assertion carries the rest: a repair step runs during
 * `occ upgrade`, where there is no session, and OpenRegister refuses the write as
 * 'Anonymous'. The step reports that with `$output->warning()`, which does NOT
 * fail an upgrade — so without `testTheSeedRunsUnderTheSystemIdentity` a step
 * that wrote nothing would still look green here.
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

use OCA\Hermiq\Repair\SeedCourseRecommendationFeature;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the ai-course-recommendations SeedCourseRecommendationFeature step.
 *
 * @spec openspec/specs/repair-steps/spec.md
 */
class SeedCourseRecommendationFeatureTest extends TestCase {

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
			 * (`SystemOperationContext::run()`) invokes and returns, and a double
			 * that only returned null would make a CORRECT step look broken.
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
	 * @return SeedCourseRecommendationFeature
	 */
	private function step(ObjectService $objectService): SeedCourseRecommendationFeature {
		return new SeedCourseRecommendationFeature(
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
	 * A fresh install seeds the high-risk feature, DISABLED and fleet-wide.
	 *
	 * @return void
	 */
	public function testFreshInstallSeedsTheFeatureDisabled(): void {
		$objectService = $this->objectService(['agentaifeature' => []]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(1, $objectService->saved);

		$seeded = $objectService->saved[0]['object'];
		$this->assertSame('agentaifeature', $objectService->saved[0]['schema']);
		$this->assertSame('course-recommendations', $seeded['slug']);
		$this->assertSame('high', $seeded['riskCategory'], 'Annex III §3 education recommender — high risk.');
		$this->assertSame('disabled', $seeded['lifecycle'], 'An upgrade must never enable a high-risk feature.');
		$this->assertSame('', $seeded['tenantId'], 'The seed is fleet-wide so any DPO can acknowledge it.');
		$this->assertNotSame('', trim((string)$seeded['name']));
		$this->assertNotSame('', trim((string)$seeded['description']));

	}//end testFreshInstallSeedsTheFeatureDisabled()

	/**
	 * The write happens INSIDE the system identity.
	 *
	 * `occ upgrade` has no session, so an unelevated write is refused as
	 * 'Anonymous' and reported with a warning that does not fail the upgrade. A
	 * saved-row count is true in both the fixed and the broken world; the
	 * elevation flag is what separates them.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/repair-steps/spec.md
	 */
	public function testTheSeedRunsUnderTheSystemIdentity(): void {
		$objectService = $this->objectService(['agentaifeature' => []]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertSame(1, $objectService->elevations);
		$this->assertCount(1, $objectService->saved);
		$this->assertTrue($objectService->saved[0]['elevated']);

	}//end testTheSeedRunsUnderTheSystemIdentity()

	/**
	 * A re-run writes nothing once the slug exists.
	 *
	 * @return void
	 */
	public function testReRunIsIdempotent(): void {
		$objectService = $this->objectService(
			['agentaifeature' => [$this->object('existing-1', ['slug' => 'course-recommendations'])]]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $objectService->saved);

	}//end testReRunIsIdempotent()

	/**
	 * A different feature's row does not satisfy this seed.
	 *
	 * The existence check filters by slug, but the real `findAll()` filter is
	 * advisory — the step re-checks each returned payload. A row for another
	 * feature must therefore NOT read as "already seeded".
	 *
	 * @return void
	 */
	public function testAnotherFeaturesRowDoesNotSatisfyTheSeed(): void {
		$objectService = $this->objectService(
			[
				'agentaifeature' => [
					$this->object('existing-1', ['slug' => 'chat-companion']),
					'not-an-entity',
				],
			]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(1, $objectService->saved);
		$this->assertSame('course-recommendations', $objectService->saved[0]['object']['slug']);

	}//end testAnotherFeaturesRowDoesNotSatisfyTheSeed()

	/**
	 * A failing write warns and returns — it never aborts the repair pass.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsReportedAndNotThrown(): void {
		$objectService = $this->objectService(['agentaifeature' => []], failWrites: true);

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

		$step = new SeedCourseRecommendationFeature(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step->run(output: $output);

		$this->addToAssertionCount(1);

	}//end testNoopsWhenOpenRegisterUnavailable()
}//end class
