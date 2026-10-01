<?php

/**
 * Unit tests for the SeedRecordSummary repair step (agents-bound-to-their-app, task 5).
 *
 * The prompt is seeded through the REAL AssistantPromptLibrary over an
 * in-memory ObjectService, so the seed is proven against the library's own
 * rule that an administered prompt is never restored.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedRecordSummary;
use OCA\Hermiq\Service\Assistant\AssistantPromptLibrary;
use OCA\Hermiq\Service\Assistant\RecordSummaryService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The record-summary AI feature and its prompt are seeded once, under a system identity.
 */
final class SeedRecordSummaryTest extends TestCase {

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
	 * A stored object.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $payload The data.
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
	 * Run the step over an in-memory ObjectService.
	 *
	 * @param ObjectService $objectService The object service.
	 *
	 * @return void
	 */
	private function runStep(ObjectService $objectService): void {
		$library = new AssistantPromptLibrary(
			objectService: $objectService,
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			logger: $this->createMock(LoggerInterface::class)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class) => match ($class) {
				ObjectService::class => $objectService,
				AssistantPromptLibrary::class => $library,
				default => throw new RuntimeException("Unexpected service: {$class}"),
			}
		);

		$step = new SeedRecordSummary(container: $container, logger: $this->createMock(LoggerInterface::class));
		self::assertNotSame('', trim($step->getName()));
		$step->run(output: $this->createMock(IOutput::class));
	}//end runStep()

	/**
	 * The saves made to one schema.
	 *
	 * @param ObjectService $objectService The object service.
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedTo(ObjectService $objectService, string $schema): array {
		return array_values(array_filter($objectService->saved, static fn (array $s): bool => $s['schema'] === $schema));
	}//end savedTo()

	public function testAFreshInstallSeedsTheFeatureOffAndTheShippedPrompt(): void {
		$objectService = $this->objectService(['agentaifeature' => [], 'agentassistantprompt' => []]);

		$this->runStep(objectService: $objectService);

		$features = $this->savedTo(objectService: $objectService, schema: 'agentaifeature');
		self::assertCount(1, $features);
		self::assertTrue($features[0]['elevated'], 'an upgrade has no session: the write runs as the system');
		self::assertSame('record-summary', $features[0]['object']['slug']);
		self::assertSame('limited', $features[0]['object']['riskCategory']);
		self::assertSame('disabled', $features[0]['object']['lifecycle'], 'a DPO enables it, as every other feature');
		self::assertTrue($features[0]['object']['outputsLabelled']);
		self::assertTrue($this->valid(schema: 'AiFeature', payload: $features[0]['object']));

		$prompts = $this->savedTo(objectService: $objectService, schema: 'agentassistantprompt');
		self::assertCount(1, $prompts);
		self::assertSame('record-summary', $prompts[0]['object']['usageScope']);
		self::assertSame(RecordSummaryService::DEFAULT_PROMPT, $prompts[0]['object']['prompt']);
		self::assertSame('hermiq', $prompts[0]['object']['source']);
		self::assertTrue($this->valid(schema: 'AssistantPrompt', payload: $prompts[0]['object']));
	}//end testAFreshInstallSeedsTheFeatureOffAndTheShippedPrompt()

	public function testAnExistingFeatureAndAnAdministeredPromptAreLeftAlone(): void {
		$objectService = $this->objectService(
			[
				'agentaifeature' => [$this->object('f1', ['slug' => 'record-summary', 'lifecycle' => 'enabled'])],
				'agentassistantprompt' => [$this->object('p1', ['label' => 'Record summary', 'prompt' => 'Our own words.', 'usageScope' => 'record-summary', 'administered' => true])],
			]
		);

		$this->runStep(objectService: $objectService);

		self::assertSame([], $objectService->saved, 'the DPO\'s switch and the administrator\'s text stay as they are');
	}//end testAnExistingFeatureAndAnAdministeredPromptAreLeftAlone()

	public function testAFailingRegisterDoesNotBreakTheUpgrade(): void {
		$objectService = $this->objectService(['agentaifeature' => [], 'agentassistantprompt' => []], failWrites: true);

		$this->runStep(objectService: $objectService);

		self::assertSame([], $objectService->saved);
	}//end testAFailingRegisterDoesNotBreakTheUpgrade()

	public function testTheValidatorRefusesAFeatureWithAnUnknownRisk(): void {
		self::assertFalse($this->valid(schema: 'AiFeature', payload: ['slug' => 'record-summary', 'name' => 'x', 'riskCategory' => 'whatever', 'lifecycle' => 'disabled']), 'negative control');
	}//end testTheValidatorRefusesAFeatureWithAnUnknownRisk()

	/**
	 * Validate a payload against a real register fragment.
	 *
	 * @param string $schema The schema key.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool
	 */
	private function valid(string $schema, array $payload): bool {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
		$fragment = $register->components->schemas->{$schema};
		unset($fragment->authorization);
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $fragment)->isValid();
	}//end valid()
}//end class
