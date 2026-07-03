---
example: true
capability: dashboard-page
status: example
built_by: openspec/changes/example-change
---

# Dashboard Page Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `nextcloud-app-template` repository
> as a demonstration of the OpenSpec format. It describes the behaviour of
> `lib/Controller/DashboardController.php` in the template's own code, but the
> capability name, REQs, and scenarios are reference material — apps built from
> this template should replace this content with their own.

## Purpose

Serves the single-page application (SPA) that is the user-facing front end of
an OpenSpec-based Nextcloud app. The controller is deliberately thin: it
renders a Twig template that boots the Vue bundle, and it provides a
catch-all route so that the Vue router's history mode can resolve deep links
without hash (`#`) fragments.

## Requirements

### REQ-DASH-001: Render the main dashboard SPA entry point

The system MUST expose a public (no-admin-required, no-CSRF-token-required) HTTP endpoint that returns the Twig `index` template for the app. The template is responsible for loading the Vue bundle; the controller MUST NOT perform any business logic beyond template selection.

#### Scenario: Authenticated user opens the dashboard

- GIVEN a signed-in Nextcloud user navigates to `/apps/app-template/`
- WHEN the request reaches `DashboardController::page()`
- THEN the system MUST return a `TemplateResponse` for template `index` under app id `app-template`
- AND the response MUST be accessible without an admin role (`#[NoAdminRequired]`)
- AND the request MUST be accepted without a CSRF token (`#[NoCSRFRequired]`) so that first-request GETs work

### REQ-DASH-002: Catch-all route for Vue history-mode deep links

The system MUST expose a catch-all route that also returns the dashboard SPA so that any path under the app's URL root resolves to the same Vue bundle. This lets the Vue router's history mode (not hash mode — per ADR-004) own in-app routing, while the Nextcloud server still serves the SPA for every sub-path.

#### Scenario: Deep link to an in-app route

- GIVEN a user opens `/apps/app-template/items/abc-123` directly (e.g. from an external link)
- WHEN the request reaches `DashboardController::catchAll()`
- THEN the system MUST return the same `TemplateResponse` as `page()`
- AND the Vue router MUST resolve the `/items/abc-123` path client-side after hydration
- AND the catch-all MUST be public in the same sense as `page()` (`#[NoAdminRequired]`, `#[NoCSRFRequired]`)
