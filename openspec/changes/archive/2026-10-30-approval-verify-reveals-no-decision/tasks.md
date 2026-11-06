# Tasks

- [x] 1. Test: an `unknown` or `binding-mismatch` verdict carries null `decidedBy`, `decidedAt` and `expiresAt` (fails on the old code: 4 of 4 cases).
- [x] 2. Null the three fields in `ApprovalVerdictService::verify()` for those two reasons.
- [x] 3. Spec delta on REQ-APVER-001.
