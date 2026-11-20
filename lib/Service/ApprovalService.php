<?php

/**
 * Hermiq ApprovalService.
 *
 * The human-approval gate write-path (EU AI Act Art. 14). Gates four kinds of
 * gated action:
 *
 * - A **scheduled** run (`sourceType: "schedule"`, the original shape): when the
 *   dispatcher meets a schedule that requires approval it asks this service to
 *   ensure exactly ONE pending `Approval` OpenRegister object exists for that
 *   schedule (idempotent — never one per tick), routed to the schedule's
 *   resolved reviewer. Approval re-runs via `ScheduleService::runNow()` with the
 *   approval gate bypassed.
 * - A **flow-triggered** run (`sourceType: "flow"`, from OpenRegister's
 *   `AgentRunRequestedEvent` — ADR-041): `FlowAgentRunService` asks this service
 *   to ensure a pending Approval carrying the run's resume context
 *   (`flowContext`), keyed by the event's `correlationId` for idempotency.
 *   Approval re-runs via `FlowAgentRunService::run()` with the gate bypassed.
 * - A **webhook-triggered** run (`sourceType: "webhook"`, from a verified
 *   inbound webhook trigger — agent-webhook-trigger): `WebhookAgentRunService`
 *   asks this service to ensure a pending Approval carrying the run's resume
 *   context (`webhookContext`, its payload already redacted before it reaches
 *   this service), keyed by the trigger's own generated `correlationId`.
 *   Approval re-runs via `WebhookAgentRunService::run()` with the gate bypassed.
 * - An **un-granted destructive tool invocation** (`sourceType: "tool"`,
 *   agent-tool-governance-and-disclosure): `FacadeToolInvoker` asks this service
 *   to ensure a pending Approval for a specific (agentId, toolId) pair mid-run,
 *   keyed by that pair for idempotency. There is no run to auto-resume on
 *   approval (the chat turn that attempted the call has already returned) — the
 *   decision simply flips to `approved`/`denied` so the NEXT invocation attempt
 *   of that (agentId, toolId) pair — `FacadeToolInvoker::handleGatedInvocation()`
 *   — proceeds or is blocked permanently.
 * - A **guardrail-policy `confirm`-classified tool call** (`sourceType:
 *   "toolcall"`, agent-guardrails): `FacadeToolInvoker` asks this service to
 *   ensure a pending Approval for a specific agentId+toolId+arguments
 *   combination, keyed by a `correlationId` hash of that combination for
 *   idempotency — distinct from `sourceType: "tool"`: an approval here is
 *   single-use and TTL-bounded (design.md Decision 4), never a permanent
 *   per-(agentId,toolId) decision. Approving it does NOT re-execute anything
 *   (Decision 5, `resumeGatedRun()`'s `toolcall` branch is a deliberate no-op)
 *   — it authorises exactly one subsequent, argument-matching retry, which
 *   `FacadeToolInvoker::handleConfirmClassifiedInvocation()` consumes.
 *
 * - A **skill consolidation draft** (`sourceType: "skill-draft"`,
 *   skill-self-improvement, ADR-068 §5): `SkillConsolidationService` asks this
 *   service to ensure a pending Approval for a pre-qualified `SkillDraft`, keyed
 *   by the draft's UUID for idempotency. The Approval's `draftPayload` (deep link
 *   to the SkillDetail review surface, scan verdict, eval delta or the explicit
 *   `noEvalEvidence` flag, driving-learnings summary) is REQUIRED at creation —
 *   an Approval missing any of it is rejected as invalid and never reaches an
 *   inbox. The pending→`approved` TRANSITION is the ONLY applier of the draft's
 *   content (from ANY surface, the generic inbox included); denial reconciles the
 *   draft to `rejected`. An edited-but-not-yet-requalified draft blocks the
 *   transition entirely (the approve is refused, the Approval stays pending).
 *
 * Either way, a reviewer (or an instance admin) later approves or denies:
 * approve transitions the object to `approved`, audits the decision, and
 * dispatches the resume path matching `sourceType`; deny transitions to
 * `denied` and never runs. Every decision is written to OpenRegister's
 * hash-chained AuditTrail after redaction.
 *
 * This is a recognised ADR-031 imperative exception: a side-effecting governance
 * service, not a derived value or declarative lifecycle. All persistence flows through
 * OpenRegister's ObjectService single write-path (ADR-001, ADR-004), so tenancy and
 * the audit trail are inherited. Hermiq owns no LLM/tool engine.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Hermiq\Kernel\Contracts\ApprovalServiceInterface;
use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Service\Approval\ApprovalDecision;
use OCA\Hermiq\Service\Approval\ApprovalTarget;
use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\Hermiq\Service\Approval\DraftEditRefused;
use OCA\Hermiq\Service\Approval\DraftRelease;
use OCA\Hermiq\Service\Approval\QuestionAnswer;
use OCA\Hermiq\Service\ComputerUse\ComputerRunGrant;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates, routes, and decides Hermiq approval-gate objects via OpenRegister.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Coordinates several OR/NC services.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class owns FIVE parallel
 *   sourceType shapes (schedule/flow/webhook/tool/toolcall) — each
 *   ensurePendingApprovalFor*()/runApproved*() pair is individually simple; the sum
 *   crosses the class-wide threshold because each generalisation (webhook, tool,
 *   toolcall) added its own pair rather than duplicating an unrelated class, per the
 *   established "generalise ApprovalService" pattern (flow-agent-listener,
 *   agent-webhook-trigger, agent-tool-governance-and-disclosure, agent-guardrails).
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Same five-sourceType generalisation:
 *   each ensure/run/shape trio is short but the class hosts all five shapes.
 * @SuppressWarnings(PHPMD.TooManyMethods)           One ensure/find/resume method set per
 *   sourceType (schedule/flow/webhook/tool/toolcall/skill-draft) by design.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Each sourceType exposes its own public
 *   ensure/find/decide entry points consumed by distinct callers.
 *
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
 */
class ApprovalService implements ApprovalServiceInterface {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for approval objects.
	 *
	 * @var string
	 */
	private const APPROVAL_SCHEMA = 'approval';

	/**
	 * The source type of a pre-authorisation of computer actions on one machine for one run.
	 *
	 * @var string
	 */
	public const COMPUTER_RUN = ComputerRunGrant::SOURCE_TYPE;

	/**
	 * How long such a pre-authorisation lasts, at most (REQ-COMPUTER-003).
	 *
	 * @var int
	 */
	public const COMPUTER_RUN_MINUTES = ComputerRunGrant::MINUTES;

	/**
	 * OpenRegister schema slug for schedule objects.
	 *
	 * @var string
	 */
	private const SCHEDULE_SCHEMA = 'schedule';

	/**
	 * OpenRegister schema slug for agent objects (tool-invocation approvals'
	 * owner-impersonation lookup — agent-tool-governance-and-disclosure).
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * The validity window (seconds) an APPROVED `toolcall` Approval authorises
	 * exactly one matching retry within, before the authorization expires and a
	 * further identical attempt is treated as brand new (design.md Decision 4).
	 * A fixed class constant — no new schema field.
	 *
	 * @var int
	 */
	public const TOOLCALL_APPROVAL_TTL_SECONDS = 3600;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read/write (single write-path).
	 * @param IUserSession $userSession Session used to impersonate the schedule owner on save.
	 * @param IUserManager $userManager Resolves the owner UID to an IUser.
	 * @param IGroupManager $groupManager Resolves reviewer-group membership + instance-admin.
	 * @param DeliveryService $deliveryService Notifies the resolved reviewer (Talk/Notifications).
	 * @param AuditTrailMapper $auditTrailMapper OR audit write-path for the decision entry.
	 * @param RedactionService $redactionService Masks the (user-supplied) reason before the audit write.
	 * @param ContainerInterface $container Server container for lazy ScheduleService resolution.
	 * @param LoggerInterface $logger PSR-3 logger (non-fatal gate diagnostics).
	 * @param ApprovalTaskBridge $taskBridge Mirrors pending Approvals as OpenRegister tasks
	 *   and releases the mirrors on decision (approval-task convergence, phase 1).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is
	 *   a distinct injected collaborator, not a logic-bearing argument list.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly DeliveryService $deliveryService,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly RedactionService $redactionService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ApprovalTaskBridge $taskBridge,
	) {
	}//end __construct()

	/**
	 * Idempotently ensure a single pending Approval exists for a gated schedule.
	 *
	 * If a pending Approval already exists for this schedule, this is a no-op — the
	 * dispatcher calls it every tick while the schedule stays due, so it must NOT
	 * create (or re-notify) a duplicate. Otherwise it creates one pending Approval,
	 * routed to the schedule's resolved reviewer, and notifies that reviewer.
	 *
	 * @param ObjectEntity $schedule The gated schedule due to run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
	 */
	public function ensurePendingApproval(ObjectEntity $schedule): void {
		$scheduleId = (string)$schedule->getUuid();
		if ($scheduleId === '') {
			return;
		}

		// Idempotency: never enqueue a second pending Approval for the same schedule.
		if ($this->findPendingApprovalForSchedule(scheduleId: $scheduleId) !== null) {
			return;
		}

		[$reviewer, $reviewerType] = $this->resolveReviewer(schedule: $schedule);

		$data = $schedule->getObject();
		$owner = (string)($schedule->getOwner() ?? '');

		$payload = [
			'status' => 'pending',
			'sourceType' => 'schedule',
			'scheduleId' => $scheduleId,
			'agentId' => (string)($data['agentId'] ?? ''),
			'prompt' => (string)($data['prompt'] ?? ''),
			'requestedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
			'reviewer' => $reviewer,
			'reviewerType' => $reviewerType,
			'decidedAt' => null,
			'decidedBy' => null,
			'reason' => null,
		];

		$approval = $this->persistApproval(data: $payload, target: new ApprovalTarget(uuid: null, owner: $owner));

		// Notify the resolved reviewer(s). Never fatal to the dispatch tick.
		try {
			$this->deliveryService->deliverApprovalRequest(
				schedule: $schedule,
				approval: $approval,
				reviewerUids: $this->reviewerUids(reviewer: $reviewer, reviewerType: $reviewerType)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not notify reviewer for approval ' . ((string)$approval->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end ensurePendingApproval()

	/**
	 * Idempotently ensure a single pending Approval exists for a gated
	 * flow-triggered agent run (OpenRegister's `AgentRunRequestedEvent`, ADR-041).
	 *
	 * Mirrors `ensurePendingApproval()` for the schedule case, but the run has no
	 * "schedule owner" to impersonate or notify through the same reviewer-resolution
	 * path — the reviewer defaults to the agent's own `owner` (the same account the
	 * run itself acts as; see `FlowAgentRunService`), falling back to the `admin`
	 * group when the agent has no owner. Idempotency is keyed by the event's
	 * `correlationId` rather than a `scheduleId` (there is no Schedule object here).
	 *
	 * @param array<string,mixed> $context The flow-run payload (subjectUuid/subjectRegister/
	 *                                     subjectSchema/agent/skill/prompt/resultField/mode/
	 *                                     flowName/correlationId) from
	 *                                     AgentRunRequestedEvent::getPayload().
	 * @param string $agentOwner The agent's acting user (reviewer default + impersonation).
	 *
	 * @return ObjectEntity The pending (or already-pending) Approval.
	 *
	 * @spec openspec/changes/archive/2026-09-06-flow-agent-listener/tasks.md#3-approvalservice-generalisation-sourcetype-flow
	 */
	public function ensurePendingApprovalForFlowRun(array $context, string $agentOwner): ObjectEntity {
		$correlationId = (string)($context['correlationId'] ?? '');

		if ($correlationId !== '') {
			$existing = $this->findPendingApprovalForCorrelation(correlationId: $correlationId);
			if ($existing !== null) {
				return $existing;
			}
		}

		$review = $this->reviewFor(owner: $agentOwner);
		$payload = [
			'status' => 'pending',
			'sourceType' => 'flow',
			'correlationId' => $correlationId,
			'flowContext' => $context,
			'agentId' => (string)($context['agent'] ?? ''),
			'prompt' => (string)($context['prompt'] ?? ''),
			...$review,
			'decidedAt' => null,
			'decidedBy' => null,
			'reason' => null,
		];

		$approval = $this->persistApproval(data: $payload, target: new ApprovalTarget(uuid: null, owner: $agentOwner));

		// Notify the resolved reviewer(s). Never fatal to the run.
		try {
			$this->deliveryService->deliverApprovalRequestForFlowRun(
				approval: $approval,
				reviewerUids: $this->reviewerUids(reviewer: $review['reviewer'], reviewerType: $review['reviewerType'])
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not notify reviewer for flow-run approval '
				. ((string)$approval->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}

		return $approval;
	}//end ensurePendingApprovalForFlowRun()

	/**
	 * Idempotently ensure a single pending Approval exists for a gated
	 * webhook-triggered agent run (agent-webhook-trigger).
	 *
	 * Mirrors `ensurePendingApprovalForFlowRun()` for the flow-run case, but the
	 * reviewer is resolved from the webhook's OWN configured `reviewer`/
	 * `reviewerType` (the `AgentWebhook` schema mirrors `Schedule`'s identical
	 * fields, unlike a flow trigger which has no comparable object) — an empty
	 * configured reviewer falls back to the agent owner as a `user` reviewer,
	 * exactly like `resolveReviewer()` does for a Schedule. Idempotency is keyed
	 * by the trigger's own generated `correlationId`, exactly like the flow-run
	 * case (there is no Schedule object here either).
	 *
	 * The caller (`WebhookAgentRunService`) is responsible for passing a
	 * `$context` whose `payload` is ALREADY redacted (redaction-before-persist);
	 * this method persists `$context` verbatim as `webhookContext`.
	 *
	 * @param array<string,mixed> $context The webhook-run resume context
	 *                                     (agentId/payload(redacted)/correlationId/
	 *                                     requiresApproval/reviewer/reviewerType).
	 * @param string $agentOwner The agent's owner (reviewer fallback + impersonation).
	 *
	 * @return ObjectEntity The pending (or already-pending) Approval.
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-5-approvalservice-sourcetype-webhook-generalisation
	 */
	public function ensurePendingApprovalForWebhookRun(array $context, string $agentOwner): ObjectEntity {
		$correlationId = (string)($context['correlationId'] ?? '');

		if ($correlationId !== '') {
			$existing = $this->findPendingApprovalForCorrelation(correlationId: $correlationId);
			if ($existing !== null) {
				return $existing;
			}
		}

		$reviewer = trim((string)($context['reviewer'] ?? ''));
		$reviewerType = (string)($context['reviewerType'] ?? 'user');
		if ($reviewer === '') {
			$reviewer = $agentOwner;
			$reviewerType = 'user';
		}

		if ($reviewerType !== 'group') {
			$reviewerType = 'user';
		}

		$payload = [
			'status' => 'pending',
			'sourceType' => 'webhook',
			'correlationId' => $correlationId,
			'webhookContext' => $context,
			'agentId' => (string)($context['agentId'] ?? ''),
			'prompt' => '',
			'requestedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
			'reviewer' => $reviewer,
			'reviewerType' => $reviewerType,
			'decidedAt' => null,
			'decidedBy' => null,
			'reason' => null,
		];

		$approval = $this->persistApproval(data: $payload, target: new ApprovalTarget(uuid: null, owner: $agentOwner));

		// Notify the resolved reviewer(s). Never fatal to the run.
		try {
			$this->deliveryService->deliverApprovalRequestForWebhookRun(
				approval: $approval,
				reviewerUids: $this->reviewerUids(reviewer: $reviewer, reviewerType: $reviewerType)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not notify reviewer for webhook-run approval '
				. ((string)$approval->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}

		return $approval;
	}//end ensurePendingApprovalForWebhookRun()

	/**
	 * Idempotently ensure a single pending Approval exists for an un-granted
	 * destructive tool invocation attempted mid-run (agent-tool-governance-and-disclosure,
	 * EU AI Act Art. 14). Mirrors `ensurePendingApprovalForFlowRun()`'s idempotent-ensure
	 * shape, keyed by the (agentId, toolId) pair instead of a correlation id — a
	 * repeated attempt of the SAME tool by the SAME agent while a decision is
	 * still pending returns the SAME Approval, never a duplicate.
	 *
	 * The reviewer defaults to the agent's own owner (mirrors the flow-run
	 * default), falling back to the `admin` group when the agent has no owner
	 * or cannot be resolved.
	 *
	 * @param string $agentId The agent UUID attempting the invocation.
	 * @param string $toolId The full namespaced tool id (dotted `mcpId` form).
	 * @param array<string, mixed> $arguments The invocation's arguments (never persisted
	 *                                        verbatim — only a short summary is stored,
	 *                                        matching the ADR-063 audit's "never raw
	 *                                        payloads" posture).
	 *
	 * @return ObjectEntity The pending (or already-pending) Approval.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#scenario-an-agent-attempts-an-un-granted-destructive-tool-call
	 */
	public function ensurePendingApprovalForToolInvocation(string $agentId, string $toolId, array $arguments): ObjectEntity {
		$existing = $this->findPendingApprovalForToolInvocation(agentId: $agentId, toolId: $toolId);
		if ($existing !== null) {
			return $existing;
		}

		$owner = $this->resolveAgentOwner(agentId: $agentId);

		$payload = [
			'status' => 'pending',
			'sourceType' => 'tool',
			'agentId' => $agentId,
			'toolId' => $toolId,
			'prompt' => $this->summarizeArguments(arguments: $arguments),
			...$this->reviewFor(owner: $owner),
			'decidedAt' => null,
			'decidedBy' => null,
			'reason' => null,
		];

		return $this->openToolApproval(payload: $payload, label: 'reviewer for tool-invocation approval');
	}//end ensurePendingApprovalForToolInvocation()

	/**
	 * Idempotently ensure a single pending `toolcall` Approval exists for a
	 * `confirm`-classified tool call attempted mid-run (agent-guardrails, EU AI
	 * Act Art. 14). Mirrors `ensurePendingApprovalForToolInvocation()`'s
	 * idempotent-ensure shape, but keyed by `correlationId` (a hash of
	 * agentId+toolId+arguments — see `FacadeToolInvoker::toolCallCorrelationId()`)
	 * rather than the bare (agentId, toolId) pair, since a `confirm` Approval is
	 * scoped to one specific set of arguments, not the tool as a whole (design.md
	 * Decision 4) — distinct from `ensurePendingApprovalForToolInvocation()`'s
	 * `sourceType: "tool"`.
	 *
	 * The reviewer defaults to the agent's own owner (mirrors the tool-invocation
	 * default), falling back to the `admin` group when the agent has no owner
	 * or cannot be resolved. The arguments are redacted before persistence
	 * (redaction-before-persist) — never stored raw.
	 *
	 * @param string $agentId The agent UUID attempting the invocation.
	 * @param string $toolId The full namespaced tool id (dotted `mcpId` form).
	 * @param array<string, mixed> $arguments The invocation's arguments (redacted before
	 *                                        persistence).
	 * @param string $correlationId The hash of agentId+toolId+arguments
	 *                              (idempotency key).
	 * @param string $runId The run a computer action belongs to; '' for any other call.
	 *
	 * @return ObjectEntity The pending (or already-pending) Approval.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate
	 */
	public function ensurePendingApprovalForToolCall(
		string $agentId,
		string $toolId,
		array $arguments,
		string $correlationId,
		string $runId = '',
	): ObjectEntity {
		$existing = $this->findPendingApprovalForToolCall(correlationId: $correlationId);
		if ($existing !== null) {
			return $existing;
		}

		$owner = $this->resolveAgentOwner(agentId: $agentId);

		$payload = [
			'status' => 'pending',
			'sourceType' => 'toolcall',
			'correlationId' => $correlationId,
			'agentId' => $agentId,
			'toolId' => $toolId,
			'toolArguments' => $this->redactedArguments(arguments: $arguments),
			'prompt' => $this->summarizeArguments(arguments: $arguments),
			...$this->reviewFor(owner: $owner),
			'decidedAt' => null,
			'decidedBy' => null,
			'reason' => null,
			'consumedAt' => null,
		];
		// Tools-browser-and-computer-use D5: the run the call belongs to, so a
		// reviewer can approve the actions on one machine for this run only.
		if ($runId !== '') {
			$payload['runId'] = $runId;
		}

		return $this->openToolApproval(payload: $payload, label: 'reviewer for toolcall approval');
	}//end ensurePendingApprovalForToolCall()

	/**
	 * Idempotently raise one pending `toolcall` Approval for a batch another app
	 * staged (integriq's agent tools answer `status: staged` with a binding).
	 *
	 * The approval stores the binding as given: Hermiq never recomputes it, it
	 * only compares it when the staging app asks for a verdict. The key is
	 * sha256 over agent, tool and binding, so the same batch is raised once and
	 * another batch, tool or agent gets its own approval. The decision fields are
	 * omitted rather than null: the register types them and refuses a null.
	 *
	 * @param string            $agentId    The agent that staged the batch.
	 * @param string            $toolId     The staging tool id.
	 * @param string            $proposalId The staging app's proposal id.
	 * @param string            $binding    The staging app's sha256 batch binding.
	 * @param array<int,string> $targetIds  The ids the batch would act on.
	 *
	 * @return ObjectEntity The pending (or already pending) Approval.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-staged-batch-raises-an-approval-that-keeps-its-binding-req-apver-002
	 */
	public function ensurePendingApprovalForStagedBatch(
		string $agentId,
		string $toolId,
		string $proposalId,
		string $binding,
		array $targetIds,
	): ObjectEntity {
		$correlationId = hash('sha256', (string)json_encode([$agentId, $toolId, 'batch', $binding]));
		$existing = $this->findPendingApprovalForToolCall(correlationId: $correlationId);
		if ($existing !== null) {
			return $existing;
		}

		$owner = $this->resolveAgentOwner(agentId: $agentId);

		$payload = [
			'status' => 'pending',
			'sourceType' => 'toolcall',
			'correlationId' => $correlationId,
			'agentId' => $agentId,
			'toolId' => $toolId,
			'binding' => $binding,
			'toolArguments' => ['proposalId' => $proposalId, 'targetIds' => array_values($targetIds)],
			'prompt' => 'Run ' . $toolId . ' on ' . count($targetIds) . ' records (proposal ' . $proposalId . ').',
			...$this->reviewFor(owner: $owner),
		];

		return $this->openToolApproval(payload: $payload, label: 'the reviewer of staged batch approval');
	}//end ensurePendingApprovalForStagedBatch()

	/**
	 * Idempotently ensure a single pending Approval exists for a pre-qualified
	 * skill consolidation draft (skill-self-improvement, EU AI Act Art. 14).
	 * Mirrors the other ensure* shapes, keyed by the draft's UUID.
	 *
	 * The `draftPayload` decision evidence is REQUIRED at creation: the SkillDetail
	 * deep link, the scan verdict, the eval delta OR the explicit `noEvalEvidence`
	 * flag, and the one-line driving-learnings summary — an Approval missing any of
	 * them is rejected as invalid (`InvalidArgumentException`) and never persisted,
	 * so it never reaches any approval surface; the draft stays awaiting a valid
	 * Approval. The reviewer defaults to the skill's owner, falling back to the
	 * `admin` group (the flow-run default).
	 *
	 * @param ObjectEntity $draft The pre-qualified draft (`awaiting-approval`).
	 * @param ObjectEntity $skill The skill the draft targets.
	 * @param array<string, mixed> $draftPayload The REQUIRED decision-evidence payload.
	 *
	 * @return ObjectEntity The pending (or already-pending) Approval.
	 *
	 * @throws InvalidArgumentException When the decision-evidence payload is incomplete.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function ensurePendingApprovalForSkillDraft(
		ObjectEntity $draft,
		ObjectEntity $skill,
		array $draftPayload,
	): ObjectEntity {
		$this->assertSkillDraftPayloadComplete(draftPayload: $draftPayload);

		$draftId = (string)$draft->getUuid();
		$existing = $this->findPendingApprovalForSkillDraft(draftId: $draftId);
		if ($existing !== null) {
			return $existing;
		}

		$owner = (string)($skill->getOwner() ?? '');
		$review = $this->reviewFor(owner: $owner);

		// NOTE: `agentId` is OMITTED — a skill-draft Approval gates a skill
		// version, not an agent run, and OR validates `format: uuid` on any
		// PRESENT value (an empty string fails validation and made every
		// skill-draft Approval write silently impossible).
		$payload = [
			'status' => 'pending',
			'sourceType' => 'skill-draft',
			'draftId' => $draftId,
			'skillId' => (string)($draft->getObject()['skillId'] ?? ''),
			'draftPayload' => $draftPayload,
			'prompt' => (string)($draftPayload['learningsSummary'] ?? ''),
			...$review,
			'decidedAt' => null,
			'decidedBy' => null,
			'reason' => null,
		];

		$approval = $this->persistApproval(data: $payload, target: new ApprovalTarget(uuid: null, owner: $owner));

		// Notify the resolved reviewer(s) — the existing pending-approval ping.
		// Never fatal to the pipeline.
		try {
			$this->deliveryService->deliverApprovalRequestForSkillDraft(
				approval: $approval,
				reviewerUids: $this->reviewerUids(reviewer: $review['reviewer'], reviewerType: $review['reviewerType']),
				skillName: (string)($skill->getObject()['name'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not notify reviewer for skill-draft approval '
				. ((string)$approval->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}

		return $approval;
	}//end ensurePendingApprovalForSkillDraft()

	/**
	 * Enforce the skill-draft Approval's decision-evidence contract: a payload
	 * missing the deep link, the scan verdict, the learnings summary, or BOTH the
	 * eval delta and the `noEvalEvidence` flag is invalid — the Approval is never
	 * created (payload-incomplete Approvals must not reach an inbox).
	 *
	 * @param array<string, mixed> $draftPayload The candidate payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When any required element is missing.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	private function assertSkillDraftPayloadComplete(array $draftPayload): void {
		if (trim((string)($draftPayload['deepLink'] ?? '')) === '') {
			throw new InvalidArgumentException('Skill-draft Approval payload is missing the SkillDetail deep link.');
		}

		if (trim((string)($draftPayload['scanVerdict'] ?? '')) === '') {
			throw new InvalidArgumentException('Skill-draft Approval payload is missing the scan verdict.');
		}

		$hasDelta = (isset($draftPayload['evalDelta']) === true && is_numeric($draftPayload['evalDelta']) === true);
		if ($hasDelta === false && ($draftPayload['noEvalEvidence'] ?? false) !== true) {
			throw new InvalidArgumentException('Skill-draft Approval payload is missing the eval delta / noEvalEvidence flag.');
		}

		if (trim((string)($draftPayload['learningsSummary'] ?? '')) === '') {
			throw new InvalidArgumentException('Skill-draft Approval payload is missing the driving-learnings summary.');
		}

	}//end assertSkillDraftPayloadComplete()

	/**
	 * Find the open pending Approval for a skill draft, if one exists — the
	 * skill-draft counterpart to `findPendingApprovalForSchedule()`.
	 *
	 * @param string $draftId The SkillDraft UUID.
	 *
	 * @return ObjectEntity|null The pending approval, or null.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function findPendingApprovalForSkillDraft(string $draftId): ?ObjectEntity {
		if ($draftId === '') {
			return null;
		}

		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::APPROVAL_SCHEMA)
			->findAll(
				config: ['filters' => ['draftId' => $draftId, 'status' => 'pending']],
				_rbac: false,
				_multitenancy: false
			);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $object->getObject();
			if ((string)($data['draftId'] ?? '') === $draftId
				&& (string)($data['status'] ?? '') === 'pending'
			) {
				return $object;
			}
		}

		return null;
	}//end findPendingApprovalForSkillDraft()

	/**
	 * Find the open pending `toolcall` Approval for a correlationId, if one
	 * exists — consulted by `FacadeToolInvoker` so a repeated attempt while a
	 * decision is still pending never creates a duplicate (idempotent).
	 *
	 * @param string $correlationId The agentId+toolId+arguments hash.
	 *
	 * @return ObjectEntity|null The pending approval, or null.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate
	 */
	public function findPendingApprovalForToolCall(string $correlationId): ?ObjectEntity {
		foreach ($this->toolCallApprovals(correlationId: $correlationId) as $object) {
			if ((string)($object->getObject()['status'] ?? '') === 'pending') {
				return $object;
			}
		}

		return null;
	}//end findPendingApprovalForToolCall()

	/**
	 * Find an APPROVED, UNCONSUMED `toolcall` Approval for a correlationId
	 * whose decision is still within the validity window, if one exists —
	 * consulted by `FacadeToolInvoker` before invoking a `confirm`-classified
	 * tool: only this Approval authorises the retry to actually proceed
	 * (design.md Decision 4).
	 *
	 * @param string $correlationId The agentId+toolId+arguments hash.
	 *
	 * @return ObjectEntity|null The approved, unconsumed, unexpired approval, or null.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate
	 */
	public function findApprovedUnconsumedToolCallApproval(string $correlationId): ?ObjectEntity {
		foreach ($this->toolCallApprovals(correlationId: $correlationId) as $object) {
			$data = $object->getObject();
			if ((string)($data['status'] ?? '') !== 'approved') {
				continue;
			}

			if ($this->isConsumed(data: $data) === true) {
				continue;
			}

			if ($this->isWithinToolCallTtl(data: $data) === false) {
				continue;
			}

			return $object;
		}

		return null;
	}//end findApprovedUnconsumedToolCallApproval()

	/**
	 * The run-scoped pre-authorisation for a governed workspace, if one was
	 * requested for this agent and run (hermiq-runner-git-capability).
	 *
	 * An approved one wins over a pending or denied one, so a re-request after a
	 * decision cannot shadow it; a denied one wins over a pending one.
	 *
	 * @param string $agentId The agent UUID, from the verified run token.
	 * @param string $runId   The run id, from the verified run token.
	 *
	 * @return array{uuid: string, status: string, decidedBy: string}|null
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-a-run-scoped-pre-authorisation-covers-the-run-and-nothing-else
	 */
	public function runPreAuthorisation(string $agentId, string $runId): ?array {
		$found = null;
		$rank = ['pending' => 1, 'denied' => 2, 'approved' => 3];
		foreach ($this->runPreAuthorisations(correlationId: $this->runPreAuthorisationKey(agentId: $agentId, runId: $runId)) as $object) {
			$data = $object->getObject();
			$status = (string)($data['status'] ?? '');
			if (isset($rank[$status]) === false) {
				continue;
			}

			if ($found === null || $rank[$status] > $rank[$found['status']]) {
				$found = ['uuid' => (string)$object->getUuid(), 'status' => $status, 'decidedBy' => (string)($data['decidedBy'] ?? '')];
			}
		}

		return $found;
	}//end runPreAuthorisation()

	/**
	 * Request a run-scoped pre-authorisation: one pending Approval naming the
	 * agent, the run, the repository and the ref, routed to the agent's owner
	 * (or the `admin` group for an agent without one).
	 *
	 * @param string $agentId    The agent UUID.
	 * @param string $runId      The run id.
	 * @param string $toolId     The write-shaped tool that asked first.
	 * @param string $repository The workspace's repository slug.
	 * @param string $ref        The workspace's starting ref.
	 *
	 * @return array{uuid: string, status: string, decidedBy: string}
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form
	 */
	public function requestRunPreAuthorisation(string $agentId, string $runId, string $toolId, string $repository, string $ref): array {
		$owner = $this->resolveAgentOwner(agentId: $agentId);

		$payload = [
			'status' => 'pending',
			'sourceType' => 'workspace-run',
			'correlationId' => $this->runPreAuthorisationKey(agentId: $agentId, runId: $runId),
			'agentId' => $agentId,
			'toolId' => $toolId,
			'toolArguments' => ['runId' => $runId, 'repository' => $repository, 'ref' => $ref],
			'prompt' => 'Let this agent change files and commit in ' . $repository . ' (from ' . $ref . ') for the rest of this run.',
			...$this->reviewFor(owner: $owner),
		];

		// The decision fields (decidedAt, decidedBy, reason) are OMITTED rather
		// than null: the register types them string and date-time and refuses a
		// null, and a pending record has none.
		$approval = $this->openToolApproval(payload: $payload, label: 'the reviewer of workspace approval');

		return ['uuid' => (string)$approval->getUuid(), 'status' => 'pending', 'decidedBy' => ''];
	}//end requestRunPreAuthorisation()

	/**
	 * The correlation key of a run's pre-authorisation.
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $runId   The run id.
	 *
	 * @return string
	 */
	private function runPreAuthorisationKey(string $agentId, string $runId): string {
		return hash('sha256', 'workspace-run|' . $agentId . '|' . $runId);
	}//end runPreAuthorisationKey()

	/**
	 * Every `workspace-run` Approval with the given correlation key.
	 *
	 * @param string $correlationId The key.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function runPreAuthorisations(string $correlationId): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::APPROVAL_SCHEMA)
			->findAll(
				config: ['filters' => ['correlationId' => $correlationId, 'sourceType' => 'workspace-run']],
				_rbac: false,
				_multitenancy: false
			);

		return array_values(
			array_filter(
				$objects,
				static fn ($object): bool => $object instanceof ObjectEntity
					&& (string)($object->getObject()['correlationId'] ?? '') === $correlationId
					&& (string)($object->getObject()['sourceType'] ?? '') === 'workspace-run'
			)
		);
	}//end runPreAuthorisations()

	/**
	 * The approved, unexpired pre-authorisation of computer actions on one
	 * machine for one run, or null.
	 *
	 * Tools-browser-and-computer-use D5: a reviewer may approve the actions on
	 * one machine for one run for at most fifteen minutes. The record names its
	 * decision maker; another run, another machine or a moment past its end is
	 * never covered.
	 *
	 * @param string            $agentId The acting agent's UUID.
	 * @param string            $runId   The run the call belongs to.
	 * @param string            $target  The machine (ComputerTarget id).
	 * @param DateTimeImmutable $now     The moment of the call.
	 *
	 * @return array{uuid: string, decidedBy: string, expiresAt: string}|null
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	public function computerRunPreAuthorisation(string $agentId, string $runId, string $target, DateTimeImmutable $now): ?array {
		if ($agentId === '' || $runId === '' || $target === '') {
			return null;
		}

		$key = ComputerRunGrant::key(agentId: $agentId, runId: $runId, target: $target);
		foreach ($this->approvalsOfSource(correlationId: $key, sourceType: self::COMPUTER_RUN) as $object) {
			$data = $object->getObject();
			if (ComputerRunGrant::isLive(data: $data, now: $now) === false) {
				continue;
			}

			return ['uuid' => (string)$object->getUuid(), 'decidedBy' => (string)$data['decidedBy'], 'expiresAt' => (string)$data['expiresAt']];
		}

		return null;
	}//end computerRunPreAuthorisation()

	/**
	 * Approve the actions on the machine of a pending computer action, for the
	 * run it belongs to, for fifteen minutes from now.
	 *
	 * Records a `computer-run` Approval decided by the reviewer. Only an
	 * approved `toolcall` approval of a computer action that names its run and
	 * its machine can be widened this way; anything else gets null and stays a
	 * single-action approval.
	 *
	 * @param ObjectEntity      $approval   The approved computer-action approval.
	 * @param string            $deciderUid The reviewer.
	 * @param DateTimeImmutable $now        The moment of the decision.
	 *
	 * @return array{uuid: string, expiresAt: string}|null The pre-authorisation, or null when this approval cannot carry one.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	public function preauthoriseComputerRun(ObjectEntity $approval, string $deciderUid, DateTimeImmutable $now): ?array {
		$data = ComputerRunGrant::recordFor(data: $approval->getObject(), deciderUid: $deciderUid, now: $now);
		if ($data === null) {
			return null;
		}

		$record = $this->persistApproval(
			data: $data,
			target: new ApprovalTarget(uuid: null, owner: $this->resolveAgentOwner(agentId: $data['agentId']))
		);

		return ['uuid' => (string)$record->getUuid(), 'expiresAt' => $data['expiresAt']];
	}//end preauthoriseComputerRun()

	/**
	 * Every Approval of one source type with the given correlation key.
	 *
	 * @param string $correlationId The key.
	 * @param string $sourceType    The source type.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function approvalsOfSource(string $correlationId, string $sourceType): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::APPROVAL_SCHEMA)
			->findAll(
				config: ['filters' => ['correlationId' => $correlationId, 'sourceType' => $sourceType]],
				_rbac: false,
				_multitenancy: false
			);

		return array_values(
			array_filter(
				$objects,
				static fn ($object): bool => $object instanceof ObjectEntity
					&& (string)($object->getObject()['correlationId'] ?? '') === $correlationId
					&& (string)($object->getObject()['sourceType'] ?? '') === $sourceType
			)
		);
	}//end approvalsOfSource()

	/**
	 * Mark an approved `toolcall` Approval as consumed (`consumedAt` set) so it
	 * can never authorise a second invocation (design.md Decision 4 —
	 * single-use). Idempotent: consuming an already-consumed Approval simply
	 * re-writes the same timestamp-bearing record.
	 *
	 * @param ObjectEntity $approval The approved `toolcall` Approval to consume.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate
	 */
	public function markToolCallApprovalConsumed(ObjectEntity $approval): void {
		$data = $approval->getObject();
		$data['consumedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

		$this->persistApproval(
			data: $data,
			target: new ApprovalTarget(
				uuid: (string)$approval->getUuid(),
				owner: (string)($approval->getOwner() ?? '')
			)
		);

	}//end markToolCallApprovalConsumed()

	/**
	 * Whether a `toolcall` Approval payload has already been consumed.
	 *
	 * @param array<string,mixed> $data The approval payload.
	 *
	 * @return bool
	 */
	private function isConsumed(array $data): bool {
		$consumedAt = (string)($data['consumedAt'] ?? '');

		return $consumedAt !== '';
	}//end isConsumed()

	/**
	 * Whether a `toolcall` Approval's `decidedAt` is still within the fixed
	 * validity window (`TOOLCALL_APPROVAL_TTL_SECONDS`).
	 *
	 * @param array<string,mixed> $data The approval payload.
	 *
	 * @return bool
	 */
	private function isWithinToolCallTtl(array $data): bool {
		$decidedAt = (string)($data['decidedAt'] ?? '');
		if ($decidedAt === '') {
			return false;
		}

		try {
			$decidedAtDate = new DateTimeImmutable($decidedAt);
		} catch (Throwable $e) {
			return false;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$elapsed = ($now->getTimestamp() - $decidedAtDate->getTimestamp());

		return $elapsed >= 0 && $elapsed <= self::TOOLCALL_APPROVAL_TTL_SECONDS;
	}//end isWithinToolCallTtl()

	/**
	 * Redact a tool call's arguments before persistence (redaction-before-
	 * persist), mirroring `WebhookAgentRunService::redactedPayload()`'s
	 * JSON-encode → redact → decode shape: masks secrets/PII in place; falls
	 * back to a `_redacted` wrapper string when the redacted text is no
	 * longer valid JSON (should not normally happen — redaction masks values
	 * in place — but a persisted record must never be an unparsed,
	 * partially-redacted blob passed off as structured data).
	 *
	 * @param array<string, mixed> $arguments The raw invocation arguments.
	 *
	 * @return array<string, mixed> The redacted arguments.
	 */
	private function redactedArguments(array $arguments): array {
		$json = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			$json = '{}';
		}

		$redactedJson = $this->redactionService->redact($json);
		$decoded = json_decode($redactedJson, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return ['_redacted' => $redactedJson];
	}//end redactedArguments()

	/**
	 * Load every Approval recorded for a `toolcall` correlationId (any
	 * status), RBAC-off (the caller applies whatever guard it needs) — the
	 * `toolcall` counterpart to `toolInvocationApprovals()`.
	 *
	 * @param string $correlationId The agentId+toolId+arguments hash.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function toolCallApprovals(string $correlationId): array {
		if ($correlationId === '') {
			return [];
		}

		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::APPROVAL_SCHEMA)
			->findAll(
				config: ['filters' => ['correlationId' => $correlationId, 'sourceType' => 'toolcall']],
				_rbac: false,
				_multitenancy: false
			);

		$matches = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $object->getObject();
			if ((string)($data['correlationId'] ?? '') === $correlationId
				&& (string)($data['sourceType'] ?? '') === 'toolcall'
			) {
				$matches[] = $object;
			}
		}

		return $matches;
	}//end toolCallApprovals()

	/**
	 * Find the open pending Approval for a (agentId, toolId) tool-invocation
	 * pair, if one exists — the tool-invocation counterpart to
	 * `findPendingApprovalForSchedule()`/`findPendingApprovalForCorrelation()`.
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $toolId The full namespaced tool id.
	 *
	 * @return ObjectEntity|null The pending approval, or null.
	 */
	private function findPendingApprovalForToolInvocation(string $agentId, string $toolId): ?ObjectEntity {
		foreach ($this->toolInvocationApprovals(agentId: $agentId, toolId: $toolId) as $object) {
			if ((string)($object->getObject()['status'] ?? '') === 'pending') {
				return $object;
			}
		}

		return null;
	}//end findPendingApprovalForToolInvocation()

	/**
	 * Find the most recently DECIDED (`approved` or `denied`) Approval for a
	 * (agentId, toolId) tool-invocation pair, if one exists — consulted by
	 * `FacadeToolInvoker` before creating a new pending Approval, so an already
	 * `approved` pair proceeds and an already `denied` pair blocks permanently
	 * without re-prompting a reviewer.
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $toolId The full namespaced tool id.
	 *
	 * @return ObjectEntity|null The most recent decided approval, or null when none exists.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#scenario-an-explicitly-granted-destructive-tool-call-is-not-re-gated
	 */
	public function findDecidedApprovalForToolInvocation(string $agentId, string $toolId): ?ObjectEntity {
		$candidates = $this->toolInvocationApprovals(agentId: $agentId, toolId: $toolId);

		$latest = null;
		$latestTime = '';
		foreach ($candidates as $object) {
			$data = $object->getObject();
			$status = (string)($data['status'] ?? '');
			if ($status !== 'approved' && $status !== 'denied') {
				continue;
			}

			$decidedAt = (string)($data['decidedAt'] ?? '');
			if ($latest === null || $decidedAt > $latestTime) {
				$latest = $object;
				$latestTime = $decidedAt;
			}
		}

		return $latest;
	}//end findDecidedApprovalForToolInvocation()

	/**
	 * Load every Approval recorded for a (agentId, toolId) tool-invocation pair
	 * (any status), RBAC-off (the caller applies whatever guard it needs).
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $toolId The full namespaced tool id.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function toolInvocationApprovals(string $agentId, string $toolId): array {
		if ($agentId === '' || $toolId === '') {
			return [];
		}

		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::APPROVAL_SCHEMA)
			->findAll(
				config: ['filters' => ['agentId' => $agentId, 'toolId' => $toolId, 'sourceType' => 'tool']],
				_rbac: false,
				_multitenancy: false
			);

		$matches = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $object->getObject();
			if ((string)($data['agentId'] ?? '') === $agentId
				&& (string)($data['toolId'] ?? '') === $toolId
				&& (string)($data['sourceType'] ?? '') === 'tool'
			) {
				$matches[] = $object;
			}
		}

		return $matches;
	}//end toolInvocationApprovals()

	/**
	 * Resolve an agent's owner UID (RBAC-off — this is the reviewer-default
	 * lookup, not a data-access check), or `''` when the agent cannot be found.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return string
	 */
	private function resolveAgentOwner(string $agentId): string {
		if ($agentId === '') {
			return '';
		}

		$agent = $this->objectService->find(
			id: $agentId,
			options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA),
			_rbac: false,
			_multitenancy: false
		);

		if (($agent instanceof ObjectEntity) === false) {
			return '';
		}

		return (string)($agent->getOwner() ?? '');
	}//end resolveAgentOwner()

	/**
	 * A short, non-fabricated summary of tool-invocation arguments for the
	 * Approval's `prompt` field — argument NAMES only (never values, which may
	 * carry PII/secrets), truncated.
	 *
	 * @param array<string, mixed> $arguments The invocation's arguments.
	 *
	 * @return string
	 */
	private function summarizeArguments(array $arguments): string {
		if ($arguments === []) {
			return '(no arguments)';
		}

		return 'arguments: ' . implode(', ', array_keys($arguments));
	}//end summarizeArguments()

	/**
	 * List every Approval visible in the caller's own tenant (RBAC + tenancy
	 * scoped, mirrors `OrganisationOpsService::loadObjects()`'s tenant-scoped read) —
	 * the org-scoped decision-history seam `ComplianceService` reads for the
	 * `approval-gate-oversight` control (compliance-control-packs). Purely
	 * additive: does not change any existing call site's behaviour.
	 *
	 * @return array<int, ObjectEntity> The caller's own Approval objects.
	 *
	 * @spec openspec/changes/archive/2026-07-13-compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
	 */
	public function listForOrganisation(): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::APPROVAL_SCHEMA)
			->findAll(config: ['limit' => 1000]);

		$out = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				$out[] = $object;
			}
		}

		return $out;
	}//end listForOrganisation()

	/**
	 * List every Approval concerning a specific agent (any sourceType/status),
	 * system-wide (RBAC-off — the caller applies its own authorization guard,
	 * mirrors `toolInvocationApprovals()`/`loadApproval()`) — the per-agent
	 * decision history `ComplianceService` assembles into the AI factsheet
	 * (compliance-control-packs).
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return array<int, ObjectEntity> The matching Approval objects.
	 *
	 * @spec openspec/changes/archive/2026-07-13-compliance-control-packs/tasks.md#task-4-complianceservice-dashboard-export-and-factsheet-aggregation
	 */
	public function listForAgent(string $agentId): array {
		if ($agentId === '') {
			return [];
		}

		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::APPROVAL_SCHEMA)
			->findAll(
				config: ['filters' => ['agentId' => $agentId], 'limit' => 1000],
				_rbac: false,
				_multitenancy: false
			);

		$matches = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['agentId'] ?? '') === $agentId) {
				$matches[] = $object;
			}
		}

		return $matches;
	}//end listForAgent()

	/**
	 * Load an Approval object by UUID without RBAC (the caller applies the guard).
	 *
	 * The Approval is owned by the schedule owner, but the decider is the reviewer —
	 * a different user — so an RBAC read would hide it. It is loaded RBAC-off and the
	 * caller MUST authorise via isReviewer() before acting (IDOR guard).
	 *
	 * @param string $uuid The Approval object UUID.
	 *
	 * @return ObjectEntity|null The approval, or null when absent.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 */
	public function loadApproval(string $uuid): ?ObjectEntity {
		if ($uuid === '') {
			return null;
		}

		$approval = $this->objectService->find(
			id: $uuid,
			options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::APPROVAL_SCHEMA),
			_rbac: false,
			_multitenancy: false
		);

		if (($approval instanceof ObjectEntity) === false) {
			return null;
		}

		return $approval;
	}//end loadApproval()

	/**
	 * Approve a pending Approval and execute the gated run.
	 *
	 * Transitions the Approval to `approved` (decidedBy/decidedAt), audits the
	 * decision, then resumes the gated run matching `sourceType`: a Schedule via
	 * `ScheduleService::runNow()` (the original path), a flow-triggered agent run
	 * via `FlowAgentRunService::run()`, or a webhook-triggered agent run via
	 * `WebhookAgentRunService::run()` — all three with the approval gate bypassed
	 * for this authorised occurrence, and none loops back into another pending
	 * Approval. A non-pending Approval is a no-op (no run).
	 *
	 * @param ObjectEntity $approval The pending approval to authorise.
	 * @param string $deciderUid The reviewer/admin making the decision.
	 *
	 * @return array{status:string, ran:bool} The resulting status and whether a run fired.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 * @spec openspec/changes/archive/2026-09-06-flow-agent-listener/tasks.md#3-approvalservice-generalisation-sourcetype-flow
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-5-approvalservice-sourcetype-webhook-generalisation
	 */
	public function approve(ObjectEntity $approval, string $deciderUid): array {
		$data = $approval->getObject();
		if ((string)($data['status'] ?? '') !== 'pending') {
			return ['status' => (string)($data['status'] ?? ''), 'ran' => false];
		}

		// Skill-self-improvement: a skill-draft Approval is only approvable while its
		// draft holds VALID gate evidence — a content edit invalidates scan+eval and
		// re-runs pre-qualification, and until it passes the transition is REFUSED
		// from EVERY surface (the Approval stays pending, nothing is written), so an
		// edited-but-unscanned body can never apply through an inbox approval.
		if ((string)($data['sourceType'] ?? '') === 'skill-draft') {
			$consolidation = $this->container->get(SkillConsolidationService::class);
			if ($consolidation->isDraftApprovable(draftId: (string)($data['draftId'] ?? '')) === false) {
				$this->logger->info(
					'Hermiq refused approval of skill-draft Approval '
					. ((string)$approval->getUuid()) . ': the draft is awaiting re-qualification.'
				);
				return ['status' => 'pending', 'ran' => false];
			}
		}

		// Oversight-ask-and-hold: a question is answered, never just approved
		// (answerQuestion()), and approving a draft releases it as written.
		if ((string)($data['sourceType'] ?? '') === 'question') {
			return ['status' => 'pending', 'ran' => false];
		}

		if ((string)($data['sourceType'] ?? '') === 'draft') {
			return $this->releaseDraft(approval: $approval, deciderUid: $deciderUid, edits: []);
		}

		$data['status'] = 'approved';
		$data['decidedBy'] = $deciderUid;
		$data['decidedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

		$this->persistApproval(
			data: $data,
			target: new ApprovalTarget(
				uuid: (string)$approval->getUuid(),
				owner: (string)($approval->getOwner() ?? '')
			)
		);
		$this->writeDecisionAudit(approval: $approval, decision: new ApprovalDecision(action: 'approve', reason: ''));

		// Release the OpenRegister mirror BEFORE resuming: a resume crash must
		// not leave a decided approval dangling in anyone's task inbox. When
		// the decision CAME from the task, the mirror is already terminal and
		// this is a no-op (design.md Decision 1).
		$this->taskBridge->releaseFor(approval: $approval, decision: 'approved', deciderUid: $deciderUid);

		$sourceType = (string)($data['sourceType'] ?? 'schedule');
		$ran = $this->resumeGatedRun(sourceType: $sourceType, data: $data);

		return ['status' => 'approved', 'ran' => $ran];
	}//end approve()

	/**
	 * Resume the gated run matching the Approval's `sourceType` — the dispatch
	 * table behind `approve()`, kept as its own small helper (early returns,
	 * never an `else`) so each branch stays simple and independently readable.
	 *
	 * @param string $sourceType The Approval's sourceType (schedule|flow|webhook|tool|toolcall).
	 * @param array<string,mixed> $data The Approval's payload (scheduleId/flowContext/webhookContext).
	 *
	 * @return bool Whether the gated run actually executed.
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-5-approvalservice-sourcetype-webhook-generalisation
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate
	 */
	private function resumeGatedRun(string $sourceType, array $data): bool {
		if ($sourceType === 'skill-draft') {
			// Skill-self-improvement: the pending→approved TRANSITION is the ONE
			// applier — a decision from ANY surface (SkillDetail review card or the
			// generic approval inbox) lands here and applies the draft's content
			// onto the Skill through the normal versioned write path. Resolved
			// lazily (the ScheduleService pattern) to avoid a constructor cycle.
			$consolidation = $this->container->get(SkillConsolidationService::class);
			$versionId = $consolidation->applyDraft(
				draftId: (string)($data['draftId'] ?? ''),
				deciderUid: (string)($data['decidedBy'] ?? '')
			);

			return ($versionId !== null);
		}

		if ($sourceType === 'toolcall' || $sourceType === 'tool' || $sourceType === 'workspace-run') {
			// Design.md Decision 5: approving authorises exactly one future
			// matching retry (toolcall) or flips a permanent per-(agentId,toolId)
			// decision (tool), or covers the rest of one run's workspace writes
			// (workspace-run) — none has a paused run to resume here. There
			// is deliberately no re-execution: `FacadeToolInvoker` is the ONLY
			// place the first two are acted on, `WorkspaceWriteAuthoriser` the
			// only place the third is.
			return false;
		}

		if ($sourceType === 'webhook') {
			$webhookContext = $data['webhookContext'] ?? [];
			if (is_array($webhookContext) === false) {
				$webhookContext = [];
			}

			return $this->runApprovedWebhookRun(webhookContext: $webhookContext);
		}

		if ($sourceType === 'flow') {
			$flowContext = $data['flowContext'] ?? [];
			if (is_array($flowContext) === false) {
				$flowContext = [];
			}

			return $this->runApprovedFlowRun(flowContext: $flowContext);
		}

		return $this->runApprovedSchedule(scheduleId: (string)($data['scheduleId'] ?? ''));
	}//end resumeGatedRun()

	/**
	 * Deny a pending Approval — the gated run never executes.
	 *
	 * Transitions the Approval to `denied` (decidedBy/decidedAt/reason) and audits the
	 * decision (reason redacted before the append-only write). A non-pending Approval
	 * is a no-op.
	 *
	 * @param ObjectEntity $approval The pending approval to deny.
	 * @param string $deciderUid The reviewer/admin making the decision.
	 * @param string|null $reason Optional free-text denial reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 */
	public function deny(ObjectEntity $approval, string $deciderUid, ?string $reason): void {
		$data = $approval->getObject();
		if ((string)($data['status'] ?? '') !== 'pending') {
			return;
		}

		$cleanReason = (string)($reason ?? '');

		$reasonValue = null;
		if ($cleanReason !== '') {
			$reasonValue = $cleanReason;
		}

		$data['status'] = 'denied';
		$data['decidedBy'] = $deciderUid;
		$data['decidedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$data['reason'] = $reasonValue;

		$this->persistApproval(
			data: $data,
			target: new ApprovalTarget(
				uuid: (string)$approval->getUuid(),
				owner: (string)($approval->getOwner() ?? '')
			)
		);
		$this->writeDecisionAudit(approval: $approval, decision: new ApprovalDecision(action: 'deny', reason: $cleanReason));

		// Release the OpenRegister mirror: idempotent when the denial came
		// from the task itself (design.md Decision 1).
		$this->taskBridge->releaseFor(approval: $approval, decision: 'denied', deciderUid: $deciderUid);

		if ((string)($data['sourceType'] ?? '') === 'skill-draft') {
			// Skill-self-improvement: denial from ANY surface reconciles the draft
			// to `rejected` (idempotent — a draft already settled stays settled).
			$consolidation = $this->container->get(SkillConsolidationService::class);
			$consolidation->rejectDraftByDecision(
				draftId: (string)($data['draftId'] ?? ''),
				deciderUid: $deciderUid,
				note: $cleanReason
			);
		}

	}//end deny()

	/**
	 * Idempotently ensure one pending question Approval for a scheduled run
	 * that asked a person a question (hermiq.askPerson in an unattended run).
	 *
	 * Routed like the schedule's own gate: to its reviewer, or else its owner.
	 * One open question per schedule: a second ask while one waits returns it.
	 *
	 * @param ObjectEntity $schedule The schedule whose run asked.
	 * @param array<string, mixed> $question The question: `{text, options, allowOther}`.
	 * @param string $runId The run that asked, so its history links the question.
	 *
	 * @return ObjectEntity The pending question Approval.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 */
	public function ensurePendingQuestion(ObjectEntity $schedule, array $question, string $runId = ''): ObjectEntity {
		$scheduleId = (string)$schedule->getUuid();
		$open = $this->findPendingOfSourceType(scheduleId: $scheduleId, sourceType: 'question');
		if ($open !== null) {
			return $open;
		}

		$data = $schedule->getObject();
		$payload = $this->heldPayload(
			schedule: $schedule,
			sourceType: 'question',
			draftPayload: self::questionDraft(question: $question, runId: $runId)
		);
		$payload['prompt'] = (string)($data['prompt'] ?? '');

		return $this->persistAndNotify(schedule: $schedule, payload: $payload);
	}//end ensurePendingQuestion()

	/**
	 * The draftPayload of a new question: the question, the asking run, no answer yet.
	 *
	 * @param array<string, mixed> $question The question: `{text, options, allowOther}`.
	 * @param string $runId The run that asked.
	 *
	 * @return array<string, mixed> The draftPayload.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 */
	private static function questionDraft(array $question, string $runId): array {
		return [
			'question' => [
				'text' => (string)($question['text'] ?? ''),
				'options' => array_values(array_map('strval', (array)($question['options'] ?? []))),
				'allowOther' => (($question['allowOther'] ?? false) === true),
			],
			'runId' => $runId,
			'answer' => null,
			'answeredRunId' => null,
		];
	}//end questionDraft()

	/**
	 * Answer a pending question: record the answer and dispatch the run again
	 * with its prompt, the question and the answer.
	 *
	 * @param ObjectEntity $approval The pending question Approval.
	 * @param string $deciderUid The reviewer answering.
	 * @param string $answer The chosen or typed answer.
	 *
	 * @return array{status: string, ran: bool} The resulting status and whether the run was dispatched.
	 *
	 * @throws InvalidArgumentException When the approval is not a pending question or the answer is refused.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) QuestionAnswer is a set of pure functions over a question and its
	 * answer; it holds no state to inject.
	 */
	public function answerQuestion(ObjectEntity $approval, string $deciderUid, string $answer): array {
		$data = $approval->getObject();
		if (self::matches(data: $data, expected: ['sourceType' => 'question', 'status' => 'pending']) === false) {
			throw new InvalidArgumentException('This approval is not a question waiting for an answer.');
		}

		$draft = (array)($data['draftPayload'] ?? []);
		$draft['answer'] = QuestionAnswer::validated(question: (array)($draft['question'] ?? []), answer: $answer);
		$data['draftPayload'] = $draft;
		$data = $this->recordAnswer(approval: $approval, data: $data, deciderUid: $deciderUid);

		$ran = $this->runAnsweredSchedule(scheduleId: (string)($data['scheduleId'] ?? ''), prompt: self::answeredPrompt(data: $data));
		if ($ran['runId'] !== '') {
			$data['draftPayload']['answeredRunId'] = $ran['runId'];
			$this->persistApproval(
				data: $data,
				target: new ApprovalTarget(uuid: (string)$approval->getUuid(), owner: (string)($approval->getOwner() ?? ''))
			);
		}

		return ['status' => 'approved', 'ran' => $ran['ran']];
	}//end answerQuestion()

	/**
	 * Mark an answered question approved by the reviewer and save the decision.
	 *
	 * @param ObjectEntity $approval The question Approval.
	 * @param array<string, mixed> $data Its data, with the answer in its draftPayload.
	 * @param string $deciderUid The reviewer answering.
	 *
	 * @return array<string, mixed> The data as saved.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 */
	private function recordAnswer(ObjectEntity $approval, array $data, string $deciderUid): array {
		$data['status'] = 'approved';
		$data['decidedBy'] = $deciderUid;
		$data['decidedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$this->saveDecision(approval: $approval, data: $data, decision: new ApprovalDecision(action: 'approve', reason: ''), deciderUid: $deciderUid);

		return $data;
	}//end recordAnswer()

	/**
	 * The run's prompt followed by the question and the reviewer's answer.
	 *
	 * @param array<string, mixed> $data The answered question Approval's data.
	 *
	 * @return string The prompt to run again with.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) QuestionAnswer is a set of pure functions over a question and its
	 * answer; it holds no state to inject.
	 */
	private static function answeredPrompt(array $data): string {
		$draft = (array)($data['draftPayload'] ?? []);

		return QuestionAnswer::promptWithAnswer(
			prompt: (string)($data['prompt'] ?? ''),
			question: (string)(((array)($draft['question'] ?? []))['text'] ?? ''),
			answer: (string)($draft['answer'] ?? '')
		);
	}//end answeredPrompt()

	/**
	 * Deny every pending question that has waited longer than seven days.
	 *
	 * @param DateTimeImmutable|null $now The current moment (null: now).
	 *
	 * @return int The number of questions expired.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) QuestionAnswer is a set of pure functions over a question and its
	 * answer; it holds no state to inject.
	 */
	public function expireUnansweredQuestions(?DateTimeImmutable $now = null): int {
		$now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$filters = ['sourceType' => 'question', 'status' => 'pending'];
		$expired = 0;
		foreach ($this->pendingApprovals(filters: $filters) as $object) {
			$data = $object->getObject();
			if (self::matches(data: $data, expected: $filters) === false
				|| QuestionAnswer::isExpired(requestedAt: (string)($data['requestedAt'] ?? ''), now: $now) === false
			) {
				continue;
			}

			$this->deny(approval: $object, deciderUid: 'system', reason: QuestionAnswer::EXPIRED_REASON);
			$expired++;
		}

		return $expired;
	}//end expireUnansweredQuestions()

	/**
	 * Hold a scheduled run's output for review instead of delivering it.
	 *
	 * @param ObjectEntity $schedule The schedule whose run produced the output.
	 * @param string $channel The channel the output would have gone to.
	 * @param string $output The output.
	 * @param string|null $conversationUuid The run's conversation, for a Talk delivery.
	 *
	 * @return ObjectEntity The pending draft Approval.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
	 */
	public function ensurePendingDraft(ObjectEntity $schedule, string $channel, string $output, ?string $conversationUuid = null): ObjectEntity {
		$payload = $this->heldPayload(
			schedule: $schedule,
			sourceType: 'draft',
			draftPayload: [
				'kind' => DraftRelease::KIND_DELIVERY,
				'channel' => $channel,
				'output' => $output,
				'conversationUuid' => $conversationUuid,
			]
		);

		return $this->persistAndNotify(schedule: $schedule, payload: $payload);
	}//end ensurePendingDraft()

	/**
	 * Hold an outbound message tool call of an agent with `holdOutput` for review.
	 *
	 * The tool runs with the released arguments on release, as the agent's
	 * owner; only the fields in `$editable` may be changed by the reviewer.
	 *
	 * @param string $agentId The agent that called the tool.
	 * @param string $toolId The tool id, e.g. `hermiq.sendMail`.
	 * @param array<string, mixed> $arguments The tool arguments.
	 * @param list<string> $editable The arguments a reviewer may edit.
	 *
	 * @return ObjectEntity The pending draft Approval.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
	 */
	public function ensurePendingToolDraft(
		string $agentId,
		string $toolId,
		array $arguments,
		array $editable = DraftRelease::DEFAULT_EDITABLE
	): ObjectEntity {
		$owner = $this->resolveAgentOwner(agentId: $agentId);
		$payload = [
			'status' => 'pending',
			'sourceType' => 'draft',
			'agentId' => $agentId,
			'toolId' => $toolId,
			'draftPayload' => [
				'kind' => DraftRelease::KIND_TOOL,
				'toolId' => $toolId,
				'arguments' => $arguments,
				'editable' => array_values($editable),
			],
			'requestedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
			'reviewer' => $owner,
			'reviewerType' => 'user',
			'decidedAt' => null,
			'decidedBy' => null,
			'reason' => null,
		];

		$approval = $this->persistApproval(data: $payload, target: new ApprovalTarget(uuid: null, owner: $owner));
		$this->notifyDraftReviewer(approval: $approval, owner: $owner);

		return $approval;
	}//end ensurePendingToolDraft()

	/**
	 * Tell the owner a tool draft waits for review; a failed notice is logged, never thrown.
	 *
	 * @param ObjectEntity $approval The pending draft Approval.
	 * @param string $owner The reviewer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
	 */
	private function notifyDraftReviewer(ObjectEntity $approval, string $owner): void {
		try {
			$this->deliveryService->deliverApprovalRequestForToolInvocation(
				approval: $approval,
				reviewerUids: $this->reviewerUids(reviewer: $owner, reviewerType: 'user')
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not notify the reviewer of draft ' . ((string)$approval->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end notifyDraftReviewer()

	/**
	 * Release a held draft: send it as the agent wrote it, or with the
	 * reviewer's edits, and record both texts on the Approval.
	 *
	 * @param ObjectEntity $approval The pending draft Approval.
	 * @param string $deciderUid The reviewer releasing it.
	 * @param array<string, mixed> $edits The edited fields (`output`, or the tool's editable arguments).
	 *
	 * @return array{status: string, ran: bool} The resulting status and whether it was sent.
	 *
	 * @throws DraftEditRefused When an edit changes a field that may not be edited.
	 * @throws InvalidArgumentException When the approval is not a pending draft.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
	 */
	public function releaseDraft(ObjectEntity $approval, string $deciderUid, array $edits = []): array {
		$data = $approval->getObject();
		if ((string)($data['sourceType'] ?? '') !== 'draft' || (string)($data['status'] ?? '') !== 'pending') {
			throw new InvalidArgumentException('This approval is not a draft waiting for review.');
		}

		$release = (new DraftRelease(draft: (array)($data['draftPayload'] ?? [])))->released(edits: $edits);

		$data['draftPayload'] = $release['draft'];
		$data['editedOutput'] = null;
		if ($release['edited'] === true) {
			$data['editedOutput'] = ['original' => $release['original'], 'sent' => $release['sent']];
		}

		$data['status'] = 'approved';
		$data['decidedBy'] = $deciderUid;
		$data['decidedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$this->saveDecision(approval: $approval, data: $data, decision: new ApprovalDecision(action: 'approve', reason: ''), deciderUid: $deciderUid);

		return ['status' => 'approved', 'ran' => $this->sendReleasedDraft(approval: $approval, data: $data)];
	}//end releaseDraft()

	/**
	 * Send a released draft the way the run would have sent it.
	 *
	 * @param ObjectEntity $approval The released Approval (its owner acts for a tool draft).
	 * @param array<string, mixed> $data The released Approval payload.
	 *
	 * @return bool Whether it was sent.
	 */
	private function sendReleasedDraft(ObjectEntity $approval, array $data): bool {
		$draft = (array)($data['draftPayload'] ?? []);
		if ((new DraftRelease(draft: $draft))->kind() === DraftRelease::KIND_TOOL) {
			return $this->runReleasedTool(owner: (string)($approval->getOwner() ?? ''), draft: $draft);
		}

		$schedule = $this->loadSchedule(scheduleId: (string)($data['scheduleId'] ?? ''));
		if ($schedule === null) {
			return false;
		}

		$conversationUuid = $draft['conversationUuid'] ?? null;
		if (is_string($conversationUuid) === false || $conversationUuid === '') {
			$conversationUuid = null;
		}

		$result = $this->deliveryService->deliver(
			channel: (string)($draft['channel'] ?? 'none'),
			output: (string)($draft['output'] ?? ''),
			schedule: $schedule,
			conversationUuid: $conversationUuid
		);

		return $result->isDelivered();
	}//end sendReleasedDraft()

	/**
	 * Run a released outbound message tool call as the agent's owner.
	 *
	 * @param string $owner The agent owner, who the tool acts as.
	 * @param array<string, mixed> $draft The released draft: `{toolId, arguments}`.
	 *
	 * @return bool Whether the tool ran without an error.
	 */
	private function runReleasedTool(string $owner, array $draft): bool {
		$user = null;
		if ($owner !== '') {
			$user = $this->userManager->get($owner);
		}

		if ($user === null) {
			$this->logger->warning('Hermiq could not send a released draft: its owner does not exist.');
			return false;
		}

		$priorUser = $this->userSession->getUser();
		$this->userSession->setUser($user);
		try {
			$result = $this->container->get(HermiqToolProvider::class)->invokeTool(
				toolId: (string)($draft['toolId'] ?? ''),
				arguments: (array)($draft['arguments'] ?? [])
			);
		} finally {
			$this->userSession->setUser($priorUser);
		}

		return isset($result['error']) === false;
	}//end runReleasedTool()

	/**
	 * The Approval payload of a question or draft held for a schedule's run.
	 *
	 * @param ObjectEntity $schedule The schedule.
	 * @param string $sourceType `question` or `draft`.
	 * @param array<string, mixed> $draftPayload What is held.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function heldPayload(ObjectEntity $schedule, string $sourceType, array $draftPayload): array {
		[$reviewer, $reviewerType] = $this->resolveReviewer(schedule: $schedule);
		$data = $schedule->getObject();

		return [
			'status' => 'pending',
			'sourceType' => $sourceType,
			'scheduleId' => (string)$schedule->getUuid(),
			'agentId' => (string)($data['agentId'] ?? ''),
			'draftPayload' => $draftPayload,
			'requestedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
			'reviewer' => $reviewer,
			'reviewerType' => $reviewerType,
			'decidedAt' => null,
			'decidedBy' => null,
			'reason' => null,
		];
	}//end heldPayload()

	/**
	 * Persist a held Approval for a schedule and notify its reviewer, never fatal.
	 *
	 * @param ObjectEntity $schedule The schedule.
	 * @param array<string, mixed> $payload The Approval payload.
	 *
	 * @return ObjectEntity The persisted Approval.
	 */
	private function persistAndNotify(ObjectEntity $schedule, array $payload): ObjectEntity {
		$approval = $this->persistApproval(
			data: $payload,
			target: new ApprovalTarget(uuid: null, owner: (string)($schedule->getOwner() ?? ''))
		);

		try {
			$this->deliveryService->deliverApprovalRequest(
				schedule: $schedule,
				approval: $approval,
				reviewerUids: $this->reviewerUids(reviewer: (string)$payload['reviewer'], reviewerType: (string)$payload['reviewerType'])
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not notify the reviewer of approval ' . ((string)$approval->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}

		return $approval;
	}//end persistAndNotify()

	/**
	 * Save a decided Approval, audit it and release its task mirror.
	 *
	 * @param ObjectEntity $approval The Approval.
	 * @param array<string, mixed> $data The decided payload.
	 * @param ApprovalDecision $decision The decision, for the audit.
	 * @param string $deciderUid Who decided.
	 *
	 * @return void
	 */
	private function saveDecision(ObjectEntity $approval, array $data, ApprovalDecision $decision, string $deciderUid): void {
		$this->persistApproval(
			data: $data,
			target: new ApprovalTarget(uuid: (string)$approval->getUuid(), owner: (string)($approval->getOwner() ?? ''))
		);
		$this->writeDecisionAudit(approval: $approval, decision: $decision);
		$this->taskBridge->releaseFor(approval: $approval, decision: 'approved', deciderUid: $deciderUid);
	}//end saveDecision()

	/**
	 * Dispatch a schedule again with the answered prompt, bypassing its gate.
	 *
	 * @param string $scheduleId The schedule.
	 * @param string $prompt The prompt with the question and the answer.
	 *
	 * @return array{ran: bool, runId: string} Whether it ran, and the new run's id.
	 */
	private function runAnsweredSchedule(string $scheduleId, string $prompt): array {
		$schedule = $this->loadSchedule(scheduleId: $scheduleId);
		if ($schedule === null) {
			return ['ran' => false, 'runId' => ''];
		}

		$scheduleService = $this->container->get(ScheduleService::class);
		$scheduleService->runNow(schedule: $schedule, bypassApprovalGate: true, prompt: $prompt);

		return ['ran' => true, 'runId' => $scheduleService->getLastRunId()];
	}//end runAnsweredSchedule()

	/**
	 * Load a schedule by id, or null.
	 *
	 * @param string $scheduleId The schedule UUID.
	 *
	 * @return ObjectEntity|null The schedule.
	 */
	private function loadSchedule(string $scheduleId): ?ObjectEntity {
		if ($scheduleId === '') {
			return null;
		}

		$schedule = $this->objectService->find(
			id: $scheduleId,
			options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::SCHEDULE_SCHEMA),
			_rbac: false,
			_multitenancy: false
		);
		if (($schedule instanceof ObjectEntity) === false) {
			$this->logger->warning('Hermiq schedule ' . $scheduleId . ' could not be loaded.');
			return null;
		}

		return $schedule;
	}//end loadSchedule()

	/**
	 * Find the pending Approval of one source type for a schedule, if one exists.
	 *
	 * @param string $scheduleId The schedule UUID.
	 * @param string $sourceType The source type.
	 *
	 * @return ObjectEntity|null The pending approval, or null.
	 */
	private function findPendingOfSourceType(string $scheduleId, string $sourceType): ?ObjectEntity {
		if ($scheduleId === '') {
			return null;
		}

		$filters = ['scheduleId' => $scheduleId, 'status' => 'pending', 'sourceType' => $sourceType];
		foreach ($this->pendingApprovals(filters: $filters) as $object) {
			if (self::matches(data: $object->getObject(), expected: $filters) === true) {
				return $object;
			}
		}

		return null;
	}//end findPendingOfSourceType()

	/**
	 * The Approvals the register returns for the filters, ObjectEntity objects only.
	 *
	 * @param array<string, string> $filters The register filters.
	 *
	 * @return list<ObjectEntity> The approvals found.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 */
	private function pendingApprovals(array $filters): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::APPROVAL_SCHEMA)
			->findAll(config: ['filters' => $filters], _rbac: false, _multitenancy: false);

		return array_values(array_filter($objects, static fn (mixed $object): bool => $object instanceof ObjectEntity));
	}//end pendingApprovals()

	/**
	 * Whether every expected field of an Approval's data holds the expected value.
	 *
	 * @param array<string, mixed> $data The Approval's data.
	 * @param array<string, string> $expected Field => value.
	 *
	 * @return bool True when all match.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 */
	private static function matches(array $data, array $expected): bool {
		foreach ($expected as $field => $value) {
			if ((string)($data[$field] ?? '') !== $value) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * Find the open pending Approval for a schedule, if one exists.
	 *
	 * @param string $scheduleId The schedule UUID.
	 *
	 * @return ObjectEntity|null The pending approval, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
	 */
	private function findPendingApprovalForSchedule(string $scheduleId): ?ObjectEntity {
		$filters = ['scheduleId' => $scheduleId, 'status' => 'pending'];
		foreach ($this->pendingApprovals(filters: $filters) as $object) {
			$data = $object->getObject();
			// A question or draft held for the schedule's run is not its gate
			// approval (oversight-ask-and-hold): it must not stand in for one.
			$held = in_array((string)($data['sourceType'] ?? 'schedule'), ['question', 'draft'], true);
			if ($held === false && self::matches(data: $data, expected: $filters) === true) {
				return $object;
			}
		}

		return null;
	}//end findPendingApprovalForSchedule()

	/**
	 * Find the open pending Approval for a flow-triggered run's correlation id,
	 * if one exists — the flow-run counterpart to `findPendingApprovalForSchedule()`.
	 *
	 * @param string $correlationId The AgentRunRequestedEvent dispatch's correlation id.
	 *
	 * @return ObjectEntity|null The pending approval, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-06-flow-agent-listener/tasks.md#3-approvalservice-generalisation-sourcetype-flow
	 */
	private function findPendingApprovalForCorrelation(string $correlationId): ?ObjectEntity {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::APPROVAL_SCHEMA)
			->findAll(
				config: ['filters' => ['correlationId' => $correlationId, 'status' => 'pending']],
				_rbac: false,
				_multitenancy: false
			);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $object->getObject();
			if ((string)($data['correlationId'] ?? '') === $correlationId
				&& (string)($data['status'] ?? '') === 'pending'
			) {
				return $object;
			}
		}

		return null;
	}//end findPendingApprovalForCorrelation()

	/**
	 * Resolve the reviewer for a schedule: [reviewer, reviewerType].
	 *
	 * Uses the schedule's `reviewer`/`reviewerType`; an empty reviewer defaults to the
	 * schedule owner as a `user` reviewer (backward compatible). An unknown
	 * `reviewerType` collapses to `user`.
	 *
	 * @param ObjectEntity $schedule The gated schedule.
	 *
	 * @return array{0:string, 1:string} The [reviewer, reviewerType] pair.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
	 */
	private function resolveReviewer(ObjectEntity $schedule): array {
		$data = $schedule->getObject();
		$reviewer = trim((string)($data['reviewer'] ?? ''));
		$type = (string)($data['reviewerType'] ?? 'user');

		if ($reviewer === '') {
			$reviewer = (string)($schedule->getOwner() ?? '');
			$type = 'user';
		}

		if ($type !== 'group') {
			$type = 'user';
		}

		return [$reviewer, $type];
	}//end resolveReviewer()

	/**
	 * Expand a reviewer designation into the set of user ids to notify.
	 *
	 * @param string $reviewer The reviewer user id or group id.
	 * @param string $reviewerType The reviewer type (`user`|`group`).
	 *
	 * @return array<int, string> The reviewer user ids.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#2-dispatcher-approval-gate-scheduleservice
	 */
	private function reviewerUids(string $reviewer, string $reviewerType): array {
		if ($reviewerType === 'group') {
			$group = $this->groupManager->get($reviewer);
			if ($group === null) {
				return [];
			}

			$uids = [];
			foreach ($group->getUsers() as $user) {
				$uids[] = $user->getUID();
			}

			return $uids;
		}

		if ($reviewer === '') {
			return [];
		}

		return [$reviewer];
	}//end reviewerUids()

	/**
	 * Run an approved schedule via the shared dispatch path, bypassing the gate.
	 *
	 * ScheduleService is resolved lazily from the server container so the two
	 * services need no circular constructor dependency (mirrors DeliveryService's
	 * lazy Talk resolution). The bypass runs THIS authorised occurrence without
	 * re-creating a pending Approval; the kill-switch still applies inside dispatch().
	 *
	 * @param string $scheduleId The bound schedule UUID.
	 *
	 * @return bool Whether a run was dispatched.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 */
	private function runApprovedSchedule(string $scheduleId): bool {
		if ($scheduleId === '') {
			return false;
		}

		$schedule = $this->objectService->find(
			id: $scheduleId,
			options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::SCHEDULE_SCHEMA),
			_rbac: false,
			_multitenancy: false
		);

		if (($schedule instanceof ObjectEntity) === false) {
			$this->logger->warning(
				'Hermiq approved schedule ' . $scheduleId . ' could not be loaded to run.'
			);
			return false;
		}

		$scheduleService = $this->container->get(ScheduleService::class);
		$scheduleService->runNow(schedule: $schedule, bypassApprovalGate: true);
		return true;
	}//end runApprovedSchedule()

	/**
	 * Run an approved flow-triggered agent run via `FlowAgentRunService`, bypassing
	 * the gate — the flow-run counterpart to `runApprovedSchedule()`.
	 *
	 * `FlowAgentRunService` is resolved lazily from the server container, mirroring
	 * `ScheduleService`'s lazy resolution above, so the two services need no
	 * circular constructor dependency. The bypass runs THIS authorised occurrence
	 * without re-creating a pending Approval; the kill-switch still applies inside
	 * `FlowAgentRunService::run()`.
	 *
	 * @param array<string,mixed> $flowContext The approval's stored resume context
	 *                                         (subjectUuid/subjectRegister/subjectSchema/
	 *                                         agent/skill/prompt/resultField/mode/flowName/
	 *                                         correlationId).
	 *
	 * @return bool Whether the agent run actually executed.
	 *
	 * @spec openspec/changes/archive/2026-09-06-flow-agent-listener/tasks.md#3-approvalservice-generalisation-sourcetype-flow
	 */
	private function runApprovedFlowRun(array $flowContext): bool {
		if ($flowContext === []) {
			$this->logger->warning('Hermiq approved flow-run has no stored flowContext to resume.');
			return false;
		}

		$flowAgentRunService = $this->container->get(FlowAgentRunService::class);

		return $flowAgentRunService->run(payload: $flowContext, bypassApprovalGate: true);
	}//end runApprovedFlowRun()

	/**
	 * Run an approved webhook-triggered agent run via `WebhookAgentRunService`,
	 * bypassing the gate — the webhook-run counterpart to `runApprovedFlowRun()`.
	 *
	 * `WebhookAgentRunService` is resolved lazily from the server container,
	 * mirroring `ScheduleService`/`FlowAgentRunService`'s lazy resolution above, so
	 * the two services need no circular constructor dependency. The bypass runs
	 * THIS authorised occurrence without re-creating a pending Approval; the
	 * kill-switch and budget gates still apply inside
	 * `WebhookAgentRunService::run()`. The stored `webhookContext.payload` is
	 * ALREADY redacted (it was redacted before this Approval was ever persisted —
	 * see `ensurePendingApprovalForWebhookRun()`), so this resumed run's agent
	 * input is the redacted payload, not the original raw one — a deliberate
	 * security-first trade-off: a pending Approval may sit unresolved for a long
	 * time, and its stored context must never hold an unredacted secret at rest.
	 *
	 * @param array<string,mixed> $webhookContext The approval's stored resume context
	 *                                            (agentId/payload(redacted)/correlationId/
	 *                                            requiresApproval/reviewer/reviewerType).
	 *
	 * @return bool Whether the agent run actually executed.
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-5-approvalservice-sourcetype-webhook-generalisation
	 */
	private function runApprovedWebhookRun(array $webhookContext): bool {
		if ($webhookContext === []) {
			$this->logger->warning('Hermiq approved webhook-run has no stored webhookContext to resume.');
			return false;
		}

		$webhookAgentRun = $this->container->get(WebhookAgentRunService::class);

		return $webhookAgentRun->run(context: $webhookContext, bypassApprovalGate: true);
	}//end runApprovedWebhookRun()

	/**
	 * Persist an Approval payload through OpenRegister, impersonating the owner.
	 *
	 * The Approval must be owned by (and tenant-scoped to) the schedule owner, so the
	 * owner is impersonated around the save exactly as the dispatcher impersonates the
	 * owner around the agent turn. The prior session user is always restored.
	 *
	 * @param array<string,mixed> $data The approval payload.
	 * @param ApprovalTarget $target Which approval is stored, and for whom.
	 *
	 * @return ObjectEntity The persisted approval.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
	 */
	private function persistApproval(array $data, ApprovalTarget $target): ObjectEntity {
		$uuid = $target->uuid;
		$owner = $target->owner;
		$priorUser = $this->userSession->getUser();

		$user = null;
		if ($owner !== '') {
			$user = $this->userManager->get($owner);
		}

		if ($user !== null) {
			$this->userSession->setUser($user);
		}

		try {
			$saved = $this->objectService->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::APPROVAL_SCHEMA),
					uuid: $uuid
				),
				_rbac: false,
				_multitenancy: false
			);

			// Approval-task convergence, phase 1 (design.md Decision 2): the
			// CREATION of a pending Approval is the one choke point every
			// gated shape flows through, so the OpenRegister mirror task is
			// ensured here and nowhere else. Decision saves (uuid known)
			// never mirror. Still inside the owner impersonation on purpose:
			// the backlink write-back must act as the same identity as the
			// save it amends.
			if ($uuid === null && (string)($data['status'] ?? '') === 'pending') {
				$saved = $this->mirrorPendingApproval(approval: $saved, data: $data);
			}

			return $saved;
		} finally {
			$this->userSession->setUser($priorUser);
		}

	}//end persistApproval()

	/**
	 * Mirror a just-created pending Approval as an OpenRegister task and
	 * write the mirror's uuid back onto the Approval as `taskUuid`.
	 *
	 * Non-fatal by contract, in both halves: the bridge never throws (an
	 * absent task surface is a logged no-op and the Approval stays
	 * authoritative), and a failed backlink write-back is logged rather than
	 * raised — the mirror still routes through its own metadata backlink,
	 * only release and rollback lose their shortcut.
	 *
	 * @param ObjectEntity $approval The just-persisted pending Approval.
	 * @param array<string,mixed> $data The Approval payload as saved.
	 *
	 * @return ObjectEntity The Approval, re-saved with `taskUuid` when mirrored.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-every-pending-approval-is-mirrored-as-one-openregister-task
	 */
	private function mirrorPendingApproval(ObjectEntity $approval, array $data): ObjectEntity {
		$taskUuid = $this->taskBridge->ensureTaskFor(approval: $approval);
		if ($taskUuid === null) {
			return $approval;
		}

		try {
			$data['taskUuid'] = $taskUuid;

			return $this->objectService->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::APPROVAL_SCHEMA),
					uuid: (string)$approval->getUuid()
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not write taskUuid ' . $taskUuid . ' back onto approval '
				. ((string)$approval->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);

			return $approval;
		}

	}//end mirrorPendingApproval()

	/**
	 * Write the decision AuditTrail entry via OpenRegister (redaction-before-persist).
	 *
	 * The (user-supplied) reason is redacted before it enters the append-only,
	 * hash-chained trail. Non-fatal by contract: an audit failure is logged, not
	 * raised, so it never fails a decision.
	 *
	 * @param ObjectEntity $approval The approval the decision is about.
	 * @param ApprovalDecision $decision What was decided on an approval, and why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
	 */
	private function writeDecisionAudit(ObjectEntity $approval, ApprovalDecision $decision): void {
		$action = $decision->action;
		$reason = $decision->reason;
		try {
			$status = 'denied';
			if ($action === 'approve') {
				$status = 'approved';
			}

			$context = [
				'status' => $status,
				'decidedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
			];

			if ($reason !== '') {
				// REDACTION-BEFORE-PERSIST: mask secrets/PII before the append-only write.
				$context['reason'] = $this->redactionService->redact($reason);
			}

			$this->auditTrailMapper->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $approval,
					action: $action,
					context: $context
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not write decision audit for approval '
				. ((string)$approval->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

	}//end writeDecisionAudit()
	/**
	 * The request time and the reviewer of a new approval: the owner, or the admin group when there is none.
	 *
	 * @param string $owner The owner who reviews, '' for none.
	 *
	 * @return array{requestedAt: string, reviewer: string, reviewerType: string}
	 */
	private function reviewFor(string $owner): array {
		$requestedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		if ($owner === '') {
			return ['requestedAt' => $requestedAt, 'reviewer' => 'admin', 'reviewerType' => 'group'];
		}

		return ['requestedAt' => $requestedAt, 'reviewer' => $owner, 'reviewerType' => 'user'];
	}//end reviewFor()

	/**
	 * Store a tool approval and ping its reviewer; a failed ping is logged, never fatal.
	 *
	 * The owner is the reviewing user, and none when the admin group reviews.
	 *
	 * @param array<string, mixed> $payload The approval, with its reviewer fields.
	 * @param string               $label   Who could not be notified, for the log line.
	 *
	 * @return ObjectEntity The stored approval.
	 */
	private function openToolApproval(array $payload, string $label): ObjectEntity {
		$owner = '';
		if ($payload['reviewerType'] === 'user') {
			$owner = (string)$payload['reviewer'];
		}

		$approval = $this->persistApproval(data: $payload, target: new ApprovalTarget(uuid: null, owner: $owner));

		try {
			$this->deliveryService->deliverApprovalRequestForToolInvocation(
				approval: $approval,
				reviewerUids: $this->reviewerUids(reviewer: (string)$payload['reviewer'], reviewerType: (string)$payload['reviewerType'])
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not notify ' . $label . ' ' . ((string)$approval->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}

		return $approval;
	}//end openToolApproval()
}//end class
