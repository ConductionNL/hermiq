<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // Run history — owner-scoped audit read for a schedule (run-audit-log).
        ['name' => 'runHistory#index', 'url' => '/api/schedules/{scheduleId}/runs', 'verb' => 'GET', 'requirements' => ['scheduleId' => '[^/]+']],

        // Run now — owner-scoped immediate run of a schedule's agent (agent-management-ui).
        ['name' => 'runNow#run', 'url' => '/api/schedules/{scheduleId}/run', 'verb' => 'POST', 'requirements' => ['scheduleId' => '[^/]+']],

        // Human-approval gate (human-approval-gate-enforcement): reviewer inbox + decisions.
        ['name' => 'approval#index',   'url' => '/api/approvals', 'verb' => 'GET'],
        ['name' => 'approval#approve', 'url' => '/api/approvals/{approvalId}/approve', 'verb' => 'POST', 'requirements' => ['approvalId' => '[^/]+']],
        ['name' => 'approval#deny',    'url' => '/api/approvals/{approvalId}/deny', 'verb' => 'POST', 'requirements' => ['approvalId' => '[^/]+']],

        // Per-organisation kill-switch (human-approval-gate-enforcement): read + toggle.
        ['name' => 'tenantControl#show',   'url' => '/api/tenant-control/{organisation}', 'verb' => 'GET', 'requirements' => ['organisation' => '[^/]+']],
        ['name' => 'tenantControl#toggle', 'url' => '/api/tenant-control/{organisation}/toggle', 'verb' => 'POST', 'requirements' => ['organisation' => '[^/]+']],

        // Agent memory (agent-memory): tenant-scoped memory, profiles, sessions, consolidate, recall.
        ['name' => 'memory#memory',        'url' => '/api/agents/{agentId}/memory', 'verb' => 'GET', 'requirements' => ['agentId' => '[^/]+']],
        ['name' => 'memory#addMemory',     'url' => '/api/agents/{agentId}/memory', 'verb' => 'POST', 'requirements' => ['agentId' => '[^/]+']],
        ['name' => 'memory#userProfiles',  'url' => '/api/agents/{agentId}/user-profiles', 'verb' => 'GET', 'requirements' => ['agentId' => '[^/]+']],
        ['name' => 'memory#sessions',      'url' => '/api/agents/{agentId}/sessions', 'verb' => 'GET', 'requirements' => ['agentId' => '[^/]+']],
        ['name' => 'memory#consolidate',   'url' => '/api/agents/{agentId}/memory/consolidate', 'verb' => 'POST', 'requirements' => ['agentId' => '[^/]+']],
        ['name' => 'memory#recall',        'url' => '/api/agents/{agentId}/recall', 'verb' => 'GET', 'requirements' => ['agentId' => '[^/]+']],

        // Run analytics (run-analytics): tenant-scoped run metrics from OR AuditTrail (optional agentId).
        ['name' => 'analytics#index', 'url' => '/api/analytics', 'verb' => 'GET'],

        // Tenant ops (multi-tenant-ops): per-org quota status + EU AI Act audit export (tenant-scoped).
        ['name' => 'tenantOps#quota',       'url' => '/api/tenant-ops/quota', 'verb' => 'GET'],
        ['name' => 'tenantOps#auditExport', 'url' => '/api/tenant-ops/audit-export', 'verb' => 'GET'],

        // Skills catalog (skills-catalog): browse, import/export agentskills.io packages, install onto an agent.
        ['name' => 'skill#index',   'url' => '/api/skills', 'verb' => 'GET'],
        ['name' => 'skill#import',  'url' => '/api/skills', 'verb' => 'POST'],
        ['name' => 'skill#export',  'url' => '/api/skills/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'skill#install', 'url' => '/api/skills/{id}/install', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        // Skills marketplace (skills-marketplace): quarantine install-from-source, review-approve, hub publish.
        ['name' => 'skillMarketplace#installFromSource', 'url' => '/api/skills/install-from-source', 'verb' => 'POST'],
        ['name' => 'skillMarketplace#approve',           'url' => '/api/skills/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'skillMarketplace#publish',           'url' => '/api/skills/{id}/publish', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        // AI-feature governance register (ai-feature-governance-register): list + DPO-ack + enable/disable.
        ['name' => 'aiFeature#index',       'url' => '/api/ai-features', 'verb' => 'GET'],
        ['name' => 'aiFeature#acknowledge', 'url' => '/api/ai-features/{slug}/acknowledge', 'verb' => 'POST', 'requirements' => ['slug' => '[^/]+']],
        ['name' => 'aiFeature#enable',      'url' => '/api/ai-features/{id}/enable', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'aiFeature#disable',     'url' => '/api/ai-features/{id}/disable', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        // First-time setup wizard (ADR-042) — the standard CnSetupWizard contract.
        ['name' => 'setup#status',     'url' => '/api/setup/status',            'verb' => 'GET'],
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config',            'verb' => 'POST'],
        ['name' => 'setup#runAction',  'url' => '/api/setup/action/{actionId}', 'verb' => 'POST', 'requirements' => ['actionId' => '[^/]+']],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
