# Test Plan: hermiq-flow-rename

## What could go wrong

A rename fails in ways that look like something else broke. The three real
risks, and how each is caught:

1. **A missed reference.** One `GraphBuilder` import left behind is a build
   error (loud, fine). One missed *string* is a user seeing "graph" (silent).
2. **The e2e suite passing vacuously.** The existing spec selects on
   `.graph-builder__*` classes and `[data-testid="graph-step-pane"]`. Renamed
   components with un-renamed selectors make every locator miss — and a
   `toHaveCount(0)` assertion would then *pass* against a canvas that renders
   nothing. This is the failure mode ADR-060 exists for.
3. **A dead link.** Flow URLs are pasted into Hydra issues and run logs.

## Coverage

| Risk | Test | Kind |
| --- | --- | --- |
| missed code reference | `npm run build` compiles | build |
| missed user-facing string | grep gate: no route, page id, component name, store symbol or translated string uses "graph" for a flow | CI gate |
| missed translation | `npm run test:l10n` after re-extraction | unit |
| vacuous e2e | every renamed selector assertion is `toHaveCount(n)` with n > 0, never n = 0 | e2e |
| dead link | `/apps/hermiq/graphs/<uuid>` redirects to `/apps/hermiq/flows/<uuid>` and the flow opens | e2e |
| the rename changed behaviour | the full pre-existing flow-editor e2e spec passes under new names, unchanged in substance | e2e |

## The positive control that matters

The renamed e2e spec must be shown able to FAIL. Before trusting a green run,
temporarily point one selector at a class that does not exist and confirm the
assertion goes red. A rename is exactly the change that can turn a real suite
into a suite of no-ops, and a green run proves nothing until the suite has been
shown to distinguish the two.

## Out of scope

Backend PHP — `lib/Flow/HermiqAgentNode.php` and `HermiqWorkloadNode.php` already
speak the engine's vocabulary and are untouched by this change.
