<?php

/**
 * Unit tests for the SeedLessonAuthoringFeature repair step
 * (lesson-authoring-ai-delegate).
 *
 * The seeded `lesson-authoring` AiFeature is a limited-risk EU AI Act
 * governance record. It MUST arrive `lifecycle: disabled`: an upgrade that
 * switched on an AI authoring feature without a DPO acknowledgement is the
 * failure this seed exists to prevent. Same reasoning as
 * `SeedCourseRecommendationFeatureTest`, at `riskCategory: limited` instead
 * of `high`, because the actions draft content and decide nothing about a pupil.
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
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-001-lesson-authoring-is-gated-by-a-disabled-by-default-aifeature
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedLessonAuthoringFeature;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the lesson-authoring-ai-delegate SeedLessonAuthoringFeature step.
 *
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-001-lesson-authoring-is-gated-by-a-disabled-by-default-aifeature
 */
class SeedLessonAuthoringFeatureTest extends TestCase {

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
			 * Record the elevation AND run the callable.
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
				bool $failIfExists = false,
				bool $_unowned = false,
				bool $_dedupOverride = false,
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
	 * @return SeedLessonAuthoringFeature
	 */
	private function step(ObjectService $objectService): SeedLessonAuthoringFeature {
		return new SeedLessonAuthoringFeature(
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
	 * A fresh install seeds the limited-risk feature, DISABLED and fleet-wide.
	 *
	 * @return void
	 */
	public function testFreshInstallSeedsTheFeatureDisabled(): void {
		$objectService = $this->objectService(['agentaifeature' => []]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(1, $objectService->saved);

		$seeded = $objectService->saved[0]['object'];
		$this->assertSame('agentaifeature', $objectService->saved[0]['schema']);
		$this->assertSame('lesson-authoring', $seeded['slug']);
		$this->assertSame('limited', $seeded['riskCategory'], 'It drafts content and decides nothing about a pupil, so not a high-risk system.');
		$this->assertSame('disabled', $seeded['lifecycle'], 'An upgrade must never enable the feature on its own.');
		$this->assertSame('', $seeded['tenantId'], 'The seed is fleet-wide so any DPO can acknowledge it.');
		$this->assertNotSame('', trim((string)$seeded['name']));
		$this->assertNotSame('', trim((string)$seeded['description']));
		$this->assertStringContainsString('never pupil data', (string)$seeded['description']);
		$this->assertStringContainsString('No pupil data', (string)$seeded['dataBronnen']);
		$this->assertStringContainsString('draft', (string)$seeded['humanIntervention']);
		$this->assertNotSame('', trim((string)$seeded['doel']));
		$this->assertArrayNotHasKey(
			'requiresRedaction',
			$seeded,
			'This delegate reads no filinq document, so a redaction requirement would claim a check that never runs.'
		);

	}//end testFreshInstallSeedsTheFeatureDisabled()

	/**
	 * The write happens INSIDE the system identity.
	 *
	 * @return void
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
			['agentaifeature' => [$this->object('existing-1', ['slug' => 'lesson-authoring'])]]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $objectService->saved);

	}//end testReRunIsIdempotent()

	/**
	 * A different feature's row does not satisfy this seed.
	 *
	 * @return void
	 */
	public function testAnotherFeaturesRowDoesNotSatisfyTheSeed(): void {
		$objectService = $this->objectService(
			[
				'agentaifeature' => [
					$this->object('existing-1', ['slug' => 'course-recommendations']),
					'not-an-entity',
				],
			]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(1, $objectService->saved);
		$this->assertSame('lesson-authoring', $objectService->saved[0]['object']['slug']);

	}//end testAnotherFeaturesRowDoesNotSatisfyTheSeed()

	/**
	 * A failing write warns and returns. It never aborts the repair pass.
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

		$step = new SeedLessonAuthoringFeature(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step->run(output: $output);

		$this->addToAssertionCount(1);

	}//end testNoopsWhenOpenRegisterUnavailable()
}//end class
