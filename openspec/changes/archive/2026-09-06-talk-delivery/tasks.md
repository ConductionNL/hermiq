# Tasks: talk-delivery

## 1. DeliveryService (core)

- [x] 1.1 Create `lib/Service/DeliveryService.php` (SPDX docblock) with `deliver(string $channel, string $output, ObjectEntity $schedule): DeliveryResult`; short-circuit `none`/empty/whitespace-only output to a no-op result.
- [x] 1.2 Add a small `DeliveryResult` value object (`delivered`, `channel`, `fellBack`, `warning`) — no throw crosses the `DeliveryService` boundary; every `Throwable` is caught internally.
- [x] 1.3 Implement the `notification` channel via `OCP\Notification\IManager`: `setApp('hermiq')`, `setUser(<owner>)`, `setObject('schedule', <uuid>)`, `setSubject(...)`, `setLink(<schedule url>)`, `notify()`.
- [x] 1.4 Implement the `talk` channel behind the lazy Talk guard (`OCP\Talk\IBroker::hasBackend()` / `class_exists`, `\OCP\Server::get()` the spreed classes): when the schedule's `deliverTarget` is set, resolve the room membership-checked via `Manager::getRoomForUserByToken(<deliverTarget>, <owner>)` + `ParticipantService::getParticipant()`; else resolve the owner's Note-to-self via `NoteToSelfService::ensureNoteToSelfExistsForUser(<owner>)`; then `ChatManager::sendMessage(actorType='users', actorId=<owner>)`.
- [x] 1.5 Implement the ordered fallback chain: target room → Note-to-self → notification. A non-member/invalid `deliverTarget` (`RoomNotFoundException`) MUST fall to Note-to-self; Talk absence/Note-to-self failure MUST fall to notification. Each fall-through sets `fellBack=true` + a `warning`; NEVER post to a room the owner is not resolved into.

## 2. Notifier registration

- [x] 2.1 Create `lib/Notification/Notifier.php` implementing `OCP\Notification\INotifier` (id/name + `prepare()` rendering subject/message/icon/link); throw `UnknownNotificationException` for foreign notifications.
- [x] 2.2 Register the `INotifier` (and DI-wire `DeliveryService`) in `lib/AppInfo/Application.php`; do NOT constructor-inject any `OCA\Talk\*` class (keep spreed behind the lazy guard).

## 3. Wire into the dispatcher

- [x] 3.1 Replace `ScheduleService`'s logging-only `deliver()` seam with a delegation to `DeliveryService`, called with the owner still impersonated, AFTER the agent turn.
- [x] 3.2 Ensure a delivery warning keeps `lastStatus = ok` (never `error`) and is emitted as a PSR-3 `warning` with the schedule UUID + channel + fell-back flag.
- [x] 3.3 On a delivery warning, persist the message into the schedule's `lastDeliveryError` on the post-commit `$data` and write it through the existing `persist()`/`sanitizeForSave()` path; clear `lastDeliveryError` (null) on fully-successful delivery.

## 4. Tests

- [x] 4.1 Add Talk/notification OCP + spreed stubs under `tests/Stubs/` (`IManager`/`INotification`/`IBroker` + minimal `Manager`/`ParticipantService`/`NoteToSelfService`/`ChatManager` incl. `RoomNotFoundException`) so PHPUnit runs the CI way (php:8.3-cli + OCP stubs).
- [x] 4.2 Create `tests/Unit/Service/DeliveryServiceTest.php` covering: notification channel; talk→`deliverTarget` room post (owner is member); `deliverTarget` non-member/invalid → Note-to-self fallback; empty `deliverTarget` → Note-to-self; Talk-absent → notification fallback; `none`/empty output no-op; delivery-error stays non-fatal.
- [x] 4.3 Extend `tests/Unit/Service/ScheduleServiceTest.php` to assert a delivery failure keeps `lastStatus = ok` AND persists `lastDeliveryError` (and a success clears it).

## 5. Live verify

- [x] 5.1 Verified live on NC 34 + spreed 24.0.1: `DeliveryService::deliver('talk', …)` for a `deliverTarget` room the owner (admin) is a member of returned `delivered=true, channel=talk, fellBack=false`, and the message landed in that Talk room (confirmed via the chat API). (Invoked DeliveryService directly because OR's own agent execution is currently broken in this dev build — `ChatService` throws `SQLSTATE[42703] Undefined column`, an OpenRegister WIP issue upstream of delivery, so the natural agent→output→deliver tick can't produce output yet; the dispatcher→deliver wiring is proven and nextRun advanced correctly.)
- [x] 5.2 Verified live: invalid `deliverTarget` → `RoomNotFoundException` → fell back to Note-to-self (`delivered=true, fellBack=true`, warning "target room '…' unavailable — delivered to Note-to-self" → recorded as `lastDeliveryError`); empty `deliverTarget` → Note-to-self (`delivered=true`); `deliver=notification` → `delivered=true, channel=notification` (rendered via the registered Notifier). Delivery never fails the run (`lastStatus` stays `ok`).

## Acceptance criteria

- `deliver = talk` with a `deliverTarget` posts to that room after verifying owner membership; empty `deliverTarget` posts to Note-to-self; a non-member/invalid room falls back cleanly.
- `deliver = notification` raises a rendered Nextcloud notification to the owner linking to the schedule/run; works with Talk absent.
- The fallback chain (target room → Note-to-self → notification) records a delivery warning on each fall-through and never posts to a room the owner is not in.
- Empty/whitespace-only/silent output posts nothing on any channel; `deliver = none` is silent.
- A delivery failure never fails the run (`lastStatus` stays `ok`) and is recorded as a warning AND persisted to `lastDeliveryError` (cleared on success).

## Quality reminders

- SPDX `@license`/`@copyright` tags inside each PHP file docblock; pass `composer check:strict`.
- Add `@spec openspec/changes/talk-delivery/tasks.md#...` docblock tags to changed backend methods (gate-16 spec-coverage).
- Never constructor-inject `OCA\Talk\*`; resolve spreed lazily behind `IBroker::hasBackend()`/`class_exists` so Hermiq boots without Talk.
- Use `<room-token>` / NIL UUID `00000000-0000-0000-0000-000000000000` placeholders in examples (gitleaks scans them).
- No stub bodies, no `var_dump`/`error_log`; do not use sed/awk/scripts to edit PHP — use the Edit tool.
- Security: only ever resolve a `deliverTarget` room with the owner-scoped `getRoomForUserByToken` — never a global room lookup.
- Depends on `talk-delivery-schema` (the `deliverTarget`/`lastDeliveryError` fields). NC Mail (`IMailer`) outbound stays explicit FUTURE scope — do NOT add it here.
