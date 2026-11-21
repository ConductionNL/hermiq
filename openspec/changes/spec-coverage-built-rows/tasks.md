## 1. Traceability and the gaps the spec shows

- [x] 1.1 Point the `@spec` tags on the code named in the proposal table at the matching
      REQ-SPCOV id (gate 16). Verify: the gate 46 anchor check resolves every new tag.
      (20 tags across 13 lib files for REQ-SPCOV-002..012, 014, 015. REQ-SPCOV-001 and -013 are
      manifest pages and the setup walkthrough, with no PHP method to carry a tag.)
- [x] 1.2 Build `hermiq.createFile` and `hermiq.moveFile` in `NcNativeToolService`, scoped to
      the acting user's folder and default-denied like the other write tools (REQ-SPCOV-005).
      Verify: a unit test refuses a path outside `getUserFolder($uid)`.
      (`Service/NcNative/FilesWriteService`; `FilesWriteServiceTest`, `NcNativeWriteServiceFilesTest`.)
- [ ] 1.3 Add e2e coverage for the REQ-SPCOV scenarios that have a screen, or a reasoned
      `@e2e exclude` for the server-side ones (gate 19).
- [x] 1.4 Cover REQ-SPCOV-014 and REQ-SPCOV-015, added in the closing round of 12 November: a
      unit test that `ScheduleTask` keeps parallel runs off, and a test that a register update
      without a write grant is refused while a granted one goes through. Verify: both tests
      fail when the guard is removed.
      (`ScheduleTaskTest::testATickNeverStartsWhileTheLastOneRuns`, `ToolLoopTest::testARegisterUpdateNeedsTheSchemasWriteGrant`;
      each seen failing with `setAllowParallelRuns(false)` or the grant resolution removed.)
- [x] 1.5 Show chat health on the connections page: one block that says whether chat works, with the AI provider, the default model and each connection's state and check time, read from `GET /api/chat/health` and the connection registry (row `op-health`, scenario "The connections page shows chat health with each dependency"). Verify: a component test with one failing dependency shows that row as not answering and no secret.
      (`src/widgets/ChatHealthBlock.vue` as the Integrations page's `below-header` slot, view built by
      `src/services/chatHealth.js`; `tests/vitest/chat-health-block.spec.js`, seen failing with
      `unavailable` dropped from the not-answering states.)
- [x] 1.6 Refuse a temperature outside 0 to 2 (REQ-SPCOV-002, scenario "A value out of range is refused"):
      the agent form says "The temperature must be between 0 and 2." and does not save, and every
      write path refuses a changed temperature outside the range before it is stored. Verify: a
      unit test refuses 3 on a create and on an update, and leaves an unchanged one alone.
      (`Listener/AgentTemperatureGuardListener` over `Listener/AgentFieldChange`;
      `AgentTemperatureGuardListenerTest`, vitest `agent-temperature.spec.js` and the save refusal in
      `agent-form-helpers.spec.js`.)
