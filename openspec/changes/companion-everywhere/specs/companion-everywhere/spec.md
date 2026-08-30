## ADDED Requirements

### Requirement: The companion MUST be available on every page, including third-party apps

The AI companion MUST be reachable from any Nextcloud page without navigating away,
including pages rendered by apps this fleet does not own — the office editors
(`onlyoffice`, `eurooffice`, `richdocuments`) among them.

It MUST be attached by an init script rather than by a component, because there is no
template of ours on those pages to add one to.

#### Scenario: The companion appears on a third-party office editor page

- **GIVEN** a document open in any of the four supported office suites
- **WHEN** the page has loaded
- **THEN** the companion MUST be present without leaving the editor

#### Scenario: Nothing suite-specific is added

- **GIVEN** the companion mounting logic
- **WHEN** it is inspected
- **THEN** it MUST NOT branch on which office suite is present, so no capability comes to depend on a named suite (ADR-087 §5)

### Requirement: The companion MUST NOT mount twice

On Hermiq's own pages `CnAppRoot` already renders a companion. The global bundle MUST
decline to mount there, and MUST decline to mount a second time on any page.

Detection MUST use the active-app body class rather than a URL match: a URL check
misses `/index.php/apps/hermiq/...` against `/apps/hermiq/...` and anything served
under a sub-path.

#### Scenario: Hermiq's own pages get exactly one companion

- **GIVEN** a page of the Hermiq app
- **WHEN** the global bundle runs
- **THEN** it MUST NOT mount
- **AND** exactly one companion MUST be present

#### Scenario: A repeated script evaluation does not stack companions

- **GIVEN** a page where the bundle is evaluated more than once
- **WHEN** it runs again
- **THEN** it MUST detect its own container and not mount a second instance

### Requirement: The companion MUST carry the open document's context

When the page is showing a file, the companion MUST receive that file's id, so a user
can ask about the document on screen without supplying an identifier.

Absence of a file id MUST be normal and silent — most pages are not showing a file.

#### Scenario: An office editor page passes its file id

- **GIVEN** a document open at `/apps/eurooffice/24753` or `?fileId=24753`
- **WHEN** the companion mounts
- **THEN** it MUST receive `24753` as its document context

#### Scenario: A page with no file passes none, without error

- **GIVEN** a page that is not showing a file
- **WHEN** the companion mounts
- **THEN** it MUST mount with no document context and MUST NOT log an error

### Requirement: The companion MUST NOT disturb the page it is attached to

The bundle MUST append its own container to `body` and MUST NOT write into elements
belonging to the host app.

It runs on pages owned by other teams and other projects. Reusing a host element is
how one app silently breaks another's layout, and the breakage would be attributed to
the host.

#### Scenario: The host page's DOM is untouched

- **GIVEN** any page the companion attaches to
- **WHEN** the DOM is compared before and after mounting
- **THEN** the only addition MUST be the companion's own container
