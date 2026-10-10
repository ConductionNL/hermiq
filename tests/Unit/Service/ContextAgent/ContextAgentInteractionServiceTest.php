<?php

/**
 * Unit tests for ContextAgentInteractionService (contextagent-provider).
 *
 * Covers the governed single-turn interaction: user-context guard, kill-switch gate
 * (engine never runs), conversation create/reuse via conversation_token, the
 * confirmation→approval-gate mapping (approve on 1, deny on 0, no-op without a match),
 * the actions↔tool-allowlist disclosure, and the happy-path output shape.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\ContextAgent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ContextAgent;

use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\ContextAgentInteractionService;
use LLPhant\Chat\Message as LLPhantMessage;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Exception\ProcessingException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for ContextAgentInteractionService.
 *
 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-1
 */
class ContextAgentInteractionServiceTest extends TestCase {
	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * @var Engine&MockObject
	 */
	private Engine&MockObject $engine;

	/**
	 * @var ApprovalService&MockObject
	 */
	private ApprovalService&MockObject $approvalService;

	/**
	 * @var ScheduleService&MockObject
	 */
	private ScheduleService&MockObject $scheduleService;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var AgentAccessService&MockObject
	 */
	private AgentAccessService&MockObject $agentAccess;

	/**
	 * Set up fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->engine = $this->createMock(Engine::class);
		$this->approvalService = $this->createMock(ApprovalService::class);
		$this->scheduleService = $this->createMock(ScheduleService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->agentAccess = $this->createMock(AgentAccessService::class);

		// ObjectService fluent setters return self by default on a mock only if wired.
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return ContextAgentInteractionService
	 */
	private function service(): ContextAgentInteractionService {
		$audit = $this->createMock(AuditTrailMapper::class);

		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnArgument(0);

		$agentVersionService = $this->createMock(AgentVersionService::class);
		$agentVersionService->method('currentVersionId')->willReturn('version-1');

		return new ContextAgentInteractionService(
			$this->objectService,
			$this->engine,
			$this->approvalService,
			$this->scheduleService,
			$audit,
			$redaction,
			$this->appConfig,
			new NullLogger(),
			$agentVersionService,
			$this->agentAccess
		);
	}//end service()

	/**
	 * Build an agent ObjectEntity with the given uuid/org/tools.
	 *
	 * @param string $uuid The agent uuid.
	 * @param string $org The organisation.
	 * @param array $tools The tool allowlist.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $uuid, string $org = 'org-1', array $tools = []): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid($uuid);
		$agent->setOrganisation($org);
		$agent->setObject(['name' => 'Agent', 'active' => true, 'tools' => $tools]);
		return $agent;
	}//end agent()

	/**
	 * Build a conversation ObjectEntity owned by the given user.
	 *
	 * @param string $uuid The conversation uuid.
	 * @param string $userId The owner.
	 *
	 * @return ObjectEntity
	 */
	private function conversation(string $uuid, string $userId): ObjectEntity {
		$conversation = new ObjectEntity();
		$conversation->setUuid($uuid);
		$conversation->setObject(['userId' => $userId, 'agentId' => 'agent-1']);
		return $conversation;
	}//end conversation()

	/**
	 * Point resolveAgent() at a configured agent (found via find()).
	 *
	 * @param ObjectEntity $agent The agent to return.
	 *
	 * @return void
	 */
	private function withConfiguredAgent(ObjectEntity $agent): void {
		$this->appConfig->method('getValueString')->willReturn((string)$agent->getUuid());
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($agent): ?ObjectEntity {
				if ($id === $agent->getUuid()) {
					return $agent;
				}
				return null;
			}
		);
	}//end withConfiguredAgent()

	/**
	 * A null user context is a processing error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
	 */
	public function testNullUserThrows(): void {
		$this->expectException(ProcessingException::class);
		$this->service()->interact(null, 'hi', null, '');
	}//end testNullUserThrows()

	/**
	 * An engaged kill-switch halts the interaction and never runs the engine.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
	 */
	public function testKillSwitchHaltsBeforeEngine(): void {
		$this->withConfiguredAgent($this->agent('agent-1', 'org-engaged'));
		$this->scheduleService->method('isOrganisationEngaged')->with('org-engaged')->willReturn(true);
		$this->engine->expects($this->never())->method('processMessage');

		$this->expectException(ProcessingException::class);
		$this->service()->interact('alice', 'hi', null, '');
	}//end testKillSwitchHaltsBeforeEngine()

	/**
	 * A first turn (empty token) creates a conversation and returns its uuid + the
	 * agent's tool allowlist in actions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
	 */
	public function testFirstTurnCreatesConversationAndReturnsShape(): void {
		$this->withConfiguredAgent($this->agent('agent-1', 'org-1', ['openregister.searchObjects']));
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);

		$created = $this->conversation('conv-new', 'alice');
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($created);

		$this->engine->method('processMessage')->willReturn(['message' => 'hello back', 'usage' => []]);

		$result = $this->service()->interact('alice', 'hi', null, '');

		$this->assertSame('hello back', $result['output']);
		$this->assertSame('conv-new', $result['conversation_token']);
		$actions = json_decode($result['actions'], true);
		$this->assertContains('openregister.searchObjects', $actions['toolAllowlist']);
	}//end testFirstTurnCreatesConversationAndReturnsShape()

	/**
	 * agent-versioning: the interaction's audit entry pins the serving agent's
	 * current version, resolved for the SAME agent id resolveAgent() picked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-a-runs-audit-entry-pins-the-exact-agent-version-that-executed-it
	 */
	public function testAgentVersionIsPinnedOnInteractionAudit(): void {
		$this->withConfiguredAgent($this->agent('agent-1', 'org-1'));
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);

		$created = $this->conversation('conv-new', 'alice');
		$this->objectService->method('saveObject')->willReturn($created);
		$this->engine->method('processMessage')->willReturn(['message' => 'hello back', 'usage' => []]);

		$auditCalls = [];
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []) use (&$auditCalls): AuditTrail {
				$auditCalls[] = $context;
				$entry = new AuditTrail();
				$entry->setAction($action);
				$entry->setChanged($context);
				return $entry;
			}
		);

		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnArgument(0);

		$agentVersionService = $this->createMock(AgentVersionService::class);
		$agentVersionService->method('currentVersionId')->with('agent-1')->willReturn('version-uuid-1');

		$service = new ContextAgentInteractionService(
			$this->objectService,
			$this->engine,
			$this->approvalService,
			$this->scheduleService,
			$audit,
			$redaction,
			$this->appConfig,
			new NullLogger(),
			$agentVersionService,
			$this->agentAccess
		);

		$service->interact('alice', 'hi', null, '');

		$this->assertNotEmpty($auditCalls);
		$this->assertSame('version-uuid-1', $auditCalls[0]['agentVersion']);
	}//end testAgentVersionIsPinnedOnInteractionAudit()

	/**
	 * A resolvable, user-owned conversation_token is reused (no new conversation).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
	 */
	public function testExistingTokenIsReused(): void {
		$agent = $this->agent('agent-1');
		$existing = $this->conversation('conv-1', 'alice');

		$this->appConfig->method('getValueString')->willReturn('agent-1');
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($agent, $existing): ?ObjectEntity {
				if ($id === 'agent-1') {
					return $agent;
				}
				if ($id === 'conv-1') {
					return $existing;
				}
				return null;
			}
		);
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->engine->method('processMessage')->willReturn(['message' => 'reply']);

		$result = $this->service()->interact('alice', 'continue', null, 'conv-1');

		$this->assertSame('conv-1', $result['conversation_token']);
	}//end testExistingTokenIsReused()

	/**
	 * confirmation=1 approves the user's matching pending Approval.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
	 */
	public function testConfirmationApprovesPendingApproval(): void {
		$this->withConfiguredAgent($this->agent('agent-1'));
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->objectService->method('saveObject')->willReturn($this->conversation('conv-x', 'alice'));
		$this->engine->method('processMessage')->willReturn(['message' => 'ok']);

		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$this->approvalService->method('listPendingForReviewer')->with('alice')->willReturn(
			[['id' => 'appr-1', 'agentId' => 'agent-1']]
		);
		$this->approvalService->method('loadApproval')->with('appr-1')->willReturn($approval);
		$this->approvalService->expects($this->once())->method('approve')->with($approval, 'alice');
		$this->approvalService->expects($this->never())->method('deny');

		$this->service()->interact('alice', 'go', 1, '');
	}//end testConfirmationApprovesPendingApproval()

	/**
	 * confirmation=0 denies the user's matching pending Approval.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
	 */
	public function testConfirmationDeniesPendingApproval(): void {
		$this->withConfiguredAgent($this->agent('agent-1'));
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->objectService->method('saveObject')->willReturn($this->conversation('conv-x', 'alice'));
		$this->engine->method('processMessage')->willReturn(['message' => 'ok']);

		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$this->approvalService->method('listPendingForReviewer')->willReturn(
			[['id' => 'appr-1', 'agentId' => 'agent-1']]
		);
		$this->approvalService->method('loadApproval')->willReturn($approval);
		$this->approvalService->expects($this->once())->method('deny')->with($approval, 'alice', $this->anything());
		$this->approvalService->expects($this->never())->method('approve');

		$this->service()->interact('alice', 'no', 0, '');
	}//end testConfirmationDeniesPendingApproval()

	/**
	 * confirmation with no matching pending approval is a no-op (no approve/deny).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
	 */
	public function testConfirmationNoPendingIsNoop(): void {
		$this->withConfiguredAgent($this->agent('agent-1'));
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->objectService->method('saveObject')->willReturn($this->conversation('conv-x', 'alice'));
		$this->engine->method('processMessage')->willReturn(['message' => 'ok']);
		$this->approvalService->method('listPendingForReviewer')->willReturn([]);
		$this->approvalService->expects($this->never())->method('approve');
		$this->approvalService->expects($this->never())->method('deny');

		$result = $this->service()->interact('alice', 'hi', 1, '');
		$this->assertSame('ok', $result['output']);
	}//end testConfirmationNoPendingIsNoop()

	/**
	 * No available agent is a processing error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
	 */
	public function testNoAgentThrows(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->objectService->method('findAll')->willReturn([]);

		$this->expectException(ProcessingException::class);
		$this->service()->interact('alice', 'hi', null, '');
	}//end testNoAgentThrows()

	/**
	 * A user who is not an admin gets their first-turn session (hermiq#1088).
	 *
	 * The Session schema lists no `create`, on purpose (hermiq#319,
	 * PrivateSchemaReadRulesTest::testWriteActionsStayOmitted), so the default
	 * `_rbac: true` save refuses every non-admin, and refuses everyone when the
	 * task runs in the background without a user session. The service is the
	 * guard: the task has a user, and the agent is the one an administrator named
	 * for every user (`contextagent_agent`, design.md). So the session is saved
	 * with `_rbac: false`, and it is always the task user's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contextagent-provider/specs/contextagent-provider/spec.md#requirement-an-interaction-runs-one-governed-turn-and-returns-the-contextagent-shape
	 */
	public function testANonAdminsFirstTurnSessionIsSavedForThemPastTheObjectApiCreateCheck(): void {
		$this->withConfiguredAgent($this->agent('agent-1'));
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);

		$args = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (mixed ...$passed) use (&$args): ObjectEntity {
				$args = $passed;
				return $this->conversation('conv-new', 'bob');
			}
		);
		$this->engine->method('processMessage')->willReturn(['message' => 'hello back']);

		$result = $this->service()->interact('bob', 'hi', null, '');

		$this->assertSame('conv-new', $result['conversation_token']);
		$this->assertSame('bob', $args[0]['userId'], 'The session belongs to the task user.');
		$this->assertSame('agent-1', $args[0]['agentId']);
		$this->assertSame('agentsession', $args[3]);
		$this->assertFalse($args[5], 'The create must not depend on an object-API create grant the schema does not give (_rbac).');
		$this->assertTrue($args[6] ?? true, 'Multitenancy stays on.');
	}//end testANonAdminsFirstTurnSessionIsSavedForThemPastTheObjectApiCreateCheck()

	/**
	 * Without a configured agent, a fallback agent the task user may not use is
	 * never bound to their session (REQ-AGSHARE-002).
	 *
	 * The fallback list used to rely on the caller's read rule, but a background
	 * task has no user session to apply it to. AgentAccessService decides instead,
	 * and a user with no usable agent gets a clear ProcessingException.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-group-sharing-is-enforced-wherever-an-agent-is-read-or-run-req-agshare-002
	 */
	public function testAFallbackAgentTheUserMayNotUseIsNeverBound(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->objectService->method('findAll')->willReturn([$this->agent('private-of-carol')]);
		$this->agentAccess->method('canUserAccessAgent')->willReturn(false);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->engine->expects($this->never())->method('processMessage');

		$this->expectException(ProcessingException::class);
		$this->service()->interact('bob', 'hi', null, '');
	}//end testAFallbackAgentTheUserMayNotUseIsNeverBound()

	/**
	 * The fallback takes the first active agent the task user may use.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-group-sharing-is-enforced-wherever-an-agent-is-read-or-run-req-agshare-002
	 */
	public function testTheFallbackTakesTheFirstAgentTheUserMayUse(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->objectService->method('findAll')->willReturn([$this->agent('private-of-carol'), $this->agent('shared-1')]);
		$this->agentAccess->method('canUserAccessAgent')->willReturnCallback(
			static fn (ObjectEntity $agent, string $userId): bool => $agent->getUuid() === 'shared-1' && $userId === 'bob'
		);
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);

		$args = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (mixed ...$passed) use (&$args): ObjectEntity {
				$args = $passed;
				return $this->conversation('conv-new', 'bob');
			}
		);
		$this->engine->method('processMessage')->willReturn(['message' => 'ok']);

		$this->service()->interact('bob', 'hi', null, '');

		$this->assertSame('shared-1', $args[0]['agentId']);
	}//end testTheFallbackTakesTheFirstAgentTheUserMayUse()

	/**
	 * Assistant chat runs from cron: the engine turn's broker call acts for the task's user.
	 *
	 * Drives a REAL ProviderFactory (no session) whose broker is stubbed, and has the engine
	 * make the Anthropic call a real turn makes. Fails on the old code: the broker received
	 * `actingUserId` null, so an organisation key refused every Assistant chat message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-a-background-task-acts-for-the-tasks-user
	 */
	public function testTheEngineTurnActsForTheTaskUser(): void {
		$this->withConfiguredAgent($this->agent('agent-1', 'org-1'));
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->objectService->method('saveObject')->willReturn($this->conversation('conv-new', 'bob'));

		$acting = [];
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			static function (
				string $credentialId,
				string $appId,
				string $method,
				string $path,
				array $headers = [],
				?string $body = null,
				?string $actingUserId = null,
			) use (&$acting): array {
				$acting[] = $actingUserId;
				return [
					'status' => 200,
					'headers' => [],
					'body' => (string)json_encode(['content' => [['type' => 'text', 'text' => 'hello bob']], 'stop_reason' => 'end_turn']),
				];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === BrokerHttpClient::BROKER_CLASS ? $broker : null)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$factory = new ProviderFactory(
			$this->createMock(LlmSettingsHandler::class),
			$this->createMock(IManager::class),
			$session,
			new NullLogger(),
			'hermiq',
			null,
			null,
			null,
			null,
			null,
			null,
			$container
		);

		$this->engine->method('processMessage')->willReturnCallback(
			static fn (): array => [
				'message' => $factory->callAnthropicChat(
					credentialId: 'cred-org',
					model: 'claude-opus-4-8',
					baseUrl: 'https://api.anthropic.com/v1',
					messageHistory: [LLPhantMessage::user('hi')]
				),
			]
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnArgument(0);
		$versions = $this->createMock(AgentVersionService::class);
		$versions->method('currentVersionId')->willReturn('version-1');
		$service = new ContextAgentInteractionService(
			$this->objectService,
			$this->engine,
			$this->approvalService,
			$this->scheduleService,
			$audit,
			$redaction,
			$this->appConfig,
			new NullLogger(),
			$versions,
			$this->agentAccess,
			$factory,
			$session,
			$this->userManagerWith(uid: 'bob', enabled: true)
		);

		// Without a session the whole interaction runs as the task's user in OpenRegister,
		// so the engine's RBAC-checked reads answer for bob, not for an anonymous caller.
		$ranAs = [];
		$this->objectService->expects($this->once())->method('runAs')->willReturnCallback(
			static function (IUser $user, callable $operation) use (&$ranAs) {
				$ranAs[] = $user->getUID();
				return $operation();
			}
		);

		$result = $service->interact('bob', 'hi', null, '');

		$this->assertSame('hello bob', $result['output']);
		$this->assertSame(['bob'], $acting);
		$this->assertSame(['bob'], $ranAs);
	}//end testTheEngineTurnActsForTheTaskUser()

	/**
	 * A cron run for a disabled (or vanished) user is refused before the engine runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-a-background-task-acts-for-the-tasks-user
	 */
	public function testACronRunForADisabledUserIsRefused(): void {
		$this->withConfiguredAgent($this->agent('agent-1', 'org-1'));
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->engine->expects($this->never())->method('processMessage');
		$this->objectService->expects($this->never())->method('runAs');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$service = new ContextAgentInteractionService(
			$this->objectService,
			$this->engine,
			$this->approvalService,
			$this->scheduleService,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(RedactionService::class),
			$this->appConfig,
			new NullLogger(),
			$this->createMock(AgentVersionService::class),
			$this->agentAccess,
			null,
			$session,
			$this->userManagerWith(uid: 'bob', enabled: false)
		);

		$this->expectException(ProcessingException::class);
		$service->interact('bob', 'hi', null, '');
	}//end testACronRunForADisabledUserIsRefused()

	/**
	 * A user manager that knows one user.
	 *
	 * @param string $uid The user id.
	 * @param bool $enabled Whether the account is enabled.
	 *
	 * @return IUserManager
	 */
	private function userManagerWith(string $uid, bool $enabled): IUserManager {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);

		$manager = $this->createMock(IUserManager::class);
		$manager->method('get')->willReturnCallback(static fn (string $id): ?IUser => ($id === $uid ? $user : null));
		return $manager;
	}//end userManagerWith()
}//end class
