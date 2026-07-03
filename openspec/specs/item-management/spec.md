---
example: true
capability: item-management
status: example
built_by: openspec/changes/example-change
---

# Item Management Specification

> ⚠️ **EXAMPLE SPEC (documentation-only)** — This spec lives in the
> `nextcloud-app-template` repository as a demonstration of the OpenSpec format.
> It documents the ADR-005 per-object authorization pattern using a single
> `destroy` endpoint as the smallest complete illustration. The template no
> longer ships a standalone `ItemController`/`ItemService` (removed in the
> manifest-renderer refactor, hydra ADR-024); the per-object-auth mechanism it
> describes is now demonstrated concretely by `ActionAuthService`
> (ADR-023, `openspec/architecture/adr-023-action-authorization.md`). Apps built
> from this template will typically extend this capability with `create`,
> `read`, `update`, and `list` REQs on real mutation endpoints; the pattern
> stays the same.

## Purpose

Demonstrates the ADR-003 thin-controller / ADR-005 per-object authorization
pattern that every mutation in a template-derived app is expected to follow:

- The controller is marked `#[NoAdminRequired]` because non-admins have
  legitimate business accessing their own data.
- The **actual authorization check** lives in the service, not the controller.
  It uses a backend-derived UID (from `IUserSession`), never a UID taken from
  request parameters.
- Error responses carry static, generic messages; the real cause is logged
  server-side only (ADR-005).
- The existence of an object a caller is not authorized to see MUST NOT leak:
  missing-object and unauthorized-object both resolve in favour of the
  404-over-403 choice where the rule applies (OWASP A01).

## Data Model

The template uses a single `Article` schema stored in an OpenRegister
register. Each object carries an `@self.owner` property that records the UID
of the user who created it. "Admin" means a member of the Nextcloud `admin`
group, resolved via `IGroupManager::isAdmin()`.

## Requirements

### REQ-ITEM-001: Delete an Article by UUID with per-object authorization

The system MUST expose `DELETE /api/items/{id}`. The endpoint MUST be reachable
by authenticated non-admins (`#[NoAdminRequired]`). The service MUST allow the
deletion only if the caller is a Nextcloud admin OR the object's `@self.owner`
matches the caller's UID; otherwise it MUST refuse. The UID used for the
authorization check MUST be derived from `IUserSession`, never from the
request URL or body.

#### Scenario: Owner deletes their own Article

- GIVEN a signed-in non-admin user `alice`
- AND an Article object `abc-123` whose `@self.owner` is `alice`
- WHEN `alice` sends `DELETE /api/items/abc-123`
- THEN `ItemController::destroy()` MUST read `alice` from `IUserSession` (not from the URL)
- AND the controller MUST invoke `ItemService::delete(id: "abc-123", userId: "alice")`
- AND the service MUST call `OpenRegister\ObjectService::delete()` and return `STATUS_DELETED`
- AND the controller MUST map `STATUS_DELETED` to HTTP 204 No Content

#### Scenario: Admin deletes any Article

- GIVEN a signed-in admin user `root`
- AND an Article object `abc-123` owned by `alice`
- WHEN `root` sends `DELETE /api/items/abc-123`
- THEN the authorization check MUST succeed because `root` is in the admin group
- AND the response MUST be HTTP 204

#### Scenario: Unrelated user tries to delete

- GIVEN a signed-in non-admin user `bob`
- AND an Article object `abc-123` owned by `alice`
- WHEN `bob` sends `DELETE /api/items/abc-123`
- THEN the service MUST return `STATUS_FORBIDDEN` without calling `ObjectService::delete()`
- AND the controller MUST map `STATUS_FORBIDDEN` to HTTP 403
- AND the response body MUST contain a static generic message (per ADR-005); the object's existence MUST NOT leak detail beyond the 403/404 distinction

#### Scenario: Object does not exist

- GIVEN no Article with UUID `missing-000`
- WHEN any authenticated user sends `DELETE /api/items/missing-000`
- THEN the service MUST return `STATUS_NOT_FOUND`
- AND the controller MUST map it to HTTP 404

#### Scenario: Unauthenticated request

- GIVEN no active session
- WHEN `DELETE /api/items/abc-123` is received
- THEN the controller MUST return HTTP 401 with a static generic message
- AND no service call MUST be attempted

#### Scenario: OpenRegister is unavailable

- GIVEN the `openregister` app is not installed or enabled
- WHEN any authenticated caller invokes the delete endpoint
- THEN the service MUST return `STATUS_UNAVAILABLE`
- AND the controller MUST map it to HTTP 503
- AND a server-side log entry MUST be written describing the unavailability (per ADR-005)

### REQ-ITEM-002: Owner extraction MUST tolerate multiple object shapes

The service's internal `extractOwner()` helper MUST accept an object in any of
the shapes that OpenRegister returns (associative array with `@self.owner`
key, typed entity with a `getOwner()` method, or typed entity whose
`jsonSerialize()` returns the associative array form). This REQ exists
because the upstream shape is version-dependent and a narrow extractor would
produce silent auth bypasses.

#### Scenario: Associative-array object

- GIVEN an object returned as `['@self' => ['owner' => 'alice'], ...]`
- WHEN `extractOwner()` is invoked
- THEN it MUST return `"alice"`

#### Scenario: Entity with `getOwner()`

- GIVEN an entity object exposing a typed `getOwner(): string` accessor
- WHEN `extractOwner()` is invoked
- THEN it MUST return the accessor's result

#### Scenario: Unknown shape

- GIVEN an object whose shape matches neither form
- WHEN `extractOwner()` is invoked
- THEN it MUST return `null`
- AND `isAuthorized()` MUST treat a `null` owner as "no ownership claim" and fall back to the admin-only path (a missing owner MUST NOT grant access)
