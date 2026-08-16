# Tasks

## 1. Verify it actually mounts — FIRST

- [ ] Build the `companion` bundle and confirm `js/hermiq-companion.js` is produced
- [ ] Load an office editor page and confirm exactly one companion is present
- [ ] Confirm a Hermiq page still has exactly one, not two

Acceptance criteria:
- Nothing in this change may be described as working until it has been seen to mount on a third-party editor page. The code was written before the toolchain could build it.

## 2. Context

- [ ] Confirm the open document's id reaches the companion from both URL shapes
- [ ] Confirm a page with no file mounts silently

Acceptance criteria:
- `/apps/eurooffice/24753` and `?fileId=24753` both yield `24753`.
- No error is logged on a page that is not showing a file.

## 3. Cost

- [ ] Measure the bundle size and confirm it does not pull the Hermiq app shell
- [ ] Record the measured size

Acceptance criteria:
- This bundle loads on EVERY page in Nextcloud. An unmeasured size is a cost imposed on every app in the fleet without anyone agreeing to it.

## 4. Guard the suite-independence property

- [ ] Confirm the mounting logic branches on no office suite
- [ ] Confirm `SuiteIndependenceTest` still passes

Acceptance criteria:
- Attaching per suite would make a capability depend on a named suite, which ADR-087 §5 bans.
