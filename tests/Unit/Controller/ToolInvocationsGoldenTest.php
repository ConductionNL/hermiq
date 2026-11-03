<?php

/**
 * Golden test for ToolOversightController::toolInvocations().
 *
 * Runs the tool-invocation listing over generated worlds: the agent's owner and
 * its schedules (owned by others, other agents, unowned, non-entities), MCP audit
 * entries by correlated and foreign users, coarse run entries on the agent's and
 * other schedules, entries without a date, every time window (open, half-open,
 * empty strings), both audit sources and both formats. The response (JSON data,
 * or the CSV body) and every read it made are compared with a recorded fixture.
 * Set GOLDEN_RECORD=1 to (re)write the fixture; a run in record mode compares
 * nothing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-the-richer-invocation-audit-shape-is-not-yet-available
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use DateTime;
use OCA\Hermiq\Controller\ToolOversightController;
use OCA\Hermiq\Service\Agent\GrantWaiverAudit;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Golden comparison of the tool-invocation listing.
 *
 * @covers \OCA\Hermiq\Controller\ToolOversightController
 * @uses   \OCA\Hermiq\Service\AgentAccessService
 * @uses   \OCA\Hermiq\Service\ToolInvocationRows
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-the-richer-invocation-audit-shape-is-not-yet-available
 */
class ToolInvocationsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/tool-invocations.json';

	private const CASES = 200;

	private const USERS = ['alice', 'bob', 'carol', '', null];

	private const WINDOWS = [
		['', ''],
		['', ''],
		['2026-10-01T00:00:00+00:00', ''],
		['', '2026-10-01T02:00:00+00:00'],
		['2026-10-01T01:00:00+00:00', '2026-10-01T02:30:00+00:00'],
		['2026-12-01T00:00:00+00:00', ''],
	];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * Every generated world gives the recorded listing after the recorded reads.
	 *
	 * @return void
	 */
	public function testToolInvocationsMatchTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$reads = [];
			$rich = ($this->random->getInt(0, 1) === 1);
			$controller = $this->controller(reads: $reads, rich: $rich);
			$window = $this->pick(pool: self::WINDOWS);
			$format = $this->pick(pool: ['json', 'json', 'csv']);
			$response = $controller->toolInvocations('agent-1', $format, $window[0], $window[1]);

			if ($response instanceof DataDownloadResponse) {
				$body = $response->render();
			} else {
				$body = $response->getData();
			}

			$actual[] = [
				'rich' => $rich,
				'window' => $window,
				'format' => $format,
				'status' => $response->getStatus(),
				'body' => $body,
				'reads' => $reads,
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

	}//end testToolInvocationsMatchTheRecordedFixture()

	/**
	 * The controller over a generated world, recording every read.
	 *
	 * @param array<int, mixed> $reads The read log, appended to.
	 * @param bool $rich Whether the per-invocation audit shape is available.
	 *
	 * @return ToolOversightController
	 */
	private function controller(array &$reads, bool $rich): ToolOversightController {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setOwner('alice');
		$agent->setObject(['name' => 'Agent']);
		$schedules = $this->schedules();
		$mcp = $this->entries(actions: ['mcp.search', 'mcp.create'], objects: ['o1', null, '']);
		$runs = $this->entries(actions: ['run'], objects: ['s1', 's2', 's9', null]);

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$reads, $objectService): ObjectService {
				$reads[] = ['setSchema', $schema];
				return $objectService;
			}
		);
		$objectService->method('find')->willReturn($agent);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use (&$reads, $schedules): array {
				$reads[] = ['findAll', $config];
				return $schedules;
			}
		);

		$mapper = $this->createMock(originalClassName: AuditTrailMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (mixed ...$args) use (&$reads, $mcp, $runs): array {
				$filters = ($args[2] ?? $args['filters'] ?? []);
				$reads[] = ['auditFindAll', $filters];
				if (($filters['action'] ?? '') === 'run') {
					return $runs;
				}

				return $mcp;
			}
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$logger = new NullLogger();

		return new class(
			request: $this->createMock(originalClassName: IRequest::class),
			objectService: $objectService,
			toolRegistry: $this->createMock(originalClassName: ToolRegistryFacade::class),
			grantResolver: new ToolGrantResolver(),
			auditTrailMapper: $mapper,
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			userSession: $session,
			groupManager: $groups,
			accessRequests: $this->createMock(originalClassName: ToolAccessRequestService::class),
			logger: $logger,
			agentAccess: new AgentAccessService(objectService: $objectService, logger: $logger, groupManager: $groups),
			waiverAudit: $this->createMock(originalClassName: GrantWaiverAudit::class),
			rich: $rich
		) extends ToolOversightController {
			/**
			 * The controller with richAuditAvailable() forced.
			 *
			 * @param IRequest $request The request.
			 * @param ObjectService $objectService The object service.
			 * @param ToolRegistryFacade $toolRegistry The tool registry.
			 * @param ToolGrantResolver $grantResolver The grant resolver.
			 * @param AuditTrailMapper $auditTrailMapper The audit mapper.
			 * @param IAppConfig $appConfig The app config.
			 * @param IUserSession $userSession The session.
			 * @param IGroupManager $groupManager The group manager.
			 * @param ToolAccessRequestService $accessRequests The access requests.
			 * @param NullLogger $logger The logger.
			 * @param AgentAccessService $agentAccess The agent access check.
			 * @param GrantWaiverAudit $waiverAudit The waiver audit.
			 * @param bool $rich Forced return value of richAuditAvailable().
			 */
			public function __construct(
				IRequest $request,
				ObjectService $objectService,
				ToolRegistryFacade $toolRegistry,
				ToolGrantResolver $grantResolver,
				AuditTrailMapper $auditTrailMapper,
				IAppConfig $appConfig,
				IUserSession $userSession,
				IGroupManager $groupManager,
				ToolAccessRequestService $accessRequests,
				NullLogger $logger,
				AgentAccessService $agentAccess,
				GrantWaiverAudit $waiverAudit,
				private readonly bool $rich,
			) {
				parent::__construct(
					request: $request,
					objectService: $objectService,
					toolRegistry: $toolRegistry,
					grantResolver: $grantResolver,
					auditTrailMapper: $auditTrailMapper,
					appConfig: $appConfig,
					userSession: $userSession,
					groupManager: $groupManager,
					accessRequests: $accessRequests,
					logger: $logger,
					agentAccess: $agentAccess,
					waiverAudit: $waiverAudit
				);
			}//end __construct()

			/**
			 * The forced answer.
			 *
			 * @return bool
			 */
			protected function richAuditAvailable(): bool {
				return $this->rich;
			}//end richAuditAvailable()
		};

	}//end controller()

	/**
	 * The schedule page: this agent's and others', owned or not, plus a non-entity.
	 *
	 * @return array<int, mixed>
	 */
	private function schedules(): array {
		$rows = [];
		foreach (['s1', 's2', 's3'] as $uuid) {
			if ($this->random->getInt(0, 3) === 0) {
				continue;
			}

			$schedule = new ObjectEntity();
			$schedule->setUuid($uuid);
			$schedule->setOwner($this->pick(pool: ['bob', 'carol', '', null]));
			$schedule->setObject(['agentId' => $this->pick(pool: ['agent-1', 'agent-1', 'agent-2', ''])]);
			$rows[] = $schedule;
		}

		if ($this->random->getInt(0, 4) === 0) {
			$rows[] = ['not' => 'an entity'];
		}

		return $rows;

	}//end schedules()

	/**
	 * Audit entries of the given actions.
	 *
	 * @param array<int, string> $actions The actions to draw from.
	 * @param array<int, string|null> $objects The object uuids to draw from.
	 *
	 * @return array<int, AuditTrail>
	 */
	private function entries(array $actions, array $objects): array {
		$rows = [];
		for ($i = $this->random->getInt(0, 6); $i > 0; $i--) {
			$entry = new AuditTrail();
			$entry->setAction($this->pick(pool: $actions));
			$entry->setUser($this->pick(pool: self::USERS));
			$entry->setObjectUuid($this->pick(pool: $objects));
			$entry->setToolId($this->pick(pool: ['openregister.search', null]));
			$entry->setParamsDigest($this->pick(pool: ['sha256:ab', null]));
			$entry->setResultSummary($this->pick(pool: [['ok' => true], null]));
			$entry->setChanged($this->pick(pool: [['status' => 'ok'], ['status' => 'error'], [], null]));
			if ($this->random->getInt(0, 5) !== 0) {
				$entry->setCreated(new DateTime('@' . (1790812800 + $this->random->getInt(0, 6) * 1800)));
			}

			$rows[] = $entry;
		}

		return $rows;

	}//end entries()

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
}//end class
