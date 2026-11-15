<?php

/**
 * Contract of SkillConsolidationService
 *
 * The public surface of SkillConsolidationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of SkillConsolidationService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SkillConsolidationServiceInterface {

	/**
	 * Awaiting the human decision on the linked Approval.
	 *
	 * @var string
	 */
	public const STATUS_AWAITING_APPROVAL = 'awaiting-approval';

	/**
	 * Every draft for a skill, newest first (the SkillDetail surface's list).
	 *
	 * @param string $skillId The skill UUID.
	 *
	 * @return array<int, ObjectEntity> The drafts, newest first.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
	 */
	public function draftsForSkill(string $skillId): array;

	/**
	 * Edit-then-accept content update (SkillDetail-only surface): replaces the DRAFT's
	 * proposed content, records `editedBeforeAccept` + the editor (human curation is
	 * evidence, Arize finding), INVALIDATES the stored scan + eval evidence, moves the
	 * draft back to `proposed`, and re-runs pre-qualification — the linked Approval is
	 * not approvable from ANY surface until the re-run passes (the
	 * `isDraftApprovable()` gate every approve path consults).
	 *
	 * @param ObjectEntity $draft The draft in `awaiting-approval`.
	 * @param array<string, mixed> $replacement `frontmatter` and `body` (strings) and `files` (a list);
	 *                                          a missing or other-typed value keeps the current one.
	 * @param string $editorUid The editing reviewer's uid.
	 *
	 * @return ObjectEntity The draft after re-qualification ran.
	 *
	 * @throws InvalidArgumentException When the draft is not in `awaiting-approval`.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function editDraftContent(ObjectEntity $draft, array $replacement, string $editorUid): ObjectEntity;

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
	public function getDraft(string $draftId): ?ObjectEntity;

	/**
	 * Record curator-marked bad learnings on a still-open draft BEFORE its Approval
	 * is denied (the SkillDetail reject flow): the denial transition then reconciles
	 * the draft to `rejected` with these refs already aboard — marking lives on the
	 * DRAFT, never as an edit to `learnings.md` (design.md Decision 8).
	 *
	 * @param ObjectEntity $draft The draft being rejected.
	 * @param array<int, string> $refs Learnings entry refs marked bad.
	 *
	 * @return ObjectEntity The draft with the refs merged.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function markRejectedLearningRefs(ObjectEntity $draft, array $refs): ObjectEntity;

	/**
	 * Propose a draft for one skill — the shared body of all three triggers.
	 *
	 * Gate order: one-open-draft (all triggers no-op, the manual caller receives the
	 * existing draft), then kill-switch, then budget — no `ProviderFactory` call is
	 * ever made behind a closed gate, and a blocked attempt is audited. On success the
	 * draft is persisted (`proposed`, provenance + pinned `baseVersionId`) and
	 * immediately pre-qualified.
	 *
	 * @param ObjectEntity $skill The skill to consolidate.
	 * @param string $trigger `threshold`|`regression`|`manual`.
	 * @param string|null $triggerEvalRunId The regressed EvalRun UUID (trigger=regression).
	 *
	 * @return array{created: bool, status: string, draft: ObjectEntity|null} The outcome.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) One linear, spec-ordered gate chain
	 *   (open-draft, kill-switch, budget, learnings, LLM pass, persist, audit) — each
	 *   gate is a simple early return; splitting would obscure the binding order.
	 */
	public function proposeForSkill(ObjectEntity $skill, string $trigger, ?string $triggerEvalRunId = null): array;

	/**
	 * Reconcile a draft to `rejected` — fired by the linked Approval's `denied`
	 * transition (any surface) and by reconciliation. Curator-marked bad learnings
	 * (`rejectedLearningRefs`, keyed by dated entry hashes) are recorded on the DRAFT
	 * (never as an edit to `learnings.md` — design.md Decision 8) and excluded from
	 * driving the skill's next proposal.
	 *
	 * @param string $draftId The draft UUID.
	 * @param string $deciderUid The denying reviewer's uid.
	 * @param string $note Optional rejection note.
	 * @param array<int, string> $refs Learnings entry refs marked bad.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function rejectDraftByDecision(string $draftId, string $deciderUid, string $note = '', array $refs = []): void;
}//end interface
