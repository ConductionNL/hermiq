---
kind: code
depends_on: []
---

# Proposal: forms-smart-paste-fill

## Summary

A person on a form in a built app pastes an email signature, clicks Fill, and sees the name, the address and the phone number proposed in the right fields. Hermiq is the part that reads the text: a gated endpoint takes the pasted text and the fields the maker allowed, and returns proposed values, marked as a draft and never saved.

## Why

Buildiq's merged change `ai-smart-paste-into-forms` (buildiq development, merged in buildiq's OpenSpec pass) names this half in its "Sibling halves" section:

> hermiq: the fill endpoint. It takes pasted text and the allowed fields (key, label, type, allowed values) and returns proposed values, marked as a draft, never saved. It follows Hermiq's governed delegate pattern (`lesson-authoring-ai-delegate`: an AI feature row seeded off, a DPO acknowledgement before it can be turned on, an input allowlist, one log line per call). No such endpoint exists on hermiq development.

Nextcloud-vue's merged change `form-smart-paste` builds the renderer half around a host-registered handler (`fill: async (text, fields) => ({ values })`, `available: async () => true`), "so buildiq can wire hermiq's route when it ships". Its design records the same gap: "hermiq development has no fill route". The nextcloud-vue OpenSpec-pass lane recorded it for `ai-smart-paste`: "The fill endpoint the sibling expects does not exist in hermiq yet, so the change calls a host-registered handler and names no hermiq URL."

The row is `ai-smart-paste` in buildiq's `openspec/parity/capabilities.json` ("Paste text or a document into a form and have AI fill in its fields", rated no), with a roadmap demand row (Power Apps 2026 wave 1, https://learn.microsoft.com/en-us/power-platform/release-plan/2026wave1/power-apps/planned-features) and two competitors rated yes:

- NocoBase: "packages/plugins/@nocobase/plugin-ai/src/client-v2/ai-employees/form-filler/tools/index.ts:192 formFiller tool used by Dex ... Reached on: form, AI employee Dex, paste text" (source read at v2.2.18).
- Power Apps: "form fill assistance suggests field values from text or an image the user copied and pasted with smart paste" (https://learn.microsoft.com/en-us/power-apps/maker/common/faq-from-filling-assistance).

This half was handed to hermiq after hermiq's own OpenSpec-pass lane had finished (owner-moves pass, 28 September 2026). Decision: build, because merged changes of two other products depend on it.

## What changes

- An AI feature `form-fill` is seeded switched off, at limited risk, and needs the data protection acknowledgement before an admin can switch it on.
- `POST /api/assistant/form-fill` takes the pasted text and the allowed fields and returns proposed values with a draft notice.
- `GET /api/assistant/form-fill/available` answers whether the feature is on, for the renderer's `available()`.
- Proposals only name allowed fields, only use a field's allowed values, and are dropped when they do not fit the field's type.
- Every call writes one log line without the text or the values.

## Out of scope

- Images and files as input. Text only, as buildiq's change says.
- Anonymous callers. The endpoint needs a signed-in user; buildiq refuses smart paste on public forms.
- Saving anything. The proposal goes back to the form, and the person submits.
- Buildiq's handler that calls this route: buildiq's half, listed for the coordinator.
