<?php

/**
 * Hermiq SkillConsolidationService.
 *
 * The skill-self-improvement pipeline owner (ADR-068 §5): proposes DRAFT new skill
 * versions by consolidating a skill's promoted `files['learnings.md']` entries through
 * ONE LLM pass, pre-qualifies every draft BEFORE any human sees it (marketplace content
 * scan with `learnings.md` treated as instruction content, then a paired draft-vs-active
 * eval), routes acceptance through the human-approval-gate `Approval` state machine, and
 * applies an approved draft onto the Skill through the normal versioned write path —
 * the active skill is NEVER edited in place by any other code path in this capability.
 *
 * This is a recognised ADR-031 imperative exception (design.md Decision 1): every
 * meaningful transition is gated on imperative external evidence — an LLM response, a
 * ContentScanService verdict, a paired-eval delta, an ADR-023 action check — so a
 * declarative lifecycle block would be a thin index over PHP handlers, not logic. The
 * HUMAN decision is deliberately NOT re-modeled: it lives ONLY on the linked `Approval`
 * object (pending/approved/denied); the draft's own `status` tracks the PIPELINE.
 *
 * Prompt-injection threat model (design.md, binding): draft-only writes; the scan sees
 * `learnings.md` exactly as an agent would obey it; `dangerous` discards with NO
 * override (stricter than the install quarantine path); strictly-worse evals
 * auto-discard silently; the kill-switch and budget hard-caps gate the LLM pass and the
 * paired eval exactly as schedule ticks; every transition is audited.
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContentScanService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Proposes, pre-qualifies, applies and reconciles SkillDraft objects — the ONLY
 * self-modification path for skill content, and even it applies exclusively through
 * the linked Approval's pending→approved transition.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   The pipeline spans LLM proposal,
 *   content scan, paired eval, approval routing, versioned apply and notifications —
 *   each dependency is one gate of the ADR-068 §5 chain.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One state machine, service-owned
 *   by design (ADR-031 justification, design.md Decision 1): each transition helper is
 *   individually simple; the sum is the audited pipeline the spec demands.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Same reasoning — splitting the
 *   transitions across classes would scatter the one-applier invariant.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     One public seam per pipeline verb
 *   (propose/prequalify/apply/reject/edit/reconcile/watch) plus small read helpers.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
 */
class SkillConsolidationService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for skill objects.
     *
     * @var string
     */
    private const SKILL_SCHEMA = 'agentskill';

    /**
     * Schema slug for skill draft objects (skill-self-improvement).
     *
     * @var string
     */
    private const DRAFT_SCHEMA = 'agentskilldraft';

    /**
     * Schema slug for eval datasets (linked via `skillRefs`).
     *
     * @var string
     */
    private const EVALDATASET_SCHEMA = 'evaldataset';

    /**
     * Schema slug for eval runs (regression trigger + regression watch reads).
     *
     * @var string
     */
    private const EVALRUN_SCHEMA = 'evalrun';

    /**
     * App-config key for the learnings-entry consolidation threshold.
     *
     * @var string
     */
    private const THRESHOLD_KEY = 'skillConsolidationThreshold';

    /**
     * Default learnings-entry count that triggers a consolidation proposal
     * (service-owned constant per design.md Open Questions; admin exposure can follow).
     *
     * @var int
     */
    public const DEFAULT_LEARNINGS_THRESHOLD = 20;

    /**
     * Rough chars-per-token estimate for the consolidation pass's budget accounting
     * (the SkillLearningsCaptureService convention).
     *
     * @var int
     */
    private const CHARS_PER_TOKEN = 4;

    /**
     * Draft pipeline states (design.md "Draft pipeline states"): terminal states are
     * terminal — a decided draft is never reopened; a new proposal is a new draft.
     *
     * @var string
     */
    public const STATUS_PROPOSED = 'proposed';

    /**
     * Awaiting the human decision on the linked Approval.
     *
     * @var string
     */
    public const STATUS_AWAITING_APPROVAL = 'awaiting-approval';

    /**
     * Applied onto the Skill as a new version (terminal).
     *
     * @var string
     */
    public const STATUS_ACCEPTED = 'accepted';

    /**
     * Denied by the reviewer (terminal).
     *
     * @var string
     */
    public const STATUS_REJECTED = 'rejected';

    /**
     * Auto-discarded by a pre-qualification gate (terminal, reachable only from
     * `proposed`; scan/eval evidence in the audit note).
     *
     * @var string
     */
    public const STATUS_DISCARDED = 'discarded';

    /**
     * The AuditTrail action every draft transition is recorded under
     * (run-audit-log seam).
     *
     * @var string
     */
    public const AUDIT_ACTION = 'skill-draft';

    /**
     * Constructor.
     *
     * @param ObjectService                  $objectService      OpenRegister object read/write (single write-path).
     * @param SkillMaturityService           $maturityService    Computed-maturity write guard on apply.
     * @param SkillVersionService            $versionService     Base-version pin + post-apply version id.
     * @param SkillLearningsPromotionService $promotionService   Learnings grammar owner (entry counting, file name).
     * @param SkillLearningsCaptureService   $captureService     `files` map helpers (fileContent).
     * @param ProviderFactory                $providerFactory    The governed LLM chokepoint (ONE pass per proposal).
     * @param ScheduleService                $scheduleService    Org kill-switch check (same gate as a schedule tick).
     * @param BudgetService                  $budgetService      Budget hard-cap gate (same gate as a schedule tick).
     * @param ContentScanService             $contentScanService OpenRegister heuristic content scanner.
     * @param EvalRunService                 $evalRunService     Paired draft-vs-active comparison (skill-evals seam).
     * @param ApprovalService                $approvalService    The ONE human-decision surface (Approval objects).
     * @param AgentMapper                    $agentMapper        Resolves the eval agent for the paired comparison.
     * @param AuditTrailMapper               $auditTrailMapper   OR audit write-path (one entry per transition).
     * @param DeliveryService                $deliveryService    Behind-badge + rollback-suggestion notifications.
     * @param IAppConfig                     $appConfig          Threshold configuration.
     * @param IUserSession                   $userSession        Owner impersonation around draft persistence.
     * @param IUserManager                   $userManager        Resolves the skill owner for impersonation.
     * @param IURLGenerator                  $urlGenerator       SkillDetail deep link for the Approval payload.
     * @param LoggerInterface                $logger             PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is
     *   a distinct injected collaborator (one per pipeline gate), not a logic-bearing
     *   argument list.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SkillMaturityService $maturityService,
        private readonly SkillVersionService $versionService,
        private readonly SkillLearningsPromotionService $promotionService,
        private readonly SkillLearningsCaptureService $captureService,
        private readonly ProviderFactory $providerFactory,
        private readonly ScheduleService $scheduleService,
        private readonly BudgetService $budgetService,
        private readonly ContentScanService $contentScanService,
        private readonly EvalRunService $evalRunService,
        private readonly ApprovalService $approvalService,
        private readonly AgentMapper $agentMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly DeliveryService $deliveryService,
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * One full consolidation pass over every skill (the TimedJob's body): per skill —
     * reconcile any decided-but-unapplied Approval first (idempotent), advance a
     * pending draft through pre-qualification, or evaluate the threshold/regression
     * triggers for a new proposal; then run the post-acceptance regression watch.
     * Sequential with per-skill try/catch: one failure never aborts the pass.
     *
     * @return array<string, int> Summary counts.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    public function runPass(): array
    {
        $summary = [
            'scanned'    => 0,
            'proposed'   => 0,
            'advanced'   => 0,
            'reconciled' => 0,
            'failures'   => 0,
        ];

        foreach ($this->loadSkills() as $skill) {
            $summary['scanned']++;

            try {
                $this->passForSkill(skill: $skill, summary: $summary);
            } catch (Throwable $e) {
                $summary['failures']++;
                $this->logger->warning(
                    sprintf(
                        'Hermiq consolidation pass failed for skill %s: %s',
                        (string) $skill->getUuid(),
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            }
        }

        $this->logger->debug('Hermiq skill consolidation pass complete', $summary);

        return $summary;

    }//end runPass()

    /**
     * The per-skill leg of `runPass()` (extracted so the job loop stays a bare
     * try/catch shell).
     *
     * @param ObjectEntity       $skill   The skill under consideration.
     * @param array<string, int> $summary Mutable pass summary.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    private function passForSkill(ObjectEntity $skill, array &$summary): void
    {
        $skillId = (string) $skill->getUuid();

        $openDraft = $this->findOpenDraftForSkill(skillId: $skillId);
        if ($openDraft !== null) {
            $status = (string) ($openDraft->getObject()['status'] ?? '');
            if ($status === self::STATUS_PROPOSED) {
                $this->prequalifyDraft(draft: $openDraft);
                $summary['advanced']++;
                return;
            }

            // Awaiting-approval: (re)create a missing Approval, then reconcile a
            // decision made on any surface whose transition event was missed.
            if ((string) ($openDraft->getObject()['approvalId'] ?? '') === '') {
                $this->ensureApprovalForDraft(draft: $openDraft, skill: $skill);
                return;
            }

            if ($this->reconcileDraftApproval(draft: $openDraft) === true) {
                $summary['reconciled']++;
            }

            return;
        }//end if

        // No open draft — evaluate the automatic triggers (a) threshold and
        // (b) linked-eval regression. (c) manual comes through the endpoint.
        $regressionRunId = $this->pendingRegressionTriggerRunId(skill: $skill);
        if ($regressionRunId !== null) {
            $result = $this->proposeForSkill(skill: $skill, trigger: 'regression', triggerEvalRunId: $regressionRunId);
            if ($result['created'] === true) {
                $summary['proposed']++;
            }

            return;
        }

        if ($this->learningsEntryCount(skill: $skill) >= $this->threshold()) {
            $result = $this->proposeForSkill(skill: $skill, trigger: 'threshold');
            if ($result['created'] === true) {
                $summary['proposed']++;
            }

            return;
        }

        $this->watchPostAcceptanceRegression(skill: $skill);

    }//end passForSkill()

    /**
     * Propose a draft for one skill — the shared body of all three triggers.
     *
     * Gate order: one-open-draft (all triggers no-op, the manual caller receives the
     * existing draft), then kill-switch, then budget — no `ProviderFactory` call is
     * ever made behind a closed gate, and a blocked attempt is audited. On success the
     * draft is persisted (`proposed`, provenance + pinned `baseVersionId`) and
     * immediately pre-qualified.
     *
     * @param ObjectEntity $skill            The skill to consolidate.
     * @param string       $trigger          `threshold`|`regression`|`manual`.
     * @param string|null  $triggerEvalRunId The regressed EvalRun UUID (trigger=regression).
     *
     * @return array{created: bool, status: string, draft: ObjectEntity|null} The outcome.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
     */
    public function proposeForSkill(ObjectEntity $skill, string $trigger, ?string $triggerEvalRunId=null): array
    {
        $skillId = (string) $skill->getUuid();

        $existing = $this->findOpenDraftForSkill(skillId: $skillId);
        if ($existing !== null) {
            // Design.md Decision 7: at most ONE open draft per skill — every trigger
            // no-ops; the manual endpoint returns the open draft as a pointer.
            return [
                'created' => false,
                'status'  => 'open_draft_exists',
                'draft'   => $existing,
            ];
        }

        $organisation = (string) ($skill->getOrganisation() ?? '');

        // GATE — KILL-SWITCH (exactly as a schedule tick / eval run is gated).
        if ($organisation !== '' && $this->scheduleService->isOrganisationEngaged(organisation: $organisation) === true) {
            $this->auditSkill(skill: $skill, context: ['transition' => 'blocked', 'reason' => 'killswitch', 'trigger' => $trigger]);
            return [
                'created' => false,
                'status'  => 'blocked_killswitch',
                'draft'   => null,
            ];
        }

        // GATE — BUDGET HARD CAP.
        if ($this->budgetService->isBlocked(organisation: $organisation) === true) {
            $this->auditSkill(skill: $skill, context: ['transition' => 'blocked', 'reason' => 'budget', 'trigger' => $trigger]);
            return [
                'created' => false,
                'status'  => 'blocked_budget',
                'draft'   => null,
            ];
        }

        $data     = $skill->getObject();
        $excluded = $this->rejectedRefsForSkill(skillId: $skillId);
        $entries  = $this->drivingEntries(data: $data, excludedRefs: $excluded);
        if ($entries === []) {
            // Nothing (left) to consolidate — a proposal without driving learnings
            // would fabricate provenance.
            return [
                'created' => false,
                'status'  => 'no_learnings',
                'draft'   => null,
            ];
        }

        // ONE LLM pass through the governed chokepoint (tenant model policy applies).
        $policyScope = $organisation;
        if ($policyScope === '') {
            $policyScope = null;
        }

        $prompt   = $this->buildConsolidationPrompt(data: $data, entries: $entries);
        $response = $this->providerFactory->generateText(
            prompt: $prompt,
            userId: null,
            allowNextcloud: true,
            organisation: $policyScope
        );

        $proposedBody = $this->extractProposedBody(response: $response);
        if ($proposedBody === '') {
            $this->logger->info(
                sprintf('Hermiq consolidation: empty LLM proposal for skill %s — no draft created.', $skillId)
            );
            return [
                'created' => false,
                'status'  => 'empty_proposal',
                'draft'   => null,
            ];
        }

        $draftPayload = [
            'skillId'             => $skillId,
            'baseVersionId'       => (string) ($this->versionService->currentVersionId(skillUuid: $skillId) ?? ''),
            'trigger'             => $trigger,
            'status'              => self::STATUS_PROPOSED,
            'proposedFrontmatter' => (string) ($data['frontmatter'] ?? ''),
            'proposedBody'        => $proposedBody,
            'proposedFiles'       => $this->normalisedFiles(files: ($data['files'] ?? [])),
            'provenance'          => [
                'learningRefs'     => array_values(array_map(static fn (array $entry): string => $entry['ref'], $entries)),
                'runIds'           => $this->runIdsFromEntries(entries: $entries),
                'triggerEvalRunId' => (string) ($triggerEvalRunId ?? ''),
            ],
            'noEvalEvidence'      => false,
            'approvalId'          => '',
        ];

        $draft = $this->persistDraft(data: $draftPayload, uuid: null, owner: (string) ($skill->getOwner() ?? ''));

        $this->auditDraft(
            draft: $draft,
            context: [
                'transition' => self::STATUS_PROPOSED,
                'actor'      => $this->actorId(),
                'trigger'    => $trigger,
                'skillId'    => $skillId,
            ]
        );

        // Budget accounting: the consolidation pass's estimated usage lands as an
        // `action='run'` entry on the draft, which BudgetService's scope union sums
        // into the SAME per-org budget a scheduled run uses.
        $this->recordConsolidationUsage(draft: $draft, prompt: $prompt, response: $response);

        $draft = $this->prequalifyDraft(draft: $draft);

        return [
            'created' => true,
            'status'  => 'proposed',
            'draft'   => $draft,
        ];

    }//end proposeForSkill()

    /**
     * Pre-qualification — BEFORE any human sees the draft (spec order is binding):
     *
     * 1. Content scan over proposed frontmatter + body + ALL proposed files, with
     *    `learnings.md` scanned AS INSTRUCTION CONTENT (it is injected into agent
     *    context — the ADR-068 laundering channel). `dangerous` → `discarded`, NO
     *    override path. Scan unavailable → the draft STAYS `proposed` (fail closed).
     * 2. Paired draft-vs-active eval on the linked EvalDataset (kill-switch + budget
     *    gated inside the comparison): strictly-worse → `discarded` with both pass
     *    rates in the audit note (learnings retained); a TIE SURVIVES; no linked
     *    dataset → the draft advances flagged `noEvalEvidence: true`; eval engine
     *    unavailable → stays `proposed`.
     * 3. Advance to `awaiting-approval` and create the linked payload-carrying
     *    Approval (the pending-approval notification fires there).
     *
     * @param ObjectEntity $draft The draft in `proposed`.
     *
     * @return ObjectEntity The draft after the gates ran.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-every-draft-is-content-scanned-with-learnings-treated-as-instruction-content
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
     */
    public function prequalifyDraft(ObjectEntity $draft): ObjectEntity
    {
        $data = $draft->getObject();
        if ((string) ($data['status'] ?? '') !== self::STATUS_PROPOSED) {
            return $draft;
        }

        $skillId = (string) ($data['skillId'] ?? '');
        $skill   = $this->loadSkill(skillId: $skillId);
        if ($skill === null) {
            $this->logger->warning(sprintf('Hermiq consolidation: skill %s gone — draft stays proposed.', $skillId));
            return $draft;
        }

        // GATE 1 — CONTENT SCAN (fail closed on unavailability).
        try {
            $scan = $this->scanDraftContent(data: $data);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Hermiq consolidation: content scan unavailable for draft %s — staying proposed (fail closed): %s',
                    (string) $draft->getUuid(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            return $draft;
        }

        $verdict = (string) ($scan['severity'] ?? ContentScanService::SEVERITY_CLEAN);
        $data['scanVerdict'] = $verdict;
        $data['scanReport']  = $scan;

        if ($verdict === ContentScanService::SEVERITY_DANGEROUS) {
            // Design.md Decision 5: a self-modified draft rated dangerous is discarded
            // with NO override affordance — the force button does not exist here.
            $data['status']    = self::STATUS_DISCARDED;
            $data['auditNote'] = 'Discarded by the content scan: verdict dangerous ('
                .count(($scan['findings'] ?? [])).' finding(s)). No override path exists for a self-modified draft.';
            $data['decidedAt'] = $this->now();

            $draft = $this->persistDraft(data: $data, uuid: (string) $draft->getUuid(), owner: (string) ($draft->getOwner() ?? ''));
            $this->auditDraft(
                draft: $draft,
                context: [
                    'transition'  => self::STATUS_DISCARDED,
                    'actor'       => $this->actorId(),
                    'gate'        => 'content-scan',
                    'scanVerdict' => $verdict,
                ]
            );
            return $draft;
        }

        // GATE 2 — PAIRED DRAFT-VS-ACTIVE EVAL (when a dataset is linked).
        $dataset = $this->findLinkedDataset(skillId: $skillId);
        if ($dataset === null) {
            $data['noEvalEvidence'] = true;
        } else {
            $evalOutcome = $this->runDraftEval(dataset: $dataset, skill: $skill, data: $data);
            if ($evalOutcome === null) {
                // Eval engine unavailable / blocked / failed — evidence, not bypass:
                // persist any scan evidence gathered and stay `proposed`.
                return $this->persistDraft(data: $data, uuid: (string) $draft->getUuid(), owner: (string) ($draft->getOwner() ?? ''));
            }

            $data['evalEvidence']   = $evalOutcome;
            $data['noEvalEvidence'] = false;

            if ((float) $evalOutcome['draftPassRate'] < (float) $evalOutcome['activePassRate']) {
                // Strictly worse → auto-discard, both rates in the audit note; the
                // driving learnings are RETAINED (they may drive a better proposal).
                // A TIE deliberately SURVIVES (design.md Decision 4).
                $data['status']    = self::STATUS_DISCARDED;
                $data['auditNote'] = sprintf(
                    'Discarded by the paired eval gate: draft pass rate %.2f < active pass rate %.2f (eval run %s). Learnings retained.',
                    (float) $evalOutcome['draftPassRate'],
                    (float) $evalOutcome['activePassRate'],
                    (string) $evalOutcome['draftEvalRunId']
                );
                $data['decidedAt'] = $this->now();

                $draft = $this->persistDraft(data: $data, uuid: (string) $draft->getUuid(), owner: (string) ($draft->getOwner() ?? ''));
                $this->auditDraft(
                    draft: $draft,
                    context: [
                        'transition'     => self::STATUS_DISCARDED,
                        'actor'          => $this->actorId(),
                        'gate'           => 'paired-eval',
                        'draftPassRate'  => $evalOutcome['draftPassRate'],
                        'activePassRate' => $evalOutcome['activePassRate'],
                        'evalRunId'      => $evalOutcome['draftEvalRunId'],
                    ]
                );
                return $draft;
            }//end if
        }//end if

        // ADVANCE — the human gate is next; the Approval carries the decision evidence.
        $data['status'] = self::STATUS_AWAITING_APPROVAL;
        $draft          = $this->persistDraft(data: $data, uuid: (string) $draft->getUuid(), owner: (string) ($draft->getOwner() ?? ''));

        $this->auditDraft(
            draft: $draft,
            context: [
                'transition'     => self::STATUS_AWAITING_APPROVAL,
                'actor'          => $this->actorId(),
                'scanVerdict'    => $verdict,
                'noEvalEvidence' => (bool) $data['noEvalEvidence'],
            ]
        );

        return $this->ensureApprovalForDraft(draft: $draft, skill: $skill);

    }//end prequalifyDraft()

    /**
     * Create (idempotently) the draft's linked payload-carrying Approval and stamp
     * `approvalId`. An Approval whose payload would be incomplete is rejected as
     * invalid by `ApprovalService` and never reaches an inbox — the draft then stays
     * awaiting a valid Approval and the next pass retries.
     *
     * @param ObjectEntity $draft The draft in `awaiting-approval`.
     * @param ObjectEntity $skill The draft's skill.
     *
     * @return ObjectEntity The draft (with `approvalId` stamped on success).
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    private function ensureApprovalForDraft(ObjectEntity $draft, ObjectEntity $skill): ObjectEntity
    {
        $data = $draft->getObject();

        try {
            $approval = $this->approvalService->ensurePendingApprovalForSkillDraft(
                draft: $draft,
                skill: $skill,
                draftPayload: $this->buildApprovalPayload(data: $data)
            );
        } catch (InvalidArgumentException $e) {
            $this->logger->warning(
                sprintf('Hermiq consolidation: Approval for draft %s rejected as invalid: %s', (string) $draft->getUuid(), $e->getMessage())
            );
            return $draft;
        }

        $data['approvalId'] = (string) $approval->getUuid();

        return $this->persistDraft(data: $data, uuid: (string) $draft->getUuid(), owner: (string) ($draft->getOwner() ?? ''));

    }//end ensureApprovalForDraft()

    /**
     * The decision-evidence payload REQUIRED on a skill-draft Approval at creation:
     * a deep link to the SkillDetail review surface, the scan verdict, the eval delta
     * (or the explicit `noEvalEvidence` flag), and a one-line driving-learnings
     * summary — an inbox approver can make an informed decision without opening
     * SkillDetail (design.md Decision 6).
     *
     * @param array<string, mixed> $data The draft payload.
     *
     * @return array<string, mixed> The Approval `draftPayload`.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    private function buildApprovalPayload(array $data): array
    {
        $payload = [
            'deepLink'         => $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/skills/'.((string) ($data['skillId'] ?? ''))),
            'scanVerdict'      => (string) ($data['scanVerdict'] ?? ''),
            'noEvalEvidence'   => (bool) ($data['noEvalEvidence'] ?? false),
            'learningsSummary' => $this->learningsSummary(data: $data),
        ];

        $evidence = ($data['evalEvidence'] ?? null);
        if (is_array($evidence) === true && isset($evidence['delta']) === true) {
            $payload['evalDelta'] = (float) $evidence['delta'];
        }

        return $payload;

    }//end buildApprovalPayload()

    /**
     * Whether the draft's linked Approval may be approved right now, from ANY surface:
     * the draft must be in `awaiting-approval` with VALID gate evidence — a content
     * edit clears the evidence and re-runs pre-qualification, so an
     * edited-but-unscanned body can never apply through an inbox approval.
     *
     * @param string $draftId The draft UUID.
     *
     * @return bool True when the Approval transition may fire.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function isDraftApprovable(string $draftId): bool
    {
        $draft = $this->getDraft(draftId: $draftId);
        if ($draft === null) {
            return false;
        }

        $data = $draft->getObject();
        if ((string) ($data['status'] ?? '') !== self::STATUS_AWAITING_APPROVAL) {
            return false;
        }

        $verdict = (string) ($data['scanVerdict'] ?? '');
        if ($verdict === '' || $verdict === ContentScanService::SEVERITY_DANGEROUS) {
            return false;
        }

        $evidence = ($data['evalEvidence'] ?? null);
        $measured = (is_array($evidence) === true && (string) ($evidence['datasetId'] ?? '') !== '');

        return ($measured === true || (bool) ($data['noEvalEvidence'] ?? false) === true);

    }//end isDraftApprovable()

    /**
     * THE apply step — fired exclusively by the linked Approval's pending→`approved`
     * transition (from ANY surface, the generic inbox included) and by the idempotent
     * reconciliation of a missed transition. Writes the draft's content onto the Skill
     * through the normal versioned write path: the payload starts from the LIVE skill,
     * replaces only `frontmatter`/`body`/`files`, and passes the maturity
     * computed-field guard — so unsurfaced fields survive and computed maturity is
     * carried forward; the write lands as an ordinary AuditTrail `update`, the new
     * version subsequent runs pin. Stamps `lastAcceptedVersionAt` in the same write.
     *
     * @param string $draftId    The draft UUID.
     * @param string $deciderUid The approving reviewer's uid.
     *
     * @return string|null The new version id, or null when nothing was applied
     *                     (already accepted — idempotent — or not applicable).
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function applyDraft(string $draftId, string $deciderUid): ?string
    {
        $draft = $this->getDraft(draftId: $draftId);
        if ($draft === null) {
            return null;
        }

        $data   = $draft->getObject();
        $status = (string) ($data['status'] ?? '');
        if ($status === self::STATUS_ACCEPTED) {
            // Idempotent: a reconciled or repeated transition applies nothing twice.
            return null;
        }

        if ($this->isDraftApprovable(draftId: $draftId) === false) {
            $this->logger->warning(
                sprintf('Hermiq consolidation: apply refused — draft %s is not approvable (status %s).', $draftId, $status)
            );
            return null;
        }

        $skillId = (string) ($data['skillId'] ?? '');
        $skill   = $this->loadSkill(skillId: $skillId);
        if ($skill === null) {
            return null;
        }

        $live    = $skill->getObject();
        $payload = $live;
        $payload['frontmatter'] = (string) ($data['proposedFrontmatter'] ?? '');
        $payload['body']        = (string) ($data['proposedBody'] ?? '');
        $payload['files']       = $this->normalisedFiles(files: ($data['proposedFiles'] ?? []));

        $behindBefore = $this->isBehind(data: $live);

        // Stamp acceptance in the SAME versioned write; the computed-maturity guard
        // keeps client-forgeable evidence fields at their stored values.
        $payload['lastAcceptedVersionAt'] = $this->now();
        $payload = $this->maturityService->preserveComputedFields(incoming: $payload, stored: $live);
        unset($payload['id'], $payload['uuid'], $payload['@self']);

        $this->objectService->saveObject(
            object: $payload,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            uuid: $skillId,
            _rbac: false,
            _multitenancy: false
        );

        $versionId = (string) ($this->versionService->currentVersionId(skillUuid: $skillId) ?? '');

        $data['status']    = self::STATUS_ACCEPTED;
        $data['decidedBy'] = $deciderUid;
        $data['decidedAt'] = $this->now();
        $data['auditNote'] = 'Accepted — applied as skill version '.$versionId.'.';

        $draft = $this->persistDraft(data: $data, uuid: (string) $draft->getUuid(), owner: (string) ($draft->getOwner() ?? ''));

        $this->auditDraft(
            draft: $draft,
            context: [
                'transition'         => self::STATUS_ACCEPTED,
                'actor'              => $deciderUid,
                'versionId'          => $versionId,
                'editedBeforeAccept' => (bool) ($data['editedBeforeAccept'] ?? false),
            ]
        );

        // Republish signal: notify the publisher once per NEWLY-behind transition —
        // acceptance always postdates publishedAt, so "newly" means it was not
        // already behind before this acceptance (design.md Decision 9).
        if ($behindBefore === false && $this->isPublished(data: $live) === true) {
            $this->deliveryService->deliverSkillPublishedBehind(
                skillUuid: $skillId,
                skillName: (string) ($live['name'] ?? ''),
                recipientUid: (string) ($skill->getOwner() ?? '')
            );
        }

        return $versionId;

    }//end applyDraft()

    /**
     * Reconcile a draft to `rejected` — fired by the linked Approval's `denied`
     * transition (any surface) and by reconciliation. Curator-marked bad learnings
     * (`rejectedLearningRefs`, keyed by dated entry hashes) are recorded on the DRAFT
     * (never as an edit to `learnings.md` — design.md Decision 8) and excluded from
     * driving the skill's next proposal.
     *
     * @param string             $draftId    The draft UUID.
     * @param string             $deciderUid The denying reviewer's uid.
     * @param string             $note       Optional rejection note.
     * @param array<int, string> $refs       Learnings entry refs marked bad.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function rejectDraftByDecision(string $draftId, string $deciderUid, string $note='', array $refs=[]): void
    {
        $draft = $this->getDraft(draftId: $draftId);
        if ($draft === null) {
            return;
        }

        $data   = $draft->getObject();
        $status = (string) ($data['status'] ?? '');
        if ($status === self::STATUS_REJECTED || $status === self::STATUS_ACCEPTED) {
            // Terminal states are terminal — idempotent reconciliation.
            return;
        }

        $stored = ($data['rejectedLearningRefs'] ?? []);
        if (is_array($stored) === false) {
            $stored = [];
        }

        $clean = array_values(
                array_unique(
                array_merge(
            array_filter($stored, static fn ($ref): bool => is_string($ref) === true && $ref !== ''),
            array_filter($refs, static fn ($ref): bool => is_string($ref) === true && $ref !== '')
                )
                )
                );

        $data['status']    = self::STATUS_REJECTED;
        $data['decidedBy'] = $deciderUid;
        $data['decidedAt'] = $this->now();
        $data['rejectedLearningRefs'] = $clean;
        if ($note !== '') {
            $data['auditNote'] = $note;
        }

        $draft = $this->persistDraft(data: $data, uuid: (string) $draft->getUuid(), owner: (string) ($draft->getOwner() ?? ''));

        $this->auditDraft(
            draft: $draft,
            context: [
                'transition'           => self::STATUS_REJECTED,
                'actor'                => $deciderUid,
                'rejectedLearningRefs' => $clean,
            ]
        );

    }//end rejectDraftByDecision()

    /**
     * Record curator-marked bad learnings on a still-open draft BEFORE its Approval
     * is denied (the SkillDetail reject flow): the denial transition then reconciles
     * the draft to `rejected` with these refs already aboard — marking lives on the
     * DRAFT, never as an edit to `learnings.md` (design.md Decision 8).
     *
     * @param ObjectEntity       $draft The draft being rejected.
     * @param array<int, string> $refs  Learnings entry refs marked bad.
     *
     * @return ObjectEntity The draft with the refs merged.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function markRejectedLearningRefs(ObjectEntity $draft, array $refs): ObjectEntity
    {
        $data   = $draft->getObject();
        $stored = ($data['rejectedLearningRefs'] ?? []);
        if (is_array($stored) === false) {
            $stored = [];
        }

        $data['rejectedLearningRefs'] = array_values(
                array_unique(
                array_merge(
            array_filter($stored, static fn ($ref): bool => is_string($ref) === true && $ref !== ''),
            array_filter($refs, static fn ($ref): bool => is_string($ref) === true && $ref !== '')
                )
                )
                );

        return $this->persistDraft(data: $data, uuid: (string) $draft->getUuid(), owner: (string) ($draft->getOwner() ?? ''));

    }//end markRejectedLearningRefs()

    /**
     * Edit-then-accept content update (SkillDetail-only surface): replaces the DRAFT's
     * proposed content, records `editedBeforeAccept` + the editor (human curation is
     * evidence, Arize finding), INVALIDATES the stored scan + eval evidence, moves the
     * draft back to `proposed`, and re-runs pre-qualification — the linked Approval is
     * not approvable from ANY surface until the re-run passes (the
     * `isDraftApprovable()` gate every approve path consults).
     *
     * @param ObjectEntity           $draft       The draft in `awaiting-approval`.
     * @param string|null            $frontmatter Replacement frontmatter (null = keep).
     * @param string|null            $body        Replacement body (null = keep).
     * @param array<int, mixed>|null $files       Replacement files (null = keep).
     * @param string                 $editorUid   The editing reviewer's uid.
     *
     * @return ObjectEntity The draft after re-qualification ran.
     *
     * @throws InvalidArgumentException When the draft is not in `awaiting-approval`.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function editDraftContent(
        ObjectEntity $draft,
        ?string $frontmatter,
        ?string $body,
        ?array $files,
        string $editorUid
    ): ObjectEntity {
        $data = $draft->getObject();
        if ((string) ($data['status'] ?? '') !== self::STATUS_AWAITING_APPROVAL) {
            throw new InvalidArgumentException('Only a draft awaiting approval can be edited.');
        }

        if ($frontmatter !== null) {
            $data['proposedFrontmatter'] = $frontmatter;
        }

        if ($body !== null) {
            $data['proposedBody'] = $body;
        }

        if ($files !== null) {
            $data['proposedFiles'] = $this->normalisedFiles(files: $files);
        }

        // The edit invalidates ALL prior gate evidence — nothing unscanned can be
        // approved anywhere; re-qualification below re-earns it over the new content.
        $data['editedBeforeAccept'] = true;
        $data['editedBy']           = $editorUid;
        $data['scanVerdict']        = '';
        $data['scanReport']         = [];
        $data['evalEvidence']       = [];
        $data['noEvalEvidence']     = false;
        // TOCTOU hardening: sever the link to the pre-edit Approval too — a decision
        // recorded on the OLD Approval (raced in around the edit) must never apply
        // the edited content; the re-qualification pass links a fresh Approval.
        $data['approvalId'] = '';
        $data['status']     = self::STATUS_PROPOSED;

        $draft = $this->persistDraft(data: $data, uuid: (string) $draft->getUuid(), owner: (string) ($draft->getOwner() ?? ''));

        $this->auditDraft(
            draft: $draft,
            context: [
                'transition' => 'edited',
                'actor'      => $editorUid,
                'note'       => 'Content edited before accept — scan and eval evidence invalidated; re-qualification required.',
            ]
        );

        return $this->prequalifyDraft(draft: $draft);

    }//end editDraftContent()

    /**
     * Idempotently reconcile a draft whose linked Approval was decided on a surface
     * whose transition event was missed: approved → apply (the SAME versioned apply
     * path any-surface approval runs), denied → reject. Divergence is audited via the
     * transitions themselves.
     *
     * @param ObjectEntity $draft The draft in `awaiting-approval`.
     *
     * @return bool Whether a missed decision was reconciled.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function reconcileDraftApproval(ObjectEntity $draft): bool
    {
        $data       = $draft->getObject();
        $approvalId = (string) ($data['approvalId'] ?? '');
        if ($approvalId === '') {
            return false;
        }

        $approval = $this->approvalService->loadApproval(uuid: $approvalId);
        if ($approval === null) {
            return false;
        }

        $approvalData = $approval->getObject();
        $status       = (string) ($approvalData['status'] ?? '');
        $decider      = (string) ($approvalData['decidedBy'] ?? '');

        if ($status === 'approved') {
            $this->applyDraft(draftId: (string) $draft->getUuid(), deciderUid: $decider);
            return true;
        }

        if ($status === 'denied') {
            $this->rejectDraftByDecision(
                draftId: (string) $draft->getUuid(),
                deciderUid: $decider,
                note: (string) ($approvalData['reason'] ?? '')
            );
            return true;
        }

        return false;

    }//end reconcileDraftApproval()

    /**
     * Post-acceptance regression watch: when the NEXT eval run for the skill's linked
     * dataset (after the acceptance) reports the EXISTING regression gate as `failed`,
     * notify the accepting reviewer once per regressing run — the advisory "roll back
     * to previous version?" suggestion; rollback stays an explicit human action.
     *
     * @param ObjectEntity $skill The skill to watch.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion
     */
    public function watchPostAcceptanceRegression(ObjectEntity $skill): void
    {
        $skillId  = (string) $skill->getUuid();
        $accepted = $this->latestAcceptedDraft(skillId: $skillId);
        if ($accepted === null) {
            return;
        }

        $acceptedData = $accepted->getObject();
        $decidedAt    = (string) ($acceptedData['decidedAt'] ?? '');
        if ($decidedAt === '') {
            return;
        }

        $dataset = $this->findLinkedDataset(skillId: $skillId);
        if ($dataset === null) {
            return;
        }

        $failing = $this->latestFailedRegressionRun(datasetId: (string) $dataset->getUuid(), after: $decidedAt);
        if ($failing === null) {
            return;
        }

        $failingId = (string) $failing->getUuid();
        if ((string) ($acceptedData['regressionNotifiedRunId'] ?? '') === $failingId) {
            // Already notified for this regressing run — once per run, never a repeat.
            return;
        }

        $acceptedData['regressionNotifiedRunId'] = $failingId;
        $accepted = $this->persistDraft(
            data: $acceptedData,
            uuid: (string) $accepted->getUuid(),
            owner: (string) ($accepted->getOwner() ?? '')
        );

        $this->deliveryService->deliverSkillRollbackSuggestion(
            skillUuid: $skillId,
            skillName: (string) ($skill->getObject()['name'] ?? ''),
            recipientUid: (string) ($acceptedData['decidedBy'] ?? '')
        );

        $this->auditDraft(
            draft: $accepted,
            context: [
                'transition' => 'regression-watch',
                'actor'      => $this->actorId(),
                'evalRunId'  => $failingId,
                'note'       => 'Post-acceptance regression detected — advisory rollback suggestion raised (no rollback performed).',
            ]
        );

    }//end watchPostAcceptanceRegression()

    /**
     * The open (`proposed`/`awaiting-approval`) draft for a skill, when one exists —
     * the one-open-draft-per-skill rule's lookup (design.md Decision 7).
     *
     * @param string $skillId The skill UUID.
     *
     * @return ObjectEntity|null The open draft, or null.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    public function findOpenDraftForSkill(string $skillId): ?ObjectEntity
    {
        foreach ($this->draftsForSkill(skillId: $skillId) as $draft) {
            $status = (string) ($draft->getObject()['status'] ?? '');
            if ($status === self::STATUS_PROPOSED || $status === self::STATUS_AWAITING_APPROVAL) {
                return $draft;
            }
        }

        return null;

    }//end findOpenDraftForSkill()

    /**
     * Every draft for a skill, newest first (the SkillDetail surface's list).
     *
     * @param string $skillId The skill UUID.
     *
     * @return array<int, ObjectEntity> The drafts, newest first.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
     */
    public function draftsForSkill(string $skillId): array
    {
        if ($skillId === '') {
            return [];
        }

        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::DRAFT_SCHEMA)
            ->findAll(
                config: ['filters' => ['skillId' => $skillId], 'limit' => 200],
                _rbac: false,
                _multitenancy: false
            );

        $drafts = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getObject()['skillId'] ?? '') === $skillId) {
                $drafts[] = $object;
            }
        }

        usort(
            $drafts,
            static function (ObjectEntity $draftA, ObjectEntity $draftB): int {
                $timeA = (string) ($draftA->getObject()['decidedAt'] ?? '');
                $timeB = (string) ($draftB->getObject()['decidedAt'] ?? '');
                return strcmp($timeB, $timeA);
            }
        );

        return $drafts;

    }//end draftsForSkill()

    /**
     * Load one draft by UUID, RBAC-off (the controller applies the visibility guard
     * through the SKILL, mirroring `ApprovalService::loadApproval()`).
     *
     * @param string $draftId The draft UUID.
     *
     * @return ObjectEntity|null The draft, or null.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function getDraft(string $draftId): ?ObjectEntity
    {
        if ($draftId === '') {
            return null;
        }

        $draft = $this->objectService->find(
            id: $draftId,
            register: self::REGISTER_SLUG,
            schema: self::DRAFT_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        if (($draft instanceof ObjectEntity) === false) {
            return null;
        }

        return $draft;

    }//end getDraft()

    /**
     * The configured learnings-entry threshold (trigger (a)).
     *
     * @return int The threshold (default 20).
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    public function threshold(): int
    {
        return $this->appConfig->getValueInt(
            Application::APP_ID,
            self::THRESHOLD_KEY,
            self::DEFAULT_LEARNINGS_THRESHOLD
        );

    }//end threshold()

    /**
     * Count a skill's promoted learnings entries (the grammar owner's counter over
     * `files['learnings.md']`).
     *
     * @param ObjectEntity $skill The skill.
     *
     * @return int The entry count.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    public function learningsEntryCount(ObjectEntity $skill): int
    {
        $files = ($skill->getObject()['files'] ?? []);
        if (is_array($files) === false) {
            return 0;
        }

        $content = $this->captureService->fileContent(files: $files, name: SkillLearningsPromotionService::LEARNINGS_FILE);

        return $this->promotionService->countLearningsEntries(content: $content);

    }//end learningsEntryCount()

    /**
     * Run the paired draft-vs-active comparison and shape the draft's `evalEvidence`.
     * Returns null when the comparison could not produce evidence (no resolvable
     * agent, gates blocked, infra failure) — fail closed, the draft stays `proposed`.
     *
     * @param ObjectEntity         $dataset The linked EvalDataset.
     * @param ObjectEntity         $skill   The skill under comparison.
     * @param array<string, mixed> $data    The draft payload (proposed content source).
     *
     * @return array<string, mixed>|null The `evalEvidence` map, or null.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
     */
    private function runDraftEval(ObjectEntity $dataset, ObjectEntity $skill, array $data): ?array
    {
        $agent = $this->resolveEvalAgent(skill: $skill);
        if ($agent === null) {
            $this->logger->info(
                sprintf(
                    'Hermiq consolidation: no resolvable agent for skill %s — eval evidence unavailable, draft stays proposed.',
                    (string) $skill->getUuid()
                )
            );
            return null;
        }

        try {
            $outcome = $this->evalRunService->runDraftComparison(
                dataset: $dataset,
                agent: $agent,
                skillId: (string) $skill->getUuid(),
                draftContent: [
                    'name'        => (string) ($skill->getObject()['name'] ?? ''),
                    'description' => (string) ($skill->getObject()['description'] ?? ''),
                    'body'        => (string) ($data['proposedBody'] ?? ''),
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Hermiq consolidation: paired draft eval failed for skill %s: %s',
                    (string) $skill->getUuid(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            return null;
        }//end try

        if ((string) $outcome['status'] !== 'draft-comparison') {
            // Blocked (kill-switch/budget) or failed — gates are evidence, never
            // bypassed: no advancement without a real measurement.
            return null;
        }

        return [
            'datasetId'       => (string) $dataset->getUuid(),
            'draftPassRate'   => (float) $outcome['draftPassRate'],
            'activePassRate'  => (float) $outcome['activePassRate'],
            'delta'           => ((float) $outcome['draftPassRate'] - (float) $outcome['activePassRate']),
            'draftEvalRunId'  => (string) $outcome['evalRunId'],
            'activeEvalRunId' => (string) $outcome['evalRunId'],
        ];

    }//end runDraftEval()

    /**
     * Resolve the agent the paired comparison executes as: the first of the skill's
     * `installedOn` agents that resolves. Null when none does (eval unavailable).
     *
     * @param ObjectEntity $skill The skill.
     *
     * @return \OCA\OpenRegister\Db\Agent|null The agent, or null.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
     */
    private function resolveEvalAgent(ObjectEntity $skill): ?\OCA\OpenRegister\Db\Agent
    {
        $installed = ($skill->getObject()['installedOn'] ?? []);
        if (is_array($installed) === false) {
            return null;
        }

        foreach ($installed as $agentId) {
            if (is_string($agentId) === false || $agentId === '') {
                continue;
            }

            try {
                return $this->agentMapper->findByUuid($agentId);
            } catch (Throwable $e) {
                continue;
            }
        }

        return null;

    }//end resolveEvalAgent()

    /**
     * The first EvalDataset whose `skillRefs` links this skill (the skill-evals
     * relation dialect), system-wide.
     *
     * @param string $skillId The skill UUID.
     *
     * @return ObjectEntity|null The linked dataset, or null.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
     */
    private function findLinkedDataset(string $skillId): ?ObjectEntity
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::EVALDATASET_SCHEMA)
            ->findAll(config: ['limit' => 500], _rbac: false, _multitenancy: false);

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $refs = ($object->getObject()['skillRefs'] ?? []);
            if (is_array($refs) === true && in_array($skillId, $refs, true) === true) {
                return $object;
            }
        }

        return null;

    }//end findLinkedDataset()

    /**
     * The regressed EvalRun that should trigger a proposal (trigger (b)), when one
     * exists: the latest run for a linked dataset with `regressionGateResult` `failed`
     * that no prior draft of this skill was already triggered by.
     *
     * @param ObjectEntity $skill The skill.
     *
     * @return string|null The triggering EvalRun UUID, or null.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    private function pendingRegressionTriggerRunId(ObjectEntity $skill): ?string
    {
        $skillId = (string) $skill->getUuid();
        $dataset = $this->findLinkedDataset(skillId: $skillId);
        if ($dataset === null) {
            return null;
        }

        $failing = $this->latestFailedRegressionRun(datasetId: (string) $dataset->getUuid(), after: '');
        if ($failing === null) {
            return null;
        }

        $failingId = (string) $failing->getUuid();
        foreach ($this->draftsForSkill(skillId: $skillId) as $draft) {
            $provenance = ($draft->getObject()['provenance'] ?? []);
            if (is_array($provenance) === true
                && (string) ($provenance['triggerEvalRunId'] ?? '') === $failingId
            ) {
                // This regression already drove a proposal — never re-propose from
                // the same failing run.
                return null;
            }
        }

        return $failingId;

    }//end pendingRegressionTriggerRunId()

    /**
     * The most recent EvalRun for a dataset whose regression gate reported `failed`,
     * optionally restricted to runs ended after a timestamp.
     *
     * @param string $datasetId The dataset UUID.
     * @param string $after     ISO timestamp lower bound ('' = unbounded).
     *
     * @return ObjectEntity|null The failing run, or null.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion
     */
    private function latestFailedRegressionRun(string $datasetId, string $after): ?ObjectEntity
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::EVALRUN_SCHEMA)
            ->findAll(
                config: ['filters' => ['datasetId' => $datasetId], 'limit' => 500],
                _rbac: false,
                _multitenancy: false
            );

        $latest     = null;
        $latestTime = '';
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            if ((string) ($data['datasetId'] ?? '') !== $datasetId) {
                continue;
            }

            if ((string) ($data['regressionGateResult'] ?? '') !== 'failed') {
                continue;
            }

            $endedAt = (string) ($data['endedAt'] ?? '');
            if ($after !== '' && strcmp($endedAt, $after) <= 0) {
                continue;
            }

            if ($latest === null || strcmp($endedAt, $latestTime) > 0) {
                $latest     = $object;
                $latestTime = $endedAt;
            }
        }//end foreach

        return $latest;

    }//end latestFailedRegressionRun()

    /**
     * The most recently accepted draft for a skill (the regression watch's anchor).
     *
     * @param string $skillId The skill UUID.
     *
     * @return ObjectEntity|null The accepted draft, or null.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion
     */
    private function latestAcceptedDraft(string $skillId): ?ObjectEntity
    {
        foreach ($this->draftsForSkill(skillId: $skillId) as $draft) {
            if ((string) ($draft->getObject()['status'] ?? '') === self::STATUS_ACCEPTED) {
                return $draft;
            }
        }

        return null;

    }//end latestAcceptedDraft()

    /**
     * Scan the draft's FULL proposed content — frontmatter + body + every file,
     * `files['learnings.md']` explicitly included AS INSTRUCTION CONTENT (folded into
     * the scanned text exactly like the body, because it is injected into agent
     * context; the scan sees what an agent would obey).
     *
     * @param array<string, mixed> $data The draft payload.
     *
     * @return array<string, mixed> The scan report.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-every-draft-is-content-scanned-with-learnings-treated-as-instruction-content
     */
    private function scanDraftContent(array $data): array
    {
        $content = (string) ($data['proposedBody'] ?? '');

        $frontmatter = (string) ($data['proposedFrontmatter'] ?? '');
        if ($frontmatter !== '') {
            $content .= "\n".$frontmatter;
        }

        foreach ($this->normalisedFiles(files: ($data['proposedFiles'] ?? [])) as $file) {
            $content .= "\n\n# file: ".((string) $file['name'])."\n".((string) $file['content']);
        }

        $report = $this->contentScanService->scan(content: $content, metadata: []);
        $report['scannedAt'] = $this->now();

        return $report;

    }//end scanDraftContent()

    /**
     * Parse the driving learnings entries out of `files['learnings.md']`: every
     * promoted bullet line, keyed by its dated-entry ref (`YYYY-MM-DD-<hash8>`), with
     * entries marked bad in ANY prior rejected draft excluded.
     *
     * @param array<string, mixed> $data         The skill payload.
     * @param array<int, string>   $excludedRefs Refs marked bad in prior rejected drafts.
     *
     * @return array<int, array{ref: string, text: string, runs: array<int, string>}> The entries.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    public function drivingEntries(array $data, array $excludedRefs=[]): array
    {
        $files = ($data['files'] ?? []);
        if (is_array($files) === false) {
            return [];
        }

        $content  = $this->captureService->fileContent(files: $files, name: SkillLearningsPromotionService::LEARNINGS_FILE);
        $excluded = array_flip($excludedRefs);

        $entries = [];
        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '- ') === false) {
                continue;
            }

            // `- text <!-- promoted YYYY-MM-DD | runs: id1,id2 [| eval-fail: ref] -->`
            // (the promotion grammar). Undated bullets get a stable nil-date ref so a
            // hand-edited file still yields deterministic provenance keys.
            $text    = $trimmed;
            $date    = '0000-00-00';
            $runs    = [];
            $pattern = '/^- (.+?) <!-- promoted (\d{4}-\d{2}-\d{2}) \| runs: '
                .'([0-9A-Za-z,\-]+)(?: \| eval-fail: \S+)? -->$/';
            if (preg_match($pattern, $trimmed, $matches) === 1) {
                $text = $matches[1];
                $date = $matches[2];
                $runs = array_values(array_filter(explode(',', $matches[3])));
            } else {
                $text = trim(substr($trimmed, 2));
            }

            if ($text === '') {
                continue;
            }

            $ref = $date.'-'.substr(sha1($text), 0, 8);
            if (isset($excluded[$ref]) === true) {
                continue;
            }

            $entries[] = [
                'ref'  => $ref,
                'text' => $text,
                'runs' => $runs,
            ];
        }//end foreach

        return $entries;

    }//end drivingEntries()

    /**
     * The union of `rejectedLearningRefs` across every REJECTED draft of a skill —
     * entries a curator marked bad never drive another proposal (design.md Decision 8).
     *
     * @param string $skillId The skill UUID.
     *
     * @return array<int, string> The excluded refs.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function rejectedRefsForSkill(string $skillId): array
    {
        $refs = [];
        foreach ($this->draftsForSkill(skillId: $skillId) as $draft) {
            $data = $draft->getObject();
            if ((string) ($data['status'] ?? '') !== self::STATUS_REJECTED) {
                continue;
            }

            $marked = ($data['rejectedLearningRefs'] ?? []);
            if (is_array($marked) === false) {
                continue;
            }

            foreach ($marked as $ref) {
                if (is_string($ref) === true && $ref !== '') {
                    $refs[] = $ref;
                }
            }
        }

        return array_values(array_unique($refs));

    }//end rejectedRefsForSkill()

    /**
     * The one-line driving-learnings summary the Approval payload carries.
     *
     * @param array<string, mixed> $data The draft payload.
     *
     * @return string The summary line.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    private function learningsSummary(array $data): string
    {
        $provenance = ($data['provenance'] ?? []);
        $refs       = [];
        if (is_array($provenance) === true && is_array($provenance['learningRefs'] ?? null) === true) {
            $refs = $provenance['learningRefs'];
        }

        $count = count($refs);
        if ($count === 0) {
            return 'Consolidation draft (no recorded driving entries).';
        }

        $suffix = 'ies';
        if ($count === 1) {
            $suffix = 'y';
        }

        return sprintf('Consolidates %d promoted learnings entr%s into the skill body.', $count, $suffix);

    }//end learningsSummary()

    /**
     * Build the single consolidation prompt: the current body plus the driving
     * entries, asking for ONE improved body. The response is shaped by
     * `extractProposedBody()` — the LLM never writes any object.
     *
     * @param array<string, mixed>                                                   $data    The skill payload.
     * @param array<int, array{ref: string, text: string, runs: array<int, string>}> $entries The driving entries.
     *
     * @return string The prompt.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    private function buildConsolidationPrompt(array $data, array $entries): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = '- '.$entry['text'];
        }

        return "You maintain an agent skill written in markdown.\n"
            ."Rewrite the skill BODY below so the accumulated learnings are folded into the instructions where they belong.\n"
            ."Rules: keep the structure and intent; change only what the learnings justify; "
            ."output ONLY the full improved markdown body, no commentary.\n\n"
            ."CURRENT BODY:\n"
            .((string) ($data['body'] ?? ''))."\n\n"
            ."LEARNINGS TO CONSOLIDATE:\n"
            .implode("\n", $lines)."\n";

    }//end buildConsolidationPrompt()

    /**
     * Shape the LLM response into the proposed body: trim, strip a single wrapping
     * markdown code fence when present. Empty result = no draft.
     *
     * @param string $response The raw LLM response.
     *
     * @return string The proposed body ('' when unusable).
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    private function extractProposedBody(string $response): string
    {
        $body = trim($response);
        if (preg_match('/^```(?:markdown|md)?\n(.*)\n```$/s', $body, $matches) === 1) {
            $body = trim($matches[1]);
        }

        return $body;

    }//end extractProposedBody()

    /**
     * Normalise a files array to clean `{name, content}` entries.
     *
     * @param mixed $files The raw files value.
     *
     * @return array<int, array{name: string, content: string}> The normalised entries.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    private function normalisedFiles(mixed $files): array
    {
        if (is_array($files) === false) {
            return [];
        }

        $out = [];
        foreach ($files as $file) {
            if (is_array($file) === false) {
                continue;
            }

            $name = (string) ($file['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $out[] = [
                'name'    => $name,
                'content' => (string) ($file['content'] ?? ''),
            ];
        }

        return $out;

    }//end normalisedFiles()

    /**
     * The run ids recorded across the driving entries (provenance).
     *
     * @param array<int, array{ref: string, text: string, runs: array<int, string>}> $entries The entries.
     *
     * @return array<int, string> Deduplicated run ids.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    private function runIdsFromEntries(array $entries): array
    {
        $runs = [];
        foreach ($entries as $entry) {
            foreach ($entry['runs'] as $runId) {
                if ($runId !== '') {
                    $runs[] = $runId;
                }
            }
        }

        return array_values(array_unique($runs));

    }//end runIdsFromEntries()

    /**
     * Whether a skill payload is GitHub-published (`githubRepo` + `publishedAt` set).
     *
     * @param array<string, mixed> $data The skill payload.
     *
     * @return bool True when published.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
     */
    private function isPublished(array $data): bool
    {
        return (string) ($data['githubRepo'] ?? '') !== '' && (string) ($data['publishedAt'] ?? '') !== '';

    }//end isPublished()

    /**
     * Whether a skill payload is ALREADY behind its published copy
     * (`lastAcceptedVersionAt` postdating `publishedAt`).
     *
     * @param array<string, mixed> $data The skill payload.
     *
     * @return bool True when already behind.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
     */
    private function isBehind(array $data): bool
    {
        if ($this->isPublished(data: $data) === false) {
            return false;
        }

        $accepted = (string) ($data['lastAcceptedVersionAt'] ?? '');
        if ($accepted === '') {
            return false;
        }

        try {
            $acceptedAt  = new DateTimeImmutable($accepted);
            $publishedAt = new DateTimeImmutable((string) $data['publishedAt']);
        } catch (Throwable $e) {
            return false;
        }

        return $acceptedAt > $publishedAt;

    }//end isBehind()

    /**
     * Load every skill system-wide (the background pass has no user session; the
     * SkillCuratorTask/SkillMarketplaceService pattern — lifecycle only, never
     * crossing tenants with data).
     *
     * @return array<int, ObjectEntity> The skills.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    private function loadSkills(): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SKILL_SCHEMA)
            ->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);

        $out = [];
        foreach ($objects as $object) {
            if ($object instanceof ObjectEntity) {
                $out[] = $object;
            }
        }

        return $out;

    }//end loadSkills()

    /**
     * Load one skill by UUID, RBAC-off (background context).
     *
     * @param string $skillId The skill UUID.
     *
     * @return ObjectEntity|null The skill, or null.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    private function loadSkill(string $skillId): ?ObjectEntity
    {
        if ($skillId === '') {
            return null;
        }

        $skill = $this->objectService->find(
            id: $skillId,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        if (($skill instanceof ObjectEntity) === false) {
            return null;
        }

        return $skill;

    }//end loadSkill()

    /**
     * Persist a draft payload, impersonating the skill owner so the draft carries the
     * owner/organisation tenant scope (the `ApprovalService::persistApproval()`
     * pattern) — required for the budget scope union and RBAC-scoped reads.
     *
     * @param array<string, mixed> $data  The draft payload.
     * @param string|null          $uuid  The target UUID (null to create).
     * @param string               $owner The skill owner UID to impersonate.
     *
     * @return ObjectEntity The persisted draft.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
     */
    private function persistDraft(array $data, ?string $uuid, string $owner): ObjectEntity
    {
        unset($data['id'], $data['uuid'], $data['@self']);

        $priorUser = $this->userSession->getUser();

        $user = null;
        if ($owner !== '') {
            $user = $this->userManager->get($owner);
        }

        if ($user !== null) {
            $this->userSession->setUser($user);
        }

        try {
            return $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER_SLUG,
                schema: self::DRAFT_SCHEMA,
                uuid: $uuid,
                _rbac: false,
                _multitenancy: false
            );
        } finally {
            $this->userSession->setUser($priorUser);
        }

    }//end persistDraft()

    /**
     * Write one AuditTrail entry for a draft transition (run-audit-log seam): acting
     * principal, timestamp, and gate evidence — never fatal.
     *
     * @param ObjectEntity         $draft   The draft the transition is about.
     * @param array<string, mixed> $context The transition evidence.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-every-draft-state-transition-is-audited
     */
    private function auditDraft(ObjectEntity $draft, array $context): void
    {
        try {
            $context['at'] = $this->now();
            $this->auditTrailMapper->createAuditTrailEntry(
                object: $draft,
                action: self::AUDIT_ACTION,
                context: $context
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not write draft transition audit for '
                .((string) $draft->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end auditDraft()

    /**
     * Write one AuditTrail entry against the SKILL for a blocked consolidation
     * attempt (kill-switch/budget) — the blocked attempt is auditable even though no
     * draft object exists yet.
     *
     * @param ObjectEntity         $skill   The skill the attempt was for.
     * @param array<string, mixed> $context The block evidence.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
     */
    private function auditSkill(ObjectEntity $skill, array $context): void
    {
        try {
            $context['at'] = $this->now();
            $this->auditTrailMapper->createAuditTrailEntry(
                object: $skill,
                action: self::AUDIT_ACTION,
                context: $context
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not write blocked-consolidation audit for skill '
                .((string) $skill->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end auditSkill()

    /**
     * Record the consolidation pass's estimated LLM usage as an `action='run'`
     * AuditTrail entry on the draft, so BudgetService's scope union sums it into the
     * SAME per-org budget a scheduled run uses. Never fatal.
     *
     * @param ObjectEntity $draft    The freshly-persisted draft.
     * @param string       $prompt   The consolidation prompt (usage estimate input).
     * @param string       $response The LLM response (usage estimate input).
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
     */
    private function recordConsolidationUsage(ObjectEntity $draft, string $prompt, string $response): void
    {
        try {
            $this->auditTrailMapper->createAuditTrailEntry(
                object: $draft,
                action: 'run',
                context: [
                    'status'  => 'consolidation',
                    'usage'   => [
                        'promptTokens'     => (int) ceil(strlen($prompt) / self::CHARS_PER_TOKEN),
                        'completionTokens' => (int) ceil(strlen($response) / self::CHARS_PER_TOKEN),
                    ],
                    'summary' => 'Skill consolidation LLM pass (skill-self-improvement).',
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not record consolidation usage for draft '
                .((string) $draft->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end recordConsolidationUsage()

    /**
     * The acting principal for audit entries: the session user, or the background job.
     *
     * @return string The actor id.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-every-draft-state-transition-is-audited
     */
    private function actorId(): string
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            return $user->getUID();
        }

        return 'system:skill-consolidation-task';

    }//end actorId()

    /**
     * The current UTC timestamp in ISO-8601.
     *
     * @return string The timestamp.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-every-draft-state-transition-is-audited
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    }//end now()
}//end class
