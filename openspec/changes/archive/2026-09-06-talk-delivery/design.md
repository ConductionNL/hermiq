# Design: talk-delivery

## Context

`agent-schedule-dispatcher` shipped a delivery *seam*: `ScheduleService::deliver(channel, output, schedule)` runs inside the dispatch loop AFTER the agent turn and while the owner is still impersonated, but today it only logs its intent. This change fills that seam with real Nextcloud-native delivery per ADR-005 (deliver through Nextcloud, not a multi-platform gateway) and ADR-001 (Hermiq stays thin; identity/RBAC/Talk/notifications belong to Nextcloud).

This is the **service half of the ADR-032 chain** headed by `talk-delivery-schema`, which
adds two `Schedule` fields this change consumes: `deliverTarget` (the Talk room token to
post to) and `lastDeliveryError` (the persisted delivery-failure message). `depends_on:
[talk-delivery-schema]`.

Current state and constraints:
- The dispatcher already impersonates `Schedule.owner` before `deliver()` is called, so the delivery layer acts **as the owner** with no extra identity plumbing.
- The dispatcher isolates per-schedule failures, but a delivery failure must be *even softer* than a run failure: it MUST NOT mark the run `error`. So delivery cannot simply throw into the dispatch try/catch (that branch sets `lastStatus = error`).
- Talk (spreed 24.0.1) is installed & enabled on this NC 34 instance, but Talk is an **optional operator dependency** in general — Hermiq must boot and still deliver (via notification) when Talk is absent.
- The schema fields exist upstream (`talk-delivery-schema`): this change reads `deliverTarget` and writes `lastDeliveryError`. When `deliverTarget` is set, `deliver=talk` targets that room (membership-checked); otherwise it targets the owner's personal Note-to-self room.

## Goals / Non-Goals

**Goals:**
- Replace the `deliver()` no-op with a real `Hermiq\Service\DeliveryService` covering `notification`, `talk`, and `none`.
- `notification`: raise a Nextcloud notification to the owner via `OCP\Notification\IManager`, linking to the schedule; render it with an `INotifier`.
- `talk`: post run output to the schedule's `deliverTarget` room when set (verifying owner membership), else to the owner's Note-to-self; fall back through Note-to-self → notification when a target/Talk can't be resolved, recording a delivery warning.
- Persist `lastDeliveryError` on the `Schedule` object on any delivery failure (via `ObjectService`, routed through the dispatcher's `sanitizeForSave`).
- Delivery is **never fatal**: failures are caught inside the delivery layer, returned as a result, and recorded as a warning; the run stays `ok`.
- Empty/silent agent output produces no message on any channel.

**Non-Goals (explicit FUTURE scope):**
- **The `deliverTarget` / `lastDeliveryError` schema fields themselves** — declared upstream by `talk-delivery-schema`; this change only reads/writes them.
- **NC Mail (`IMailer`) outbound** — a third delivery channel, planned under `nc-native-tools` (ADR-005). Not here.
- Auto-creating or auto-joining a `deliverTarget` room the owner is not a member of — a missing/inaccessible room falls back rather than provisioning; room provisioning is out of scope.
- Threaded two-way conversations / reply handling — out of scope for MVP.

## Decisions

### Chosen Talk API: spreed server-side classes, resolved lazily — `Manager` + `ParticipantService` + `NoteToSelfService` + `ChatManager`

I researched the three candidate paths against the installed spreed 24.0.1 (`../openregister/custom_apps/spreed/lib`) and NC 34 core (`OCP\Talk`):

- **(a) Nextcloud core `OCP\Talk\*` (`IBroker`, `IConversation`, `IConversationOptions`)** — the only *stable* public Talk surface. Verified: `IBroker` can `hasBackend()`, `createConversation()`, `deleteConversation()`; `IConversation` exposes only `getId()` (token) + `getAbsoluteUrl()`. **There is no message-posting method anywhere in `OCP\Talk`, and no way to resolve an existing Note-to-self room.** Sufficient to *detect* Talk and to create rooms, but it **cannot post a chat message** — so it can't deliver on its own. Rejected as the posting path (but its `hasBackend()` is a clean availability probe).
- **(b) spreed server-side classes** — verified present in 24.0.1:
  - `OCA\Talk\Manager::getRoomForUserByToken(token, uid): Room` — resolves a room by token **scoped to the user**; it joins the attendees table for that UID and throws `RoomNotFoundException` when the user has no access. This *is* the membership check: a successful return means the owner is a member of the `deliverTarget` room.
  - `OCA\Talk\Service\ParticipantService::getParticipant(Room, uid): Participant` — the owner's `Participant` for `sendMessage` (throws `ParticipantNotFoundException` otherwise).
  - `OCA\Talk\Service\NoteToSelfService::ensureNoteToSelfExistsForUser(uid): Room` — resolves (and lazily creates) the owner's Note-to-self `Room` from just the UID — the fallback target, no schema change.
  - `OCA\Talk\Chat\ChatManager::sendMessage(Room, ?Participant, actorType, actorId, message, DateTime, …)` — posts as an actor (`actorType='users'`, `actorId=<owner-uid>`).
  **Chosen.**
- **(c) Internal OCS chat API loopback** — `POST /ocs/v2.php/apps/spreed/api/v1/chat/{token}` (room token from `GET …/api/v4/room/note-to-self`, or the `deliverTarget` token directly). Decoupled from spreed's PHP classes, but requires an authenticated HTTP round-trip from inside a background job. Impersonation via `IUserSession::setUser()` does **not** mint an HTTP session cookie/CSRF token, so a loopback call as the owner is fragile (auth, base-URL resolution, CSRF). Rejected for a cron context.

**Rationale for (b):** it needs no HTTP loopback (background-job-friendly), reuses the owner we already impersonate, gets a **membership-checked** room resolution for free via `getRoomForUserByToken`, and posts through spreed's own manager (same code path as the app itself).

### Talk target resolution + fallback chain

For `deliver = talk`, `DeliveryService` resolves the target room in this order, taking the first that succeeds and recording a warning on each fall-through:
1. **`deliverTarget` set** → `Manager::getRoomForUserByToken(<deliverTarget>, <owner>)`. Success ⇒ post to that room (owner is provably a member). If it throws `RoomNotFoundException` (invalid token OR owner not a member) or any error ⇒ fall to step 2 with a warning.
2. **Note-to-self** → `NoteToSelfService::ensureNoteToSelfExistsForUser(<owner>)` → post. Any error ⇒ fall to step 3.
3. **Notification** → raise a Nextcloud notification to the owner (the always-available baseline).

The membership check is a **security boundary**: Hermiq never posts to a room the owner is not a member of — an owner-scoped resolution is the only room lookup used (never a global `getRoomByToken`).

**Trade-off / mitigation (the version-coupling risk):** `OCA\Talk\*` is spreed's *internal* API, not an `OCP` contract, so it can change across spreed majors. Mitigations, all mandatory:
1. **Never constructor-inject** the `OCA\Talk\*` classes — if they were DI-declared, Hermiq would fail to boot when Talk is absent. Resolve them **lazily** inside a guard: probe `OCP\Talk\IBroker::hasBackend()` (or `class_exists(\OCA\Talk\Service\NoteToSelfService::class)`), then `\OCP\Server::get(...)` the concrete classes only when present. On any absence/throw, take the notification fallback.
2. Treat **any** Talk failure (not installed, resolution error, post error) as the fallback path — never propagate.
3. Pin the observed 24.0.1 signatures in this design and cover them with a smoke/live test, so a spreed upgrade that breaks the API is caught by the delivery test, not in production.

### Notification channel — `OCP\Notification\IManager` + an `INotifier`

`notification` (and the Talk fallback) build a notification via `IManager::createNotification()`, `setApp('hermiq')`, `setUser(<owner>)`, `setDateTime(now)`, `setObject('schedule', <schedule-uuid>)`, `setSubject('run_complete', [...])`, `setLink(<schedule/run url>)`, then `IManager::notify()`. A registered `Hermiq\Notification\Notifier implements OCP\Notification\INotifier` renders the parsed subject/message + icon + link so it shows in the bell menu. This channel has **no** optional dependency — it is the robust baseline and the Talk fallback target.

### Non-fatal delivery contract

`DeliveryService::deliver(channel, output, schedule): DeliveryResult` catches every `Throwable` internally and returns a small result value object (`{delivered: bool, channel: string, fellBack: bool, warning: ?string}`). It **never throws** for a delivery problem. `ScheduleService` calls it *outside* the branch that sets `lastStatus`, keeps `lastStatus = ok`, logs any `warning` at PSR-3 `warning` level with the schedule UUID + channel, and — when `warning` is non-null — **persists it into the schedule's `lastDeliveryError`**. On a fully-successful delivery it clears `lastDeliveryError` (sets it null). Empty/whitespace-only or explicitly-silent output short-circuits to a no-op result (nothing posted, `lastDeliveryError` untouched).

**Persisting `lastDeliveryError` safely.** The delivery result is folded into the same `$data` array the dispatcher already carries post-commit, and written through the existing `persist()`/`sanitizeForSave()` path — so the date-time (`nextRun`/`runAt`) and nullable-`repeat` round-trip artifacts are neutralised on the same save, and `lastDeliveryError` does not corrupt the write. It is set alongside the `lastStatus = ok` finalisation, in the dispatcher (not inside `DeliveryService`), so the single write-path and at-most-once commit semantics are preserved. A failure to persist `lastDeliveryError` is itself logged and non-fatal.

### Wiring into ScheduleService

The private `deliver()` seam is replaced by a delegation to the injected `DeliveryService` (constructor-injected — it depends only on always-present OCP services; the optional spreed classes live behind the lazy guard *inside* `DeliveryService`, not in its constructor). The dispatch order is unchanged: agent turn → deliver (owner still impersonated) → finalise `lastStatus`.

## Declarative-vs-imperative decision (ADR-031)

One-liner: `DeliveryService` is a legitimate **imperative external-integration service** — it posts to Talk / raises NC notifications, which are side-effecting calls into Nextcloud subsystems, not a derived value, declarative lifecycle transition, or aggregation OpenRegister could compute. It is the recognised ADR-031 exception; there is no schema, seed data, or lifecycle to declare here.

## Risks / Trade-offs

- **spreed internal-API version coupling** → resolve `OCA\Talk\*` lazily behind an `IBroker::hasBackend()` / `class_exists` guard; any failure falls back through the chain to notification; pin 24.0.1 signatures + a live/smoke test.
- **`ChatManager::sendMessage()` needs a `Room` + actor, not just a token** → for `deliverTarget`, obtain the membership-checked `Room` from `Manager::getRoomForUserByToken(<token>, <owner>)` and the `Participant` from `ParticipantService::getParticipant(Room, <owner>)`; for Note-to-self, from `NoteToSelfService::ensureNoteToSelfExistsForUser(<owner>)`. Post with `actorType='users'`, `actorId=<owner-uid>`. Verified against the 24.0.1 signatures.
- **Posting to a room the owner is not in (security)** → only ever resolve the room via the owner-scoped `getRoomForUserByToken`; a non-member/invalid token throws `RoomNotFoundException` and falls back to Note-to-self rather than posting. Never use a global room lookup.
- **Delivery failure silently swallowed** → always emit a PSR-3 `warning` (schedule UUID + channel + fell-back flag) AND persist it to `lastDeliveryError`, so misconfiguration is visible in logs and on the schedule; acceptance requires the warning + persist paths be unit-tested.
- **`lastDeliveryError` corrupting the round-trip save** → written through the existing `sanitizeForSave` seam so date-time/`repeat` artifacts are neutralised on the same save.
- **`deliverTarget` set on a non-`talk` schedule** → ignored (the field is only read when `deliver=talk`); the schema does not (cannot) enforce the coupling.

## Open Questions

- None outstanding. The two prior open questions are resolved by the user's decision to take the fuller path: configurable `deliverTarget` **and** persisted `lastDeliveryError`, both added upstream by `talk-delivery-schema` and consumed here (ADR-032 chain).
