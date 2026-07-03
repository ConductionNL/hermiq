---
example: true
capability: deep-linking
status: example
built_by: openspec/changes/example-change
---

# Deep Linking Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `nextcloud-app-template` repository
> as a demonstration of the OpenSpec format. It describes the behaviour of
> `lib/Listener/DeepLinkRegistrationListener.php` in the template's own code,
> where the Article schema is used as a stand-in for whatever primary entity a
> derived app exposes. Apps built from this template should substitute their own
> schema name and URL pattern.

## Purpose

Registers a deep-link URL template for the app's primary entity with the
Nextcloud-wide deep-link registry. The result is that other apps (search,
notifications, chat) can produce working links of the form
`/apps/{app-id}/items/{uuid}` that resolve to the correct SPA view after the
user follows them.

Per ADR-004, these links MUST use **path-based URLs** (Vue router history
mode), not hash fragments (`#/items/abc`). The dashboard catch-all route
(REQ-DASH-002) is what makes the path form work server-side.

## Requirements

### REQ-LINK-001: Register deep-link template on `DeepLinkRegistrationEvent`

The system MUST subscribe to Nextcloud's `DeepLinkRegistrationEvent` and, when
it fires, register a URL template for the app's primary `Article` schema. The
template MUST use the path form (per ADR-004) and MUST carry the schema.org
type `Article` (per ADR-011) so that downstream consumers can recognise the
target as a semantically-typed Article.

#### Scenario: The deep-link registry is built

- GIVEN the Nextcloud event dispatcher fires `DeepLinkRegistrationEvent`
- WHEN `DeepLinkRegistrationListener::handle()` receives the event
- THEN the system MUST call `$event->register()` with a URL pattern that places
  the UUID as a path segment, not as a hash fragment
  (e.g. `/apps/app-template/items/{uuid}`, not `/apps/app-template#/items/{uuid}`)
- AND the registration MUST declare the `schema.org/Article` type

#### Scenario: A foreign event type is dispatched

- GIVEN some other event type `FooEvent` reaches this listener
- WHEN `handle()` type-checks the incoming event
- THEN the system MUST return silently without registering anything
- AND the listener MUST NOT throw or log an error (type-check is a precondition filter, not a failure mode)
