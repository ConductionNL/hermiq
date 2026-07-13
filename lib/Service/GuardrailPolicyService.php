<?php

/**
 * Hermiq GuardrailPolicyService.
 *
 * Reads and writes the per-organisation `GuardrailPolicy` OpenRegister object and
 * exposes the three pure enforcement helpers every agent-turn call site threads
 * through: `filterInput()`, `filterOutput()`, `classifyTool()`. Mirrors
 * `TenantModelPolicyService`'s exact per-organisation-object pattern (`_rbac:
 * false, _multitenancy: false`, matched by `ObjectEntity.organisation`) because a
 * policy read must work for a system-wide schedule tick or flow-triggered run —
 * which run before any per-user RBAC context exists — exactly as the kill-switch
 * and model-policy reads already do.
 *
 * Resolution order (design.md Decision 1): (1) the organisation's own
 * GuardrailPolicy, if one exists AND is enabled; (2) else the organisation-less
 * instance-wide default (`ObjectEntity.organisation === ''`), if one exists AND is
 * enabled; (3) else — no policy anywhere, or every policy in scope disabled — a
 * FULLY-OPEN fallback (every filter `off`, every tool `auto`) — the exact
 * behaviour of this app before this change. This is deliberately the OPPOSITE of
 * `TenantModelPolicyService::fallbackPolicy()`'s fail-CLOSED shape: guardrails are
 * an opt-in governance control in this app, like `Budget`/`Schedule.requiresApproval`,
 * not a fail-safe cost/vendor boundary.
 *
 * PII/secret detection is REUSE, not duplication: both `filterInput()` and
 * `filterOutput()` detect PII/secrets by diffing `RedactionService::redact()`'s
 * output against its input — a difference means something was masked. No second
 * pattern set is introduced. Prompt-injection detection is new, deterministic
 * regex — a fixed list of known instruction-override phrasings — because it is a
 * different threat model (instruction confusion, not data leakage) that
 * `RedactionService` does not (and should not) own.
 *
 * This is a recognised ADR-031 imperative exception: a side-effecting governance
 * service, not a derived value or declarative lifecycle (same role as
 * `TenantControlService`/`TenantModelPolicyService`/`BudgetService`).
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
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-input-is-filtered-before-every-llm-turn
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Reads/writes GuardrailPolicy objects and resolves + applies the effective
 * policy for an organisation (own policy → instance default → fully-open
 * fallback).
 *
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
 */
class GuardrailPolicyService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for guardrail-policy objects.
     *
     * @var string
     */
    private const SCHEMA_SLUG = 'guardrailpolicy';

    /**
     * Allowed input/output PII action values.
     *
     * @var array<int, string>
     */
    private const PII_ACTIONS = ['off', 'redact', 'block'];

    /**
     * Allowed input prompt-injection action values (no `redact` — an override
     * attempt can only be refused, never partially masked; design.md Decision 3).
     *
     * @var array<int, string>
     */
    private const INJECTION_ACTIONS = ['off', 'block'];

    /**
     * Allowed per-tool classification values.
     *
     * @var array<int, string>
     */
    private const TOOL_CLASSIFICATIONS = ['auto', 'confirm', 'deny'];

    /**
     * A small, fixed list of known instruction-override / jailbreak phrasings
     * (design.md Decision 3) — case-insensitive substring/regex match. Narrow by
     * design: generic words like "ignore" are deliberately NOT matched on their
     * own, to avoid false-positives on legitimate prompts that merely mention
     * ignoring something (proposal.md Risk: "ignore the previous email" in a
     * support-ticket triage agent).
     *
     * @var array<int, string>
     */
    private const PROMPT_INJECTION_PATTERNS = [
        '~\bignore\s+(all\s+|any\s+)?(previous|prior|above)\s+instructions\b~i',
        '~\bdisregard\s+(the\s+)?(system\s+prompt|previous\s+instructions|all\s+prior\s+instructions)\b~i',
        '~\byou\s+are\s+now\s+in\s+developer\s+mode\b~i',
        '~\breveal\s+your\s+(system\s+prompt|instructions)\b~i',
        '~\b(print|show|output)\s+your\s+(system\s+prompt|instructions)\b~i',
        '~\bforget\s+(everything|all)\s+(you\s+(were|have\s+been)\s+told|previous\s+instructions)\b~i',
        '~\bact\s+as\s+(if\s+you\s+(have|had)\s+no\s+(restrictions|rules|guidelines)|an?\s+unrestricted\s+ai)\b~i',
        '~\bpretend\s+(you\s+have\s+no|there\s+are\s+no)\s+(rules|restrictions|guidelines)\b~i',
        '~\bdo\s+anything\s+now\b~i',
        '~\bjailbreak\b~i',
        '~\bDAN\s+mode\b~i',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OpenRegister object read/write (single write-path).
     * @param RedactionService $redactionService The single source of PII/secret pattern matching,
     *                                           reused (never duplicated) by diffing its output.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RedactionService $redactionService,
    ) {
    }//end __construct()

    /**
     * Get the GuardrailPolicy object for an organisation, if one exists.
     *
     * Matched by `ObjectEntity.organisation`. An empty string matches the
     * organisation-less INSTANCE-DEFAULT policy (a deliberate, valid lookup —
     * mirrors `TenantModelPolicyService::getForOrganisation()`).
     *
     * @param string $organisation The organisation identifier, or '' for the instance default.
     *
     * @return ObjectEntity|null The policy object, or null when none exists at this scope.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
     */
    public function getForOrganisation(string $organisation): ?ObjectEntity
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEMA_SLUG)
            ->findAll(config: [], _rbac: false, _multitenancy: false);

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getOrganisation() ?? '') === $organisation) {
                return $object;
            }
        }

        return null;

    }//end getForOrganisation()

    /**
     * Find a single GuardrailPolicy by UUID, system-wide (controller write-path
     * authorization needs the policy's organisation before deciding whether the
     * caller may administer it — mirrors `TenantModelPolicyService::findById()`).
     *
     * @param string $uuid The GuardrailPolicy object UUID.
     *
     * @return ObjectEntity|null The policy, or null when not found.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
     */
    public function findById(string $uuid): ?ObjectEntity
    {
        if ($uuid === '') {
            return null;
        }

        return $this->objectService->find(
            id: $uuid,
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            _rbac: false,
            _multitenancy: false
        );

    }//end findById()

    /**
     * List every GuardrailPolicy object, system-wide (controller filters visibility).
     *
     * @return array<int, ObjectEntity> Every policy object.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
     */
    public function listAll(): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEMA_SLUG)
            ->findAll(config: [], _rbac: false, _multitenancy: false);

        return array_values(array_filter($objects, static fn ($object): bool => $object instanceof ObjectEntity));

    }//end listAll()

    /**
     * Resolve the effective policy for an organisation: its own GuardrailPolicy
     * (if enabled), else the organisation-less instance default (if enabled),
     * else a fully-open synthetic policy (design.md Decision 1 — fail-OPEN, not
     * fail-closed).
     *
     * @param string $organisation The organisation identifier (may be '').
     *
     * @return array{source:string,inputFilters:array{piiAction:string,promptInjectionAction:string},outputFilters:array{piiAction:string},toolPolicy:list<array{toolId:string,classification:string}>,enabled:bool}
     *         The effective, shaped policy.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
     */
    public function effectivePolicyFor(string $organisation): array
    {
        if ($organisation !== '') {
            $own = $this->getForOrganisation(organisation: $organisation);
            if ($own !== null && $this->isEnabled(policy: $own) === true) {
                return $this->shape(policy: $own, source: 'organisation');
            }
        }

        $instanceDefault = $this->getForOrganisation(organisation: '');
        if ($instanceDefault !== null && $this->isEnabled(policy: $instanceDefault) === true) {
            return $this->shape(policy: $instanceDefault, source: 'instance');
        }

        return $this->fallbackPolicy();

    }//end effectivePolicyFor()

    /**
     * The fully-open fallback used when no GuardrailPolicy is enabled anywhere
     * in scope: every filter `off`, every tool `auto` — identical to this app's
     * behaviour before this change (design.md Decision 1).
     *
     * @return array{source:string,inputFilters:array{piiAction:string,promptInjectionAction:string},outputFilters:array{piiAction:string},toolPolicy:list<array{toolId:string,classification:string}>,enabled:bool}
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
     */
    private function fallbackPolicy(): array
    {
        return [
            'source'        => 'fallback',
            'inputFilters'  => ['piiAction' => 'off', 'promptInjectionAction' => 'off'],
            'outputFilters' => ['piiAction' => 'off'],
            'toolPolicy'    => [],
            'enabled'       => true,
        ];

    }//end fallbackPolicy()

    /**
     * Whether a GuardrailPolicy ObjectEntity is enabled (defaults to true when
     * the field is absent — mirrors `Budget.enabled`'s default).
     *
     * @param ObjectEntity $policy The policy object.
     *
     * @return bool
     */
    private function isEnabled(ObjectEntity $policy): bool
    {
        return ($policy->getObject()['enabled'] ?? true) !== false;

    }//end isEnabled()

    /**
     * Apply the effective policy's input filters to text BEFORE it is sent to
     * the LLM: prompt-injection detection first (never redacted, only blocked),
     * then the PII/secret action.
     *
     * @param array<string,mixed> $policy The effective policy (from `effectivePolicyFor()`).
     * @param string              $text   The user/prompt text to filter.
     *
     * @return array{text:string,blocked:bool,reason:?string} The filtered text and
     *         whether the turn must be refused (the caller decides what "refused"
     *         means at its own boundary — Engine throws, ScheduleService fails the run).
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-input-is-filtered-before-every-llm-turn
     */
    public function filterInput(array $policy, string $text): array
    {
        $inputFilters    = (array) ($policy['inputFilters'] ?? []);
        $injectionAction = (string) ($inputFilters['promptInjectionAction'] ?? 'off');

        if ($injectionAction === 'block' && $this->matchesPromptInjection(text: $text) === true) {
            return ['text' => $text, 'blocked' => true, 'reason' => 'prompt_injection'];
        }

        $piiAction = (string) ($inputFilters['piiAction'] ?? 'off');

        return $this->applyPiiAction(text: $text, action: $piiAction, boundary: 'input');

    }//end filterInput()

    /**
     * Apply the effective policy's output filters to the LLM's response text
     * BEFORE it is persisted or delivered. Never throws/blocks the calling turn
     * — a `block` action replaces the text with a withheld-response placeholder
     * so the turn always completes.
     *
     * @param array<string,mixed> $policy The effective policy (from `effectivePolicyFor()`).
     * @param string              $text   The LLM output text to filter.
     *
     * @return array{text:string,blocked:bool,reason:?string} The filtered (possibly
     *         placeholder) text.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
     */
    public function filterOutput(array $policy, string $text): array
    {
        $outputFilters = (array) ($policy['outputFilters'] ?? []);
        $piiAction     = (string) ($outputFilters['piiAction'] ?? 'off');

        return $this->applyPiiAction(text: $text, action: $piiAction, boundary: 'output');

    }//end filterOutput()

    /**
     * Classify a tool per the effective policy's `toolPolicy` list. A tool
     * absent from the list defaults to `auto` — zero behavior change for an
     * organisation with no policy, or one whose policy does not mention this
     * tool.
     *
     * @param array<string,mixed> $policy The effective policy (from `effectivePolicyFor()`).
     * @param string              $toolId The full namespaced tool id.
     *
     * @return string One of `auto`, `confirm`, `deny`.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
     */
    public function classifyTool(array $policy, string $toolId): string
    {
        foreach (($policy['toolPolicy'] ?? []) as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            if ((string) ($entry['toolId'] ?? '') !== $toolId) {
                continue;
            }

            $classification = (string) ($entry['classification'] ?? 'auto');
            if (in_array($classification, self::TOOL_CLASSIFICATIONS, true) === true) {
                return $classification;
            }
        }

        return 'auto';

    }//end classifyTool()

    /**
     * Whether text matches any known instruction-override / jailbreak pattern
     * (design.md Decision 3).
     *
     * @param string $text The text to scan.
     *
     * @return bool
     */
    private function matchesPromptInjection(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        foreach (self::PROMPT_INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;

    }//end matchesPromptInjection()

    /**
     * Apply a PII/secret action to text, detecting sensitive content by
     * DIFFING `RedactionService::redact()`'s output against its input (design.md
     * Decision 2) — zero new pattern set.
     *
     * @param string $text     The text to filter.
     * @param string $action   `off`|`redact`|`block`.
     * @param string $boundary `input`|`output` — selects the block placeholder shape.
     *
     * @return array{text:string,blocked:bool,reason:?string}
     */
    private function applyPiiAction(string $text, string $action, string $boundary): array
    {
        if ($action === 'off' || $text === '') {
            return ['text' => $text, 'blocked' => false, 'reason' => null];
        }

        $redacted     = $this->redactionService->redact($text);
        $hasSensitive = ($redacted !== $text);

        if ($hasSensitive === false) {
            return ['text' => $text, 'blocked' => false, 'reason' => null];
        }

        if ($action === 'block') {
            $placeholder = '';
            if ($boundary === 'output') {
                $placeholder = $this->withheldPlaceholder();
            }

            return ['text' => $placeholder, 'blocked' => true, 'reason' => 'sensitive_content'];
        }

        return ['text' => $redacted, 'blocked' => false, 'reason' => null];

    }//end applyPiiAction()

    /**
     * The placeholder text substituted for a `block`-classified output.
     *
     * @return string
     */
    private function withheldPlaceholder(): string
    {
        return 'This response was withheld because it contained sensitive content blocked by the organisation\'s guardrail policy.';

    }//end withheldPlaceholder()

    /**
     * Create-or-update the GuardrailPolicy for an organisation (at most one per
     * organisation, mirroring `TenantModelPolicyService::upsertForOrganisation()`).
     * `$organisation === ''` upserts the organisation-less instance default.
     *
     * @param string               $organisation The target organisation ('' for the instance default).
     * @param array<string, mixed> $payload      The requested `inputFilters`/`outputFilters`/`toolPolicy`/`enabled` fields.
     *
     * @return array<string, mixed> The shaped, persisted policy.
     *
     * @throws InvalidArgumentException When the payload fails validation.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
     */
    public function upsertForOrganisation(string $organisation, array $payload): array
    {
        $inputFilters  = $this->normaliseInputFilters(raw: ($payload['inputFilters'] ?? []));
        $outputFilters = $this->normaliseOutputFilters(raw: ($payload['outputFilters'] ?? []));
        $toolPolicy    = $this->normaliseToolPolicy(raw: ($payload['toolPolicy'] ?? []));
        $enabled       = ($payload['enabled'] ?? true) !== false;

        $existing = $this->getForOrganisation(organisation: $organisation);

        $data = [];
        $uuid = null;
        if ($existing !== null) {
            $data = $existing->getObject();
            $uuid = (string) $existing->getUuid();
        }

        $data['inputFilters']  = $inputFilters;
        $data['outputFilters'] = $outputFilters;
        $data['toolPolicy']    = $toolPolicy;
        $data['enabled']       = $enabled;

        // Pin the policy to the TARGET organisation, not the actor's active
        // organisation (mirrors TenantModelPolicyService::upsertForOrganisation()'s
        // @self.organisation trick).
        $self = (array) ($data['@self'] ?? []);
        $self['organisation'] = $organisation;
        $data['@self']        = $self;

        $policy = $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            uuid: $uuid,
            _rbac: false,
            _multitenancy: false
        );

        $source = 'organisation';
        if ($organisation === '') {
            $source = 'instance';
        }

        return $this->shape(policy: $policy, source: $source);

    }//end upsertForOrganisation()

    /**
     * Update an existing GuardrailPolicy by UUID (controller-gated).
     *
     * @param string               $uuid    The GuardrailPolicy object UUID.
     * @param array<string, mixed> $payload The fields to update.
     *
     * @return array<string, mixed> The shaped, updated policy.
     *
     * @throws RuntimeException         When the policy cannot be found.
     * @throws InvalidArgumentException When the merged payload fails validation.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
     */
    public function update(string $uuid, array $payload): array
    {
        $existing = $this->findById(uuid: $uuid);
        if ($existing === null) {
            throw new RuntimeException("GuardrailPolicy '{$uuid}' does not exist");
        }

        $organisation = (string) ($existing->getOrganisation() ?? '');

        return $this->upsertForOrganisation(organisation: $organisation, payload: $payload);

    }//end update()

    /**
     * Validate + normalise the `inputFilters` payload.
     *
     * @param mixed $raw The raw `inputFilters` payload value.
     *
     * @return array{piiAction:string,promptInjectionAction:string}
     *
     * @throws InvalidArgumentException When an action value is not recognised.
     */
    private function normaliseInputFilters(mixed $raw): array
    {
        if (is_array($raw) === false) {
            throw new InvalidArgumentException('inputFilters must be an object');
        }

        $piiAction = (string) ($raw['piiAction'] ?? 'off');
        if (in_array($piiAction, self::PII_ACTIONS, true) === false) {
            throw new InvalidArgumentException(
                "Unsupported inputFilters.piiAction '{$piiAction}' — must be one of: ".implode(', ', self::PII_ACTIONS)
            );
        }

        $injectionAction = (string) ($raw['promptInjectionAction'] ?? 'off');
        if (in_array($injectionAction, self::INJECTION_ACTIONS, true) === false) {
            throw new InvalidArgumentException(
                "Unsupported inputFilters.promptInjectionAction '{$injectionAction}' — must be one of: ".implode(', ', self::INJECTION_ACTIONS)
            );
        }

        return ['piiAction' => $piiAction, 'promptInjectionAction' => $injectionAction];

    }//end normaliseInputFilters()

    /**
     * Validate + normalise the `outputFilters` payload.
     *
     * @param mixed $raw The raw `outputFilters` payload value.
     *
     * @return array{piiAction:string}
     *
     * @throws InvalidArgumentException When the action value is not recognised.
     */
    private function normaliseOutputFilters(mixed $raw): array
    {
        if (is_array($raw) === false) {
            throw new InvalidArgumentException('outputFilters must be an object');
        }

        $piiAction = (string) ($raw['piiAction'] ?? 'off');
        if (in_array($piiAction, self::PII_ACTIONS, true) === false) {
            throw new InvalidArgumentException(
                "Unsupported outputFilters.piiAction '{$piiAction}' — must be one of: ".implode(', ', self::PII_ACTIONS)
            );
        }

        return ['piiAction' => $piiAction];

    }//end normaliseOutputFilters()

    /**
     * Validate + normalise the `toolPolicy` payload into a list of
     * `{toolId, classification}` entries.
     *
     * @param mixed $raw The raw `toolPolicy` payload value.
     *
     * @return list<array{toolId:string,classification:string}>
     *
     * @throws InvalidArgumentException When an entry is malformed.
     */
    private function normaliseToolPolicy(mixed $raw): array
    {
        if (is_array($raw) === false) {
            throw new InvalidArgumentException('toolPolicy must be an array of {toolId, classification} entries');
        }

        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry) === false) {
                throw new InvalidArgumentException('Each toolPolicy entry must be an object with toolId/classification');
            }

            $toolId = (string) ($entry['toolId'] ?? '');
            if ($toolId === '') {
                throw new InvalidArgumentException('Each toolPolicy entry requires a non-empty toolId');
            }

            $classification = (string) ($entry['classification'] ?? 'auto');
            if (in_array($classification, self::TOOL_CLASSIFICATIONS, true) === false) {
                throw new InvalidArgumentException(
                    "Unsupported toolPolicy classification '{$classification}' — must be one of: ".implode(', ', self::TOOL_CLASSIFICATIONS)
                );
            }

            $out[] = ['toolId' => $toolId, 'classification' => $classification];
        }//end foreach

        return $out;

    }//end normaliseToolPolicy()

    /**
     * Shape a GuardrailPolicy ObjectEntity into the API response payload / the
     * internal effective-policy shape `filterInput()`/`filterOutput()`/`classifyTool()`
     * consume.
     *
     * @param ObjectEntity $policy The policy object.
     * @param string       $source Where this policy came from ('organisation'|'instance').
     *
     * @return array<string, mixed> The response payload.
     */
    private function shape(ObjectEntity $policy, string $source): array
    {
        $data = $policy->getObject();

        return [
            'id'            => (string) ($policy->getUuid() ?? ''),
            'organisation'  => (string) ($policy->getOrganisation() ?? ''),
            'source'        => $source,
            'inputFilters'  => $this->lenientInputFilters(raw: $data['inputFilters'] ?? []),
            'outputFilters' => $this->lenientOutputFilters(raw: $data['outputFilters'] ?? []),
            'toolPolicy'    => $this->lenientToolPolicy(raw: $data['toolPolicy'] ?? []),
            'enabled'       => ($data['enabled'] ?? true) !== false,
        ];

    }//end shape()

    /**
     * A lenient (non-throwing) read of a stored `inputFilters` value — falls
     * back to `off`/`off` for garbage rather than failing a read.
     *
     * @param mixed $raw The stored `inputFilters` value.
     *
     * @return array{piiAction:string,promptInjectionAction:string}
     */
    private function lenientInputFilters(mixed $raw): array
    {
        if (is_array($raw) === false) {
            $raw = [];
        }

        $piiAction = (string) ($raw['piiAction'] ?? 'off');
        if (in_array($piiAction, self::PII_ACTIONS, true) === false) {
            $piiAction = 'off';
        }

        $injectionAction = (string) ($raw['promptInjectionAction'] ?? 'off');
        if (in_array($injectionAction, self::INJECTION_ACTIONS, true) === false) {
            $injectionAction = 'off';
        }

        return ['piiAction' => $piiAction, 'promptInjectionAction' => $injectionAction];

    }//end lenientInputFilters()

    /**
     * A lenient (non-throwing) read of a stored `outputFilters` value.
     *
     * @param mixed $raw The stored `outputFilters` value.
     *
     * @return array{piiAction:string}
     */
    private function lenientOutputFilters(mixed $raw): array
    {
        if (is_array($raw) === false) {
            $raw = [];
        }

        $piiAction = (string) ($raw['piiAction'] ?? 'off');
        if (in_array($piiAction, self::PII_ACTIONS, true) === false) {
            $piiAction = 'off';
        }

        return ['piiAction' => $piiAction];

    }//end lenientOutputFilters()

    /**
     * A lenient (non-throwing) read of a stored `toolPolicy` value.
     *
     * @param mixed $raw The stored `toolPolicy` value.
     *
     * @return list<array{toolId:string,classification:string}>
     */
    private function lenientToolPolicy(mixed $raw): array
    {
        if (is_array($raw) === false) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $toolId = (string) ($entry['toolId'] ?? '');
            if ($toolId === '') {
                continue;
            }

            $classification = (string) ($entry['classification'] ?? 'auto');
            if (in_array($classification, self::TOOL_CLASSIFICATIONS, true) === false) {
                $classification = 'auto';
            }

            $out[] = ['toolId' => $toolId, 'classification' => $classification];
        }

        return $out;

    }//end lenientToolPolicy()
}//end class
