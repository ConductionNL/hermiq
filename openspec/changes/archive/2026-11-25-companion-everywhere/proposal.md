---
kind: code
---

## Why

The AI chat exists only inside Hermiq's own Vue app, where `CnAppRoot` renders it
from `:aiCompanion="true"`.

That puts the assistant **one navigation away from the document the user is looking
at**. To ask about the file open in an office editor, a user has to leave the editor,
open Hermiq, and identify the document again by name or id — which is exactly the
context the page they just left already had.

Demonstrated 2026-08-16: proving that the chat could edit a document required handing
out *two* links, one for the editor and one for the chat, and telling the user which
`fileId` to type.

## What Changes

- A **standalone `companion` bundle** attached to every Nextcloud page with
  `\OCP\Util::addInitScript`, mounting `CnAiCompanion` into its own container.
- It **declines to mount on Hermiq's own pages**, where `CnAppRoot` already renders
  one.
- It **carries the open document's id** when the page has one, so the user can ask
  about "this document" instead of pasting an identifier.

## Why a global bundle rather than a component

The office editors are **third-party apps** — `onlyoffice`, `eurooffice`,
`richdocuments`. There is no `CnAppRoot` of ours to switch on, and no template of ours
to edit. An init script is the only seam that reaches them.

It is also not a new pattern here: this app already attaches `hermiq-agent-leaf` to
every page for exactly the same reason (ADR-019 cross-bundle registration).

## Impact

- **Code**: new `src/companion.js`, a webpack entry, one `addInitScript` call.
- **Every page in Nextcloud gains a floating chat button.** That is the intent, and
  it is also the main risk — see below.
- **Applies to all four suites at once**, because it attaches to the page rather than
  to a suite. Nothing suite-specific is added, which keeps ADR-087 §5 intact: no
  capability depends on a named office suite.

## Risks

**A bundle on every page is a cost on every page.** It must stay small and must not
pull the Hermiq app shell with it. Unmeasured as yet — see the tasks.

**Two companions would fight for the same corner.** Hence the body-class check
against Hermiq's own pages, rather than a URL match that misses `/index.php/apps/...`
and sub-paths.

**It writes into another app's DOM.** Mitigated by appending a dedicated container to
`body` rather than reusing any element of the host page.

## Capabilities

### New Capabilities
- `companion-everywhere`: where the AI companion is available, and what document
  context it carries.
