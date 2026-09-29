# Tasks: observability-feedback-per-agent

Kind: code. Size S. Row `hermiq:ob-feedback-stats`.

### Task 1: Feedback counts in the analytics
- **spec_ref**: `openspec/changes/observability-feedback-per-agent/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001`
- **files**: `lib/Service/AnalyticsService.php`
- **acceptance_criteria**: GIVEN three positive and one negative rating for an agent WHEN analytics is computed for it THEN positive 3, negative 1, helpfulRate 0.75; ratings of another organisation are not counted
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 2: The latest low ratings endpoint
- **spec_ref**: `openspec/changes/observability-feedback-per-agent/specs/run-analytics/spec.md#requirement-an-agent-owner-reads-the-latest-low-ratings-req-fbstat-002`
- **files**: `lib/Service/AnalyticsService.php`, `lib/Controller/AnalyticsController.php`, `appinfo/routes.php`
- **acceptance_criteria**: GIVEN a user who may read the agent THEN the last ten negative comments; GIVEN one who may not THEN 404
- [ ] Implement
- [ ] Test (PHPUnit per role)

### Task 3: The tile, the widget and the breakdown
- **spec_ref**: `openspec/changes/observability-feedback-per-agent/specs/run-analytics/spec.md#requirement-an-agent-owner-sees-how-answers-were-rated-req-fbstat-001`
- **files**: `src/manifest.json`, `src/components/widgets/LowRatingsWidget.vue`, the custom component registry, the breakdown widget, `l10n/`
- **acceptance_criteria**: GIVEN the agent page WHEN it loads THEN the tile and the widget render and the grid has no empty cell
- [ ] Implement
- [ ] Test (check:manifest, check:registry, eslint, test:l10n)
