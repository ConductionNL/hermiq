---
example: true
capability: frontend-data-stores
status: example
built_by: openspec/changes/example-change
---

# Frontend Data Stores Specification

> ⚠️ **EXAMPLE SPEC** — This spec lives in the `nextcloud-app-template` repository
> as a demonstration of the OpenSpec format for the **frontend** layer. It
> describes the behaviour of the Pinia stores in `src/store/` (the generic
> OpenRegister object store, the app-settings store, and the boot-time store
> initializer). Apps built from this template will typically keep this
> capability almost unchanged; the substitutions are the schema/register names
> registered with the object store and the API base paths.

## Purpose

Per ADR-022, template-derived apps own no database of their own — the Vue SPA
talks to OpenRegister's object API directly through a thin Pinia store layer.
This capability defines that store layer:

- A **generic object store** (`src/store/modules/object.js`) that is configured
  once with the OpenRegister API base URLs, then accepts the registration of
  named object types (each mapping a logical type name to a register + schema),
  and fetches collections of those objects on demand.
- An **app-settings store** (`src/store/modules/settings.js`) that reads and
  writes the app's own settings through the backend `GET`/`POST /api/settings`
  endpoints defined by the settings-management capability — it is the frontend
  half of REQ-CFG-001 / REQ-CFG-002.
- A **boot-time initializer** (`src/store/store.js`) that wires the object store
  to OpenRegister's URLs and primes the settings store before the SPA renders.

All store actions MUST degrade gracefully: a failed network request MUST be
logged client-side and resolve to a safe empty/`null` value so that a single
failing fetch never breaks the SPA mount (mirrors ADR-005's "log server-side,
return safe fallback" rule on the client).

## Requirements

### REQ-STORE-001: Configure the object store with OpenRegister URLs

The object store MUST expose a `configure({ baseUrl, schemaBaseUrl })` action
that records the OpenRegister object-API and schema-API base URLs. No object
fetch may be attempted before the store has been configured.

#### Scenario: Store is configured at boot

- GIVEN a freshly created object store with empty `baseUrl`
- WHEN `configure({ baseUrl, schemaBaseUrl })` is called with the OpenRegister object/schema API URLs
- THEN the store MUST persist `baseUrl` and `schemaBaseUrl` in its state
- AND subsequent `fetchObjects` calls MUST build their request URL from the stored `baseUrl`

### REQ-STORE-002: Register named object types

The object store MUST expose a `registerObjectType(type, schema, register)`
action that maps a logical type name to its OpenRegister `schema` + `register`
identifiers and initialises an empty result bucket for that type. Fetching an
unregistered type MUST be a no-op that warns rather than throwing.

#### Scenario: A type is registered

- GIVEN a configured object store
- WHEN `registerObjectType('item', '<schemaId>', '<registerId>')` is called
- THEN the store MUST record `{ schema, register }` under `objectTypes.item`
- AND it MUST initialise `objects.item` to an empty array if it was unset

#### Scenario: Fetching an unregistered type

- GIVEN a configured store with no `item` type registered
- WHEN `fetchObjects('item')` is called
- THEN the store MUST emit a client-side warning naming the unregistered type
- AND it MUST return an empty array without performing a network request

### REQ-STORE-003: Fetch a collection of objects

The object store MUST expose an async `fetchObjects(type, params)` action that
issues a `GET` to the configured `baseUrl` with the registered `register` and
`schema` as query parameters (plus any caller-supplied `params`), carrying the
Nextcloud request token. On success it MUST store and return the result
collection; on any failure it MUST log client-side and return an empty array,
and it MUST clear the per-type loading flag in all cases.

#### Scenario: Successful fetch

- GIVEN a registered, configured `item` type
- WHEN `fetchObjects('item', { limit: 10 })` resolves with HTTP 200
- THEN the request URL MUST carry `register`, `schema`, and `limit=10` query parameters
- AND the request MUST send the `requesttoken` header
- AND the store MUST set `objects.item` to `data.results` (or `data` when no `results` envelope) and return it
- AND `loading.item` MUST be cleared to `false`

#### Scenario: Network failure

- GIVEN a registered type
- WHEN the fetch rejects or returns a non-OK status
- THEN the store MUST log the error client-side
- AND it MUST return an empty array
- AND `loading.item` MUST still be cleared to `false`

### REQ-STORE-004: Read and write app settings from the SPA

The settings store MUST expose an async `fetchSettings()` action that `GET`s
`/api/settings` and a `saveSettings(payload)` action that `POST`s a partial
settings payload to the same endpoint, both carrying the request token. These
are the client counterparts of the settings-management capability's REQ-CFG-001
and REQ-CFG-002. `fetchSettings()` MUST additionally derive the
`hasOpenRegisters` and `isAdmin` flags from the response so the UI can degrade
gracefully (ADR-005 / REQ-CFG-004). Both actions MUST log and return `null` on
failure rather than throwing.

#### Scenario: Settings load

- GIVEN the backend `GET /api/settings` returns HTTP 200
- WHEN `fetchSettings()` runs
- THEN the store MUST store the returned settings object
- AND it MUST set `hasOpenRegisters` and `isAdmin` from the response payload
- AND it MUST return the settings object

#### Scenario: Settings save

- GIVEN an admin user changes a setting
- WHEN `saveSettings({ register: 'x' })` is called
- THEN the store MUST `POST` the payload as JSON with the `requesttoken` header
- AND on HTTP 200 it MUST replace its local settings with the freshly-returned config and return it

#### Scenario: Settings request fails

- GIVEN any settings request rejects or returns a non-OK status
- WHEN the action handles the failure
- THEN it MUST log the error client-side
- AND it MUST return `null`
- AND `loading` MUST be reset to `false`

### REQ-STORE-005: Initialise the stores before the SPA renders

The system MUST expose an async `initializeStores()` boot helper that
configures the object store to point at OpenRegister's object/schema API URLs
and primes the settings store with a first `fetchSettings()` call, returning
both store handles to the caller.

#### Scenario: Boot sequence

- WHEN `initializeStores()` is awaited during SPA bootstrap
- THEN it MUST call `objectStore.configure()` with the OpenRegister `/api/objects` and `/api/schemas` URLs
- AND it MUST await `settingsStore.fetchSettings()` so the first render has settings available
- AND it MUST return `{ settingsStore, objectStore }`
