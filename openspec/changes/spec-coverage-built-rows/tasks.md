## 1. Traceability and the gaps the spec shows

- [ ] 1.1 Point the `@spec` tags on the code named in the proposal table at the matching
      REQ-SPCOV id (gate 16). Verify: the gate 46 anchor check resolves every new tag.
- [ ] 1.2 Build `hermiq.createFile` and `hermiq.moveFile` in `NcNativeToolService`, scoped to
      the acting user's folder and default-denied like the other write tools (REQ-SPCOV-005).
      Verify: a unit test refuses a path outside `getUserFolder($uid)`.
- [ ] 1.3 Add e2e coverage for the REQ-SPCOV scenarios that have a screen, or a reasoned
      `@e2e exclude` for the server-side ones (gate 19).
- [ ] 1.4 Cover REQ-SPCOV-014 and REQ-SPCOV-015, added in the closing round of 12 November: a
      unit test that `ScheduleTask` keeps parallel runs off, and a test that a register update
      without a write grant is refused while a granted one goes through. Verify: both tests
      fail when the guard is removed.
