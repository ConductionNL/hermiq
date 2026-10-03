<?php

/**
 * Unit tests for the SeedMessageTranslationFeature repair step
 * (message-translation-delegate).
 *
 * The seeded `message-translation` AiFeature is a limited-risk EU AI Act
 * governance record. It MUST arrive `lifecycle: disabled` — an upgrade that
 * switched on an AI translation feature without a DPO acknowledgement is the
 * failure this seed exists to prevent, matching
 * `SeedCourseRecommendationFeatureTest`'s reasoning at `riskCategory: limited`
 * instead of `high`.
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
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedMessageTranslationFeature;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the message-translation-delegate SeedMessageTranslationFeature step.
 *
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
 * @spec openspec/changes/ai-translation-provenance/specs/ai-feature-governance/spec.md#requirement-the-register-records-whether-a-features-outputs-are-labelled-as-ai-made
 */
class SeedMessageTranslationFeatureTest extends TestCase {

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
			 * @var array<int, array{schema: string, object: array, elevated: bool, uuid: string|null}>
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
					'uuid' => $uuid,
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
	 * @return SeedMessageTranslationFeature
	 */
	private function step(ObjectService $objectService): SeedMessageTranslationFeature {
		return new SeedMessageTranslationFeature(
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
		$this->assertSame('message-translation', $seeded['slug']);
		$this->assertSame('limited', $seeded['riskCategory'], 'Transparency obligation only — not a high-risk decision system.');
		$this->assertSame('disabled', $seeded['lifecycle'], 'An upgrade must never enable the feature on its own.');
		$this->assertSame('', $seeded['tenantId'], 'The seed is fleet-wide so any DPO can acknowledge it.');
		$this->assertNotSame('', trim((string)$seeded['name']));
		$this->assertNotSame('', trim((string)$seeded['description']));
		$this->assertTrue($seeded['outputsLabelled'], 'The register records that translations are labelled as AI-made.');
		$this->assertStringContainsString('translatedByAi', (string)$seeded['outputLabelling']);
		$this->assertNull($objectService->saved[0]['uuid'], 'A fresh seed creates, it does not update.');

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
	 * A re-run writes nothing once the row exists and records its labelling.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/ai-feature-governance/spec.md#requirement-the-register-records-whether-a-features-outputs-are-labelled-as-ai-made
	 */
	public function testReRunIsIdempotent(): void {
		$objectService = $this->objectService(
			['agentaifeature' => [$this->object('existing-1', ['slug' => 'message-translation', 'outputsLabelled' => true])]]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(0, $objectService->saved);

	}//end testReRunIsIdempotent()

	/**
	 * A row seeded before the labelling fields is back-filled once, under its
	 * own uuid, with every other field (the DPO's enablement included) kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/ai-feature-governance/spec.md#requirement-the-register-records-whether-a-features-outputs-are-labelled-as-ai-made
	 */
	public function testAnOlderRowIsBackfilledOnce(): void {
		$older = [
			'id' => 'existing-1',
			'@self' => ['register' => 'hermiq'],
			'slug' => 'message-translation',
			'name' => 'Message translation',
			'riskCategory' => 'limited',
			'lifecycle' => 'enabled',
			'dpoAckBy' => 'dpo',
			'tenantId' => '',
		];
		$objectService = $this->objectService(['agentaifeature' => [$this->object('existing-1', $older)]]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(1, $objectService->saved);
		$saved = $objectService->saved[0];
		$this->assertSame('existing-1', $saved['uuid'], 'The back-fill updates the row, it does not create a second one.');
		$this->assertTrue($saved['object']['outputsLabelled']);
		$this->assertNotSame('', (string)$saved['object']['outputLabelling']);
		$this->assertSame('enabled', $saved['object']['lifecycle'], 'A back-fill must not move the lifecycle.');
		$this->assertSame('dpo', $saved['object']['dpoAckBy']);
		$this->assertArrayNotHasKey('id', $saved['object']);
		$this->assertArrayNotHasKey('@self', $saved['object']);
		$this->assertTrue($saved['elevated']);

	}//end testAnOlderRowIsBackfilledOnce()

	/**
	 * A back-fill keeps a labelling text a DPO already wrote.
	 *
	 * @return void
	 */
	public function testABackfillKeepsAnExistingLabellingText(): void {
		$objectService = $this->objectService(
			[
				'agentaifeature' => [
					$this->object(
						'existing-1',
						['slug' => 'message-translation', 'outputsLabelled' => false, 'outputLabelling' => 'Our own words.']
					),
				],
			]
		);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		$this->assertCount(1, $objectService->saved);
		$this->assertTrue($objectService->saved[0]['object']['outputsLabelled']);
		$this->assertSame('Our own words.', $objectService->saved[0]['object']['outputLabelling']);

	}//end testABackfillKeepsAnExistingLabellingText()

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
		$this->assertSame('message-translation', $objectService->saved[0]['object']['slug']);

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

		$step = new SeedMessageTranslationFeature(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step->run(output: $output);

		$this->addToAssertionCount(1);

	}//end testNoopsWhenOpenRegisterUnavailable()
}//end class
