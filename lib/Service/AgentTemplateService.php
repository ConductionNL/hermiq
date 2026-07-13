<?php

/**
 * Hermiq AgentTemplateService.
 *
 * The agent-level analogue of SkillService + SkillMarketplaceService (agent-template-gallery):
 * CRUD over the tenant-scoped AgentTemplate catalog, export of an existing Agent to a
 * secret-free/tenant-free portable package, import of a package (quarantined + content-scanned
 * when externally-sourced, mirroring skills-marketplace's exact discipline), the review-gate
 * approval, and "Use this template" instantiation into a real Agent with the suggested
 * provider/model coerced through the caller's effective TenantModelPolicy. All persistence
 * flows through OpenRegister's ObjectService (single write-path, native tenant scoping,
 * ADR-001 Option C+/ADR-003) — no new write path, no new RBAC primitive.
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
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-3-agenttemplateservice-export-import-quarantine-approve
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-4-agenttemplateserviceinstantiate-model-coercion-skill-ref-resolution-schedule-hint
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContentScanService;
use OCA\OpenRegister\Service\ObjectService;

/**
 * CRUD + export/import/quarantine/approve + instantiate for AgentTemplate objects.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   The gallery lifecycle spans template CRUD,
 * package (de)serialisation, content scanning (OR ContentScanService), model-policy coercion
 * (TenantModelPolicyService), and best-effort skill-ref resolution (SkillService); each
 * dependency is one lifecycle stage, exactly as SkillMarketplaceService's coupling mirrors.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Sum of many small, single-purpose CRUD/
 * export/import/quarantine/instantiate methods (mirrors ScheduleService/TenantOpsService's
 * documented coordinator-service pattern) — no individual method is itself complex.
 *
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-3-agenttemplateservice-export-import-quarantine-approve
 */
class AgentTemplateService
{
    use SanitizesForSaveTrait;

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for AgentTemplate objects.
     *
     * @var string
     */
    private const TEMPLATE_SCHEMA = 'agenttemplate';

    /**
     * Schema slug for Agent objects (instantiate's write target).
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * Request/payload keys a caller may never set directly on a template — lifecycle state
     * assigned by this service's own import/approve flow, and entity/tenant identity assigned
     * by ObjectService (mirrors AgentsController::PROTECTED_KEYS).
     *
     * @var array<int, string>
     */
    private const PROTECTED_KEYS = [
        '_route',
        'id',
        'uuid',
        'created',
        'updated',
        'organisation',
        'owner',
        'state',
        'source',
        'quarantineReason',
        'scanReport',
        'createdBy',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService            $objectService      OpenRegister object read/write (single write-path).
     * @param AgentTemplateSerializer  $serializer         The JSON package (de)serialiser.
     * @param ContentScanService       $contentScanService OpenRegister heuristic content scanner.
     * @param TenantModelPolicyService $modelPolicyService Per-org provider/model allowlist.
     * @param SkillService             $skillService       Best-effort skill-ref resolution.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Distinct collaborators (mirrors
     * SkillMarketplaceService's constructor).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AgentTemplateSerializer $serializer,
        private readonly ContentScanService $contentScanService,
        private readonly TenantModelPolicyService $modelPolicyService,
        private readonly SkillService $skillService,
    ) {
    }//end __construct()

    /**
     * List the templates visible in the caller's tenant.
     *
     * @return array<int, ObjectEntity> The AgentTemplate objects.
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#non-functional-requirements
     */
    public function list(): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::TEMPLATE_SCHEMA)
            ->findAll(config: ['limit' => 200]);

        $out = [];
        foreach ($objects as $object) {
            if ($object instanceof ObjectEntity) {
                $out[] = $object;
            }
        }

        return $out;

    }//end list()

    /**
     * Get a template by UUID (tenant-scoped), or null.
     *
     * @param string $templateId The AgentTemplate UUID.
     *
     * @return ObjectEntity|null The template, or null when not found/not visible.
     *
     * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-agenttemplatecontroller-routes-adr-023-action-seed
     */
    public function get(string $templateId): ?ObjectEntity
    {
        return $this->objectService->find(
            id: $templateId,
            register: self::REGISTER_SLUG,
            schema: self::TEMPLATE_SCHEMA
        );

    }//end get()

    /**
     * Author a new template directly (not via import) — always `active`/`local`, never scanned.
     *
     * @param array<string, mixed> $payload   The requested template fields (protected keys stripped by caller).
     * @param string               $createdBy The authoring user id.
     *
     * @return ObjectEntity The persisted template.
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-importing-a-template-from-an-external-source-lands-quarantined-and-content-scanned
     */
    public function create(array $payload, string $createdBy): ObjectEntity
    {
        $data           = $this->stripProtectedKeys(data: $payload);
        $data['state']  = 'active';
        $data['source'] = 'local';
        $data['quarantineReason'] = null;
        $data['scanReport']       = null;
        $data['createdBy']        = $createdBy;

        return $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $data),
            register: self::REGISTER_SLUG,
            schema: self::TEMPLATE_SCHEMA
        );

    }//end create()

    /**
     * Update an existing template's fields (partial merge). Lifecycle fields
     * (state/source/quarantineReason/scanReport/createdBy) are untouched — only
     * approveQuarantined() transitions state.
     *
     * @param string               $templateId The AgentTemplate UUID.
     * @param array<string, mixed> $payload    The requested field updates (protected keys stripped by caller).
     *
     * @return ObjectEntity|null The updated template, or null when not found.
     *
     * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-agenttemplatecontroller-routes-adr-023-action-seed
     */
    public function update(string $templateId, array $payload): ?ObjectEntity
    {
        $template = $this->get(templateId: $templateId);
        if ($template === null) {
            return null;
        }

        $data = array_merge($template->getObject(), $this->stripProtectedKeys(data: $payload));

        return $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $data),
            register: self::REGISTER_SLUG,
            schema: self::TEMPLATE_SCHEMA,
            uuid: (string) $template->getUuid()
        );

    }//end update()

    /**
     * Delete a template (hard delete — a template is a reusable suggestion, not an
     * auditable governance record like a Skill; unlike skills-marketplace's Curator, there
     * is no age-based archive lifecycle for templates in this change).
     *
     * @param string $templateId The AgentTemplate UUID.
     *
     * @return bool True when deleted.
     *
     * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-agenttemplatecontroller-routes-adr-023-action-seed
     */
    public function delete(string $templateId): bool
    {
        return $this->objectService->deleteObject(
            uuid: $templateId,
            register: self::REGISTER_SLUG,
            schema: self::TEMPLATE_SCHEMA
        );

    }//end delete()

    /**
     * Export an existing Agent to a portable template package — secrets/tenant fields
     * (invitedUsers, groups, requestQuota, tokenQuota, views, actingUser) are never copied;
     * only the fields the AgentTemplate schema declares are read off the Agent.
     *
     * @param string $agentId The Agent UUID.
     *
     * @return string|null The JSON package string, or null when the agent is not found.
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
     */
    public function exportFromAgent(string $agentId): ?string
    {
        $agent = $this->objectService->find(
            id: $agentId,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA
        );
        if ($agent === null) {
            return null;
        }

        $data = $agent->getObject();

        $fields = [
            'name'              => (string) ($data['name'] ?? ''),
            'description'       => (string) ($data['description'] ?? ''),
            'category'          => (string) ($data['type'] ?? ''),
            'systemPrompt'      => (string) ($data['prompt'] ?? ''),
            'suggestedProvider' => (string) ($data['provider'] ?? ''),
            'suggestedModel'    => (string) ($data['model'] ?? ''),
            'tools'             => $this->toolsList(raw: ($data['tools'] ?? null)),
            'skillRefs'         => $this->skillRefsFromInstalls(skillInstalls: ($data['skillInstalls'] ?? [])),
            'suggestedSchedule' => [],
            'version'           => '0.1.0',
        ];

        return $this->serializer->toPackage(template: $fields);

    }//end exportFromAgent()

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
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
     */
    public function exportTemplate(string $templateId): ?string
    {
        $template = $this->get(templateId: $templateId);
        if ($template === null) {
            return null;
        }

        return $this->serializer->toPackage(template: $template->getObject());

    }//end exportTemplate()

    /**
     * Import a JSON package into a new template. A package imported with `source='org'` or
     * `'hub'` lands `quarantined` and is content-scanned (systemPrompt is as much a
     * prompt-injection vector as a Skill body); a `source='local'` import (e.g. duplicating an
     * existing template) is saved `active` with no scan.
     *
     * @param string $package   The JSON package string.
     * @param string $source    The import source (`local`|`org`|`hub`).
     * @param string $createdBy The importing user id.
     *
     * @return ObjectEntity The persisted template.
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-importing-a-template-from-an-external-source-lands-quarantined-and-content-scanned
     */
    public function importPackage(string $package, string $source, string $createdBy): ObjectEntity
    {
        $parsed = $this->serializer->fromPackage(package: $package);

        $name = $parsed['name'];
        if ($name === '') {
            $name = 'Untitled template';
        }

        $data = [
            'name'              => $name,
            'description'       => $parsed['description'],
            'category'          => $parsed['category'],
            'systemPrompt'      => $parsed['systemPrompt'],
            'suggestedProvider' => $parsed['suggestedProvider'],
            'suggestedModel'    => $parsed['suggestedModel'],
            'tools'             => $parsed['tools'],
            'skillRefs'         => $parsed['skillRefs'],
            'suggestedSchedule' => $parsed['suggestedSchedule'],
            'version'           => $parsed['version'],
            'source'            => $source,
            'createdBy'         => $createdBy,
        ];

        if ($source === 'local') {
            $data['state']            = 'active';
            $data['quarantineReason'] = null;
            $data['scanReport']       = null;

            return $this->objectService->saveObject(
                object: $this->sanitizeForSave(data: $data),
                register: self::REGISTER_SLUG,
                schema: self::TEMPLATE_SCHEMA
            );
        }

        $scan          = $this->scanSystemPrompt(systemPrompt: $parsed['systemPrompt']);
        $data['state'] = 'quarantined';
        $data['quarantineReason'] = $this->quarantineReasonFor(source: $source, scan: $scan);
        $data['scanReport']       = $scan;

        return $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $data),
            register: self::REGISTER_SLUG,
            schema: self::TEMPLATE_SCHEMA
        );

    }//end importPackage()

    /**
     * The review gate: transition a quarantined template to active.
     *
     * A `dangerous` content-scan verdict blocks one-click approval — the template stays
     * quarantined and the caller must override explicitly (`$force=true`), mirroring
     * `SkillMarketplaceService::approveQuarantined()` exactly.
     *
     * @param string $templateId The AgentTemplate UUID.
     * @param bool   $force      Override a `dangerous` scan verdict (a conscious reviewer decision).
     *
     * @return ObjectEntity|null The updated template, or null when not found.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$force` is a genuine two-mode reviewer
     * decision (explicit dangerous-verdict override), part of the public seam the controller
     * exposes — not an SRP smell (mirrors SkillMarketplaceService::approveQuarantined()).
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-approving-a-quarantined-template-requires-action-authorization
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-overriding-a-dangerous-scan-verdict-requires-a-stricter-action
     */
    public function approveQuarantined(string $templateId, bool $force=false): ?ObjectEntity
    {
        $template = $this->get(templateId: $templateId);
        if ($template === null) {
            return null;
        }

        $data = $template->getObject();
        if ((string) ($data['state'] ?? '') !== 'quarantined') {
            // Not quarantined — nothing to approve; return unchanged.
            return $template;
        }

        // Re-scan at the gate (content is authoritative; the stored report may be stale).
        $scan = $this->scanSystemPrompt(systemPrompt: (string) ($data['systemPrompt'] ?? ''));
        $data['scanReport'] = $scan;
        if (($scan['severity'] ?? '') === ContentScanService::SEVERITY_DANGEROUS && $force === false) {
            $data['quarantineReason'] = $this->quarantineReasonFor(source: (string) ($data['source'] ?? 'org'), scan: $scan);
            return $this->objectService->saveObject(
                object: $this->sanitizeForSave(data: $data),
                register: self::REGISTER_SLUG,
                schema: self::TEMPLATE_SCHEMA,
                uuid: (string) $template->getUuid()
            );
        }

        $data['state']            = 'active';
        $data['quarantineReason'] = null;

        return $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $data),
            register: self::REGISTER_SLUG,
            schema: self::TEMPLATE_SCHEMA,
            uuid: (string) $template->getUuid()
        );

    }//end approveQuarantined()

    /**
     * "Use this template": instantiate a template into a real Agent in the caller's
     * organisation. The suggested provider/model is always resolved against
     * `TenantModelPolicyService::effectivePolicyFor()` — an out-of-policy suggestion is
     * replaced by the policy's default (or first allowed provider) and the substitution is
     * reported, never silently applied and never silently dropped. Skill refs are resolved
     * best-effort (a miss never fails the call); the suggested schedule is returned verbatim
     * and NO `Schedule` object is ever created here.
     *
     * @param string               $templateId   The AgentTemplate UUID.
     * @param string               $organisation The caller's active organisation (may be '').
     * @param array<string, mixed> $overrides    Caller-supplied field overrides for the created Agent (protected keys stripped).
     *
     * @return array<string, mixed>|null The instantiate result { agent, modelCoerced,
     *                                    requestedProvider, requestedModel, resolvedProvider,
     *                                    resolvedModel, unresolvedSkillRefs, suggestedSchedule },
     *                                    or null when the template is not found.
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-silently-violates-the-callers-model-policy
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-resolves-skill-references-best-effort
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-auto-creates-a-live-schedule
     */
    public function instantiate(string $templateId, string $organisation, array $overrides=[]): ?array
    {
        $template = $this->get(templateId: $templateId);
        if ($template === null) {
            return null;
        }

        $data = $template->getObject();

        $requestedProvider = (string) ($data['suggestedProvider'] ?? '');
        $requestedModel    = (string) ($data['suggestedModel'] ?? '');

        [$resolvedProvider, $resolvedModel, $modelCoerced] = $this->resolveModel(
            organisation: $organisation,
            requestedProvider: $requestedProvider,
            requestedModel: $requestedModel
        );

        [$installSkillIds, $unresolvedSkillRefs] = $this->resolveSkillRefs(skillRefs: ($data['skillRefs'] ?? []));

        $agentPayload = array_merge(
            [
                'name'          => (string) ($data['name'] ?? 'Untitled agent'),
                'description'   => (string) ($data['description'] ?? ''),
                'type'          => (string) ($data['category'] ?? ''),
                'provider'      => $resolvedProvider,
                'model'         => $resolvedModel,
                'prompt'        => (string) ($data['systemPrompt'] ?? ''),
                'tools'         => $this->toolsList(raw: ($data['tools'] ?? null)),
                'isPrivate'     => true,
                'searchFiles'   => true,
                'searchObjects' => true,
            ],
            $this->stripProtectedKeys(data: $overrides)
        );

        $agent = $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $agentPayload),
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA
        );

        $agentUuid = (string) $agent->getUuid();
        foreach ($installSkillIds as $skillId) {
            $this->skillService->installOnAgent(skillId: $skillId, agentId: $agentUuid);
        }

        return [
            'agent'               => $this->shapeAgent(agent: $agent),
            'modelCoerced'        => $modelCoerced,
            'requestedProvider'   => $requestedProvider,
            'requestedModel'      => $requestedModel,
            'resolvedProvider'    => $resolvedProvider,
            'resolvedModel'       => $resolvedModel,
            'unresolvedSkillRefs' => $unresolvedSkillRefs,
            'suggestedSchedule'   => $this->scheduleHintOrEmpty(raw: ($data['suggestedSchedule'] ?? null)),
        ];

    }//end instantiate()

    /**
     * Resolve the (provider, model) to apply to the created Agent: the suggestion verbatim
     * when it is allowed (or absent), else the organisation's effective policy default/
     * first-allowed provider — never a pair outside the caller's effective ModelPolicy.
     *
     * @param string $organisation      The caller's organisation (may be '').
     * @param string $requestedProvider The template's suggested provider (may be '').
     * @param string $requestedModel    The template's suggested model (may be '').
     *
     * @return array{0: string, 1: string, 2: bool} [resolvedProvider, resolvedModel, modelCoerced].
     */
    private function resolveModel(string $organisation, string $requestedProvider, string $requestedModel): array
    {
        $policy = $this->modelPolicyService->effectivePolicyFor(organisation: $organisation);

        $hasSuggestion = ($requestedProvider !== '');
        $isAllowed     = ($hasSuggestion === true)
            && $this->modelPolicyService->isAllowed(organisation: $organisation, provider: $requestedProvider, model: $requestedModel);

        if ($hasSuggestion === true && $isAllowed === true) {
            return [$requestedProvider, $requestedModel, false];
        }

        $default = $policy['defaultModel'];
        if (is_array($default) === true && $default['provider'] !== '') {
            return [$default['provider'], $default['model'], $hasSuggestion];
        }

        $allowed = $policy['allowed'];
        $first   = ($allowed[0] ?? null);
        if (is_array($first) === true && $first['provider'] !== '') {
            // No default pinned — the first allowed provider, no model (forces an explicit
            // user choice rather than guessing a model id the org never vetted).
            return [$first['provider'], '', $hasSuggestion];
        }

        // No policy anywhere allows anything (fail-closed fallback with no configured
        // provider) — leave both empty; the created Agent is inert until an admin sets a
        // provider, never silently wired to an unvetted one.
        return ['', '', $hasSuggestion];

    }//end resolveModel()

    /**
     * Resolve a template's skillRefs best-effort: a hit that is `active` and visible to the
     * caller's organisation (SkillService::getSkill() is already tenant-scoped) is queued for
     * install; a miss is reported, never fails the caller.
     *
     * @param mixed $skillRefs The template's raw skillRefs value.
     *
     * @return array{0: array<int, string>, 1: array<int, array<string, string>>} [installSkillIds, unresolvedSkillRefs].
     */
    private function resolveSkillRefs(mixed $skillRefs): array
    {
        if (is_array($skillRefs) === false) {
            return [[], []];
        }

        $install    = [];
        $unresolved = [];
        foreach ($skillRefs as $ref) {
            if (is_array($ref) === false) {
                continue;
            }

            $skillId = (string) ($ref['skillId'] ?? '');
            $name    = (string) ($ref['name'] ?? '');
            if ($skillId === '') {
                $unresolved[] = ['skillId' => $skillId, 'name' => $name];
                continue;
            }

            $skill = $this->skillService->getSkill(skillId: $skillId);
            $state = '';
            if ($skill !== null) {
                $state = (string) ($skill->getObject()['state'] ?? '');
            }

            if ($skill !== null && $state === 'active') {
                $install[] = $skillId;
                continue;
            }

            $unresolved[] = ['skillId' => $skillId, 'name' => $name];
        }//end foreach

        return [$install, $unresolved];

    }//end resolveSkillRefs()

    /**
     * Build `skillRefs` from an Agent's `skillInstalls` uuids for export — resolves each
     * uuid's current name best-effort (a name-hint, not a live reference).
     *
     * @param mixed $skillInstalls The Agent's raw skillInstalls value.
     *
     * @return array<int, array{skillId: string, name: string}> The skillRefs.
     */
    private function skillRefsFromInstalls(mixed $skillInstalls): array
    {
        if (is_array($skillInstalls) === false) {
            return [];
        }

        $refs = [];
        foreach ($skillInstalls as $skillId) {
            if (is_string($skillId) === false || $skillId === '') {
                continue;
            }

            $name  = '';
            $skill = $this->skillService->getSkill(skillId: $skillId);
            if ($skill !== null) {
                $name = (string) ($skill->getObject()['name'] ?? '');
            }

            $refs[] = ['skillId' => $skillId, 'name' => $name];
        }

        return $refs;

    }//end skillRefsFromInstalls()

    /**
     * Normalise a raw `tools` value into a plain list (tolerant of a missing/malformed input).
     *
     * @param mixed $raw The candidate value.
     *
     * @return array<int, string> The normalised tools list.
     */
    private function toolsList(mixed $raw): array
    {
        if (is_array($raw) === false) {
            return [];
        }

        return array_values($raw);

    }//end toolsList()

    /**
     * Normalise a raw `suggestedSchedule` value into a plain hint array (tolerant of a
     * missing/malformed input — an empty hint is a valid "no suggestion" state).
     *
     * @param mixed $raw The candidate value.
     *
     * @return array<string, mixed> The normalised hint.
     */
    private function scheduleHintOrEmpty(mixed $raw): array
    {
        if (is_array($raw) === false) {
            return [];
        }

        return $raw;

    }//end scheduleHintOrEmpty()

    /**
     * Run the OpenRegister content scanner over a template's systemPrompt.
     *
     * @param string $systemPrompt The template's system prompt text.
     *
     * @return array<string, mixed> The scan report { severity, safe, findings, scannedAt, … }.
     */
    private function scanSystemPrompt(string $systemPrompt): array
    {
        $report = $this->contentScanService->scan(content: $systemPrompt, metadata: []);
        $report['scannedAt'] = $this->now();

        return $report;

    }//end scanSystemPrompt()

    /**
     * The quarantine reason for a freshly-imported or re-scanned template, reflecting the
     * scan verdict so a reviewer sees why it needs attention (mirrors
     * SkillMarketplaceService::quarantineReasonFor()).
     *
     * @param string               $source The import source (`org`|`hub`).
     * @param array<string, mixed> $scan   The scan report from scanSystemPrompt().
     *
     * @return string The human-readable quarantine reason.
     */
    private function quarantineReasonFor(string $source, array $scan): string
    {
        $severity = (string) ($scan['severity'] ?? ContentScanService::SEVERITY_CLEAN);
        $count    = count(($scan['findings'] ?? []));

        if ($severity === ContentScanService::SEVERITY_DANGEROUS) {
            return 'Imported from '.$source.'; content scan flagged '.$count.' DANGEROUS pattern(s) — '
                .'review before activation (approval is blocked until overridden).';
        }

        if ($severity === ContentScanService::SEVERITY_SUSPICIOUS) {
            return 'Imported from '.$source.'; content scan flagged '.$count.' suspicious pattern(s) — review before activation.';
        }

        return 'Imported from '.$source.'; awaiting review before activation.';

    }//end quarantineReasonFor()

    /**
     * Strip caller-protected keys from a create/update/override payload (mirrors
     * AgentsController::stripProtectedKeys()).
     *
     * @param array<string, mixed> $data The raw payload.
     *
     * @return array<string, mixed> The payload with protected keys removed.
     */
    private function stripProtectedKeys(array $data): array
    {
        foreach (self::PROTECTED_KEYS as $key) {
            unset($data[$key]);
        }

        return $data;

    }//end stripProtectedKeys()

    /**
     * Shape an Agent ObjectEntity into a UUID + payload response map (mirrors the
     * AgentsController/SkillController response shape).
     *
     * @param ObjectEntity $agent The created Agent object.
     *
     * @return array<string, mixed> The response payload.
     */
    private function shapeAgent(ObjectEntity $agent): array
    {
        $data         = $agent->getObject();
        $data['uuid'] = (string) $agent->getUuid();

        return $data;

    }//end shapeAgent()

    /**
     * The current UTC timestamp in ISO-8601.
     *
     * @return string The ISO-8601 timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    }//end now()
}//end class
