---
description: Run Hermiq's unit tests (PHPUnit and Vitest)
---
Run the unit tests from the repository root. If `$ARGUMENTS` names a test file or filter, pass it to the matching runner.

1. `composer test:unit` (PHPUnit, configured in `phpunit.xml`)
2. `npm run test:unit` (Vitest, configured in `vitest.config.mjs`)

Report failing tests with the assertion message. Fix the code, not the test, unless the test is wrong.
