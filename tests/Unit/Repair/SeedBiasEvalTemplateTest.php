<?php

/**
 * Unit tests for the SeedBiasEvalTemplate repair step (reuse-scheduled-bias-evals).
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
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedBiasEvalTemplate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectSaveOptions as PublishedObjectSaveOptions;

/**
 * @covers \OCA\Hermiq\Repair\SeedBiasEvalTemplate
 */
class SeedBiasEvalTemplateTest extends TestCase {

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
			/**
			 * The schema the next read is for.
			 *
			 * @var string|null
			 */
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
			 * The rows of the remembered schema.
			 *
			 * @param array $config        Ignored.
			 * @param bool  $_rbac         Ignored.
			 * @param bool  $_multitenancy Ignored.
			 *
			 * @return array
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->bySchema[$this->schema] ?? []);
			}

			/**
			 * Record the save, or fail it.
			 *
			 * @param array|ObjectEntity                           $object        The payload.
			 * @param ObjectSaveOptions|PublishedObjectSaveOptions $options       The target.
			 * @param bool                                         $_rbac         Ignored.
			 * @param bool                                         $_multitenancy Ignored.
			 *
			 * @return ObjectEntity
			 */
			public function saveObject(
				array|ObjectEntity $object,
				ObjectSaveOptions|PublishedObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true
			): ObjectEntity {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$silent = $options->policy->silent ?? false;
				$_validation = $options->policy->validation ?? true;
				$uploadedFiles = $options->uploadedFiles;
				$currentUser = $options->actor->currentUser;
				$failIfExists = $options->policy->failIfExists ?? false;
				$_unowned = $options->actor->unowned ?? false;
				$_dedupOverride = $options->policy->dedupOverride ?? false;
				$_owner = $options->actor->owner;
				if ($this->failWrites === true) {
					throw new RuntimeException('register unavailable');
				}

				$payload = $object;
				if ($object instanceof ObjectEntity) {
					$payload = $object->getObject();
				}

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
	 * The step wired to the given object service.
	 *
	 * @param ObjectService $objectService The object service double.
	 *
	 * @return SeedBiasEvalTemplate
	 */
	private function step(ObjectService $objectService): SeedBiasEvalTemplate {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class) => match ($class) {
				ObjectService::class => $objectService,
				default => throw new RuntimeException("Unexpected service: {$class}"),
			}
		);

		return new SeedBiasEvalTemplate(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end step()

	/**
	 * A fresh install gets one template per language, each written under the system identity.
	 *
	 * @return void
	 */
	public function testAFreshInstallGetsOneTemplatePerLanguage(): void {
		$objectService = $this->objectService(bySchema: ['evaldataset' => []]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(originalClassName: IOutput::class));

		self::assertCount(expectedCount: 2, haystack: $objectService->saved);
		self::assertSame(expected: ['en', 'nl'], actual: array_map(static fn (array $row): string => $row['object']['language'], $objectService->saved));
		foreach ($objectService->saved as $row) {
			self::assertSame(expected: 'evaldataset', actual: $row['schema']);
			self::assertTrue(condition: $row['elevated']);
			self::assertTrue(condition: $row['object']['template']);
			self::assertSame(expected: SeedBiasEvalTemplate::TEMPLATE_KEY, actual: $row['object']['templateKey']);
		}
	}//end testAFreshInstallGetsOneTemplatePerLanguage()

	/**
	 * Seeding twice stores nothing the second time: each template is found by name.
	 *
	 * @return void
	 */
	public function testSeedingTwiceKeepsOneTemplatePerLanguage(): void {
		$stored = array_map(
			static function (array $template): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('tpl-' . $template['language']);
				$entity->setObject($template);
				return $entity;
			},
			SeedBiasEvalTemplate::templates()
		);
		$objectService = $this->objectService(bySchema: ['evaldataset' => $stored]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(originalClassName: IOutput::class));

		self::assertSame(expected: [], actual: $objectService->saved);
	}//end testSeedingTwiceKeepsOneTemplatePerLanguage()

	/**
	 * A write failure is reported, not thrown: an upgrade does not stop on it.
	 *
	 * @return void
	 */
	public function testAWriteFailureIsReportedNotThrown(): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->exactly(count: 2))->method('warning');

		$this->step(objectService: $this->objectService(bySchema: ['evaldataset' => []], failWrites: true))->run(output: $output);
	}//end testAWriteFailureIsReportedNotThrown()

	/**
	 * Each template holds twenty pairs, four per attribute, each with two different requests.
	 *
	 * @return void
	 */
	public function testEachTemplateHoldsTwentyPairsOverTheFiveAttributes(): void {
		$templates = SeedBiasEvalTemplate::templates();
		self::assertCount(expectedCount: 2, haystack: $templates);

		foreach ($templates as $template) {
			self::assertCount(expectedCount: 20, haystack: $template['cases']);
			$perAttribute = [];
			foreach ($template['cases'] as $case) {
				self::assertSame(expected: 'counterfactualPair', actual: $case['expectationType']);
				self::assertSame(expected: $case['inputA'], actual: $case['prompt']);
				self::assertNotSame(expected: $case['inputA'], actual: $case['inputB']);
				$perAttribute[$case['attribute']] = (($perAttribute[$case['attribute']] ?? 0) + 1);
			}

			ksort($perAttribute);
			self::assertSame(expected: ['age' => 4, 'disability' => 4, 'gender' => 4, 'name' => 4, 'origin' => 4], actual: $perAttribute);
		}
	}//end testEachTemplateHoldsTwentyPairsOverTheFiveAttributes()

	/**
	 * The template names are the ones the spec scenario opens.
	 *
	 * @return void
	 */
	public function testTheEnglishTemplateHasTheSpecifiedName(): void {
		self::assertSame(expected: 'Bias check: public services (template)', actual: SeedBiasEvalTemplate::templates()[0]['name']);
	}//end testTheEnglishTemplateHasTheSpecifiedName()
}//end class
