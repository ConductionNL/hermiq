---
kind: code
depends_on: [talk-delivery-schema]
---

# Proposal: talk-delivery

## Why

The `agent-schedule-dispatcher` change shipped a delivery *seam* — `ScheduleService::deliver(channel, output, schedule)` — that only logs its intent. So a scheduled agent runs and produces output, but nothing reaches the user. This change replaces that no-op seam with real, Nextcloud-native delivery so run output actually lands where the owner works: a Nextcloud **Notification** (always available) and, when the owner asked for it, a **Talk** message. This is the ADR-005 decision — one in-suite channel instead of Hermes' 22-platform gateway.

## What Changes

- Replace `ScheduleService`'s logging-only `deliver()` seam with a call into a new `Hermiq\Service\DeliveryService` that performs real delivery and never lets a delivery failure fail the run.
- Add `Hermiq\Service\DeliveryService` implementing three channels:
  - `deliver = notification` — raise a Nextcloud notification (`OCP\Notification\IManager`) to the schedule **owner**, linking back to the schedule/run. The robust, always-available baseline (no Talk dependency).
  - `deliver = talk` — when the schedule's **`deliverTarget`** (the new schema field) is set, post the run output to **that Talk room**, first verifying the already-impersonated owner is a member; if the owner is not a member or the room is invalid/unreachable, fall back to the owner's **Note-to-self** conversation. When `deliverTarget` is empty/unset, go straight to Note-to-self. If Talk is absent or Note-to-self can't be resolved either, **fall back** to a notification (ADR-005).
  - `deliver = none` — silent, no delivery.
- Add an `INotifier` registration so Hermiq notifications render with a subject/message and a link to the schedule.
- Guarantee delivery is **non-fatal**: `DeliveryService` catches its own errors and returns a result; the dispatcher records the warning AND persists **`lastDeliveryError`** on the `Schedule` object (via `ObjectService`, routed through the dispatcher's existing `sanitizeForSave` so date/`repeat` round-trip artifacts don't corrupt the save) — without aborting or marking the run `error`.
- Add PHPUnit coverage for all channels + the targeted-room, membership-fallback, Talk-absent, and `lastDeliveryError`-persist paths, and a live-verify on a real dispatcher tick posting to a configured room.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `talk-delivery`: implements the planned spec's configurable-room delivery. `deliver=talk` posts to the schedule's `deliverTarget` room (membership-checked) with a Note-to-self → notification fallback chain, plus the always-on notification channel; delivery failures persist `lastDeliveryError` on the schedule. This is the service half of the ADR-032 chain headed by `talk-delivery-schema`, which added the `deliverTarget` and `lastDeliveryError` fields.

## Impact

- **Code:** `lib/Service/ScheduleService.php` (replace `deliver()` seam; persist `lastDeliveryError` through `sanitizeForSave`), new `lib/Service/DeliveryService.php`, new `lib/Notification/Notifier.php` (`INotifier`), DI registration in `lib/AppInfo/Application.php`, `tests/Unit/Service/DeliveryServiceTest.php` (+ Talk/notification OCP stubs under `tests/Stubs/`).
- **Dependencies:** `depends_on: [talk-delivery-schema]` — reads `deliverTarget`, writes `lastDeliveryError` (both added by that change). Nextcloud core `OCP\Notification\*` (always present). Talk (spreed) is an **optional runtime** dependency — resolved lazily and guarded so Hermiq boots and delivers (via notification) even when Talk is not installed.
- **Other Conduction apps:** none affected; couples only to Nextcloud core + optional spreed, not to any leaf app.
