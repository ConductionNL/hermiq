<?php

/**
 * Hermiq route registration.
 *
 * @category AppInfo
 * @package  OCA\Hermiq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Admin LLM provider selection (SPECTR-NEXTCLOUD-PLAN.md §8 move 1 — makes the
        // `nextcloud` TaskProcessing driver, and the pre-existing openai/ollama/fireworks
        // drivers, selectable from the admin panel instead of only via `occ`).
        ['name' => 'Settings\LlmSettings#get',    'url' => '/api/settings/llm', 'verb' => 'GET'],
        ['name' => 'Settings\LlmSettings#update', 'url' => '/api/settings/llm', 'verb' => 'PATCH'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // Run history — owner-scoped audit read for a schedule (run-audit-log).
        ['name' => 'runHistory#index', 'url' => '/api/schedules/{scheduleId}/runs', 'verb' => 'GET', 'requirements' => ['scheduleId' => '[^/]+']],

        // Run trace — owner-scoped, redacted per-run step timeline (run-trace-observability).
        [
            'name'         => 'runHistory#trace',
            'url'          => '/api/schedules/{scheduleId}/runs/{runId}/trace',
            'verb'         => 'GET',
            'requirements' => ['scheduleId' => '[^/]+', 'runId' => '[^/]+'],
        ],

        // Run now — owner-scoped immediate run of a schedule's agent (agent-management-ui).
        ['name' => 'runNow#run', 'url' => '/api/schedules/{scheduleId}/run', 'verb' => 'POST', 'requirements' => ['scheduleId' => '[^/]+']],

        // Agent webhook trigger (agent-webhook-trigger): owner-guarded secret lifecycle
        // CRUD, plus the public, secret-authenticated trigger endpoint. 'webhook-secret'
        // routes are registered before the bare '/webhook' trigger route so they never
        // collide (distinct literal path segments, no ambiguity either way).
        [
            'name'         => 'agentWebhook#create',
            'url'          => '/api/agents/{id}/webhook-secret',
            'verb'         => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'agentWebhook#rotate',
            'url'          => '/api/agents/{id}/webhook-secret/rotate',
            'verb'         => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'agentWebhook#revoke',
            'url'          => '/api/agents/{id}/webhook-secret/revoke',
            'verb'         => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'agentWebhook#patch',
            'url'          => '/api/agents/{id}/webhook-secret',
            'verb'         => 'PATCH',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'agentWebhook#show',
            'url'          => '/api/agents/{id}/webhook-secret',
            'verb'         => 'GET',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'webhookTrigger#trigger',
            'url'          => '/api/agents/{id}/webhook',
            'verb'         => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],

        // Human-approval gate (human-approval-gate-enforcement): reviewer inbox + decisions.
        ['name' => 'approval#index',   'url' => '/api/approvals', 'verb' => 'GET'],
        ['name' => 'approval#approve', 'url' => '/api/approvals/{approvalId}/approve', 'verb' => 'POST', 'requirements' => ['approvalId' => '[^/]+']],
        ['name' => 'approval#deny',    'url' => '/api/approvals/{approvalId}/deny', 'verb' => 'POST', 'requirements' => ['approvalId' => '[^/]+']],

        // Per-organisation kill-switch (human-approval-gate-enforcement): read + toggle.
        [
            'name'         => 'tenantControl#show',
            'url'          => '/api/tenant-control/{organisation}',
            'verb'         => 'GET',
            'requirements' => ['organisation' => '[^/]+'],
        ],
        [
            'name'         => 'tenantControl#toggle',
            'url'          => '/api/tenant-control/{organisation}/toggle',
            'verb'         => 'POST',
            'requirements' => ['organisation' => '[^/]+'],
        ],

        // Agent memory (agent-memory): tenant-scoped memory, profiles, sessions, consolidate, recall.
        ['name' => 'memory#memory',        'url' => '/api/agents/{agentId}/memory', 'verb' => 'GET', 'requirements' => ['agentId' => '[^/]+']],
        ['name' => 'memory#addMemory',     'url' => '/api/agents/{agentId}/memory', 'verb' => 'POST', 'requirements' => ['agentId' => '[^/]+']],
        ['name' => 'memory#userProfiles',  'url' => '/api/agents/{agentId}/user-profiles', 'verb' => 'GET', 'requirements' => ['agentId' => '[^/]+']],
        ['name' => 'memory#sessions',      'url' => '/api/agents/{agentId}/sessions', 'verb' => 'GET', 'requirements' => ['agentId' => '[^/]+']],
        [
            'name'         => 'memory#consolidate',
            'url'          => '/api/agents/{agentId}/memory/consolidate',
            'verb'         => 'POST',
            'requirements' => ['agentId' => '[^/]+'],
        ],
        ['name' => 'memory#recall',        'url' => '/api/agents/{agentId}/recall', 'verb' => 'GET', 'requirements' => ['agentId' => '[^/]+']],

        // Run analytics (run-analytics): tenant-scoped run metrics from OR AuditTrail (optional agentId).
        ['name' => 'analytics#index', 'url' => '/api/analytics', 'verb' => 'GET'],

        // Tenant ops (multi-tenant-ops): per-org quota status + EU AI Act audit export (tenant-scoped).
        ['name' => 'tenantOps#quota',       'url' => '/api/tenant-ops/quota', 'verb' => 'GET'],
        ['name' => 'tenantOps#auditExport', 'url' => '/api/tenant-ops/audit-export', 'verb' => 'GET'],

        // Agent-lifecycle-governance: periodic access review + attestation + reassignment,
        // incident records, and the retention-period setting (tenant-scoped; the four
        // mutating endpoints additionally gate through ActionAuthService).
        ['name' => 'tenantOps#reviewList', 'url' => '/api/tenant-ops/access-review', 'verb' => 'GET'],
        [
            'name'         => 'tenantOps#attestReview',
            'url'          => '/api/tenant-ops/access-review/{uuid}/attest',
            'verb'         => 'POST',
            'requirements' => ['uuid' => '[^/]+'],
        ],
        [
            'name'         => 'tenantOps#reassignAgent',
            'url'          => '/api/tenant-ops/access-review/{uuid}/reassign',
            'verb'         => 'POST',
            'requirements' => ['uuid' => '[^/]+'],
        ],
        ['name' => 'tenantOps#incidents',       'url' => '/api/tenant-ops/incidents', 'verb' => 'GET'],
        ['name' => 'tenantOps#createIncident',  'url' => '/api/tenant-ops/incidents', 'verb' => 'POST'],
        ['name' => 'tenantOps#retention',       'url' => '/api/tenant-ops/retention', 'verb' => 'GET'],
        ['name' => 'tenantOps#updateRetention', 'url' => '/api/tenant-ops/retention', 'verb' => 'PUT'],

        // Budget guardrails (cost-guardrails): per-org/per-agent spend caps, status, pre-run
        // estimate. 'status' is registered before the GET list route needs no {budgetId} at
        // all (only PUT/DELETE use it), so there is no path-matching ambiguity.
        ['name' => 'budget#status', 'url' => '/api/budgets/status', 'verb' => 'GET'],
        ['name' => 'budget#index',  'url' => '/api/budgets', 'verb' => 'GET'],
        ['name' => 'budget#create', 'url' => '/api/budgets', 'verb' => 'POST'],
        ['name' => 'budget#update',  'url' => '/api/budgets/{budgetId}', 'verb' => 'PUT', 'requirements' => ['budgetId' => '[^/]+']],
        ['name' => 'budget#destroy', 'url' => '/api/budgets/{budgetId}', 'verb' => 'DELETE', 'requirements' => ['budgetId' => '[^/]+']],
        ['name' => 'budget#estimate', 'url' => '/api/agents/{agentId}/budget-estimate', 'verb' => 'GET', 'requirements' => ['agentId' => '[^/]+']],

        // Tenant model policy (tenant-model-policy): per-organisation provider/model
        // allowlists. 'effective' is registered before the {policyId} routes so the
        // literal path never falls into the id matcher.
        ['name' => 'tenantModelPolicy#effective', 'url' => '/api/model-policy/effective', 'verb' => 'GET'],
        ['name' => 'tenantModelPolicy#index',  'url' => '/api/model-policy', 'verb' => 'GET'],
        ['name' => 'tenantModelPolicy#create', 'url' => '/api/model-policy', 'verb' => 'POST'],
        ['name' => 'tenantModelPolicy#update', 'url' => '/api/model-policy/{policyId}', 'verb' => 'PUT', 'requirements' => ['policyId' => '[^/]+']],

        // Skills catalog (skills-catalog): browse, import/export agentskills.io packages, install onto an agent.
        ['name' => 'skill#index',   'url' => '/api/skills', 'verb' => 'GET'],
        ['name' => 'skill#import',  'url' => '/api/skills', 'verb' => 'POST'],
        ['name' => 'skill#export',  'url' => '/api/skills/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'skill#install', 'url' => '/api/skills/{id}/install', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        [
            'name'         => 'skill#uninstall',
            'url'          => '/api/skills/{id}/install/{agentId}',
            'verb'         => 'DELETE',
            'requirements' => ['id' => '[^/]+', 'agentId' => '[^/]+'],
        ],

        // Skills marketplace (skills-marketplace): quarantine install-from-source, review-approve, hub publish.
        ['name' => 'skillMarketplace#installFromSource', 'url' => '/api/skills/install-from-source', 'verb' => 'POST'],
        ['name' => 'skillMarketplace#approve',           'url' => '/api/skills/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'skillMarketplace#publish',           'url' => '/api/skills/{id}/publish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        // AI-feature governance register (ai-feature-governance-register): list + DPO-ack + enable/disable.
        ['name' => 'aiFeature#index',       'url' => '/api/ai-features', 'verb' => 'GET'],
        ['name' => 'aiFeature#acknowledge', 'url' => '/api/ai-features/{slug}/acknowledge', 'verb' => 'POST', 'requirements' => ['slug' => '[^/]+']],
        ['name' => 'aiFeature#enable',      'url' => '/api/ai-features/{id}/enable', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'aiFeature#disable',     'url' => '/api/ai-features/{id}/disable', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        // Algoritmeregister publication (algoritmeregister-publication): publish/withdraw a
        // high-risk feature to the national register, delegated to OpenCatalogi's publication
        // path via the runtime seam (action-auth-gated; NO direct national-portal call).
        [
            'name'         => 'aiFeature#publishToAlgoritmeregister',
            'url'          => '/api/ai-features/{id}/publish',
            'verb'         => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'aiFeature#withdrawFromAlgoritmeregister',
            'url'          => '/api/ai-features/{id}/withdraw',
            'verb'         => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],

        // First-time setup wizard (ADR-042) — the standard CnSetupWizard contract.
        ['name' => 'setup#status',     'url' => '/api/setup/status',            'verb' => 'GET'],
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config',            'verb' => 'POST'],
        ['name' => 'setup#runAction',  'url' => '/api/setup/action/{actionId}', 'verb' => 'POST', 'requirements' => ['actionId' => '[^/]+']],

        // ---------------------------------------------------------------------------
        // Agent engine API (agent-engine-port): route-for-route mirror of OpenRegister's
        // chat/conversation/agent surface onto the in-app Engine. Ids are hermiq-register
        // object UUIDs ('[^/]+'), not OR's int row ids. OR's TemplateResponse page routes
        // (agents#page GET /agents, ui#chat) are deliberately NOT mirrored: hermiq's SPA
        // catch-all (dashboard#catchAll, last entry below) already serves every page URL.
        // ---------------------------------------------------------------------------
        // Agents: stats + tools MUST be registered before the /api/agents/{id} routes,
        // or 'stats'/'tools' would be captured as an {id}. The existing
        // /api/agents/{agentId}/memory block above matches longer paths — no conflict.
        ['name' => 'agents#stats',   'url' => '/api/agents/stats', 'verb' => 'GET'],
        ['name' => 'agents#tools',   'url' => '/api/agents/tools', 'verb' => 'GET'],
        ['name' => 'agents#index',   'url' => '/api/agents', 'verb' => 'GET'],
        ['name' => 'agents#create',  'url' => '/api/agents', 'verb' => 'POST'],
        ['name' => 'agents#show',    'url' => '/api/agents/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'agents#update',  'url' => '/api/agents/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'agents#patch',   'url' => '/api/agents/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'agents#destroy', 'url' => '/api/agents/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],

        // Chat: send/history/stats + per-message feedback (messageId is a UUID here,
        // where OR required '\d+').
        ['name' => 'chat#sendMessage',  'url' => '/api/chat/send', 'verb' => 'POST'],
        ['name' => 'chat#getHistory',   'url' => '/api/chat/history', 'verb' => 'GET'],
        ['name' => 'chat#clearHistory', 'url' => '/api/chat/history', 'verb' => 'DELETE'],
        ['name' => 'chat#getChatStats', 'url' => '/api/chat/stats', 'verb' => 'GET'],
        [
            'name'         => 'chat#sendFeedback',
            'url'          => '/api/conversations/{conversationUuid}/messages/{messageId}/feedback',
            'verb'         => 'POST',
            'requirements' => ['conversationUuid' => '[^/]+', 'messageId' => '[^/]+'],
        ],

        // Chat health probe (PublicPage — widget probes before authenticating).
        ['name' => 'chatHealth#health', 'url' => '/api/chat/health', 'verb' => 'GET'],

        // SSE streaming chat endpoint (six-event envelope, hydra ADR-034 Decision 6).
        ['name' => 'chatStream#stream', 'url' => '/api/chat/stream', 'verb' => 'POST'],

        // Conversations: CRUD + messages + archive lifecycle (restore/permanent).
        ['name' => 'conversation#index', 'url' => '/api/conversations', 'verb' => 'GET'],
        ['name' => 'conversation#create', 'url' => '/api/conversations', 'verb' => 'POST'],
        ['name' => 'conversation#show', 'url' => '/api/conversations/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
        [
            'name'         => 'conversation#messages',
            'url'          => '/api/conversations/{uuid}/messages',
            'verb'         => 'GET',
            'requirements' => ['uuid' => '[^/]+'],
        ],
        ['name' => 'conversation#update', 'url' => '/api/conversations/{uuid}', 'verb' => 'PATCH', 'requirements' => ['uuid' => '[^/]+']],
        ['name' => 'conversation#destroy', 'url' => '/api/conversations/{uuid}', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+']],
        [
            'name'         => 'conversation#restore',
            'url'          => '/api/conversations/{uuid}/restore',
            'verb'         => 'POST',
            'requirements' => ['uuid' => '[^/]+'],
        ],
        [
            'name'         => 'conversation#destroyPermanent',
            'url'          => '/api/conversations/{uuid}/permanent',
            'verb'         => 'DELETE',
            'requirements' => ['uuid' => '[^/]+'],
        ],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
