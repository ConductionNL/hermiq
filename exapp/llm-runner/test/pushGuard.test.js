/**
 * The push fences, exercised as functions.
 *
 * These are unit tests and they are NOT the proof. A guard function that refuses
 * everything it is handed proves nothing about whether the guard is WIRED — that
 * is `stage.push.test.js`'s job, and it is the distinction hermiq#96's iptables
 * jail died on: every assertion about it was a grep over its source, so a green
 * suite said nothing about whether it could even start.
 *
 * What these DO prove is the part a live run cannot: that each rule refuses for
 * its OWN reason. A live push to `main` is refused by three independent things
 * (the branch rule, the forge ruleset, the credential's repo scope), so a live
 * refusal cannot tell you which one fired — and if two of them were broken, the
 * observation would look identical.
 *
 * Every negative test therefore asserts the refusal CODE, never the message.
 *
 * Run: `node --test test/pushGuard.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');

const {
    PushRefused,
    repoIdentity,
    assertSameRepository,
    assertBranchAllowed,
    assertDiffAllowed,
    assertPushAllowed,
} = require('../src/pushGuard');

/**
 * Assert that a call refuses with a specific code.
 *
 * @param {Function} fn   The call.
 * @param {string}   code The expected refusal code.
 * @param {string}   what What is being asserted, for the failure message.
 * @returns {void}
 */
function refuses(fn, code, what) {
    let thrown = null;
    try {
        fn();
    } catch (err) {
        thrown = err;
    }

    assert.ok(thrown !== null, `${what}: expected a refusal, got none`);
    assert.ok(thrown instanceof PushRefused, `${what}: expected PushRefused, got ${thrown.name}`);
    assert.strictEqual(thrown.code, code, `${what}: wrong refusal code (message was: ${thrown.message})`);
}

// ─────────────────────────────────────────────────────────────────────────────
// POSITIVE CONTROLS FIRST.
//
// Deliberately at the top. A guard that refuses everything passes every negative
// test in this file, and a suite whose first fifty assertions are refusals will
// happily certify one. If these break, nothing below means anything.
// ─────────────────────────────────────────────────────────────────────────────

test('the legitimate push clears every fence', () => {
    assert.doesNotThrow(() => assertPushAllowed({
        pushUrl: 'https://github.com/ConductionNL/hydra',
        allowedUrl: 'https://github.com/ConductionNL/hydra',
        branch: 'feature/493/builder-write-access',
        issue: 493,
        files: ['lib/Service/Thing.php', 'tests/Unit/ThingTest.php'],
        scope: ['lib', 'tests'],
    }));
});

test('a legitimate push with no declared scope still clears', () => {
    // An absent scope disables the SCOPE rule only. It must not be a way to
    // switch the whole gate off — see the forbidden-prefix test below, which
    // runs with no scope too.
    assert.doesNotThrow(() => assertPushAllowed({
        pushUrl: 'https://github.com/ConductionNL/hydra',
        allowedUrl: 'https://github.com/ConductionNL/hydra',
        branch: 'feature/1/x',
        issue: '1',
        files: ['anywhere/at/all.php'],
        scope: [],
    }));
});

// ─────────────────────────────────────────────────────────────────────────────
// Repository identity
// ─────────────────────────────────────────────────────────────────────────────

test('repoIdentity normalises the forms the same repository is written in', () => {
    const canonical = 'github.com/conductionnl/hydra';

    assert.strictEqual(repoIdentity('https://github.com/ConductionNL/hydra'), canonical);
    assert.strictEqual(repoIdentity('https://github.com/ConductionNL/hydra.git'), canonical);
    assert.strictEqual(repoIdentity('https://github.com/ConductionNL/hydra/'), canonical);
    assert.strictEqual(repoIdentity('https://x-access-token@github.com/ConductionNL/hydra'), canonical);
    assert.strictEqual(repoIdentity('https://GITHUB.COM/conductionnl/HYDRA'), canonical);
});

test('repoIdentity returns null rather than guessing', () => {
    assert.strictEqual(repoIdentity(''), null);
    assert.strictEqual(repoIdentity(undefined), null);
    assert.strictEqual(repoIdentity('not a url'), null);
    // scp-like syntax is not a shape this runner produces or accepts.
    assert.strictEqual(repoIdentity('git@github.com:ConductionNL/hydra.git'), null);
    assert.strictEqual(repoIdentity('https://github.com'), null);
});

test('a push to a different repository is refused', () => {
    refuses(
        () => assertSameRepository(
            'https://github.com/attacker/exfil',
            'https://github.com/ConductionNL/hydra'
        ),
        'repo_not_allowed',
        'a different owner/name'
    );
});

test('a push to the same path on a different HOST is refused', () => {
    // The trap a path-only comparison walks into. `evil.example/ConductionNL/hydra`
    // has the same owner and name and is somebody else's server entirely.
    refuses(
        () => assertSameRepository(
            'https://evil.example/ConductionNL/hydra',
            'https://github.com/ConductionNL/hydra'
        ),
        'repo_not_allowed',
        'same path, different host'
    );
});

test('an unreadable allowlist entry fails CLOSED', () => {
    refuses(
        () => assertSameRepository('https://github.com/ConductionNL/hydra', ''),
        'allowed_repo_unreadable',
        'empty allowed repo'
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// Branch allowlist
// ─────────────────────────────────────────────────────────────────────────────

test('a push to a protected branch is refused, by name', () => {
    for (const branch of ['main', 'master', 'development', 'develop', 'beta']) {
        refuses(() => assertBranchAllowed(branch, 493), 'protected_branch', branch);
    }
});

test('a push to another issue’s branch is refused', () => {
    refuses(
        () => assertBranchAllowed('feature/91/whatever', 493),
        'branch_not_allowed',
        'a branch belonging to a different issue'
    );
});

test('a fully-qualified ref is refused rather than normalised', () => {
    // Normalising `refs/heads/main` down to `main` would work, but it would also
    // mean the protected-branch list depends on a normalisation step being
    // correct. Refusing the shape outright removes that dependency.
    refuses(() => assertBranchAllowed('refs/heads/main', 493), 'qualified_ref', 'refs/heads/main');
    refuses(() => assertBranchAllowed('refs/tags/v1', 493), 'qualified_ref', 'a tag ref');
});

test('a branch that merely CONTAINS the allowed pattern is refused', () => {
    // The anchoring test. An unanchored regex admits every one of these.
    refuses(() => assertBranchAllowed('evil/feature/493/x', 493), 'branch_not_allowed', 'prefixed');
    refuses(() => assertBranchAllowed('feature/493/x/../../main', 493), 'branch_not_allowed', 'traversal');
    refuses(() => assertBranchAllowed('feature/4930/x', 493), 'branch_not_allowed', 'issue number prefix');
    refuses(() => assertBranchAllowed('feature/493/x:refs/heads/main', 493), 'branch_not_allowed', 'refspec smuggling');
});

test('a stage with no issue number cannot push anywhere', () => {
    // Without an issue there is no scope, so the pattern would degrade to
    // `feature/<anything>/<anything>`. Fail closed instead.
    refuses(() => assertBranchAllowed('feature/493/x', ''), 'issue_missing', 'no issue');
    refuses(() => assertBranchAllowed('feature/493/x', undefined), 'issue_missing', 'undefined issue');
    refuses(() => assertBranchAllowed('feature/493/x', 'abc'), 'issue_missing', 'non-numeric issue');
});

test('the allowed branch shape is accepted', () => {
    assert.doesNotThrow(() => assertBranchAllowed('feature/493/builder-write-access', 493));
    assert.doesNotThrow(() => assertBranchAllowed('feature/493/a', '493'));
    assert.doesNotThrow(() => assertBranchAllowed('feature/7/fix.thing_2', 7));
});

// ─────────────────────────────────────────────────────────────────────────────
// Diff gate
// ─────────────────────────────────────────────────────────────────────────────

test('a diff touching .github/workflows is refused', () => {
    refuses(
        () => assertDiffAllowed(['.github/workflows/release.yml'], []),
        'workflow_definition',
        'a workflow definition'
    );
});

test('a NEW workflow file is refused (the untracked case)', () => {
    // This is the one a `git diff`-only change set would miss entirely: a file
    // the builder creates is untracked, so it never appears in a diff. The path
    // rule is identical; what matters is that `changedFiles()` produces it, which
    // `stage.push.test.js` proves against a real repository.
    refuses(
        () => assertDiffAllowed(['.github/workflows/pwn.yml'], []),
        'workflow_definition',
        'a new workflow definition'
    );
});

test('the forge’s other workflow dialect is refused too', () => {
    refuses(
        () => assertDiffAllowed(['.forgejo/workflows/hydra-build.yml'], []),
        'workflow_definition',
        'a forgejo workflow'
    );
    refuses(
        () => assertDiffAllowed(['.github/actions/setup/action.yml'], []),
        'workflow_definition',
        'a composite action'
    );
});

test('a workflow edit is refused even when the declared scope allows it', () => {
    // Ordering matters: the absolute prohibitions run BEFORE the scope rule, so
    // an issue whose scope happens to be `.github` cannot buy its way in.
    refuses(
        () => assertDiffAllowed(['.github/workflows/x.yml'], ['.github']),
        'workflow_definition',
        'in-scope workflow edit'
    );
});

test('a dependency manifest is refused at any depth', () => {
    refuses(() => assertDiffAllowed(['composer.json'], []), 'dependency_manifest', 'root composer.json');
    refuses(() => assertDiffAllowed(['exapp/llm-runner/package.json'], []), 'dependency_manifest', 'nested package.json');
    refuses(() => assertDiffAllowed(['package-lock.json'], []), 'dependency_manifest', 'a lockfile');
    refuses(() => assertDiffAllowed(['src/go.mod'], []), 'dependency_manifest', 'go.mod');
});

test('a file whose name merely resembles a manifest is allowed', () => {
    // The other half of the manifest rule. A basename match must not catch
    // `package.json.dist` or `MyComposer.json`, which are ordinary files.
    assert.doesNotThrow(() => assertDiffAllowed(
        ['package.json.dist', 'docs/composer.json.md', 'src/MyPackage.json'],
        []
    ));
});

test('a change outside the declared scope is refused', () => {
    refuses(
        () => assertDiffAllowed(['lib/Service/A.php', 'src/Other.vue'], ['lib']),
        'out_of_scope',
        'a file outside scope'
    );
});

test('scope matches on directory boundaries, not string prefixes', () => {
    // `lib/Service` must not admit `lib/ServiceAccountKeys.php`.
    refuses(
        () => assertDiffAllowed(['lib/ServiceAccountKeys.php'], ['lib/Service']),
        'out_of_scope',
        'sibling with a shared prefix'
    );
    assert.doesNotThrow(() => assertDiffAllowed(['lib/Service/A.php'], ['lib/Service']));
    // An exact file in the scope list matches that file.
    assert.doesNotThrow(() => assertDiffAllowed(['README.md'], ['README.md']));
});

test('a path that climbs out of the tree is refused', () => {
    refuses(() => assertDiffAllowed(['../../etc/passwd'], []), 'path_escape', 'traversal');
    refuses(() => assertDiffAllowed(['/etc/passwd'], []), 'path_escape', 'absolute');
    refuses(() => assertDiffAllowed(['lib/../../x'], []), 'path_escape', 'embedded traversal');
});

test('an unreadable change set fails CLOSED', () => {
    // An empty array passes every rule. So "could not read the change set" must
    // never be represented as one.
    refuses(() => assertDiffAllowed(null, []), 'diff_unreadable', 'null change set');
    refuses(() => assertDiffAllowed('a b c', []), 'diff_unreadable', 'a string');
});

// ─────────────────────────────────────────────────────────────────────────────
// The composed gate
// ─────────────────────────────────────────────────────────────────────────────

test('the repository fence runs before the diff fence', () => {
    // Ordering is asserted rather than assumed: the cheapest and most total
    // check first means a wrong destination is never reported as a diff problem.
    refuses(
        () => assertPushAllowed({
            pushUrl: 'https://github.com/attacker/exfil',
            allowedUrl: 'https://github.com/ConductionNL/hydra',
            branch: 'main',
            issue: 493,
            files: ['.github/workflows/x.yml'],
            scope: [],
        }),
        'repo_not_allowed',
        'all three wrong at once'
    );
});

test('an injected instruction changes nothing — the same code refuses it', () => {
    // Prompt injection is not a separate control surface. Repository content
    // that talks a model into pushing elsewhere produces exactly the arguments
    // below, and the same function refuses them for the same reason. There is
    // no path from the model's intent to this decision.
    const asAnInjectedInstructionWouldProduce = {
        pushUrl: 'https://github.com/attacker/exfil',
        allowedUrl: 'https://github.com/ConductionNL/hydra',
        branch: 'feature/493/looks-legitimate',
        issue: 493,
        files: ['lib/Service/A.php'],
        scope: ['lib'],
    };

    refuses(
        () => assertPushAllowed(asAnInjectedInstructionWouldProduce),
        'repo_not_allowed',
        'an injected exfiltration target'
    );
});
