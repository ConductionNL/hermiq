---
kind: spec
depends_on: []
---

# Sign-in, hosting and the licence come from the platform

## Why

Four matrix rows are built today, but no hermiq spec says so, so the coverage matrix counts
them as unspecified:

| row | capability | hermiq today |
|---|---|---|
| `op-sso` | Sign in with the organisation's existing accounts and groups. | built by Nextcloud: every hermiq controller resolves the caller through `IUserSession`; `lib/` holds no SAML, OIDC or password code |
| `dm-second-factor` | Require a second factor such as an authenticator code when signing in. | built by Nextcloud's two-factor apps; hermiq pages and routes need a Nextcloud session, the `PublicPage` controllers are token or secret guarded |
| `op-self-host` | Host the whole thing on the organisation's own servers. | built: hermiq is a Nextcloud app (`appinfo/info.xml`, Nextcloud 32 to 35), with local model options (Ollama, Nextcloud's own task processing) |
| `op-open-source` | Use it under an open source licence such as the EUPL. | built: `LICENSE` is EUPL 1.2, `appinfo/info.xml` declares `<licence>EUPL-1.2</licence>` |

`op-flat-cost` (pay a flat price per instance) is a pricing fact with no code or screen. It is
recorded as decided-no in `gap-decisions.json` in this change, not specified.

## What changes

- `app-runtime-requirements` gains four requirements: sign-in and single sign-on are
  Nextcloud's, a second factor is Nextcloud's, hermiq runs on any self-managed Nextcloud in its
  declared range, and hermiq ships under the EUPL 1.2.
- `openspec/parity/capabilities.json` records `built.spec` and `built.requirement` for the four
  rows; `op-flat-cost` becomes `decided-no`.

## Non-goals

- No authentication code, session model or two-factor provider in hermiq.
- No screen: sign-in happens on Nextcloud's sign-in page, hosting on the organisation's server,
  and the licence is a fact about the source code.
