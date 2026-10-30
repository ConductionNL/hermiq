## 1. Marking

- [ ] 1.1 Repoint the `@spec` tags in `lib/Service/NcNative/AgentArtefactMarker.php` and the four
      write services from the archived `nc-native-write-tools` path to REQ-AILBL-001 in
      `openspec/specs/nc-native-tools/spec.md` (gate 46 resolves them after archive).
      Verify: existing `CalendarWriteServiceTest`, `ContactWriteServiceTest`, `NotesWriteServiceTest`
      and `TaskWriteServiceTest` pass unchanged.

## 2. Reviewed mark

- [ ] 2.1 Add `AgentArtefactMarker::markReviewed(int $fileId)` and a reviewed system tag constant;
      route `POST /api/ai-label/files/{fileId}/reviewed` in `appinfo/routes.php` on a new
      `AiLabelController` with `#[NoAdminRequired]` and a per-file read check for the caller before
      tagging. Verify: unit tests for an allowed file, a file the caller cannot read (404) and a file
      without the agent-authored tag (409).

## 3. Oversight section

- [ ] 3.1 Add `lib/Service/AiLabelStatsService.php` returning counts of agent-authored notes and
      files, contacts and calendar items, and the reviewed share (design D3), and
      `GET /api/ai-label/stats` on `AiLabelController`. Verify: unit tests for each count.
- [ ] 3.2 Add the "AI label" section to the AI oversight page as drawn on HmToezicht, as a widget in
      `src/widgets/AiLabelSection.vue` placed through `src/manifest.json` on the `AiOversight` page,
      with the link that opens Files filtered on the agent-authored tag. Verify: `npm run lint` exits 0.

## 4. Verification

- [ ] 4.1 `@spec` tags on every new method (gate 16), an e2e test or a reason-bearing `@e2e exclude`
      per scenario (gate 19).
