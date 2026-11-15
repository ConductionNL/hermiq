<?php

/**
 * Unit tests for the SeedLiteracyLessons repair step.
 *
 * Covers the unavailable-OpenRegister skip, the per slug/locale idempotency
 * check against what is already stored, and the per-lesson failure report.
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

use OCA\Hermiq\Repair\SeedLiteracyLessons;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SeedLiteracyLessons.
 *
 * @covers \OCA\Hermiq\Repair\SeedLiteracyLessons
 * @uses   \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity
 */
class SeedLiteracyLessonsTest extends TestCase {

	/**
	 * The slug/locale pairs the shipped catalogue holds, in file order.
	 *
	 * @return array<int, string>
	 */
	private function catalogueKeys(): array {
		$decoded = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/literacy_lessons.json'), true);
		return array_map(
			static fn (array $lesson): string => $lesson['slug'] . '/' . $lesson['locale'],
			$decoded['lessons']
		);
	}//end catalogueKeys()

	/**
	 * A stored lesson as findAll() returns it.
	 *
	 * @param string $slug   The lesson slug.
	 * @param string $locale The lesson locale.
	 *
	 * @return ObjectEntity
	 */
	private function stored(string $slug, string $locale): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($slug . '-' . $locale);
		$entity->setObject(['slug' => $slug, 'locale' => $locale]);
		return $entity;
	}//end stored()

	/**
	 * An ObjectService double serving $existing and recording saves.
	 *
	 * @param array<int, mixed>  $existing  What findAll() returns.
	 * @param array<int, string> $failSlugs Slug/locale keys whose save throws.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $existing, array $failSlugs = []): ObjectService {
		return new class($existing, $failSlugs) extends ObjectService {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $reads = [];

			public int $systemRuns = 0;

			/**
			 * @param array<int, mixed>  $existing  Stored lessons.
			 * @param array<int, string> $failSlugs Failing keys.
			 */
			public function __construct(private array $existing, private array $failSlugs) {
			}

			public function runAsSystem(callable $operation): mixed {
				$this->systemRuns++;
				return $operation();
			}

			public function setRegister(mixed $register): static {
				$this->reads[] = ['register' => $register];
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->reads[] = ['schema' => $schema];
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = ['config' => $config, 'rbac' => $_rbac, 'mt' => $_multitenancy];
				return $this->existing;
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
				$key = $object['slug'] . '/' . $object['locale'];
				if (in_array($key, $this->failSlugs, true) === true) {
					throw new RuntimeException('validation failed');
				}

				$this->saved[] = [
					'key'      => $key,
					'register' => $register,
					'schema'   => $schema,
					'rbac'     => $_rbac,
					'mt'       => $_multitenancy,
					'title'    => $object['title'] ?? null,
				];
				return new ObjectEntity();
			}
		};
	}//end objectService()

	/**
	 * Build the step around a container that returns $service (or throws it).
	 *
	 * @param mixed           $service The ObjectService, or a Throwable.
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @return SeedLiteracyLessons
	 */
	private function step(mixed $service, LoggerInterface $logger): SeedLiteracyLessons {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturnCallback(
			static function () use ($service): mixed {
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		return new SeedLiteracyLessons($container, $logger);
	}//end step()

	/**
	 * The step names the course it seeds.
	 *
	 * @return void
	 */
	public function testNameDescribesTheCourse(): void {
		$step = $this->step(null, $this->createMock(LoggerInterface::class));
		$this->assertSame('Seed the course Working with AI (six lessons, English and Dutch)', $step->getName());
	}//end testNameDescribesTheCourse()

	/**
	 * Without OpenRegister the step warns and returns.
	 *
	 * @return void
	 */
	public function testUnavailableOpenRegisterIsSkippedWithAWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[hermiq] Literacy lesson seed skipped: not installed');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('OpenRegister not available, skipping the Working with AI seed.');

		$this->step(new RuntimeException('not installed'), $logger)->run($output);
	}//end testUnavailableOpenRegisterIsSkippedWithAWarning()

	/**
	 * An empty register gets every lesson of the catalogue, under the system
	 * identity and without RBAC or multitenancy.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterGetsTheWholeCatalogue(): void {
		$service = $this->objectService([]);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');

		$this->step($service, $this->createMock(LoggerInterface::class))->run($output);

		$this->assertSame(1, $service->systemRuns);
		$this->assertSame(
			[
				['register' => 'hermiq'],
				['schema' => 'literacylesson'],
				['config' => ['limit' => 1000], 'rbac' => false, 'mt' => false],
			],
			$service->reads
		);
		$this->assertSame($this->catalogueKeys(), array_column($service->saved, 'key'));
		$this->assertCount(12, $service->saved);
		foreach ($service->saved as $save) {
			$this->assertSame('hermiq', $save['register']);
			$this->assertSame('literacylesson', $save['schema']);
			$this->assertFalse($save['rbac']);
			$this->assertFalse($save['mt']);
			$this->assertIsString($save['title']);
		}
	}//end testAnEmptyRegisterGetsTheWholeCatalogue()

	/**
	 * A lesson is skipped only when the same slug exists in the same locale;
	 * the same slug in another locale is still seeded.
	 *
	 * @return void
	 */
	public function testExistingLessonsAreMatchedBySlugAndLocale(): void {
		$service = $this->objectService(
			[
				$this->stored('what-an-agent-is', 'en'),
				$this->stored('check-the-sources', 'nl'),
				'not-an-entity',
			]
		);

		$this->step($service, $this->createMock(LoggerInterface::class))->run($this->createMock(IOutput::class));

		$expected = array_values(
			array_diff($this->catalogueKeys(), ['what-an-agent-is/en', 'check-the-sources/nl'])
		);
		$this->assertSame($expected, array_column($service->saved, 'key'));
		$this->assertContains('what-an-agent-is/nl', array_column($service->saved, 'key'));
	}//end testExistingLessonsAreMatchedBySlugAndLocale()

	/**
	 * A lesson that fails to save is reported and the rest are still seeded.
	 *
	 * @return void
	 */
	public function testAFailedLessonIsReportedAndTheRestStillSeed(): void {
		$service = $this->objectService([], ['answers-can-be-wrong/en']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('[hermiq] Literacy lesson seed failed for answers-can-be-wrong/en: validation failed');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Could not seed lesson "answers-can-be-wrong/en": validation failed');

		$this->step($service, $logger)->run($output);

		$this->assertCount(11, $service->saved);
		$this->assertNotContains('answers-can-be-wrong/en', array_column($service->saved, 'key'));
	}//end testAFailedLessonIsReportedAndTheRestStillSeed()
}//end class
