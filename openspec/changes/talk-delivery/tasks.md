# Tasks: talk-delivery

## 1. DeliveryService (core)

- [ ] 1.1 Create `lib/Service/DeliveryService.php` (SPDX docblock) with `deliver(string $channel, string $output, ObjectEntity $schedule): DeliveryResult`; short-circuit `none`/empty/whitespace-only output to a no-op result.
- [ ] 1.2 Add a small `DeliveryResult` value object (`delivered`, `channel`, `fellBack`, `warning`) — no throw crosses the `DeliveryService` boundary; every `Throwable` is caught internally.
- [ ] 1.3 Implement the `notification` channel via `OCP\Notification\IManager`: `setApp('hermiq')`, `setUser(<owner>)`, `setObject('schedule', <uuid>)`, `setSubject(...)`, `setLink(<schedule url>)`, `notify()`.
- [ ] 1.4 Implement the `talk` channel behind the lazy Talk guard (`OCP\Talk\IBroker::hasBackend()` / `class_exists`, `\OCP\Server::get()` the spreed classes): when the schedule's `deliverTarget` is set, resolve the room membership-checked via `Manager::getRoomForUserByToken(<deliverTarget>, <owner>)` + `ParticipantService::getParticipant()`; else resolve the owner's Note-to-self via `NoteToSelfService::ensureNoteToSelfExistsForUser(<owner>)`; then `ChatManager::sendMessage(actorType='users', actorId=<owner>)`.
- [ ] 1.5 Implement the ordered fallback chain: target room → Note-to-self → notification. A non-member/invalid `deliverTarget` (`RoomNotFoundException`) MUST fall to Note-to-self; Talk absence/Note-to-self failure MUST fall to notification. Each fall-through sets `fellBack=true` + a `warning`; NEVER post to a room the owner is not resolved into.

## 2. Notifier registration

- [ ] 2.1 Create `lib/Notification/Notifier.php` implementing `OCP\Notification\INotifier` (id/name + `prepare()` rendering subject/message/icon/link); throw `UnknownNotificationException` for foreign notifications.
- [ ] 2.2 Register the `INotifier` (and DI-wire `DeliveryService`) in `lib/AppInfo/Application.php`; do NOT constructor-inject any `OCA\Talk\*` class (keep spreed behind the lazy guard).

## 3. Wire into the dispatcher

- [ ] 3.1 Replace `ScheduleService`'s logging-only `deliver()` seam with a delegation to `DeliveryService`, called with the owner still impersonated, AFTER the agent turn.
- [ ] 3.2 Ensure a delivery warning keeps `lastStatus = ok` (never `error`) and is emitted as a PSR-3 `warning` with the schedule UUID + channel + fell-back flag.
- [ ] 3.3 On a delivery warning, persist the message into the schedule's `lastDeliveryError` on the post-commit `$data` and write it through the existing `persist()`/`sanitizeForSave()` path; clear `lastDeliveryError` (null) on fully-successful delivery.

## 4. Tests

- [ ] 4.1 Add Talk/notification OCP + spreed stubs under `tests/Stubs/` (`IManager`/`INotification`/`IBroker` + minimal `Manager`/`ParticipantService`/`NoteToSelfService`/`ChatManager` incl. `RoomNotFoundException`) so PHPUnit runs the CI way (php:8.3-cli + OCP stubs).
- [ ] 4.2 Create `tests/Unit/Service/DeliveryServiceTest.php` covering: notification channel; talk→`deliverTarget` room post (owner is member); `deliverTarget` non-member/invalid → Note-to-self fallback; empty `deliverTarget` → Note-to-self; Talk-absent → notification fallback; `none`/empty output no-op; delivery-error stays non-fatal.
- [ ] 4.3 Extend `tests/Unit/Service/ScheduleServiceTest.php` to assert a delivery failure keeps `lastStatus = ok` AND persists `lastDeliveryError` (and a success clears it).

## 5. Live verify

- [ ] 5.1 On NC 34 + spreed 24.0.1: seed a `deliver = talk` schedule with `deliverTarget` set to a real room the owner is a member of (nextRun in the past), run one `ScheduleTask` tick, and confirm the output posts to THAT room.
- [ ] 5.2 Verify fallback + persistence: set `deliverTarget` to an invalid/non-member room and confirm it falls back cleanly (Note-to-self, then notification), keeps `lastStatus = ok`, logs a warning, and records `lastDeliveryError` on the schedule; also confirm a `deliver = notification` schedule raises a rendered bell notification linking to the schedule.

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
