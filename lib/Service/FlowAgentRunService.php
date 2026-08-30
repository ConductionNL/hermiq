<?php

/**
 * Hermiq FlowAgentRunService.
 *
 * Turns an OpenRegister `AgentRunRequestedEvent` (ADR-041 cross-app command —
 * dispatched by a declarative `x-openregister-flows` action of `type: "agent"`)
 * into a governed agent run through Hermiq's EXISTING oversight rails:
 *
 *   - GATE 1 (kill-switch): the SAME TenantControl data source a scheduled tick
 *     reads, via `ScheduleService::isOrganisationEngaged()`.
 *   - GATE 2 (budget hard cap, cost-guardrails): the SAME `BudgetService` gate a
 *     scheduled tick applies — blocks a budget-exhausted organisation/agent
 *     unconditionally; a soft-threshold crossing warns without blocking.
 *   - GATE 3 (human approval, Art. 14): the SAME `ApprovalService`, generalised
 *     to a `sourceType: "flow"` Approval carrying this run's resume context.
 *   - The agent turn itself: `ScheduleService::runAgentAsOwner()` — the SAME
 *     method a scheduled run calls, including its feature-flagged dual path
 *     (OpenRegister ChatService by default, or the in-app Engine facade when
 *     `hermiq`.`engine.enabled` is on). A flow-triggered run gets IDENTICAL
 *     governance and engine routing to a scheduled run — this is the concrete
 *     payoff SPECTR-NEXTCLOUD-PLAN.md §5.2 point 2 describes ("the same
 *     ScheduleService/Engine path scheduled runs use").
 *   - The redacted per-run audit write-path (AuditTrailMapper + RedactionService).
 *
 * The result is written back to the triggering object's configured `resultField`
 * via `ObjectService`, as the agent's acting user (its `owner` — the closest
 * existing analogue to the planned per-agent `actingUser` profile field, §6.3,
 * which does not exist yet).
 *
 * This is a recognised ADR-031 imperative exception, exactly like ScheduleService
 * and ApprovalService: a side-effecting governance orchestrator, not a derived
 * value or declarative lifecycle. Hermiq owns no LLM/tool engine of its own.
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
 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes a governed agent run requested by OpenRegister's declarative flow engine.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates several OR/Hermiq services.
 *
 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
 */
class FlowAgentRunService {
	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Resolves the triggering object + writes the result field.
	 * @param AgentMapper $agentMapper Resolves the configured agent reference (UUID in v1) to
	 *                                 read its `owner` (the acting-user impersonation
	 *                                 target).
	 * @param LoggerInterface $logger Logs gate skips + run failures (never fatal).
	 * @param AuditTrailMapper $auditTrailMapper OR audit write-path for the redacted per-run entry.
	 * @param RedactionService $redactionService Masks secrets/PII before the audit write.
	 * @param ScheduleService $scheduleService Reused kill-switch check (isOrganisationEngaged) AND
	 *                                         the reused agent-turn dispatch (runAgentAsOwner) —
	 *                                         the SAME ScheduleService/Engine path a scheduled run
	 *                                         uses.
	 * @param ApprovalService $approvalService Reused human-approval gate.
	 * @param BudgetService $budgetService Reused budget hard-cap gate + soft-threshold warning
	 *                                     (cost-guardrails) — the SAME gate a scheduled run
	 *                                     applies.
	 * @param AgentVersionService $agentVersionService Resolves the executing agent's current version
	 *                                                 identifier, pinned onto the run-audit context
	 *                                                 (agent-versioning).
	 * @param SkillVersionService $skillVersionService Resolves the exposed skills' version identifiers,
	 *                                                 pinned onto the run-audit context
	 *                                                 (skill-self-improvement) — never fatal to the run.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is
	 *   a distinct injected collaborator, not a logic-bearing argument list.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentMapper $agentMapper,
		private readonly LoggerInterface $logger,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly RedactionService $redactionService,
		private readonly ScheduleService $scheduleService,
		private readonly ApprovalService $approvalService,
		private readonly BudgetService $budgetService,
		private readonly AgentVersionService $agentVersionService,
		private readonly SkillVersionService $skillVersionService,
	) {
	}//end __construct()

	/**
	 * Run one flow-triggered agent-run request, applying the synchronous oversight
	 * gates first — mirrors `ScheduleService::dispatch()`'s gate ordering exactly.
	 *
	 * @param array<string,mixed> $payload The AgentRunRequestedEvent payload
	 *                                     (subjectUuid/subjectRegister/subjectSchema/
	 *                                     agent/skill/prompt/resultField/requiresApproval/
	 *                                     mode/flowName/correlationId).
	 * @param bool $bypassApprovalGate When true, skip the requiresApproval gate for
	 *                                 this authorised occurrence (an approval-run).
	 *
	 * @return bool Whether the agent run actually executed (false when gated/skipped/failed).
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The bypass is a genuine two-mode
	 *   authorisation input (normal dispatch vs. an already-approved occurrence),
	 *   mirroring ScheduleService::runNow()'s identical parameter.
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
	 */
	public function run(array $payload, bool $bypassApprovalGate = false): bool {
		try {
			return $this->dispatch(payload: $payload, bypassApprovalGate: $bypassApprovalGate);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq flow-agent run failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return false;
		}

	}//end run()

	/**
	 * Resolve the triggering object and apply GATE 1 (kill-switch), GATE 2 (budget
	 * hard cap) and GATE 3 (human approval) before ever invoking the agent.
	 *
	 * @param array<string,mixed> $payload The event payload.
	 * @param bool $bypassApprovalGate Skip the requiresApproval gate (approval-run).
	 *
	 * @return bool Whether the agent run executed.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Gate-by-gate dispatch mirrors
	 *   ScheduleService::dispatch()'s spec-mandated gate order in one linear method.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Each branch is one oversight gate
	 *   (subject identity, kill-switch, budget, approval) — the spec's own decision
	 *   points, kept together so the ordering stays auditable.
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
	 */
	private function dispatch(array $payload, bool $bypassApprovalGate): bool {
		$subjectUuid = (string)($payload['subjectUuid'] ?? '');
		$subjectRegister = (string)($payload['subjectRegister'] ?? '');
		$subjectSchema = (string)($payload['subjectSchema'] ?? '');

		if ($subjectUuid === '' || $subjectRegister === '' || $subjectSchema === '') {
			$this->logger->warning('Hermiq flow-agent run missing subject identity; skipping.');
			return false;
		}

		$object = $this->objectService->find(
			id: $subjectUuid,
			register: $subjectRegister,
			schema: $subjectSchema,
			_rbac: false,
			_multitenancy: false
		);

		if (($object instanceof ObjectEntity) === false) {
			$this->logger->warning(
				sprintf('Hermiq flow-agent run: triggering object %s not found; skipping.', $subjectUuid)
			);
			return false;
		}

		$organisation = (string)($object->getOrganisation() ?? '');
		$agentId = (string)($payload['agent'] ?? '');

		// GATE 1 — KILL-SWITCH (same TenantControl data source ScheduleService reads).
		if ($organisation !== '' && $this->scheduleService->isOrganisationEngaged(organisation: $organisation) === true) {
			$this->writeRunAudit(object: $object, status: 'skipped_killswitch', summary: '', payload: $payload);
			return false;
		}

		// GATE 2 — BUDGET HARD CAP (cost-guardrails). Mirrors ScheduleService::dispatch()'s
		// identical gate: the soft-threshold check is unconditional (never fatal), and the
		// hard-cap block applies even to an authorised approval-bypass occurrence.
		try {
			$this->budgetService->checkAndDeliverWarnings(organisation: $organisation, agentId: $agentId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq flow-agent budget soft-threshold check failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

		if ($this->budgetService->isBlocked(organisation: $organisation, agentId: $agentId) === true) {
			$this->writeRunAudit(object: $object, status: 'skipped_budget', summary: '', payload: $payload);
			return false;
		}

		// GATE 3 — HUMAN APPROVAL (Art. 14). A gated, unauthorised occurrence does not
		// run: ensure a single pending Approval (idempotent) and mark awaiting_approval.
		$requiresApproval = (bool)($payload['requiresApproval'] ?? false);
		if ($bypassApprovalGate === false && $requiresApproval === true) {
			$agent = $this->resolveAgent(ref: (string)($payload['agent'] ?? ''));
			$agentOwner = '';
			if ($agent !== null) {
				$agentOwner = (string)($agent->getOwner() ?? '');
			}

			try {
				$this->approvalService->ensurePendingApprovalForFlowRun(context: $payload, agentOwner: $agentOwner);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Hermiq flow-agent approval gate setup failed: ' . $e->getMessage(),
					['exception' => $e]
				);
			}

			$this->writeRunAudit(object: $object, status: 'awaiting_approval', summary: '', payload: $payload);
			return false;
		}

		return $this->runAgentAndWriteBack(object: $object, payload: $payload, organisation: $organisation);
	}//end dispatch()

	/**
	 * Run the agent turn (via ScheduleService::runAgentAsOwner — the same
	 * ScheduleService/Engine path a scheduled run uses) and write the output back
	 * to the triggering object's configured `resultField`.
	 *
	 * @param ObjectEntity $object The triggering object.
	 * @param array<string,mixed> $payload The event payload.
	 * @param string $organisation The triggering object's organisation —
	 *                             threaded to `runAgentAsOwner()` so its
	 *                             defense-in-depth output-filter re-check
	 *                             resolves the correct GuardrailPolicy
	 *                             (agent-guardrails).
	 *
	 * @return bool Whether the run completed successfully (result written).
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
	 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
	 * @spec openspec/changes/archive/2026-07-13-agent-guardrails/tasks.md#task-4-wire-input-output-filters-into-scheduleservice-runagentasowner
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	private function runAgentAndWriteBack(ObjectEntity $object, array $payload, string $organisation = ''): bool {
		$agentRef = (string)($payload['agent'] ?? '');
		$resultField = (string)($payload['resultField'] ?? '');
		$prompt = (string)($payload['prompt'] ?? '');
		$skill = $payload['skill'] ?? null;

		if ($agentRef === '' || $resultField === '') {
			$this->logger->warning('Hermiq flow-agent run missing agent reference or resultField; skipping.');
			return false;
		}

		$agent = $this->resolveAgent(ref: $agentRef);
		if ($agent === null) {
			$this->logger->warning(
				sprintf('Hermiq flow-agent run: agent "%s" could not be resolved; skipping.', $agentRef)
			);
			return false;
		}

		$actingUser = (string)($agent->getOwner() ?? '');
		if ($actingUser === '') {
			$this->logger->warning(
				sprintf('Hermiq flow-agent run: agent "%s" has no owner to act as; skipping.', $agentRef)
			);
			return false;
		}

		$effectivePrompt = $prompt;
		if (is_string($skill) === true && trim($skill) !== '') {
			// V1: skills become `hermiq.skill.{slug}` TOOLS once installed on the agent
			// (skills-catalog) — there is no runtime skill-injection parameter on
			// ScheduleService::runAgentAsOwner()/ChatService::processMessage() yet, so
			// the reference is surfaced to the model as a prompt directive. Full skill
			// routing lands with the per-agent capability profile (SPECTR-NEXTCLOUD-PLAN.md §6.3).
			$effectivePrompt = sprintf('[skill: %s] %s', trim($skill), $prompt);
		}

		$startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$status = 'ok';
		$summary = '';

		try {
			// Reused verbatim — same impersonation + feature-flagged engine branch
			// (OR ChatService / in-app Engine) a scheduled run dispatches through.
			$output = $this->scheduleService->runAgentAsOwner(
				owner: $actingUser,
				agentId: $agentRef,
				prompt: $effectivePrompt,
				organisation: $organisation,
				anchor: $object
			);
			$this->writeResultField(
				resultField: $resultField,
				value: $output,
				subjectUuid: (string)$object->getUuid(),
				subjectRegister: (string)($payload['subjectRegister'] ?? ''),
				subjectSchema: (string)($payload['subjectSchema'] ?? '')
			);
			$summary = $output;
		} catch (Throwable $e) {
			$status = 'error';
			$summary = 'error: ' . $e->getMessage();
			$this->logger->warning(
				sprintf('Hermiq flow-agent run failed for object %s: %s', (string)$object->getUuid(), $e->getMessage()),
				['exception' => $e]
			);
		}//end try

		$this->writeRunAudit(object: $object, status: $status, summary: $summary, payload: $payload, startedAt: $startedAt);

		return $status === 'ok';
	}//end runAgentAndWriteBack()

	/**
	 * Resolve the configured agent reference. UUID only in v1 — OpenRegister's
	 * `Agent` entity has no `slug` field yet, so the plan's "agent-uuid-or-slug"
	 * config surface resolves as UUID until OR adds one.
	 *
	 * @param string $ref The configured agent reference.
	 *
	 * @return Agent|null The resolved agent, or null when unresolvable.
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
	 */
	private function resolveAgent(string $ref): ?Agent {
		if ($ref === '') {
			return null;
		}

		try {
			return $this->agentMapper->findByUuid($ref);
		} catch (Throwable $e) {
			return null;
		}

	}//end resolveAgent()

	/**
	 * Write the agent's output to the triggering object's configured `resultField`,
	 * via OpenRegister's single write-path — a read-modify-write on the whole object,
	 * mirroring how ScheduleService persists its own schedule fields.
	 *
	 * @param string $resultField The object field to write.
	 * @param string $value The agent's output.
	 * @param string $subjectUuid The triggering object's UUID.
	 * @param string $subjectRegister The triggering object's register.
	 * @param string $subjectSchema The triggering object's schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
	 */
	private function writeResultField(
		string $resultField,
		string $value,
		string $subjectUuid,
		string $subjectRegister,
		string $subjectSchema,
	): void {
		$fresh = $this->objectService->find(
			id: $subjectUuid,
			register: $subjectRegister,
			schema: $subjectSchema,
			_rbac: false,
			_multitenancy: false
		);

		if (($fresh instanceof ObjectEntity) === false) {
			$this->logger->warning(
				sprintf('Hermiq flow-agent run: object %s vanished before the result could be written.', $subjectUuid)
			);
			return;
		}

		$data = $fresh->getObject();
		$data[$resultField] = $value;

		$this->objectService->saveObject(
			object: $data,
			register: $subjectRegister,
			schema: $subjectSchema,
			uuid: $subjectUuid,
			_rbac: false,
			_multitenancy: false
		);

	}//end writeResultField()

	/**
	 * Write a redacted, explicit per-run AuditTrail entry on the triggering object
	 * via OpenRegister — mirrors ScheduleService::writeRunAudit()'s
	 * redaction-before-persist contract. Non-fatal by design.
	 *
	 * @param ObjectEntity $object The triggering object.
	 * @param string $status The run outcome (ok|error|skipped_killswitch|skipped_budget|awaiting_approval).
	 * @param string $summary The raw run output/error (redacted here).
	 * @param array<string,mixed> $payload The event payload (for agentId/flowName diagnostics).
	 * @param DateTimeImmutable|null $startedAt When the agent turn began, if it ran (UTC).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
	 */
	private function writeRunAudit(
		ObjectEntity $object,
		string $status,
		string $summary,
		array $payload,
		?DateTimeImmutable $startedAt = null,
	): void {
		try {
			$endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$started = ($startedAt ?? $endedAt);
			$agentId = (string)($payload['agent'] ?? '');

			$context = [
				'status' => $status,
				'agentId' => $agentId,
				'flowName' => (string)($payload['flowName'] ?? ''),
				'correlationId' => (string)($payload['correlationId'] ?? ''),
				'startedAt' => $started->format('c'),
				'endedAt' => $endedAt->format('c'),
				// REDACTION-BEFORE-PERSIST: mask secrets/PII before the append-only write.
				'summary' => $this->redactionService->redact($summary),
				// Agent-versioning: the version of the agent config that actually ran
				// this occurrence (null when unresolvable — never fatal to the run).
				'agentVersion' => $this->agentVersionService->currentVersionId(agentUuid: $agentId),
				// Skill-self-improvement: which skills the run-loop seam exposed and
				// each one's pinned version as of run start (never fatal).
				'skillsUsed' => $this->scheduleService->getLastRunSkillsUsed(),
				'skillVersions' => $this->skillVersionService->pinsFor(skillUuids: $this->scheduleService->getLastRunSkillsUsed()),
			];

			$this->auditTrailMapper->createAuditTrailEntry(
				object: $object,
				action: 'agent-run',
				context: $context
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf(
					'Hermiq could not write flow-agent run audit for object %s: %s',
					(string)$object->getUuid(),
					$e->getMessage()
				),
				['exception' => $e]
			);
		}//end try

	}//end writeRunAudit()
}//end class
