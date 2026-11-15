<?php

/**
 * Golden test for ToolOversightController::toolCatalog().
 *
 * Runs the grant-annotated catalog over generated callers (signed out, owner,
 * invited, group member, admin, stranger), agents (missing, failing lookup,
 * private or not, odd grant lists) and catalogs (read, write, workspace and
 * nameless descriptors, a failing registry), and compares the response and
 * every collaborator call with a recorded fixture. Set GOLDEN_RECORD=1 to
 * (re)write the fixture; a run in record mode compares nothing.
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
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ToolOversightController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\ToolOversight\ToolCatalogView;
use OCA\Hermiq\Service\ToolOversight\ToolGrantWrites;
use OCA\Hermiq\Service\ToolOversight\ToolInvocationLog;
use OCA\Hermiq\Service\ToolOversight\ToolOversightAgents;
use OCA\Hermiq\Service\ToolOversight\ToolOversightServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Golden comparison of the tool catalog endpoint.
 *
 * @covers \OCA\Hermiq\Controller\ToolOversightController
 * @covers \OCA\Hermiq\Service\ToolOversight\ToolOversightAgents
 * @covers \OCA\Hermiq\Service\ToolOversight\ToolCatalogView
 * @covers \OCA\Hermiq\Service\ToolOversight\ToolInvocationLog
 * @covers \OCA\Hermiq\Service\ToolOversight\ToolGrantWrites
 * @covers \OCA\Hermiq\Service\ToolOversight\ToolOversightServices
 * @uses   \OCA\Hermiq\Service\AgentAccessService
 * @uses   \OCA\Hermiq\Service\Workspace\RepoEffectingGrants
 *
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#api-design
 */
class ToolCatalogGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/tool-catalog.json';

	private const CASES = 150;

	private const CALLERS = [null, 'alice', 'bob', 'carol', 'root', ''];

	private const OWNERS = ['alice', 'bob', '__system__', ''];

	private const GRANTS = [
		'pipelinq.lead.*', 'pipelinq.lead.*', 'pipelinq.lead.search', 'pipelinq.lead.*:write', 'pipelinq.lead.*:write',
		'pipelinq.lead.delete', 'files.listFiles', 'files.*', 'openregister.objects.*',
		'openregister.objects.list', 'hermiq.workspacePush',
		'hermiq.workspacePush?repository=example-org/example-app&branch=feature-a', 'files.readFile', '', 7, null,
	];

	private const DESCRIPTORS = [
		['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search', 'description' => 'Search leads'],
		['name' => 'pipelinq_lead_delete', 'mcpId' => 'pipelinq.lead.delete'],
		['name' => 'pipelinq_lead_update', 'mcpId' => 'pipelinq.lead.update', 'readOnlyHint' => true],
		['name' => 'openregister.objects.list'],
		['name' => 'hermiq_workspacePush', 'mcpId' => 'hermiq.workspacePush', 'destructiveHint' => true],
		['name' => 'files.readFile', 'readOnlyHint' => true],
		['description' => 'nameless'],
		['mcpId' => '', 'name' => ''],
		['mcpId' => 5, 'name' => 'numeric'],
		['name' => 'listFiles', 'mcpId' => 'files.listFiles'],
	];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * The recording logger of the current case.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * Every generated case gives the recorded response and calls.
	 *
	 * @return void
	 */
	public function testToolCatalogMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$calls = [];
			$caller = $this->pick(pool: self::CALLERS);
			$controller = $this->controller(caller: $caller, calls: $calls);
			$response = $controller->toolCatalog(agentId: 'agent-' . ($case % 3));

			$actual[] = [
				'caller' => $caller,
				'status' => $response->getStatus(),
				'data' => $response->getData(),
				'calls' => $calls,
				'log' => $this->logger->lines,
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

	}//end testToolCatalogMatchesTheRecordedFixture()

	/**
	 * The controller over a generated world, recording each collaborator call.
	 *
	 * @param string|null $caller The signed-in uid, or null when signed out.
	 * @param array<int, mixed> $calls The call log, appended to.
	 *
	 * @return ToolOversightController
	 */
	private function controller(?string $caller, array &$calls): ToolOversightController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($caller !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($caller);
		}

		$session->method('getUser')->willReturn($user);

		$agent = $this->agentAnswer();
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (mixed ...$args) use (&$calls, $agent): mixed {
				$calls[] = ['find', $this->passed(class: ObjectService::class, method: 'find', args: $args)];
				if ($agent === 'throws') {
					throw new DoesNotExistException('no such object');
				}

				return $agent;
			}
		);

		$catalog = $this->catalog();
		$registry = $this->createMock(originalClassName: ToolRegistryFacade::class);
		$registry->method('listTools')->willReturnCallback(
			function () use (&$calls, $catalog): array {
				$calls[] = ['listTools'];
				if ($catalog === 'throws') {
					throw new RuntimeException('registry down');
				}

				return $catalog;
			}
		);

		$threshold = [0, 1, 2, 30][$this->random->getInt(0, 3)];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			function (string $app, string $key, int $default) use (&$calls, $threshold): int {
				$calls[] = ['getValueInt', $app, $key, $default];
				return $threshold;
			}
		);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			function (string $uid) use (&$calls): bool {
				$calls[] = ['isAdmin', $uid];
				return $uid === 'root';
			}
		);
		$groups->method('isInGroup')->willReturnCallback(
			function (string $uid, string $group) use (&$calls): bool {
				$calls[] = ['isInGroup', $uid, $group];
				return $uid === 'carol' && $group === 'team';
			}
		);

		$logger = new class extends AbstractLogger {
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

		$this->logger = $logger;

		return new ToolOversightController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			logger: $logger,
			services: new ToolOversightServices(
				agents: new ToolOversightAgents(objectService: $objectService, agentAccess: new AgentAccessService($objectService, $logger, $groups), groupManager: $groups, logger: $logger),
				catalog: new ToolCatalogView(toolRegistry: $registry, grantResolver: new ToolGrantResolver(), appConfig: $appConfig),
				invocations: new ToolInvocationLog(objectService: $objectService, auditTrailMapper: $this->createMock(originalClassName: AuditTrailMapper::class)),
				grants: new ToolGrantWrites(objectService: $objectService, accessRequests: $this->createMock(originalClassName: ToolAccessRequestService::class))
			)
		);

	}//end controller()

	/**
	 * The agent lookup's answer: an agent, nothing, or a failing lookup.
	 *
	 * @return mixed
	 */
	private function agentAnswer(): mixed {
		$kind = $this->random->getInt(0, 11);
		if ($kind === 0) {
			return null;
		}

		if ($kind === 1) {
			return 'throws';
		}

		$payload = [
			'isPrivate' => $this->pick(pool: [true, true, false, null, 'yes']),
			'invitedUsers' => $this->pick(pool: [['bob'], [], 'bob', ['carol', 'bob']]),
			'groups' => $this->pick(pool: [['team'], [], 'team', [5, '']]),
			'tools' => $this->grants(),
		];
		foreach (array_keys($payload) as $key) {
			if ($this->random->getInt(0, 5) === 0) {
				unset($payload[$key]);
			}
		}

		$entity = new ObjectEntity();
		$entity->setUuid('agent-x');
		$entity->setOwner($this->pick(pool: self::OWNERS));
		$entity->setObject($payload);
		return $entity;

	}//end agentAnswer()

	/**
	 * A grant list, or a value that is not one.
	 *
	 * @return mixed
	 */
	private function grants(): mixed {
		if ($this->random->getInt(0, 7) === 0) {
			return 'pipelinq.lead.*';
		}

		$out = [];
		for ($i = $this->random->getInt(0, 5); $i > 0; $i--) {
			$out[] = $this->pick(pool: self::GRANTS);
		}

		return $out;

	}//end grants()

	/**
	 * The registry's catalog, or a failing registry.
	 *
	 * @return string|array<int, mixed>
	 */
	private function catalog(): string|array {
		if ($this->random->getInt(0, 12) === 0) {
			return 'throws';
		}

		$out = [];
		for ($i = $this->random->getInt(0, 6); $i > 0; $i--) {
			$out[] = $this->pick(pool: self::DESCRIPTORS);
		}

		return $out;

	}//end catalog()

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
