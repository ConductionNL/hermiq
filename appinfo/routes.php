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

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
