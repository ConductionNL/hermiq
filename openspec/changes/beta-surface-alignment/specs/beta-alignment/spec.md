# beta-alignment (delta)

Ensures Hermiq's code metadata, code features, product page, and docs site agree on
the same feature vocabulary, in real (non-machine, non-English-copy) Dutch where
Dutch content is required, before the app is presented as beta-release-ready.

## ADDED Requirements

### Requirement: Every user-facing locale field in `appinfo/info.xml` MUST carry a genuine translation
`<summary lang="nl">` and `<description lang="nl">` MUST NOT be byte-for-byte
identical to their `lang="en"` counterpart.

#### Scenario: An auditor diffs the English and Dutch info.xml fields
- **GIVEN** `appinfo/info.xml`
- **WHEN** `<summary lang="nl">` is compared against `<summary lang="en">`, and
  `<description lang="nl">` against `<description lang="en">`
- **THEN** neither Dutch field MUST be an exact copy of its English counterpart

### Requirement: A public product page MUST exist at conduction.nl/apps/hermiq in both locales
`conduction-website/src/pages/apps/hermiq.mdx` (English) and
`conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/hermiq.mdx` (Dutch)
MUST both exist and describe the same canonical feature list.

#### Scenario: A visitor requests the Hermiq product page in English
- **GIVEN** `conduction-website/src/pages/apps/hermiq.mdx`
- **WHEN** the page is built and served at `/apps/hermiq`
- **THEN** it MUST render a DetailHero, a feature list, and a CTA, without a build
  error

#### Scenario: A visitor requests the Hermiq product page in Dutch
- **GIVEN** `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/hermiq.mdx`
- **WHEN** the `nl` locale is built and the page is served at `/nl/apps/hermiq`
- **THEN** it MUST render the same structure with genuinely translated Dutch copy,
  not an English copy or a machine-mirrored string

### Requirement: Product-page and docs feature claims MUST be traceable to shipped code
Every feature named on the product page or in `docs/intro.md` MUST correspond to a
page/menu entry in `src/manifest.json` and a backing controller/service under `lib/`.
Compliance/standard claims (e.g. Peppol, SEPA, BBV, DigiD) MUST NOT appear unless a
corresponding implementation exists in `lib/`.

#### Scenario: An auditor cross-checks a product-page feature bullet against the manifest
- **GIVEN** a feature bullet on `hermiq.mdx`
- **WHEN** it is checked against `src/manifest.json` `menu[]`/`pages[]` and the
  corresponding `lib/Controller/` or `lib/Service/` class
- **THEN** a matching manifest entry and backing class MUST exist

#### Scenario: An auditor checks the docs site for scaffold-leftover feature descriptions
- **GIVEN** `hermiq/docs/intro.md`
- **WHEN** its "What is this?" feature list is compared to `src/manifest.json` and
  `lib/`
- **THEN** it MUST NOT describe generic app-template scaffold components
  (`ExampleWidget`, `ExampleToolProvider`) that are not part of Hermiq's real,
  shipped feature set
