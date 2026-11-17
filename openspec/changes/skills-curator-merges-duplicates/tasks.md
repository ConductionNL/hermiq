## 1. Build

- [x] 1.1 Duplicate detection and a merge proposal per pair in `lib/BackgroundJob/SkillCuratorTask.php` and `lib/Service/SkillMarketplaceService.php`.
- [x] 1.2 Approve and reject a merge proposal; on approval archive the duplicate and move its agents to the survivor.
  Built in `lib/Service/SkillMergeService.php` (`proposeMerges`, `approveMerge`, `rejectMerge`; the curator job calls `proposeMerges` after `curate`). Duplicates: the same name, or the same instructions, with case, punctuation and spacing set aside. Survivor: the skill on more agents, then the older one. The proposal lives on the duplicate (`agentskill.mergeProposal`, `mergedInto`, `mergeRejectedWith`, schema 0.4.0). No HTTP endpoint yet: it comes with the skills page in 1.3.
- [x] 1.3 Merge proposals on the skills page (HmSkills).
  Built as the `skill-merge-proposals` widget (`src/widgets/SkillMergeProposals.vue`) in the Skills index page's `below-header` slot, over `GET /api/skill-merges` and `POST /api/skill-merges/{id}/approve|reject` (`lib/Controller/SkillMergeController.php`, `SkillMergeService::pendingMerges`). Every route needs the `skill.merge-duplicates` action (admins by default); a person without it sees no section. "Merge" (Samenvoegen) archives the duplicate and moves its agents; "Keep both" (Beide houden) rejects. Tested in `tests/Unit/Controller/SkillMergeControllerTest.php`, `SkillMergeServiceTest::testPendingMergesListTheUndecidedProposalsNewestFirst` and `tests/vitest/skill-merge-proposals.spec.js`.
- [x] 1.4 Unit tests in `tests/Unit/Service/` for detection, approval and rejection, and that nothing is hard-deleted.

## 2. Hand-over

- [ ] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
