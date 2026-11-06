## 1. Guards

- [ ] 1.1 Add a unit test `tests/Unit/AppInfo/PlatformFoundationsTest.php` that reads
      `appinfo/info.xml` and asserts `<licence>EUPL-1.2</licence>`, that the root `LICENSE`
      starts with "EUROPEAN UNION PUBLIC LICENCE v. 1.2", and that `lib/` contains no
      `saml`, `oidc` or two-factor provider class. Verify: the test passes on development.
- [ ] 1.2 `@spec` tag on the new test pointing at
      `openspec/specs/app-runtime-requirements/spec.md` (gate 16); each scenario carries a
      reason-bearing `@e2e exclude` (gate 19), already written below.
