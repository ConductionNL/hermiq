<?php

/**
 * Unit tests for the SeedSkillDraftExample repair step.
 *
 * The step seeds one pending consolidation draft for the tender-summary skill,
 * with its approval linked back onto the draft, and is a no-op when the skill
 * is absent, a draft already exists, or OpenRegister is unavailable.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedSkillDraftExample;
use OCA\Hermiq\Service\SkillConsolidationService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SeedSkillDraftExample.
 *
 * @covers \OCA\Hermiq\Repair\SeedSkillDraftExample
 * @uses   \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity
 */
class SeedSkillDraftExampleTest extends TestCase {

	private const BODY = "# Tender summary\n\n1. Extract the essentials: contracting authority, CPV codes, lot structure,\n   estimated value, submission deadline, award criteria and their weights.\n2. Write it up.";

	/**
	 * A stateful ObjectService double keyed by schema, recording every save.
	 *
	 * @param array<string, array<int, mixed>> $bySchema Schema slug → objects.
	 * @param ?string                          $failOn   Schema whose save throws.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $bySchema, ?string $failOn = null): ObjectService {
		return new class($bySchema, $failOn) extends ObjectService {
			private ?string $schema = null;

			/**
			 * @var array<int, array{schema: string, uuid: ?string, object: array}>
			 */
			public array $saved = [];

			/**
			 * @var array<int, array{schema: ?string, config: array}>
			 */
			public array $queries = [];

			/**
			 * @param array<string, array<int, mixed>> $bySchema Schema slug → objects.
			 * @param ?string                          $failOn   Schema whose save throws.
			 */
			public function __construct(
				private array $bySchema,
				private ?string $failOn,
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
				$this->queries[] = ['schema' => $this->schema, 'config' => $config];
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
				?string $_owner = null,
			): ObjectEntity {
				if ($schema === $this->failOn) {
					throw new RuntimeException('register is read-only');
				}
				$payload = is_array($object) ? $object : $object->getObject();
				$this->saved[] = ['schema' => (string)$schema, 'uuid' => $uuid, 'object' => $payload];

				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? ($schema . '-' . count($this->saved)));
				$entity->setObject($payload + ['id' => 9, 'uuid' => 'x', '@self' => []]);
				return $entity;
			}
		};
	}

	/**
	 * An object with the given uuid and payload.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $payload): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid($uuid);
		$e->setObject($payload);
		return $e;
	}

	/**
	 * A container resolving the given services, throwing for any other.
	 *
	 * @param array<string, object> $services Class → instance.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class) => ($services[$class] ?? throw new RuntimeException("Unexpected service: {$class}"))
		);
		return $container;
	}

	/**
	 * A URL generator prefixing the host.
	 *
	 * @return IURLGenerator
	 */
	private function urls(): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $p): string => 'https://cloud.test' . $p);
		return $urls;
	}

	/**
	 * The tender-summary skill as seeded.
	 *
	 * @return ObjectEntity
	 */
	private function skill(): ObjectEntity {
		return $this->object('skill-1', ['name' => 'tender-summary', 'frontmatter' => 'fm', 'body' => self::BODY, 'files' => ['a.md']]);
	}

	/**
	 * Run the step and return the info lines it wrote.
	 *
	 * @param SeedSkillDraftExample $step    The step.
	 * @param list<string>          $warning Collected warnings (by reference).
	 *
	 * @return list<string>
	 */
	private function runStep(SeedSkillDraftExample $step, array &$warning = []): array {
		$info = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $m) use (&$info): void {
			$info[] = $m;
		});
		$output->method('warning')->willReturnCallback(static function (string $m) use (&$warning): void {
			$warning[] = $m;
		});
		$step->run($output);
		return $info;
	}

	/**
	 * The step has a human-readable name.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new SeedSkillDraftExample($this->createMock(ContainerInterface::class), $this->urls(), $this->createMock(LoggerInterface::class));
		self::assertSame('Seed a pending skill consolidation draft example (skill-self-improvement)', $step->getName());
	}

	/**
	 * A fresh install seeds the draft, its approval, and links the approval onto the draft.
	 *
	 * @return void
	 */
	public function testSeedsADraftAndItsApproval(): void {
		$objects = $this->objectService(['agentskill' => [$this->skill(), 'not an entity']]);
		$consolidation = $this->createMock(SkillConsolidationService::class);
		$consolidation->expects(self::once())->method('drivingEntries')
			->with(self::callback(static fn (array $d): bool => $d['name'] === 'tender-summary'))
			->willReturn([['ref' => 'r1'], ['ref' => 'r2'], ['ref' => 'r3'], ['ref' => 'r4']]);
		$versions = $this->createMock(SkillVersionService::class);
		$versions->method('currentVersionId')->with('skill-1')->willReturn('v-3');
		$step = new SeedSkillDraftExample(
			$this->container([
				ObjectService::class => $objects,
				SkillConsolidationService::class => $consolidation,
				SkillVersionService::class => $versions,
			]),
			$this->urls(),
			$this->createMock(LoggerInterface::class)
		);

		self::assertSame(['Skill draft example seed complete.'], $this->runStep($step));

		self::assertSame(['agentskilldraft', 'approval', 'agentskilldraft'], array_column($objects->saved, 'schema'));
		$draft = $objects->saved[0]['object'];
		self::assertSame('skill-1', $draft['skillId']);
		self::assertSame('v-3', $draft['baseVersionId']);
		self::assertSame('awaiting-approval', $draft['status']);
		self::assertSame('threshold', $draft['trigger']);
		self::assertSame('fm', $draft['proposedFrontmatter']);
		self::assertSame(['a.md'], $draft['proposedFiles']);
		self::assertSame(['r1', 'r2', 'r3'], $draft['provenance']['learningRefs']);
		self::assertSame('', $draft['approvalId']);
		self::assertStringContainsString('quote the award-criteria weights verbatim', $draft['proposedBody']);
		self::assertStringEndsWith("## Exemption note\n\n- When a self-cleaning claim is present, record it verbatim under the exclusion check — note it, never judge it.\n", $draft['proposedBody']);

		$approval = $objects->saved[1]['object'];
		self::assertSame('pending', $approval['status']);
		self::assertSame('skill-draft', $approval['sourceType']);
		self::assertSame('agentskilldraft-1', $approval['draftId']);
		self::assertSame('skill-1', $approval['skillId']);
		self::assertSame('https://cloud.test/index.php/apps/hermiq/skills/skill-1', $approval['draftPayload']['deepLink']);

		self::assertSame('agentskilldraft-1', $objects->saved[2]['uuid']);
		$linked = $objects->saved[2]['object'];
		self::assertSame('approval-2', $linked['approvalId']);
		self::assertArrayNotHasKey('id', $linked);
		self::assertArrayNotHasKey('uuid', $linked);
		self::assertArrayNotHasKey('@self', $linked);
	}

	/**
	 * Without learnings or the version service the draft still seeds, with placeholders.
	 *
	 * @return void
	 */
	public function testFallsBackToPlaceholderRefsAndNoBaseVersion(): void {
		$objects = $this->objectService(['agentskill' => [$this->skill()]]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')
			->with(self::stringStartsWith('[hermiq] draft seed: falling back to placeholder learnings refs: Unexpected service'));
		$step = new SeedSkillDraftExample($this->container([ObjectService::class => $objects]), $this->urls(), $logger);

		$this->runStep($step);

		$draft = $objects->saved[0]['object'];
		self::assertSame('', $draft['baseVersionId']);
		$refs = $draft['provenance']['learningRefs'];
		self::assertCount(3, $refs);
		foreach ($refs as $i => $ref) {
			self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-0000000' . $i . '$/', $ref);
		}
	}

	/**
	 * No driving learnings also falls back to placeholders.
	 *
	 * @return void
	 */
	public function testNoDrivingEntriesFallsBackToo(): void {
		$objects = $this->objectService(['agentskill' => [$this->skill()]]);
		$consolidation = $this->createMock(SkillConsolidationService::class);
		$consolidation->method('drivingEntries')->willReturn([]);
		$step = new SeedSkillDraftExample(
			$this->container([ObjectService::class => $objects, SkillConsolidationService::class => $consolidation]),
			$this->urls(),
			$this->createMock(LoggerInterface::class)
		);

		$this->runStep($step);

		self::assertStringEndsWith('-00000002', $objects->saved[0]['object']['provenance']['learningRefs'][2]);
	}

	/**
	 * An existing draft for the skill makes the run a no-op.
	 *
	 * @return void
	 */
	public function testAnExistingDraftIsKept(): void {
		$objects = $this->objectService([
			'agentskill' => [$this->skill()],
			'agentskilldraft' => [$this->object('d-1', ['skillId' => 'skill-1'])],
		]);
		$step = new SeedSkillDraftExample($this->container([ObjectService::class => $objects]), $this->urls(), $this->createMock(LoggerInterface::class));

		self::assertSame(['Skill draft example already present — skipped.'], $this->runStep($step));
		self::assertSame([], $objects->saved);
		self::assertSame(['filters' => ['skillId' => 'skill-1'], 'limit' => 50], $objects->queries[1]['config']);
	}

	/**
	 * A draft of another skill returned by the filter does not count.
	 *
	 * @return void
	 */
	public function testADraftOfAnotherSkillDoesNotCount(): void {
		$objects = $this->objectService([
			'agentskill' => [$this->skill()],
			'agentskilldraft' => [$this->object('d-1', ['skillId' => 'skill-9']), 'junk'],
		]);
		$step = new SeedSkillDraftExample($this->container([ObjectService::class => $objects]), $this->urls(), $this->createMock(LoggerInterface::class));

		self::assertSame(['Skill draft example seed complete.'], $this->runStep($step));
		self::assertCount(3, $objects->saved);
	}

	/**
	 * Without the tender-summary skill nothing is seeded; a similarly named skill does not count.
	 *
	 * @return void
	 */
	public function testAnAbsentSkillSkips(): void {
		$objects = $this->objectService(['agentskill' => [$this->object('s', ['name' => 'tender-summary-v2'])]]);
		$step = new SeedSkillDraftExample($this->container([ObjectService::class => $objects]), $this->urls(), $this->createMock(LoggerInterface::class));

		self::assertSame(['tender-summary seed skill absent — skipping draft example seed.'], $this->runStep($step));
		self::assertSame([], $objects->saved);
		self::assertSame(['filters' => ['name' => 'tender-summary'], 'limit' => 50], $objects->queries[0]['config']);
	}

	/**
	 * A failing save is reported as a warning and logged, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingSaveBecomesAWarning(): void {
		$objects = $this->objectService(['agentskill' => [$this->skill()]], 'approval');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('[hermiq] skill draft example seed failed: register is read-only');
		$step = new SeedSkillDraftExample($this->container([ObjectService::class => $objects]), $this->urls(), $logger);

		$warnings = [];
		self::assertSame([], $this->runStep($step, $warnings));
		self::assertSame(['Could not seed the skill draft example: register is read-only'], $warnings);
	}

	/**
	 * Without OpenRegister the step warns and seeds nothing.
	 *
	 * @return void
	 */
	public function testSkipsWithoutOpenRegister(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('[hermiq] skill draft example seed skipped: Unexpected service: ' . ObjectService::class);
		$step = new SeedSkillDraftExample($this->container([]), $this->urls(), $logger);

		$warnings = [];
		self::assertSame([], $this->runStep($step, $warnings));
		self::assertSame(['OpenRegister not available — skipping skill draft example seed.'], $warnings);
	}
}
