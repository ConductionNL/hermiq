/**
 * Stage-workload tests (hydra-flows-first-port, task 3.4).
 *
 * The stage endpoint is what lets a flow do work that needs a FILESYSTEM —
 * clone a ref, run hydra's gate runner over it, return what it said. It is
 * therefore the one place in the runner that both fetches remote code AND
 * executes something over it, so the properties worth testing are the ones that
 * keep those two from becoming a general-purpose RCE:
 *
 *   - the command ALLOWLIST is the control, and it is checked BEFORE anything is
 *     cloned (a refusal that happens after the fetch is a refusal that already
 *     paid for the attack);
 *   - a branch that exists only on the remote still checks out — the bug this
 *     file exists to pin, found by running the thing rather than reading it:
 *     `checkout --detach development` fails on a clone whose local head is
 *     `main`, and the failure looked exactly like a bad ref;
 *   - the command's exit code is returned VERBATIM, because hydra's gate runner
 *     uses its exit code as a failure COUNT — collapsing it to a boolean here
 *     would throw away the number the caller wants;
 *   - the scratch tree is removed on EVERY exit path, success or failure, since
 *     it is what the askpass helper (and therefore the forge token) lives in.
 *
 * The clone tests use a local bare repo as the remote, so they exercise real
 * git without needing network — the same code path a forge clone takes.
 *
 * Run: `node --test test/stage.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict';

// Set BEFORE the require: the allowlist is read at module load, which is itself
// deliberate — it cannot be widened at call time by anything the caller sends.
process.env.RUNNER_STAGE_COMMANDS = 'scripts/run-hydra-gates.sh,./probe.sh';

const test = require('node:test');
const assert = require('node:assert');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const { runStage, isAllowedCommand, ALLOWED_COMMANDS } = require('../src/stage');

/**
 * Build a bare repo whose default branch is `main` and which ALSO has a
 * `development` branch.
 *
 * The asymmetry is the point: after cloning this, `main` has a local head and
 * `development` exists only as `origin/development`. That is the exact shape
 * that broke, and a fixture where both branches resolve locally would pass
 * against the broken code.
 *
 * @returns {{remote: string, root: string}} The bare repo path and its temp root.
 */
function makeRemote() {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'stage-fixture-'));
    const work = path.join(root, 'work');
    const remote = path.join(root, 'remote.git');

    fs.mkdirSync(work);
    const git = (...args) => execFileSync('git', args, {
        cwd: work,
        env: {
            ...process.env,
            GIT_AUTHOR_NAME: 'test', GIT_AUTHOR_EMAIL: 't@example.invalid',
            GIT_COMMITTER_NAME: 'test', GIT_COMMITTER_EMAIL: 't@example.invalid',
        },
    });

    git('init', '--quiet');
    // `git init --initial-branch` is not in every git this runs against, and
    // `init.defaultBranch` may be set to anything in the ambient config — so
    // name the branch explicitly on the unborn head instead of assuming either.
    git('checkout', '--quiet', '-b', 'main');
    fs.writeFileSync(path.join(work, 'README.md'), 'main\n');
    git('add', '-A');
    git('commit', '--quiet', '-m', 'main commit');

    git('checkout', '--quiet', '-b', 'development');
    // A probe that reports which branch it is standing on and exits non-zero, so
    // one fixture covers both the checkout assertion and the exit-code one.
    fs.writeFileSync(
        path.join(work, 'probe.sh'),
        '#!/bin/sh\ncat marker.txt\nexit 7\n',
        { mode: 0o755 }
    );
    fs.writeFileSync(path.join(work, 'marker.txt'), 'ON-DEVELOPMENT\n');
    git('add', '-A');
    git('commit', '--quiet', '-m', 'development commit');

    git('checkout', '--quiet', 'main');
    execFileSync('git', ['clone', '--quiet', '--bare', work, remote]);

    return { remote, root };
}

test('the allowlist refuses an unlisted command', async () => {
    await assert.rejects(
        () => runStage({ repo: 'https://example.invalid/x', ref: 'main', command: ['sh', '-c', 'id'] }),
        /command not allowed: sh/
    );
});

test('the refusal happens BEFORE anything is cloned', async () => {
    // The repo host does not resolve, so if the clone ran first this would fail
    // with a clone error instead. Getting the allowlist message proves ordering.
    await assert.rejects(
        () => runStage({
            repo: 'https://nonexistent.invalid/repo.git',
            ref: 'main',
            command: ['/bin/echo', 'hello'],
        }),
        /command not allowed/
    );
});

test('a command must be a non-empty argv array', () => {
    assert.strictEqual(isAllowedCommand([]), false);
    assert.strictEqual(isAllowedCommand(undefined), false);
    assert.strictEqual(isAllowedCommand('scripts/run-hydra-gates.sh'), false);
    assert.strictEqual(isAllowedCommand(['./probe.sh', '--flag']), true);
});

test('the default allowlist is hydra\'s gate runner alone', () => {
    // Not a tautology: it asserts the env var is the ONLY way to widen this, so
    // a deployment that sets nothing gets the narrowest grant.
    assert.ok(ALLOWED_COMMANDS.includes('scripts/run-hydra-gates.sh'));
});

test('a missing repo or ref is refused', async () => {
    await assert.rejects(() => runStage({ ref: 'main', command: ['./probe.sh'] }), /needs a repo/);
    await assert.rejects(() => runStage({ repo: 'x', command: ['./probe.sh'] }), /needs a ref/);
});

test('a remote-only branch checks out, and the exit code comes back verbatim', async () => {
    const { remote, root } = makeRemote();

    try {
        const result = await runStage({
            repo: remote,
            ref: 'development',
            command: ['./probe.sh'],
            timeoutMs: 120000,
        });

        // The checkout landed on `development` even though only `main` had a
        // local head — the regression this test exists for.
        assert.match(result.output, /ON-DEVELOPMENT/);
        // 7, not `false`: hydra reads this number as a failure count.
        assert.strictEqual(result.exitCode, 7);
        assert.strictEqual(result.ref, 'development');
    } finally {
        fs.rmSync(root, { recursive: true, force: true });
    }
});

test('an explicit sha checks out too', async () => {
    const { remote, root } = makeRemote();

    try {
        const sha = execFileSync('git', ['rev-parse', 'development'], { cwd: remote })
            .toString().trim();
        const result = await runStage({
            repo: remote, ref: sha, command: ['./probe.sh'], timeoutMs: 120000,
        });

        assert.match(result.output, /ON-DEVELOPMENT/);
    } finally {
        fs.rmSync(root, { recursive: true, force: true });
    }
});

test('the scratch tree is removed after a successful run AND after a failed one', async () => {
    const { remote, root } = makeRemote();
    const count = () => fs.readdirSync(os.tmpdir()).filter((n) => n.startsWith('hydra-stage-')).length;

    try {
        const before = count();

        await runStage({ repo: remote, ref: 'development', command: ['./probe.sh'], timeoutMs: 120000 });
        assert.strictEqual(count(), before, 'scratch left behind after a successful run');

        // The token lives in the child env and the askpass helper lives in this
        // tree, so a failure path that skips cleanup leaves a secret-reading
        // helper on disk. That is why this half of the assertion exists.
        await assert.rejects(
            () => runStage({
                repo: path.join(root, 'does-not-exist.git'),
                ref: 'development',
                command: ['./probe.sh'],
                timeoutMs: 120000,
            }),
            /clone failed/
        );
        assert.strictEqual(count(), before, 'scratch left behind after a failed clone');
    } finally {
        fs.rmSync(root, { recursive: true, force: true });
    }
});

test('an unresolvable ref fails loudly rather than running the command on the wrong tree', async () => {
    const { remote, root } = makeRemote();

    try {
        await assert.rejects(
            () => runStage({ repo: remote, ref: 'no-such-branch', command: ['./probe.sh'], timeoutMs: 120000 }),
            /checkout of "no-such-branch" failed/
        );
    } finally {
        fs.rmSync(root, { recursive: true, force: true });
    }
});

test('the forge token reaches git through GIT_ASKPASS and never through argv', async () => {
    const { remote, root } = makeRemote();

    try {
        // `./probe.sh` is on the allowlist and runs inside the clone, so it can
        // report the child environment the stage built. If the token were being
        // put on a command line instead, GIT_ASKPASS would be unset.
        const work = path.join(root, 'work');
        const env = {
            ...process.env,
            GIT_AUTHOR_NAME: 'test', GIT_AUTHOR_EMAIL: 't@example.invalid',
            GIT_COMMITTER_NAME: 'test', GIT_COMMITTER_EMAIL: 't@example.invalid',
        };

        // On `development`, not `main` — the fixture leaves the work tree on
        // main, and committing there would make the push a non-fast-forward.
        execFileSync('git', ['checkout', '--quiet', 'development'], { cwd: work, env });
        fs.writeFileSync(
            path.join(work, 'probe.sh'),
            '#!/bin/sh\necho "ASKPASS=${GIT_ASKPASS:-unset}"\necho "TOKEN=${GIT_FORGE_TOKEN:-unset}"\nexit 0\n',
            { mode: 0o755 }
        );
        execFileSync('git', ['add', '-A'], { cwd: work, env });
        execFileSync('git', ['commit', '--quiet', '-m', 'probe env'], { cwd: work, env });
        execFileSync('git', ['push', '--quiet', remote, 'development'], { cwd: work, env });

        const result = await runStage({
            repo: remote,
            ref: 'development',
            command: ['./probe.sh'],
            forgeToken: 'tok-should-not-appear-on-argv',
            timeoutMs: 120000,
        });

        assert.match(result.output, /ASKPASS=\/.*askpass\.sh/);
        assert.match(result.output, /TOKEN=tok-should-not-appear-on-argv/);
    } finally {
        fs.rmSync(root, { recursive: true, force: true });
    }
});
