## 1. Traceability and the gaps the spec shows

- [ ] 1.1 Point the `@spec` tags on the code named in the proposal table at the matching
      REQ-SPCOV id (gate 16). Verify: the gate 46 anchor check resolves every new tag.
- [x] 1.2 Build `hermiq.createFile` and `hermiq.moveFile` in `NcNativeToolService`, scoped to
      the acting user's folder and default-denied like the other write tools (REQ-SPCOV-005).
      Verify: a unit test refuses a path outside `getUserFolder($uid)`.
      (`Service/NcNative/FilesWriteService`; `FilesWriteServiceTest`, `NcNativeWriteServiceFilesTest`.)
- [ ] 1.3 Add e2e coverage for the REQ-SPCOV scenarios that have a screen, or a reasoned
      `@e2e exclude` for the server-side ones (gate 19).
- [ ] 1.4 Cover REQ-SPCOV-014 and REQ-SPCOV-015, added in the closing round of 12 November: a
      unit test that `ScheduleTask` keeps parallel runs off, and a test that a register update
      without a write grant is refused while a granted one goes through. Verify: both tests
      fail when the guard is removed.
- [ ] 1.5 Show chat health on the connections page: one block that says whether chat works, with the AI provider, the default model and each connection's state and check time, read from `GET /api/chat/health` and the connection registry (row `op-health`, scenario "The connections page shows chat health with each dependency"). Verify: a component test with one failing dependency shows that row as not answering and no secret.
