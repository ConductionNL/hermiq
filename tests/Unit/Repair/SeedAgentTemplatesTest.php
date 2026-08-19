<?php

/**
 * Unit tests for the SeedAgentTemplates repair step (agent-template-gallery).
 *
 * Covers the fresh-install seed (5 starter templates: Morning briefing, Inbox triage,
 * Website/monitor watcher, Weekly report, Meeting-notes summariser) and idempotency: a
 * re-run does not duplicate an already-seeded template (matched by `name`), preserving an
 * admin's edit.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-6-seedagenttemplates-repair-step
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedAgentTemplates;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the agent-template-gallery SeedAgentTemplates repair step.
 *
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-6-seedagenttemplates-repair-step
 */
class SeedAgentTemplatesTest extends TestCase {

	/**
	 * A stateful ObjectService test double keyed by schema, recording every
	 * saveObject() call (mirrors SeedComplianceControlsTest's precedent).
	 *
	 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $bySchema): ObjectService {
		return new class($bySchema) extends ObjectService {
			private ?string $schema = null;

			/**
			 * @var array<int, array{schema: string, object: array}>
			 */
			public array $saved = [];

			/**
			 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
			 */
			public function __construct(
				private array $bySchema,
			) {
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
				$this->saved[] = ['schema' => (string)$schema, 'object' => $payload];

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
	 * A fresh install seeds all 5 starter templates, each active/local.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-a-fresh-install-ships-seeded-starter-templates
	 */
	public function testFreshInstallSeedsFiveTemplates(): void {
		$objectService = $this->objectService(['agenttemplate' => []]);

		$step = new SeedAgentTemplates(
			container: $this->container(objectService: $objectService),
			logger: $this->createMock(LoggerInterface::class),
		);

		$step->run(output: $this->createMock(IOutput::class));

		$this->assertCount(5, $objectService->saved);

		$names = array_map(static fn (array $s) => $s['object']['name'], $objectService->saved);
		$this->assertSame(
			[
				'Morning briefing',
				'Inbox triage',
				'Website/monitor watcher',
				'Weekly report',
				'Meeting-notes summariser',
			],
			array_values($names)
		);

		foreach ($objectService->saved as $entry) {
			$this->assertSame('active', $entry['object']['state']);
			$this->assertSame('local', $entry['object']['source']);
		}

	}//end testFreshInstallSeedsFiveTemplates()

	/**
	 * A re-run does not duplicate an already-seeded template (matched by name) and
	 * preserves an admin's edit to it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-a-fresh-install-ships-seeded-starter-templates
	 */
	public function testReRunIsIdempotentAndPreservesEdits(): void {
		$edited = $this->object(
			'existing-1',
			['name' => 'Morning briefing', 'systemPrompt' => 'An admin edited this prompt.', 'state' => 'active', 'source' => 'local']
		);

		$objectService = $this->objectService(['agenttemplate' => [$edited]]);

		$step = new SeedAgentTemplates(
			container: $this->container(objectService: $objectService),
			logger: $this->createMock(LoggerInterface::class),
		);

		$step->run(output: $this->createMock(IOutput::class));

		// Morning briefing already exists — skipped; the remaining 4 are seeded.
		$this->assertCount(4, $objectService->saved);

		$names = array_map(static fn (array $s) => $s['object']['name'], $objectService->saved);
		$this->assertNotContains('Morning briefing', $names);

	}//end testReRunIsIdempotentAndPreservesEdits()

	/**
	 * The step no-ops gracefully (never throws) when OpenRegister is not available.
	 *
	 * @return void
	 */
	public function testNoopsWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister not installed'));

		$step = new SeedAgentTemplates(container: $container, logger: $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step->run(output: $output);

		$this->addToAssertionCount(1);

	}//end testNoopsWhenOpenRegisterUnavailable()
}//end class
