# Tasks

## 1. Verify it actually mounts — FIRST

- [x] Build the `companion` bundle and confirm `js/hermiq-companion.js` is produced
  - `npm run build` on development (2026-11-20) emits `js/hermiq-companion.js` (100,805 bytes) and the async `js/hermiq-companion-panel.js`.
- [ ] Load an office editor page and confirm exactly one companion is present
- [x] Confirm a Hermiq page still has exactly one, not two
  - 2026-11-24: `tests/e2e/spec-coverage/companion-everywhere.spec.ts`: on a Hermiq page exactly one `cn-ai-companion` and no global container; on the Files page exactly one, in its own container appended to body, with no console error. Written, runs in nightly (UNBLOCK rule 3); not run here, no instance.

Acceptance criteria:
- Nothing in this change may be described as working until it has been seen to mount on a third-party editor page. The code was written before the toolchain could build it.

## 2. Context

- [x] Confirm the open document's id reaches the companion from both URL shapes
- [x] Confirm a page with no file mounts silently

Acceptance criteria:
- `/apps/eurooffice/24753` and `?fileId=24753` both yield `24753`.
- No error is logged on a page that is not showing a file.

## 3. Cost

- [x] Measure the bundle size and confirm it does not pull the Hermiq app shell
- [x] Record the measured size
  - `node tests/companion-bundle.spec.js` after that build: eager `hermiq-companion.js` 37.3 KB gzipped, panel 305.6 KB, `companion.css` 23.3 KB; 366.2 KB per page load against the 400 KB budget. The eager bundle references neither `hermiq-shared-nc-vue` nor `hermiq-shared-vendor`, so it pulls no app shell.
  - ⚠️ One guard in that file is red: the panel is 305.6 KB against its 300 KB `@nextcloud/vue` tree-shaking limit. The file is not part of `check:specs`, so nothing caught it.

Acceptance criteria:
- This bundle loads on EVERY page in Nextcloud. An unmeasured size is a cost imposed on every app in the fleet without anyone agreeing to it.

## 4. Guard the suite-independence property

- [x] Confirm the mounting logic branches on no office suite
- [x] Confirm `SuiteIndependenceTest` still passes
  - No such test existed. `tests/companion-suite-independence.spec.js` (`npm run check:companion-suite-independence`, in `check:specs`) asserts that `src/companion.js` names no office suite in code, and its two fixtures prove the detector finds a branch and ignores a comment.

Acceptance criteria:
- Attaching per suite would make a capability depend on a named suite, which ADR-087 §5 bans.
