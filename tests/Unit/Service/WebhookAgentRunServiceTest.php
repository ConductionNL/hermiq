<?php

/**
 * Unit tests for WebhookAgentRunService (agent-webhook-trigger).
 *
 * Exercises the governed dispatch contract without a live Nextcloud/OpenRegister:
 *   - GATE 1 (kill-switch) halts the run before the agent is ever invoked and
 *     audits skipped_killswitch against the Agent's ObjectEntity;
 *   - GATE 2 (budget hard cap) halts the run and audits skipped_budget, even for
 *     an authorised approval-bypass;
 *   - GATE 3 (human approval) ensures a pending Approval (with a REDACTED
 *     payload) and audits awaiting_approval, without running the agent;
 *   - the happy path resolves the agent (both representations), runs via
 *     ScheduleService::runAgentAsOwner with the RAW (unredacted) payload folded
 *     into the prompt, and audits an 'ok' entry whose persisted payload IS
 *     redacted;
 *   - a run failure is recorded as an 'error' audit entry and never throws;
 *   - malformed contexts (missing agentId / unresolvable agent / no owner) are
 *     skipped, never invoking the agent.
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
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\Hermiq\Service\WebhookAgentRunService;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the agent-webhook-trigger WebhookAgentRunService.
 *
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
 */
class WebhookAgentRunServiceTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock AgentMapper.
	 *
	 * @var AgentMapper&MockObject
	 */
	private AgentMapper $agentMapper;

	/**
	 * Mock ScheduleService (reused kill-switch check + agent-turn dispatch).
	 *
	 * @var ScheduleService&MockObject
	 */
	private ScheduleService $scheduleService;

	/**
	 * Mock ApprovalService (reused human-approval gate).
	 *
	 * @var ApprovalService&MockObject
	 */
	private ApprovalService $approvalService;

	/**
	 * Mock BudgetService (reused budget hard-cap gate + soft-threshold warning).
	 *
	 * @var BudgetService&MockObject
	 */
	private BudgetService $budgetService;

	/**
	 * Mock AuditTrailMapper (captures explicit per-run entries).
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * Mock AgentVersionService (agent-versioning) — a stable, non-null pin by
	 * default.
	 *
	 * @var AgentVersionService&MockObject
	 */
	private AgentVersionService $agentVersionService;

	/**
	 * Recorded createAuditTrailEntry() calls: each ['action' => ..., 'context' => ...].
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $auditCalls = [];

	/**
	 * Service under test.
	 *
	 * @var WebhookAgentRunService
	 */
	private WebhookAgentRunService $service;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->agentMapper = $this->createMock(AgentMapper::class);
		$this->scheduleService = $this->createMock(ScheduleService::class);
		$this->approvalService = $this->createMock(ApprovalService::class);

		$this->budgetService = $this->createMock(BudgetService::class);
		$this->budgetService->method('isBlocked')->willReturn(false);

		$this->auditCalls = [];
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []): AuditTrail {
				$this->auditCalls[] = ['action' => $action, 'context' => $context];
				$entry = new AuditTrail();
				$entry->setAction($action);
				$entry->setChanged($context);
				return $entry;
			}
		);

		$this->agentVersionService = $this->createMock(AgentVersionService::class);
		$this->agentVersionService->method('currentVersionId')->willReturn('version-1');

		$this->service = new WebhookAgentRunService(
			objectService: $this->objectService,
			agentMapper: $this->agentMapper,
			logger: $this->createMock(LoggerInterface::class),
			auditTrailMapper: $this->auditTrailMapper,
			redactionService: new RedactionService($this->createMock(IConfig::class)),
			scheduleService: $this->scheduleService,
			approvalService: $this->approvalService,
			budgetService: $this->budgetService,
			agentVersionService: $this->agentVersionService,
			skillVersionService: $this->createMock(SkillVersionService::class),
		);

	}//end setUp()

	/**
	 * Build the Agent's ObjectEntity (audit target — hermiq-register 'agent' schema).
	 *
	 * @return ObjectEntity
	 */
	private function agentObject(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-uuid-1');
		$entity->setObject(['name' => 'Support triage']);
		return $entity;
	}//end agentObject()

	/**
	 * Build the OR-native Agent with the given owner/organisation.
	 *
	 * @param string $owner The agent's owner (acting user).
	 * @param string $organisation The agent's organisation.
	 *
	 * @return Agent
	 */
	private function agent(string $owner = 'dave', string $organisation = ''): Agent {
		$agent = new Agent();
		$agent->setUuid('agent-uuid-1');
		$agent->setOwner($owner);
		$agent->setOrganisation($organisation);
		return $agent;
	}//end agent()

	/**
	 * Build a default webhook trigger context, with overrides.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function context(array $overrides = []): array {
		$defaults = [
			'agentId' => 'agent-uuid-1',
			'payload' => ['event' => 'ping'],
			'correlationId' => 'corr-1',
			'requiresApproval' => false,
			'reviewer' => '',
			'reviewerType' => 'user',
		];

		return array_merge($defaults, $overrides);
	}//end context()

	/**
	 * Happy path: resolves the agent (both representations), runs via
	 * ScheduleService::runAgentAsOwner, and audits an 'ok' entry against the
	 * Agent's ObjectEntity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails
	 */
	public function testHappyPathRunsAgentAndAudits(): void {
		$this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
		$this->objectService->method('find')->willReturn($this->agentObject());
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->scheduleService->expects($this->once())
			->method('runAgentAsOwner')
			->with('dave', 'agent-uuid-1', $this->stringContains('"event":"ping"'), '')
			->willReturn('Acknowledged');

		$ran = $this->service->run($this->context());

		$this->assertTrue($ran);
		$this->assertCount(1, $this->auditCalls);
		$this->assertSame('agent-run', $this->auditCalls[0]['action']);
		$this->assertSame('ok', $this->auditCalls[0]['context']['status']);
		$this->assertSame('corr-1', $this->auditCalls[0]['context']['correlationId']);
		$this->assertSame('version-1', $this->auditCalls[0]['context']['agentVersion'], 'agent-versioning: the executing agent version must be pinned.');

	}//end testHappyPathRunsAgentAndAudits()

	/**
	 * GATE 1 — an engaged kill-switch halts the run before the agent is ever
	 * invoked, and audits a skipped_killswitch entry against the Agent object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails
	 */
	public function testKillSwitchHaltsRunBeforeAgent(): void {
		$this->agentMapper->method('findByUuid')->willReturn($this->agent('dave', 'org-x'));
		$this->objectService->method('find')->willReturn($this->agentObject());
		$this->scheduleService->method('isOrganisationEngaged')->with('org-x')->willReturn(true);
		$this->scheduleService->expects($this->never())->method('runAgentAsOwner');

		$ran = $this->service->run($this->context());

		$this->assertFalse($ran);
		$this->assertCount(1, $this->auditCalls);
		$this->assertSame('skipped_killswitch', $this->auditCalls[0]['context']['status']);

	}//end testKillSwitchHaltsRunBeforeAgent()

	/**
	 * GATE 2 (cost-guardrails) — a budget-exhausted organisation/agent halts a
	 * webhook-triggered run before the agent is ever invoked, and audits a
	 * skipped_budget entry — even for an authorised approval-bypass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails
	 */
	public function testBudgetHardCapHaltsRunEvenWithApprovalBypass(): void {
		$this->agentMapper->method('findByUuid')->willReturn($this->agent('dave', 'org-x'));
		$this->objectService->method('find')->willReturn($this->agentObject());
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->budgetService = $this->createMock(BudgetService::class);
		$this->budgetService->method('isBlocked')->willReturn(true);
		$this->scheduleService->expects($this->never())->method('runAgentAsOwner');

		$this->service = new WebhookAgentRunService(
			objectService: $this->objectService,
			agentMapper: $this->agentMapper,
			logger: $this->createMock(LoggerInterface::class),
			auditTrailMapper: $this->auditTrailMapper,
			redactionService: new RedactionService($this->createMock(IConfig::class)),
			scheduleService: $this->scheduleService,
			approvalService: $this->approvalService,
			budgetService: $this->budgetService,
			agentVersionService: $this->agentVersionService,
			skillVersionService: $this->createMock(SkillVersionService::class),
		);

		$ran = $this->service->run($this->context(['requiresApproval' => true]), true);

		$this->assertFalse($ran);
		$this->assertSame('skipped_budget', $this->auditCalls[0]['context']['status']);

	}//end testBudgetHardCapHaltsRunEvenWithApprovalBypass()

	/**
	 * GATE 3 — requiresApproval=true (and no bypass) ensures a pending Approval
	 * via ApprovalService with a REDACTED payload, does NOT run the agent, and
	 * audits awaiting_approval.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails
	 */
	public function testApprovalGateEnsuresPendingWithRedactedContext(): void {
		$this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
		$this->objectService->method('find')->willReturn($this->agentObject());
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->scheduleService->expects($this->never())->method('runAgentAsOwner');

		$secretLikePayload = ['token' => 'sk-abcdefghijklmnopqrst'];

		$this->approvalService->expects($this->once())
			->method('ensurePendingApprovalForWebhookRun')
			->with(
				$this->callback(
					function (array $context) {
						// The persisted context's payload must be REDACTED (not the raw secret).
						$encoded = json_encode($context['payload']);
						return str_contains((string)$encoded, 'sk-abcdefghijklmnopqrst') === false;
					}
				),
				'dave'
			);

		$ran = $this->service->run(
			$this->context(['requiresApproval' => true, 'payload' => $secretLikePayload])
		);

		$this->assertFalse($ran);
		$this->assertCount(1, $this->auditCalls);
		$this->assertSame('awaiting_approval', $this->auditCalls[0]['context']['status']);

	}//end testApprovalGateEnsuresPendingWithRedactedContext()

	/**
	 * The approval bypass (an authorised approval-run) skips GATE 3 and runs
	 * the agent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails
	 */
	public function testApprovalBypassRunsAgentWithoutGating(): void {
		$this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
		$this->objectService->method('find')->willReturn($this->agentObject());
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->scheduleService->expects($this->once())->method('runAgentAsOwner')->willReturn('output');
		$this->approvalService->expects($this->never())->method('ensurePendingApprovalForWebhookRun');

		$ran = $this->service->run($this->context(['requiresApproval' => true]), true);

		$this->assertTrue($ran);

	}//end testApprovalBypassRunsAgentWithoutGating()

	/**
	 * The redaction-before-persistence requirement: a successful run's audit
	 * entry has its payload masked, while the agent's ACTUAL run input (the
	 * prompt handed to runAgentAsOwner) contains the unredacted token.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-webhook-payload-becomes-run-input-redacted-before-persistence
	 */
	public function testAuditRedactsPayloadWhileAgentReceivesRawInput(): void {
		$this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
		$this->objectService->method('find')->willReturn($this->agentObject());
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);

		$capturedPrompt = '';
		$this->scheduleService->method('runAgentAsOwner')->willReturnCallback(
			function (string $owner, string $agentId, string $prompt) use (&$capturedPrompt): string {
				$capturedPrompt = $prompt;
				return 'ok';
			}
		);

		$token = 'sk-abcdefghijklmnopqrst';
		$ran = $this->service->run($this->context(['payload' => ['apiKey' => $token]]));

		$this->assertTrue($ran);
		// The agent's ACTUAL input carries the unredacted token.
		$this->assertStringContainsString($token, $capturedPrompt);
		// The PERSISTED audit entry's payload does NOT.
		$persistedPayload = json_encode($this->auditCalls[0]['context']['payload']);
		$this->assertStringNotContainsString($token, (string)$persistedPayload);

	}//end testAuditRedactsPayloadWhileAgentReceivesRawInput()

	/**
	 * A run failure (the agent turn throws) is recorded as an 'error' audit
	 * entry and never propagates — run() always returns, never throws.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-8-test-suite-completion-and-full-suite-regression-check
	 */
	public function testRunFailureIsAuditedAndNeverThrows(): void {
		$this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
		$this->objectService->method('find')->willReturn($this->agentObject());
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->scheduleService->method('runAgentAsOwner')->willThrowException(new RuntimeException('LLM down'));

		$ran = $this->service->run($this->context());

		$this->assertFalse($ran);
		$this->assertCount(1, $this->auditCalls);
		$this->assertSame('error', $this->auditCalls[0]['context']['status']);

	}//end testRunFailureIsAuditedAndNeverThrows()

	/**
	 * A context missing agentId is skipped without ever touching AgentMapper.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
	 */
	public function testMissingAgentIdIsSkipped(): void {
		$this->agentMapper->expects($this->never())->method('findByUuid');

		$ran = $this->service->run($this->context(['agentId' => '']));

		$this->assertFalse($ran);

	}//end testMissingAgentIdIsSkipped()

	/**
	 * An unresolvable agent (AgentMapper throws) is skipped without ever
	 * invoking the agent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
	 */
	public function testUnresolvableAgentIsSkipped(): void {
		$this->agentMapper->method('findByUuid')->willThrowException(new RuntimeException('not found'));
		$this->scheduleService->expects($this->never())->method('runAgentAsOwner');

		$ran = $this->service->run($this->context());

		$this->assertFalse($ran);

	}//end testUnresolvableAgentIsSkipped()

	/**
	 * When the resolved agent has no owner, the run is skipped — there is no
	 * acting user to impersonate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
	 */
	public function testAgentWithNoOwnerIsSkipped(): void {
		$this->agentMapper->method('findByUuid')->willReturn($this->agent(''));
		$this->objectService->method('find')->willReturn($this->agentObject());
		$this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
		$this->scheduleService->expects($this->never())->method('runAgentAsOwner');

		$ran = $this->service->run($this->context());

		$this->assertFalse($ran);

	}//end testAgentWithNoOwnerIsSkipped()

	/**
	 * When the Agent's ObjectEntity cannot be resolved (audit target missing),
	 * the run is skipped without invoking the agent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
	 */
	public function testMissingAgentObjectIsSkipped(): void {
		$this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
		$this->objectService->method('find')->willReturn(null);
		$this->scheduleService->expects($this->never())->method('runAgentAsOwner');

		$ran = $this->service->run($this->context());

		$this->assertFalse($ran);

	}//end testMissingAgentObjectIsSkipped()
}//end class
