<?php

/**
 * The recommendation engine reads the slug this instance actually carries.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\LearnerSignalReader;
use OCA\Hermiq\Service\LearnerSignalRegister;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Migrated and unmigrated instances, told apart.
 *
 * ## Why the migrated case is the only one that matters
 *
 * Reinstating the pinned literal reddens the migrated-instance test below and
 * the static guard in `RegisterSlugPinTest`. It does NOT redden the
 * unmigrated-instance test, because on an unmigrated instance the pinned literal
 * happens to be the right answer. That is why this defect survived: every test
 * anyone had written here was, in effect, the unmigrated case, and the
 * ObjectService double in `CourseRecommendationEngineTest` discards its
 * `setRegister()` argument entirely, so none of them could see the register at
 * all whichever slug it named.
 *
 * Watched failing, not assumed. With `LearnerSignalReader::readSignal()`
 * reverted to `->setRegister(self::SCHOLIQ_REGISTER)` and that constant put back
 * at `'scholiq'`, three assertions reddened and they were the right three:
 *
 *  - `testAMigratedInstanceIsReadWithItsNewSlug`, which expected `learniq` and
 *    got `scholiq`.
 *  - `testTheAppBeingInstalledDoesNotDecideTheRegisterSlug`, the same mismatch
 *    at its migrated leg.
 *  - `RegisterSlugPinTest::testNoSourceFilePinsASupersededRegisterSlug`, naming
 *    `lib/Service/LearnerSignalReader.php`, the line and the canonical slug.
 *
 * `testAnUnmigratedInstanceIsReadWithItsOldSlug` stayed GREEN under that
 * mutation, exactly as it should, and so did the absent-register case: the gate
 * short-circuits before any read, so the slug the read would have used never
 * comes up. So did all twelve cases in `CourseRecommendationEngineTest`,
 * including the ones this branch points at a migrated instance, because that
 * file's double throws its `setRegister()` argument away and therefore cannot
 * fail on it whichever slug it names.
 *
 * ## Why the app-installed check is not enough
 *
 * `getOrRegenerate()` already asked `FleetAppId::isInstalled(canonical:
 * 'learniq')` before this work, and that check passes on the very instances the
 * read was failing on. The app id and the register slug are moved by two
 * different repair steps and either can run first, so an instance can answer
 * `learniq` to `IAppManager` while its register row still reads `scholiq`.
 * `testTheAppBeingInstalledDoesNotDecideTheRegisterSlug` holds that distinction
 * in place.
 */
class CourseRegisterResolutionTest extends TestCase {

	/**
	 * Every findAll() the engine made, as `register|schema` pairs, in order.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * Reset the capture between cases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reads = [];
	}//end setUp()

	/**
	 * An instance that has run learniq's rename is read with the new slug.
	 *
	 * @return void
	 */
	public function testAMigratedInstanceIsReadWithItsNewSlug(): void {
		$result = $this->recommend(presentSlugs: ['learniq']);

		$this->assertSame(['learniq'], $this->signalRegisters(), 'Every learner-signal read must use the resolved slug.');
		$this->assertSame('fresh', $result['status']);
		$this->assertNotSame([], $result['recommendations'], 'A migrated instance must actually recommend something.');
	}//end testAMigratedInstanceIsReadWithItsNewSlug()

	/**
	 * An instance that has not run it is read with the old slug.
	 *
	 * This case passes both before and after the resolution. It is here to prove
	 * that the fix did not simply swap one literal for another, which would have
	 * moved the breakage to the other half of the estate rather than removing it.
	 *
	 * @return void
	 */
	public function testAnUnmigratedInstanceIsReadWithItsOldSlug(): void {
		$result = $this->recommend(presentSlugs: ['scholiq']);

		$this->assertSame(['scholiq'], $this->signalRegisters());
		$this->assertSame('fresh', $result['status']);
		$this->assertNotSame([], $result['recommendations']);
	}//end testAnUnmigratedInstanceIsReadWithItsOldSlug()

	/**
	 * An instance carrying the register under NEITHER slug reads nothing, and says so.
	 *
	 * The absence has to be visible. Before the resolution this path read
	 * `scholiq` regardless and OpenRegister answered with the empty list a
	 * register holding no matching objects answers with, so the learner was
	 * served a complete, successful, empty recommendation set and no channel
	 * anywhere distinguished that from having nothing to recommend.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutTheRegisterRecommendsNothingAndNamesTheReason(): void {
		$result = $this->recommend(presentSlugs: []);

		$this->assertSame([], $this->reads, 'Nothing may be read when the register is absent, from any register.');
		$this->assertSame('unavailable', $result['status']);
		$this->assertSame(
			LearnerSignalRegister::REASON_REGISTER_ABSENT,
			($result['unavailableReason'] ?? null),
			'The result must name the absent register, not report an empty success.'
		);
		$this->assertSame([], $result['recommendations']);
	}//end testAnInstanceWithoutTheRegisterRecommendsNothingAndNamesTheReason()

	/**
	 * An absent app and an absent register are two different repairs, and say so.
	 *
	 * Both return `unavailable`, which is correct: neither can serve a
	 * recommendation. What must differ is WHY, because one is fixed by enabling
	 * an app and the other by provisioning a register, and an operator reading
	 * only `status` cannot tell which to do.
	 *
	 * @return void
	 */
	public function testAnAbsentAppAndAnAbsentRegisterAreDistinguishable(): void {
		$absentRegister = $this->recommend(presentSlugs: []);
		$absentApp = $this->recommend(presentSlugs: ['learniq'], appInstalled: false);

		$this->assertSame('unavailable', $absentRegister['status']);
		$this->assertSame('unavailable', $absentApp['status']);
		$this->assertNotSame(
			$absentApp['unavailableReason'],
			$absentRegister['unavailableReason'],
			'Two different repairs must not arrive as one indistinct unavailable.'
		);
		$this->assertSame(LearnerSignalRegister::REASON_APP_ABSENT, $absentApp['unavailableReason']);
	}//end testAnAbsentAppAndAnAbsentRegisterAreDistinguishable()

	/**
	 * The app being installed does not decide which slug the register carries.
	 *
	 * `IAppManager` says yes to `learniq` in every case in this file except the
	 * one above, including the case where the register carries the old slug and
	 * the case where it is absent entirely. If the app-id answer were being used
	 * to pick the slug, the unmigrated leg would have read `learniq` and the
	 * absent leg would have read at all.
	 *
	 * @return void
	 */
	public function testTheAppBeingInstalledDoesNotDecideTheRegisterSlug(): void {
		$this->recommend(presentSlugs: ['learniq']);
		$migrated = $this->signalRegisters();

		$this->reads = [];
		$this->recommend(presentSlugs: ['scholiq']);
		$unmigrated = $this->signalRegisters();

		$this->reads = [];
		$this->recommend(presentSlugs: []);
		$absent = $this->signalRegisters();

		$this->assertSame(['learniq'], $migrated);
		$this->assertSame(['scholiq'], $unmigrated, 'An installed app with an unmigrated register is still read with the old slug.');
		$this->assertSame([], $absent, 'An installed app with no register at all is read with no slug.');
	}//end testTheAppBeingInstalledDoesNotDecideTheRegisterSlug()

	/**
	 * The distinct registers the learner-signal schemas were read from.
	 *
	 * Hermiq's own register is filtered out deliberately: it is not renamed, it
	 * is not what this file is about, and including it would make every
	 * assertion below depend on the engine's persistence order.
	 *
	 * @return array<int, string>
	 */
	private function signalRegisters(): array {
		$signalSchemas = ['enrolment', 'course', 'xapi-statement', 'learning-plan', 'competency-attainment'];

		$registers = [];
		foreach ($this->reads as $read) {
			[$register, $schema] = explode('|', $read);
			if (in_array($schema, $signalSchemas, true) === true) {
				$registers[] = $register;
			}
		}

		return array_values(array_unique($registers));
	}//end signalRegisters()

	/**
	 * Run one recommendation against an instance carrying the given register slugs.
	 *
	 * @param list<string> $presentSlugs The register slugs this instance carries.
	 * @param bool         $appInstalled Whether the learner-signal app is installed.
	 *
	 * @return array<string, mixed> The recommendation payload.
	 */
	private function recommend(array $presentSlugs, bool $appInstalled = true): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$aiFeatureService = $this->createMock(AiFeatureService::class);
		$aiFeatureService->method('findBySlug')->willReturn(
			$this->entity('feat-1', ['slug' => 'course-recommendations', 'lifecycle' => 'enabled'])
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($appInstalled);

		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->method('isOrganisationEngaged')->willReturn(false);

		$organisationMapper = $this->createMock(OrganisationMapper::class);
		$organisationMapper->method('findByUserId')->willReturn([]);

		$logger = $this->createMock(LoggerInterface::class);
		$objectService = $this->objectService($this->signalFixtures($now));

		$engine = new CourseRecommendationEngine(
			$objectService,
			new LearnerSignalRegister(new FakeSlugResolver($presentSlugs), $appManager, $logger),
			new LearnerSignalReader($objectService, $logger),
			$aiFeatureService,
			$scheduleService,
			$this->createMock(ProviderFactory::class),
			$organisationMapper,
			$logger
		);

		return $engine->getOrRegenerate(learnerUid: 'alice');
	}//end recommend()

	/**
	 * One published course that matches one open learning-plan goal.
	 *
	 * The smallest fixture that produces a non-empty recommendation set, so that
	 * "read with the wrong slug" and "read with the right slug" have visibly
	 * different outcomes rather than both being empty.
	 *
	 * @param DateTimeImmutable $now The reference time.
	 *
	 * @return array<string, array<int, ObjectEntity>> Schema slug => objects.
	 */
	private function signalFixtures(DateTimeImmutable $now): array {
		return [
			'course' => [
				$this->entity(
					'course-a',
					['code' => 'ADV-1', 'name' => 'Advanced Security', 'tags' => ['security'], 'lifecycle' => 'published']
				),
			],
			'enrolment' => [],
			'xapi-statement' => [],
			'learning-plan' => [
				$this->entity(
					'plan-1',
					[
						'learnerId' => 'alice',
						'goals' => [
							['goalId' => 'g1', 'description' => 'improve security skills', 'domain' => 'security', 'status' => 'open'],
						],
						'updatedAt' => $now->format('c'),
					]
				),
			],
			'competency-attainment' => [],
		];
	}//end signalFixtures()

	/**
	 * An ObjectService double that CAPTURES the register it is read with.
	 *
	 * The distinction from the double in `CourseRecommendationEngineTest` is the
	 * point of this file: that one's `setRegister()` accepts its argument and
	 * throws it away, so no assertion built on it can ever see which register was
	 * read. A double that discards the value under test cannot fail on it.
	 *
	 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug => objects findAll() returns.
	 *
	 * @return ObjectService The double.
	 */
	private function objectService(array $bySchema): ObjectService {
		$reads = &$this->reads;

		return new class($bySchema, $reads) extends ObjectService {
			/**
			 * The register set by the most recent setRegister() call.
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The schema set by the most recent setSchema() call.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug => objects.
			 * @param array<int, string>                      $reads    Captured `register|schema` reads.
			 */
			public function __construct(private array $bySchema, private array &$reads) {
			}

			public function setRegister(mixed $register): static {
				$this->register = (string)$register;
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = $this->register . '|' . $this->schema;
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
				// assertion: PHP refuses to declare the class and the whole suite
				// dies before it runs.
				bool $failIfExists = false,
				bool $_unowned = false,
			): ObjectEntity {
				$payload = is_array($object) ? $object : $object->getObject();
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'new-recommendation');
				$entity->setObject($payload);
				return $entity;
			}
		};
	}//end objectService()

	/**
	 * An ObjectEntity fixture.
	 *
	 * @param string               $uuid The entity uuid.
	 * @param array<string, mixed> $data The object payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()
}//end class
