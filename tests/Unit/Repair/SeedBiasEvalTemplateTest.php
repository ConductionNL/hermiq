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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
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
				ObjectSaveOptions $options = new ObjectSaveOptions(),
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
	 * The step wired to the given object service.
	 *
	 * @param ObjectService $objectService The object service double.
	 *
	 * @return SeedBiasEvalTemplate
	 */
	private function step(ObjectService $objectService): SeedBiasEvalTemplate {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class) => match ($class) {
				ObjectService::class => $objectService,
				default => throw new RuntimeException("Unexpected service: {$class}"),
			}
		);

		return new SeedBiasEvalTemplate(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * A fresh install gets one template per language, each written under the system identity.
	 *
	 * @return void
	 */
	public function testAFreshInstallGetsOneTemplatePerLanguage(): void {
		$objectService = $this->objectService(['evaldataset' => []]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		self::assertCount(2, $objectService->saved);
		self::assertSame(['en', 'nl'], array_map(static fn (array $row): string => $row['object']['language'], $objectService->saved));
		foreach ($objectService->saved as $row) {
			self::assertSame('evaldataset', $row['schema']);
			self::assertTrue($row['elevated']);
			self::assertTrue($row['object']['template']);
			self::assertSame(SeedBiasEvalTemplate::TEMPLATE_KEY, $row['object']['templateKey']);
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
		$objectService = $this->objectService(['evaldataset' => $stored]);

		$this->step(objectService: $objectService)->run(output: $this->createMock(IOutput::class));

		self::assertSame([], $objectService->saved);
	}//end testSeedingTwiceKeepsOneTemplatePerLanguage()

	/**
	 * A write failure is reported, not thrown: an upgrade does not stop on it.
	 *
	 * @return void
	 */
	public function testAWriteFailureIsReportedNotThrown(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly(2))->method('warning');

		$this->step(objectService: $this->objectService(['evaldataset' => []], failWrites: true))->run(output: $output);
	}//end testAWriteFailureIsReportedNotThrown()

	/**
	 * Each template holds twenty pairs, four per attribute, each with two different requests.
	 *
	 * @return void
	 */
	public function testEachTemplateHoldsTwentyPairsOverTheFiveAttributes(): void {
		$templates = SeedBiasEvalTemplate::templates();
		self::assertCount(2, $templates);

		foreach ($templates as $template) {
			self::assertCount(20, $template['cases']);
			$perAttribute = [];
			foreach ($template['cases'] as $case) {
				self::assertSame('counterfactualPair', $case['expectationType']);
				self::assertSame($case['inputA'], $case['prompt']);
				self::assertNotSame($case['inputA'], $case['inputB']);
				$perAttribute[$case['attribute']] = (($perAttribute[$case['attribute']] ?? 0) + 1);
			}

			ksort($perAttribute);
			self::assertSame(['age' => 4, 'disability' => 4, 'gender' => 4, 'name' => 4, 'origin' => 4], $perAttribute);
		}
	}//end testEachTemplateHoldsTwentyPairsOverTheFiveAttributes()

	/**
	 * The template names are the ones the spec scenario opens.
	 *
	 * @return void
	 */
	public function testTheEnglishTemplateHasTheSpecifiedName(): void {
		self::assertSame('Bias check: public services (template)', SeedBiasEvalTemplate::templates()[0]['name']);
	}//end testTheEnglishTemplateHasTheSpecifiedName()
}//end class
