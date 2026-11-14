<?php

/**
 * Unit tests for the SeedMaturityExampleSkills repair step's run path.
 *
 * Covers the unavailable-OpenRegister skip, first-time seeding of all three
 * example skills, the freshness refresh of an existing system-owned seed, the
 * skip of a seed someone else owns, and the per-skill failure report.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedMaturityExampleSkills;
use OCA\Hermiq\Service\SeedFreshnessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SeedMaturityExampleSkills::run().
 *
 * @covers \OCA\Hermiq\Repair\SeedMaturityExampleSkills
 * @uses   \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity
 * @uses   \OCA\Hermiq\Service\SeedFreshnessService
 * @uses   \OCA\Hermiq\Service\NamedObjectRows
 * @uses   \OCA\Hermiq\Repair\Seed\LearningsSeedTarget
 */
class SeedMaturityExampleSkillsRunTest extends TestCase {

	/**
	 * Output lines, as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $lines = [];

	/**
	 * A stored skill.
	 *
	 * @param string               $uuid  The skill uuid.
	 * @param string|null          $owner The owner.
	 * @param array<string, mixed> $data  The skill body.
	 *
	 * @return ObjectEntity
	 */
	private function skill(string $uuid, ?string $owner, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject($data);
		return $entity;
	}//end skill()

	/**
	 * An ObjectService double serving stored skills by name and recording saves.
	 *
	 * @param array<string, ObjectEntity> $byName    Skill name → stored skill.
	 * @param array<int, string>          $failNames Names whose save throws.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $byName, array $failNames = []): ObjectService {
		return new class($byName, $failNames) extends ObjectService {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

			public int $systemRuns = 0;

			/**
			 * @param array<string, ObjectEntity> $byName    Stored skills.
			 * @param array<int, string>          $failNames Failing names.
			 */
			public function __construct(private array $byName, private array $failNames) {
			}

			public function runAsSystem(callable $operation): mixed {
				$this->systemRuns++;
				return $operation();
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = ['config' => $config, 'rbac' => $_rbac, 'mt' => $_multitenancy];
				$name = $config['filters']['name'] ?? '';
				// A same-named row of another name first: the step must pick by name.
				$decoy = new ObjectEntity();
				$decoy->setObject(['name' => $name . '-copy']);
				return isset($this->byName[$name]) === true ? [$decoy, $this->byName[$name]] : [$decoy];
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
				if (in_array($object['name'] ?? '', $this->failNames, true) === true) {
					throw new RuntimeException('schema rejected');
				}

				$this->saved[] = [
					'object'   => $object,
					'register' => $register,
					'schema'   => $schema,
					'uuid'     => $uuid,
					'rbac'     => $_rbac,
					'mt'       => $_multitenancy,
				];
				return new ObjectEntity();
			}
		};
	}//end objectService()

	/**
	 * Build the step.
	 *
	 * @param mixed           $service The ObjectService, or a Throwable the container throws.
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @return SeedMaturityExampleSkills
	 */
	private function step(mixed $service, LoggerInterface $logger): SeedMaturityExampleSkills {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturnCallback(
			static function () use ($service): mixed {
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		return new SeedMaturityExampleSkills($container, $logger, new SeedFreshnessService());
	}//end step()

	/**
	 * A repair output double recording every line.
	 *
	 * @return IOutput
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->lines[] = [$level, $message];
				}
			);
		}

		return $output;
	}//end repairOutput()

	/**
	 * Without OpenRegister the step warns and returns.
	 *
	 * @return void
	 */
	public function testUnavailableOpenRegisterIsSkipped(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[hermiq] maturity example skills seed skipped: no openregister');

		$step = $this->step(new RuntimeException('no openregister'), $logger);
		$this->assertSame('Seed maturity example skills (skill-maturity)', $step->getName());
		$step->run($this->repairOutput());

		$this->assertSame(
			[['warning', 'OpenRegister not available — skipping maturity example skills seed.']],
			$this->lines
		);
	}//end testUnavailableOpenRegisterIsSkipped()

	/**
	 * A fresh install gets all three seeds, each stamped with lastActivityAt
	 * and written without RBAC or multitenancy and without a uuid.
	 *
	 * @return void
	 */
	public function testAFreshInstallGetsAllThreeSeedsStampedFresh(): void {
		$service = $this->objectService([]);

		$this->step($service, $this->createMock(LoggerInterface::class))->run($this->repairOutput());

		$this->assertSame(1, $service->systemRuns);
		$this->assertSame(
			['meeting-notes-cleanup', 'woo-request-triage', 'tender-summary'],
			array_map(static fn (array $save): string => $save['object']['name'], $service->saved)
		);
		$seeds = SeedMaturityExampleSkills::seedSkills();
		foreach ($service->saved as $index => $save) {
			$this->assertSame('hermiq', $save['register']);
			$this->assertSame('agentskill', $save['schema']);
			$this->assertNull($save['uuid']);
			$this->assertFalse($save['rbac']);
			$this->assertFalse($save['mt']);
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $save['object']['lastActivityAt']);
			$stamped = $save['object'];
			unset($stamped['lastActivityAt']);
			$this->assertSame($seeds[$index], $stamped);
		}

		$this->assertSame(
			[
				['info', 'meeting-notes-cleanup seed complete.'],
				['info', 'woo-request-triage seed complete.'],
				['info', 'tender-summary seed complete.'],
			],
			$this->lines
		);
		$this->assertSame(
			['filters' => ['name' => 'meeting-notes-cleanup'], 'limit' => 50],
			$service->queries[0]['config']
		);
		$this->assertFalse($service->queries[0]['rbac']);
		$this->assertFalse($service->queries[0]['mt']);
	}//end testAFreshInstallGetsAllThreeSeedsStampedFresh()

	/**
	 * An existing system-owned stale seed is refreshed in place (and made
	 * active); a seed a person owns is left alone; a tender-summary seed that
	 * already carries its learnings files is skipped.
	 *
	 * @return void
	 */
	public function testExistingSeedsAreRefreshedOrLeftAlone(): void {
		$tender = SeedMaturityExampleSkills::seedSkills()[2];
		$service = $this->objectService(
			[
				'meeting-notes-cleanup' => $this->skill(
					'u-meeting',
					'__system__',
					['name' => 'meeting-notes-cleanup', 'state' => 'stale', 'staleSince' => '2026-01-01T00:00:00+00:00']
				),
				'woo-request-triage'    => $this->skill('u-woo', 'alice', ['name' => 'woo-request-triage', 'state' => 'active']),
				'tender-summary'        => $this->skill('u-tender', 'alice', $tender),
			]
		);

		$this->step($service, $this->createMock(LoggerInterface::class))->run($this->repairOutput());

		$this->assertCount(1, $service->saved);
		$save = $service->saved[0];
		$this->assertSame('u-meeting', $save['uuid']);
		$this->assertSame('active', $save['object']['state']);
		$this->assertArrayNotHasKey('staleSince', $save['object']);
		$this->assertArrayNotHasKey('id', $save['object']);
		$this->assertArrayHasKey('lastActivityAt', $save['object']);

		$this->assertSame(
			[
				['info', 'meeting-notes-cleanup seed already present — freshness refreshed.'],
				['info', 'woo-request-triage seed already present — skipped.'],
				['info', 'tender-summary seed already present (learnings included) — skipped.'],
			],
			$this->lines
		);
	}//end testExistingSeedsAreRefreshedOrLeftAlone()

	/**
	 * A seed that fails to save is reported and the others still seed.
	 *
	 * @return void
	 */
	public function testAFailedSeedIsReportedAndTheOthersContinue(): void {
		$service = $this->objectService([], ['woo-request-triage']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('[hermiq] woo-request-triage seed failed: schema rejected');

		$this->step($service, $logger)->run($this->repairOutput());

		$this->assertSame(
			['meeting-notes-cleanup', 'tender-summary'],
			array_map(static fn (array $save): string => $save['object']['name'], $service->saved)
		);
		$this->assertSame(
			[
				['info', 'meeting-notes-cleanup seed complete.'],
				['warning', 'Could not seed woo-request-triage skill: schema rejected'],
				['info', 'tender-summary seed complete.'],
			],
			$this->lines
		);
	}//end testAFailedSeedIsReportedAndTheOthersContinue()
}//end class
