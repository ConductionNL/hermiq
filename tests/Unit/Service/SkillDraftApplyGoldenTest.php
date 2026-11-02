<?php

/**
 * Golden test for SkillConsolidationService::applyDraft().
 *
 * Runs the apply step over generated drafts (missing, accepted, awaiting
 * approval or not, scan verdicts, eval evidence, proposed content and files)
 * and skills (missing, published or not, behind or not, computed maturity
 * fields, owners), and compares the returned version id, every collaborator
 * call and the log lines with a recorded fixture. Timestamps the service
 * stamps at run time are compared as "now". Set GOLDEN_RECORD=1 to (re)write
 * the fixture; a run in record mode compares nothing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\EvalRunService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Service\SkillConsolidationService;
use OCA\Hermiq\Service\SkillLearningsCaptureService;
use OCA\Hermiq\Service\SkillLearningsPromotionService;
use OCA\Hermiq\Service\SkillMaturityService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContentScanService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Golden comparison of the draft apply step.
 *
 * @covers \OCA\Hermiq\Service\SkillConsolidationService
 * @uses   \OCA\Hermiq\Service\SkillMaturityService
 * @uses   \OCA\Hermiq\Service\DeliveryResult
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
 */
class SkillDraftApplyGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/skill-draft-apply.json';

	private const CASES = 400;

	private const STATUSES = ['awaiting-approval', 'awaiting-approval', 'awaiting-approval', 'awaiting-approval', 'awaiting-approval', 'awaiting-approval', 'accepted', 'rejected', '', null];

	private const VERDICTS = ['clean', 'clean', 'clean', 'clean', 'suspicious', 'dangerous', '', null];

	private const PUBLISHED_AT = ['2026-09-01T10:00:00+00:00', '2026-09-01T10:00:00+00:00', '', null, 'not a date'];

	private const ACCEPTED_AT = ['2026-08-01T10:00:00+00:00', '2026-09-15T10:00:00+00:00', '', null];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * The draft and skill payloads of the current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $world = [];

	/**
	 * Every generated case gives the recorded version id, calls and log.
	 *
	 * @return void
	 */
	public function testApplyDraftMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$calls = [];
			$logger = $this->logger();
			$service = $this->service(calls: $calls, logger: $logger);
			$draftId = ['d1', 'd1', 'd1', 'd1', 'd1', ''][$this->random->getInt(0, 5)];
			try {
				$result = $service->applyDraft(draftId: $draftId, deciderUid: 'reviewer');
			} catch (RuntimeException $e) {
				$result = 'threw: ' . $e->getMessage();
			}

			$actual[] = ['world' => $this->world, 'result' => $result, 'calls' => $this->withoutNow(value: $calls), 'log' => $logger->lines];
		}

		foreach (self::ACCEPTED_AT as $index => $acceptedAt) {
			$this->random = new Randomizer(new Mt19937(1000 + $index));
			$calls = [];
			$logger = $this->logger();
			$fixed = [
				'agentskilldraft' => $this->entity(
					uuid: 'd1',
					payload: ['status' => 'awaiting-approval', 'scanVerdict' => 'clean', 'noEvalEvidence' => true, 'skillId' => 'sk1', 'proposedBody' => 'B'],
					owner: 'curator'
				),
				'agentskill' => $this->entity(
					uuid: 'sk1',
					payload: ['name' => 'Skill', 'githubRepo' => 'org/repo', 'publishedAt' => '2026-09-01T10:00:00+00:00', 'lastAcceptedVersionAt' => $acceptedAt],
					owner: 'author'
				),
			];
			$service = $this->service(calls: $calls, logger: $logger, fixed: $fixed);
			try {
				$result = $service->applyDraft(draftId: 'd1', deciderUid: 'reviewer');
			} catch (RuntimeException $e) {
				$result = 'threw: ' . $e->getMessage();
			}

			$actual[] = ['world' => $this->world, 'result' => $result, 'calls' => $this->withoutNow(value: $calls), 'log' => $logger->lines];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertCount(expectedCount: count($expected), haystack: $actual);
		foreach ($expected as $index => $one) {
			$this->assertSame(
				expected: json_encode($one, JSON_THROW_ON_ERROR),
				actual: json_encode($actual[$index], JSON_THROW_ON_ERROR),
				message: 'case ' . $index
			);
		}

	}//end testApplyDraftMatchesTheRecordedFixture()

	/**
	 * The service over a generated draft and skill, recording each collaborator call.
	 *
	 * @param array<int, mixed> $calls The call log, appended to.
	 * @param AbstractLogger $logger The recording logger.
	 * @param array<string, ObjectEntity>|null $fixed A fixed draft and skill instead of generated ones.
	 *
	 * @return SkillConsolidationService
	 */
	private function service(array &$calls, AbstractLogger $logger, ?array $fixed = null): SkillConsolidationService {
		$objects = ($fixed ?? ['agentskilldraft' => $this->draft(), 'agentskill' => $this->skill()]);
		$this->world = array_map(static fn (?ObjectEntity $object): ?array => $object?->getObject(), $objects);
		$failSave = ($this->random->getInt(0, 12) === 0);

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (mixed ...$args) use (&$calls, $objects): mixed {
				$passed = $this->passed(class: ObjectService::class, method: 'find', args: $args);
				$calls[] = ['find', $passed];
				return ($objects[(string)($passed['schema'] ?? '')] ?? null);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use (&$calls, $failSave): ObjectEntity {
				$passed = $this->passed(class: ObjectService::class, method: 'saveObject', args: $args);
				$calls[] = ['saveObject', $passed];
				if ($failSave === true && ($passed['schema'] ?? '') === 'agentskill') {
					throw new RuntimeException('skill write refused');
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)($passed['uuid'] ?? 'new'));
				$entity->setOwner('saved-owner');
				$entity->setObject($passed['object']);
				return $entity;
			}
		);

		$version = ['v-7', 'v-8', null][$this->random->getInt(0, 2)];
		$versions = $this->createMock(originalClassName: SkillVersionService::class);
		$versions->method('currentVersionId')->willReturnCallback(
			function (string $skillUuid) use (&$calls, $version): ?string {
				$calls[] = ['currentVersionId', $skillUuid];
				return $version;
			}
		);

		$delivery = $this->createMock(originalClassName: DeliveryService::class);
		$delivery->method('deliverSkillPublishedBehind')->willReturnCallback(
			function (mixed ...$args) use (&$calls): DeliveryResult {
				$calls[] = ['deliverSkillPublishedBehind', $this->passed(class: DeliveryService::class, method: 'deliverSkillPublishedBehind', args: $args)];
				return new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null);
			}
		);

		$audit = $this->createMock(originalClassName: AuditTrailMapper::class);
		$auditFails = ($this->random->getInt(0, 9) === 0);
		$audit->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []) use (&$calls, $auditFails): AuditTrail {
				$calls[] = ['createAuditTrailEntry', $object->getUuid(), $action, $context];
				if ($auditFails === true) {
					throw new RuntimeException('audit down');
				}

				return new AuditTrail();
			}
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$prior = $this->createMock(originalClassName: IUser::class);
		$prior->method('getUID')->willReturn('prior');
		$session->method('getUser')->willReturn($prior);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user) use (&$calls): void {
				$calls[] = ['setUser', $user?->getUID()];
			}
		);
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid) use (&$calls): ?IUser {
				$calls[] = ['getUser', $uid];
				if ($uid === 'ghost') {
					return null;
				}

				$user = $this->createMock(originalClassName: IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);

		return new SkillConsolidationService(
			objectService: $objectService,
			maturityService: new SkillMaturityService($objectService),
			versionService: $versions,
			promotionService: $this->createMock(originalClassName: SkillLearningsPromotionService::class),
			captureService: $this->createMock(originalClassName: SkillLearningsCaptureService::class),
			providerFactory: $this->createMock(originalClassName: ProviderFactory::class),
			scheduleService: $this->createMock(originalClassName: ScheduleService::class),
			budgetService: $this->createMock(originalClassName: BudgetService::class),
			contentScanService: $this->createMock(originalClassName: ContentScanService::class),
			evalRunService: $this->createMock(originalClassName: EvalRunService::class),
			approvalService: $this->createMock(originalClassName: ApprovalService::class),
			agentMapper: $this->createMock(originalClassName: AgentMapper::class),
			auditTrailMapper: $audit,
			deliveryService: $delivery,
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			userSession: $session,
			userManager: $users,
			urlGenerator: $this->createMock(originalClassName: IURLGenerator::class),
			logger: $logger
		);

	}//end service()

	/**
	 * An object entity.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $payload The payload.
	 * @param string $owner The owner.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload, string $owner): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject($payload);
		return $entity;

	}//end entity()

	/**
	 * The draft lookup's answer.
	 *
	 * @return ObjectEntity|null
	 */
	private function draft(): ?ObjectEntity {
		if ($this->random->getInt(0, 12) === 0) {
			return null;
		}

		$payload = [
			'status' => $this->pick(pool: self::STATUSES),
			'scanVerdict' => $this->pick(pool: self::VERDICTS),
			'evalEvidence' => $this->pick(pool: [['datasetId' => 'ds1', 'score' => 0.8], ['datasetId' => 'ds1'], ['datasetId' => ''], 'none', null]),
			'noEvalEvidence' => $this->pick(pool: [true, true, false, null, 1]),
			'skillId' => $this->pick(pool: ['sk1', 'sk1', 'sk1', '']),
			'proposedFrontmatter' => $this->pick(pool: ["name: x\n", null]),
			'proposedBody' => $this->pick(pool: ['New body', null, 7]),
			'proposedFiles' => $this->pick(
				pool: [
					[['name' => 'a.md', 'content' => 'A'], ['name' => '', 'content' => 'B'], 'loose'],
					[],
					'not a list',
					null,
				]
			),
			'editedBeforeAccept' => $this->pick(pool: [true, false, null]),
			'auditNote' => 'Pending',
			'id' => 'd1',
		];
		foreach (array_keys($payload) as $key) {
			if ($this->random->getInt(0, 14) === 0) {
				unset($payload[$key]);
			}
		}

		$entity = new ObjectEntity();
		$entity->setUuid('d1');
		$entity->setOwner($this->pick(pool: ['curator', '', 'ghost', null]));
		$entity->setObject($payload);
		return $entity;

	}//end draft()

	/**
	 * The skill lookup's answer.
	 *
	 * @return ObjectEntity|null
	 */
	private function skill(): ?ObjectEntity {
		if ($this->random->getInt(0, 12) === 0) {
			return null;
		}

		$payload = [
			'name' => 'Skill',
			'frontmatter' => 'old',
			'body' => 'Old body',
			'files' => [['name' => 'old.md', 'content' => 'O']],
			'githubRepo' => $this->pick(pool: ['org/repo', 'org/repo', '', null]),
			'publishedAt' => $this->pick(pool: self::PUBLISHED_AT),
			'lastAcceptedVersionAt' => $this->pick(pool: self::ACCEPTED_AT),
			'maturityLevel' => $this->pick(pool: ['L2', null]),
			'levelEvidence' => $this->pick(pool: [['l1' => true, 'l5' => 'x'], [], null]),
			'id' => 'sk1',
			'uuid' => 'sk1',
			'@self' => ['owner' => 'author'],
		];
		foreach (array_keys($payload) as $key) {
			if ($this->random->getInt(0, 8) === 0) {
				unset($payload[$key]);
			}
		}

		$entity = new ObjectEntity();
		$entity->setUuid('sk1');
		$entity->setOwner($this->pick(pool: ['author', '', null]));
		$entity->setObject($payload);
		return $entity;

	}//end skill()

	/**
	 * A value with every ISO time within two minutes of now replaced by "(now)".
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function withoutNow(mixed $value): mixed {
		if (is_array($value) === true) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->withoutNow(value: $item);
			}

			return $value;
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d/', $value) === 1 && abs(strtotime($value) - time()) < 120) {
			return '(now)';
		}

		return $value;

	}//end withoutNow()

	/**
	 * A logger that keeps its lines.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * Recorded log lines.
			 *
			 * @var array<int, string>
			 */
			public array $lines = [];

			/**
			 * Record one line.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ' ' . $message . ' ' . implode(',', array_keys($context));
			}//end log()
		};

	}//end logger()

	/**
	 * One value from a pool.
	 *
	 * @param array<int, mixed> $pool The pool.
	 *
	 * @return mixed
	 */
	private function pick(array $pool): mixed {
		return $pool[$this->random->getInt(0, count($pool) - 1)];

	}//end pick()

	/**
	 * The arguments a call actually passed, by parameter name, without the ones left at their default.
	 *
	 * @param string $class The mocked class.
	 * @param string $method The method name.
	 * @param array<int, mixed> $args The positional arguments the mock received.
	 *
	 * @return array<string, mixed>
	 */
	private function passed(string $class, string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $index => $param) {
			if (array_key_exists($index, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$index]) {
				continue;
			}

			$out[$param->getName()] = $args[$index];
		}

		return $out;

	}//end passed()
}//end class
