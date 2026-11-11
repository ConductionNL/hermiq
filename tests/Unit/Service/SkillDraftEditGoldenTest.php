<?php

/**
 * Golden test for SkillDraftController::content() and SkillConsolidationService::editDraftContent().
 *
 * Sends generated edit requests (frontmatter, body and files as strings, numbers,
 * lists, null or missing) to the controller over the real consolidation service,
 * with generated sessions, drafts (awaiting approval or not, owners, evidence),
 * skills, review-action grants, content-scan answers and save failures, and
 * compares the response, every collaborator call and the log lines with a
 * recorded fixture. Timestamps the service stamps at run time are compared as
 * "now". Set GOLDEN_RECORD=1 to (re)write the fixture; a run in record mode
 * compares nothing.
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

use OCA\Hermiq\Controller\SkillDraftController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\EvalRunService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\Hermiq\Service\SkillConsolidationService;
use OCA\Hermiq\Service\SkillLearningsCaptureService;
use OCA\Hermiq\Service\SkillLearningsPromotionService;
use OCA\Hermiq\Service\SkillMaturityService;
use OCA\Hermiq\Service\SkillService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\Hermiq\Service\TenantKillSwitch;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContentScanService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Golden comparison of the edit-before-accept path.
 *
 * @covers \OCA\Hermiq\Controller\SkillDraftController
 * @covers \OCA\Hermiq\Service\SkillConsolidationService
 * @uses   \OCA\Hermiq\Service\SkillMaturityService
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
 */
class SkillDraftEditGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/skill-draft-edit.json';

	private const CASES = 300;

	private const STATUSES = ['awaiting-approval', 'awaiting-approval', 'awaiting-approval', 'awaiting-approval', 'proposed', 'accepted', '', null];

	private const VERDICTS = ['clean', 'clean', 'clean', 'suspicious', 'dangerous', '', null];

	private const PUBLISHED_AT = ['2026-09-01T10:00:00+00:00', '', null];

	private const ACCEPTED_AT = ['2026-08-01T10:00:00+00:00', '', null];

	private const PARAMS = [
		'frontmatter' => ["name: edited\n", '', null, 7, ['x'], '(missing)'],
		'body' => ['Edited body', '', null, 3.5, ['b'], true, '(missing)'],
		'files' => [
			[['name' => 'e.md', 'content' => 'E'], ['name' => '', 'content' => 'skip'], 'loose', ['content' => 'no name']],
			[],
			'a string',
			null,
			5,
			'(missing)',
		],
		'userId' => ['mallory', '(missing)'],
		'editorUid' => ['mallory', '(missing)'],
	];

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
	 * Every generated edit request gives the recorded response, calls and log.
	 *
	 * @return void
	 */
	public function testEditMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$calls = [];
			$logger = $this->logger();
			$params = [];
			foreach (self::PARAMS as $name => $pool) {
				$params[$name] = $this->pick(pool: $pool);
			}

			$controller = $this->controller(calls: $calls, logger: $logger, params: $params);
			$response = $controller->content('d1');

			$actual[] = [
				'world' => $this->world,
				'params' => $params,
				'status' => $response->getStatus(),
				'data' => $this->withoutNow(value: $response->getData()),
				'calls' => $this->withoutNow(value: $calls),
				'log' => $logger->lines,
			];
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

	}//end testEditMatchesTheRecordedFixture()

	/**
	 * The controller over the real consolidation service and a generated world.
	 *
	 * @param array<int, mixed> $calls The call log, appended to.
	 * @param AbstractLogger $logger The recording logger.
	 * @param array<string, mixed> $params The request parameters; '(missing)' leaves one out.
	 *
	 * @return SkillDraftController
	 */
	private function controller(array &$calls, AbstractLogger $logger, array $params): SkillDraftController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				if (array_key_exists($key, $params) === false || $params[$key] === '(missing)') {
					return $default;
				}

				return $params[$key];
			}
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$reviewer = $this->createMock(originalClassName: IUser::class);
		$reviewer->method('getUID')->willReturn($this->pick(pool: ['reviewer', 'second-reviewer']));
		$user = $reviewer;
		if ($this->random->getInt(0, 15) === 0) {
			$user = null;
		}

		$session->method('getUser')->willReturn($user);

		$skill = $this->skill();
		$skills = $this->createMock(originalClassName: SkillService::class);
		$skills->method('getSkill')->willReturnCallback(
			function (string $skillId) use (&$calls, $skill): ?ObjectEntity {
				$calls[] = ['getSkill', $skillId];
				return $skill;
			}
		);

		$denied = ($this->random->getInt(0, 12) === 0);
		$auth = $this->createMock(originalClassName: ActionAuthService::class);
		$auth->method('requireAction')->willReturnCallback(
			function (IUser $user, string $action) use (&$calls, $denied): void {
				$calls[] = ['requireAction', $user->getUID(), $action];
				if ($denied === true) {
					throw new OCSForbiddenException('not a reviewer');
				}
			}
		);

		return new SkillDraftController(
			request: $request,
			skillService: $skills,
			consolidation: $this->service(calls: $calls, logger: $logger, skill: $skill),
			approvalService: $this->createMock(originalClassName: ApprovalService::class),
			versionService: $this->createMock(originalClassName: SkillVersionService::class),
			actionAuth: $auth,
			seedCustody: $this->createMock(originalClassName: SeedCustodyService::class),
			userSession: $session,
			logger: $logger
		);

	}//end controller()

	/**
	 * The consolidation service over a generated draft, recording each collaborator call.
	 *
	 * @param array<int, mixed> $calls The call log, appended to.
	 * @param AbstractLogger $logger The recording logger.
	 * @param ObjectEntity|null $skill The skill every lookup answers.
	 *
	 * @return SkillConsolidationService
	 */
	private function service(array &$calls, AbstractLogger $logger, ?ObjectEntity $skill): SkillConsolidationService {
		$objects = ['agentskilldraft' => $this->draft(), 'agentskill' => $skill];
		$this->world = array_map(static fn (?ObjectEntity $object): ?array => $object?->getObject(), $objects);
		$failSave = ($this->random->getInt(0, 12) === 0);

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$calls, $objectService): ObjectService {
				$calls[] = ['setSchema', $schema];
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (mixed ...$args) use (&$calls): array {
				$calls[] = ['findAll', $this->passed(class: ObjectService::class, method: 'findAll', args: $args)];
				return [];
			}
		);
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
				if ($failSave === true) {
					throw new RuntimeException('draft write refused');
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)($passed['uuid'] ?? 'new'));
				$entity->setOwner((string)($passed['owner'] ?? ''));
				$entity->setObject($passed['object']);
				return $entity;
			}
		);

		$scanFails = ($this->random->getInt(0, 9) === 0);
		$severity = $this->pick(pool: self::VERDICTS);
		$scanner = $this->createMock(originalClassName: ContentScanService::class);
		$scanner->method('scan')->willReturnCallback(
			function (string $content, array $metadata = []) use (&$calls, $scanFails, $severity): array {
				$calls[] = ['scan', $content];
				if ($scanFails === true) {
					throw new RuntimeException('scanner down');
				}

				return ['severity' => $severity, 'findings' => []];
			}
		);

		$approvals = $this->createMock(originalClassName: ApprovalService::class);
		$approvals->method('ensurePendingApprovalForSkillDraft')->willReturnCallback(
			function (ObjectEntity $draft, ObjectEntity $skill, array $draftPayload) use (&$calls): ObjectEntity {
				$calls[] = ['ensurePendingApprovalForSkillDraft', $draft->getUuid(), $skill->getUuid(), $draftPayload];
				$approval = new ObjectEntity();
				$approval->setUuid('approval-new');
				return $approval;
			}
		);

		$audit = $this->createMock(originalClassName: AuditTrailMapper::class);
		$audit->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event) use (&$calls): AuditTrail {
				$calls[] = ['recordObjectEvent', $event->object->getUuid(), $event->action, $event->context];
				return new AuditTrail();
			}
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$actor = $this->createMock(originalClassName: IUser::class);
		$actor->method('getUID')->willReturn('reviewer');
		$session->method('getUser')->willReturn($actor);

		return new SkillConsolidationService(
			objectService: $objectService,
			maturityService: new SkillMaturityService(objectService: $objectService),
			versionService: $this->createMock(originalClassName: SkillVersionService::class),
			promotionService: $this->createMock(originalClassName: SkillLearningsPromotionService::class),
			captureService: $this->createMock(originalClassName: SkillLearningsCaptureService::class),
			providerFactory: $this->createMock(originalClassName: ProviderFactory::class),
			tenantKillSwitch: new TenantKillSwitch(
				objectService: $this->createMock(originalClassName: ObjectService::class),
				logger: new NullLogger()
			),
			budgetService: $this->createMock(originalClassName: BudgetService::class),
			contentScanService: $scanner,
			evalRunService: $this->createMock(originalClassName: EvalRunService::class),
			approvalService: $approvals,
			agentMapper: $this->createMock(originalClassName: AgentMapper::class),
			auditTrailMapper: $audit,
			deliveryService: $this->createMock(originalClassName: DeliveryService::class),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			userSession: $session,
			userManager: $this->createMock(originalClassName: IUserManager::class),
			urlGenerator: $this->createMock(originalClassName: IURLGenerator::class),
			logger: $logger
		);

	}//end service()

	/**
	 * The draft lookup's answer.
	 *
	 * @return ObjectEntity|null
	 */
	private function draft(): ?ObjectEntity {
		if ($this->random->getInt(0, 15) === 0) {
			return null;
		}

		$payload = [
			'status' => $this->pick(pool: self::STATUSES),
			'scanVerdict' => $this->pick(pool: ['clean', 'suspicious', '']),
			'scanReport' => $this->pick(pool: [['severity' => 'clean'], []]),
			'evalEvidence' => $this->pick(pool: [['datasetId' => 'ds1', 'score' => 0.8], [], null]),
			'noEvalEvidence' => $this->pick(pool: [true, false, null]),
			'approvalId' => $this->pick(pool: ['approval-old', '', null]),
			'skillId' => $this->pick(pool: ['sk1', 'sk1', 'sk1', '']),
			'proposedFrontmatter' => $this->pick(pool: ["name: x\n", null]),
			'proposedBody' => $this->pick(pool: ['New body', null]),
			'proposedFiles' => $this->pick(pool: [[['name' => 'a.md', 'content' => 'A']], [], null]),
			'editedBeforeAccept' => $this->pick(pool: [true, false, null]),
			'editedBy' => $this->pick(pool: ['someone', null]),
			'id' => 'd1',
		];
		foreach (array_keys($payload) as $key) {
			if ($this->random->getInt(0, 14) === 0) {
				unset($payload[$key]);
			}
		}

		$entity = new ObjectEntity();
		$entity->setUuid('d1');
		$entity->setOwner($this->pick(pool: ['curator', '', null]));
		$entity->setObject($payload);
		return $entity;

	}//end draft()

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
