<?php

/**
 * Contract of AgentTemplateService
 *
 * The public surface of AgentTemplateService that other components call, so they depend
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of AgentTemplateService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgentTemplateServiceInterface {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * Public because `AgentTemplateController` addresses the same register when it
	 * reads (ownership guard) and deletes through OpenRegister directly (ADR-022: no
	 * app-local CRUD wrapper) — the slug is declared once, here.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for AgentTemplate objects.
	 *
	 * Public for the same reason as REGISTER_SLUG above.
	 *
	 * @var string
	 */
	public const TEMPLATE_SCHEMA = 'agenttemplate';

	/**
	 * The review gate: transition a quarantined template to active.
	 *
	 * A `dangerous` content-scan verdict blocks one-click approval — the template stays
	 * quarantined and the caller must override explicitly (`$force=true`), mirroring
	 * `SkillMarketplaceService::approveQuarantined()` exactly.
	 *
	 * @param string $templateId The AgentTemplate UUID.
	 * @param bool $force Override a `dangerous` scan verdict (a conscious reviewer decision).
	 *
	 * @return ObjectEntity|null The updated template, or null when not found.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$force` is a genuine two-mode reviewer
	 * decision (explicit dangerous-verdict override), part of the public seam the controller
	 * exposes — not an SRP smell (mirrors SkillMarketplaceService::approveQuarantined()).
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-approving-a-quarantined-template-requires-action-authorization
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-overriding-a-dangerous-scan-verdict-requires-a-stricter-action
	 */
	public function approveQuarantined(string $templateId, bool $force = false): ?ObjectEntity;

	/**
	 * Author a new template directly (not via import) — always `active`/`local`, never scanned.
	 *
	 * @param array<string, mixed> $payload The requested template fields (protected keys stripped by caller).
	 * @param string $createdBy The authoring user id.
	 *
	 * @return ObjectEntity The persisted template.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-importing-a-template-from-an-external-source-lands-quarantined-and-content-scanned
	 */
	public function create(array $payload, string $createdBy): ObjectEntity;

	/**
	 * Export an existing Agent to a portable template package — secrets/tenant fields
	 * (invitedUsers, groups, requestQuota, tokenQuota, views, actingUser) are never copied;
	 * only the fields the AgentTemplate schema declares are read off the Agent.
	 *
	 * @param string $agentId The Agent UUID.
	 *
	 * @return string|null The JSON package string, or null when the agent is not found.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	public function exportFromAgent(string $agentId): ?string;

	/**
	 * Export an existing template's own portable fields to a shareable JSON package —
	 * the read-only counterpart to importPackage(), letting a locally-authored template be
	 * handed to another organisation/hub for their own import (never a hosted hub itself;
	 * agent-template-gallery's import/export is a package string, mirrors SkillController::
	 * exportSkill()).
	 *
	 * @param string $templateId The AgentTemplate UUID.
	 *
	 * @return string|null The JSON package string, or null when the template is not found.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	public function exportTemplate(string $templateId): ?string;

	/**
	 * Import a JSON package into a new template. A package imported with `source='org'` or
	 * `'hub'` lands `quarantined` and is content-scanned (systemPrompt is as much a
	 * prompt-injection vector as a Skill body); a `source='local'` import (e.g. duplicating an
	 * existing template) is saved `active` with no scan.
	 *
	 * @param string $package The JSON package string.
	 * @param string $source The import source (`local`|`org`|`hub`).
	 * @param string $createdBy The importing user id.
	 * @param string|null $derivedFrom The agent the package was exported from, recorded on the template.
	 *
	 * @return ObjectEntity The persisted template.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-importing-a-template-from-an-external-source-lands-quarantined-and-content-scanned
	 */
	public function importPackage(string $package, string $source, string $createdBy, ?string $derivedFrom = null): ObjectEntity;

	/**
	 * "Use this template": instantiate a template into a real Agent in the caller's
	 * organisation. The suggested provider/model is always resolved against
	 * `OrganisationModelPolicyService::effectivePolicyFor()` — an out-of-policy suggestion is
	 * replaced by the policy's default (or first allowed provider) and the substitution is
	 * reported, never silently applied and never silently dropped. Skill refs are resolved
	 * best-effort (a miss never fails the call); the suggested schedule is returned verbatim
	 * and NO `Schedule` object is ever created here.
	 *
	 * @param string $templateId The AgentTemplate UUID.
	 * @param string $organisation The caller's active organisation (may be '').
	 * @param array<string, mixed> $overrides Caller-supplied field overrides for the created Agent (protected keys stripped).
	 *
	 * @return array<string, mixed>|null The instantiate result { agent, modelCoerced,
	 *                                   requestedProvider, requestedModel, resolvedProvider,
	 *                                   resolvedModel, unresolvedSkillRefs, suggestedSchedule },
	 *                                   or null when the template is not found.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-silently-violates-the-callers-model-policy
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-resolves-skill-references-best-effort
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-auto-creates-a-live-schedule
	 */
	public function instantiate(string $templateId, string $organisation, array $overrides = []): ?array;

	/**
	 * List the templates visible in the caller's tenant.
	 *
	 * @return array<int, ObjectEntity> The AgentTemplate objects.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#non-functional-requirements
	 */
	public function list(): array;

	/**
	 * "Save as template": the agent's secret-free package, imported as an active
	 * local template that records the agent it came from. The caller has checked
	 * that the user owns the agent.
	 *
	 * @param string $agentId The Agent UUID.
	 * @param string $createdBy The saving user id.
	 *
	 * @return ObjectEntity|null The new template, or null when the agent does not resolve.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-saved-as-a-reusable-template-req-agexp-003
	 */
	public function saveAgentAsTemplate(string $agentId, string $createdBy): ?ObjectEntity;

	/**
	 * Update an existing template's fields (partial merge). Lifecycle fields
	 * (state/source/quarantineReason/scanReport/createdBy) are untouched — only
	 * approveQuarantined() transitions state.
	 *
	 * @param string $templateId The AgentTemplate UUID.
	 * @param array<string, mixed> $payload The requested field updates (protected keys stripped by caller).
	 *
	 * @return ObjectEntity|null The updated template, or null when not found.
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-template-gallery/tasks.md#task-5-agenttemplatecontroller-routes-adr-023-action-seed
	 */
	public function update(string $templateId, array $payload): ?ObjectEntity;
}//end interface
