---
example: true
capability: observability
status: example
built_by: openspec/changes/example-change
---

# Observability Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `nextcloud-app-template` repository
> as a demonstration of the OpenSpec format. It describes the behaviour of
> `lib/Controller/MetricsController.php` + `lib/Controller/HealthController.php`
> in the template's own code. Apps built from this template MUST keep the two
> endpoints (admin metrics, public health) per ADR-006 and may extend the set
> of published metrics beyond the template's stub values.

## Purpose

Every template-derived app MUST expose two observability endpoints per ADR-006:

- `GET /api/metrics` — Prometheus text-exposition format, admin-only. Always
  publishes at minimum an `{app_id}_info` gauge and an `{app_id}_health_status`
  gauge.
- `GET /api/health` — JSON health summary, public (no auth), returns HTTP 200
  when dependencies are healthy and HTTP 503 when one or more dependencies are
  missing.

The two endpoints are deliberately different in audience: metrics are for
operators scraping with a Prometheus server; health is for external probes
(Kubernetes liveness, load-balancer readiness, blackbox exporters) that need
to work without credentials.

## Requirements

### REQ-OBS-001: Prometheus metrics endpoint (admin only)

The system MUST expose `GET /api/metrics` returning Prometheus text exposition
format (`Content-Type: text/plain; version=0.0.4`). The endpoint MUST be
restricted to admin users (no `#[NoAdminRequired]`). The response MUST include
at minimum two gauge metrics: `{app_id}_info` (static labels for app id and
version) and `{app_id}_health_status` (1 when healthy, 0 when degraded).

#### Scenario: Admin scrapes metrics

- GIVEN Prometheus is configured to scrape with an admin-scoped credential
- WHEN a scrape request hits `MetricsController::index()`
- THEN the system MUST return HTTP 200 with `Content-Type` `text/plain; version=0.0.4`
- AND the response body MUST contain an `app_template_info{app="app-template",version="<v>"} 1` line
- AND the response body MUST contain an `app_template_health_status <0-or-1>` line

#### Scenario: Non-admin scrape attempt

- GIVEN a non-admin authenticated user or an unauthenticated scrape
- WHEN the request reaches the controller
- THEN the Nextcloud framework MUST reject per the default admin gate (the controller itself does not need a secondary check)
- AND no metric data MUST be emitted

#### Scenario: Metric computation throws

- GIVEN an internal error while gathering metric values
- WHEN the controller catches the exception
- THEN the system MUST log the exception server-side with full context
- AND the response MUST be HTTP 500 with a static generic error message (per ADR-005)

### REQ-OBS-002: Health check endpoint (public)

The system MUST expose `GET /api/health` as a **public** endpoint
(`#[PublicPage]` + `#[NoCSRFRequired]`) returning a JSON object summarising the
app's health and its external dependencies. The status code MUST be 200 when
all required dependencies are present, 503 when one or more are missing.

#### Scenario: Dependencies healthy

- GIVEN OpenRegister is installed and enabled
- WHEN a probe sends `GET /api/health`
- THEN the system MUST return HTTP 200
- AND the JSON body MUST include `status: "ok"`, `app: "app-template"`, `version: "<v>"`, and `dependencies: { "openregister": true }`

#### Scenario: A required dependency is missing

- GIVEN OpenRegister is not installed or not enabled
- WHEN the probe request is handled
- THEN the system MUST return HTTP 503
- AND the JSON body MUST include `status: "degraded"` and `dependencies.openregister: false`

#### Scenario: Health check itself throws

- GIVEN a runtime error while gathering health data
- WHEN the exception reaches the controller
- THEN the system MUST log server-side with full context
- AND the response MUST be HTTP 500 with a static generic message (per ADR-005)
- AND the response body MUST NOT leak exception detail
