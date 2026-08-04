# Tasks: hermiq-flow-rename

- [ ] Rename components: `GraphBuilder` → `FlowBuilder`, `GraphSidebar` →
      `FlowSidebar`, `GraphIndex` → `FlowIndex`, `RunGraphDialog` →
      `RunFlowDialog`; update `src/registry.js` keys and imports.
- [ ] Rename the store: `useGraphEditorStore` → `useFlowEditorStore`, `graph` →
      `flow`, `graphs` → `flows`, and every call site.
- [ ] Manifest: page ids `GraphIndex`/`GraphDetail` → `FlowIndex`/`FlowDetail`,
      routes `/graphs` → `/flows`, titles "Graphs"/"Graph" → "Flows"/"Flow".
- [ ] Redirect routes for `/graphs` and `/graphs/:id` — these URLs are pasted
      into Hydra issues and run logs.
- [ ] Rename CSS block prefixes `.graph-builder__*` → `.flow-builder__*` and
      `.graph-sidebar__*` → `.flow-sidebar__*`.
- [ ] Retranslate every `t('hermiq', …)` string naming a graph; re-extract i18n.
- [ ] Rename `tests/e2e/graph-builder-flow-dialect.spec.ts` and its selectors IN
      THE SAME COMMIT as the component rename — otherwise a passing suite starts
      finding nothing, which reads as a broken canvas rather than moved selectors.
- [ ] Grep gate: no route, page id, component name, store symbol or translated
      string uses "graph" for a flow.

## Acceptance criteria

- `/apps/hermiq/flows` lists flows; `/apps/hermiq/graphs` redirects to it.
- No user-facing string calls a flow a graph.
- Flows are read and written only through OpenRegister's endpoints.
- The e2e suite passes under the new names.

## Quality checklist

- `npm run lint`, `npm run stylelint`, `npm run check:specs` pass.
- `npm run test:l10n` passes after re-extraction.
- Independent of the OpenRegister chain — this can land first.
