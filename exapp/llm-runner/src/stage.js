/**
 * Stage workload for the hermiq-llm-runner ExApp.
 *
 * The runner's other half. `runner.js` executes one LLM turn; this executes one
 * piece of work that needs a FILESYSTEM — clone a ref, run a command over it,
 * return what the command said.
 *
 * WHY THIS LIVES HERE RATHER THAN ANYWHERE ELSE
 * --------------------------------------------
 * hydra's builder, reviewer and security stages need a checked-out tree, a
 * `composer install` and a `node_modules`. The flow plane cannot give them one:
 * a governed agent turn runs with `--disallowedTools Bash,Read,Write,Edit,…` by
 * design, because the whole point of that transport is that the model reaches
 * the world only through governed tools.
 *
 * The alternatives all assume access the operator does not have. This product
 * is for people who have a Kubernetes environment with Nextcloud on it and know
 * how neither works — they did not install Nextcloud, did not set up the
 * cluster, and cannot reach either. So there is no host to run a container on,
 * no cluster credential to create a Job with, and no reason to assume a forge
 * account with CI minutes. What they have is Nextcloud, and this ExApp is the
 * one place inside it that already has a filesystem and a toolchain.
 *
 * THE SAME RULES AS THE LLM TURN, FOR THE SAME REASONS
 * ---------------------------------------------------
 *   - The forge token is injected via the child ENVIRONMENT only, and reaches
 *     git through `GIT_ASKPASS`. It is never on argv (the process table is
 *     world-readable), never written to a file that outlives the run, and never
 *     logged. `-c http.extraHeader=…` would have been shorter and puts the
 *     credential straight onto the process table.
 *   - The work happens in a throwaway scratch dir, removed on every exit path.
 *   - Egress is whatever the container already allows. In the hardened
 *     deployment that is the CONNECT proxy alone, which asks Hermiq's PDP about
 *     every connection — so "may this run clone that repo" is a policy decision
 *     in the same place every other egress decision is made. The workload
 *     fences itself; nothing here opens a hole.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict';

const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const DEFAULT_STAGE_TIMEOUT_MS = Number(process.env.RUNNER_STAGE_TIMEOUT_MS || String(30 * 60 * 1000));
const MAX_OUTPUT_BYTES = Number(process.env.RUNNER_MAX_OUTPUT_BYTES || String(8 * 1024 * 1024));

/**
 * Commands a stage is allowed to run.
 *
 * An ALLOWLIST rather than a free command string, because the caller of this
 * endpoint is a flow — authored data. A flow that could name any command would
 * make authoring a flow equivalent to remote code execution inside the ExApp,
 * which is a considerably larger grant than "hydra may run its own gates".
 *
 * Entries are matched against the FIRST token only; the rest of the argv is
 * passed through, so `--scope-to-diff --base origin/development` works without
 * the allowlist having to know about flags.
 *
 * @type {Array<string>}
 */
const ALLOWED_COMMANDS = (process.env.RUNNER_STAGE_COMMANDS || 'scripts/run-hydra-gates.sh')
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s !== '');

/**
 * Remove a scratch tree, best effort.
 *
 * @param {string} dir The directory.
 * @returns {void}
 */
function cleanup(dir) {
    try {
        fs.rmSync(dir, { recursive: true, force: true });
    } catch (err) {
        // A scratch dir that will not delete must not mask the stage's own
        // result — the caller needs the verdict far more than it needs this.
    }
}

/**
 * Write the askpass helper that feeds git the token from the environment.
 *
 * git asks an external program for credentials rather than taking them on the
 * command line. The program prints the secret; the secret itself stays in the
 * child's environment, which is the same posture the vendor CLIs get.
 *
 * @param {string} scratch The scratch dir.
 * @returns {string} Path to the helper.
 */
function writeAskpass(scratch) {
    const helper = path.join(scratch, 'askpass.sh');
    fs.writeFileSync(helper, '#!/bin/sh\nexec printf %s "$GIT_FORGE_TOKEN"\n', { mode: 0o700 });
    fs.chmodSync(helper, 0o700);

    return helper;
}

/**
 * Whether a command is one this runner will execute.
 *
 * @param {Array<string>} argv The command and its arguments.
 * @returns {boolean} True when allowed.
 */
function isAllowedCommand(argv) {
    if (!Array.isArray(argv) || argv.length === 0) {
        return false;
    }

    return ALLOWED_COMMANDS.includes(String(argv[0]));
}

/**
 * Spawn one child in a directory and collect its output.
 *
 * @param {string} bin The binary.
 * @param {Array<string>} args Its arguments.
 * @param {object} opts `{cwd, env, timeoutMs}`.
 * @returns {Promise<{code: number, output: string}>} Exit code and combined output.
 */
function spawnCollect(bin, args, { cwd, env, timeoutMs }) {
    return new Promise((resolve, reject) => {
        let child;
        try {
            child = spawn(bin, args, { cwd, env, stdio: ['ignore', 'pipe', 'pipe'] });
        } catch (err) {
            reject(new Error(`failed to spawn ${bin}: ${err.message}`));
            return;
        }

        let output = '';
        let bytes = 0;
        let timedOut = false;

        const collect = (chunk) => {
            bytes += chunk.length;
            if (bytes <= MAX_OUTPUT_BYTES) {
                output += chunk.toString('utf8');
            }
        };

        child.stdout.on('data', collect);
        child.stderr.on('data', collect);

        const timer = setTimeout(() => {
            timedOut = true;
            child.kill('SIGKILL');
        }, timeoutMs);

        child.on('error', (err) => {
            clearTimeout(timer);
            reject(new Error(`${bin} process error: ${err.message}`));
        });

        child.on('close', (code) => {
            clearTimeout(timer);
            if (timedOut) {
                reject(new Error(`${bin} timed out after ${timeoutMs}ms`));
                return;
            }

            resolve({ code: code === null ? -1 : code, output });
        });
    });
}

/**
 * Run one stage: clone a ref, execute a command over it, return the result.
 *
 * The result carries the command's EXIT CODE and its full output rather than a
 * pass/fail verdict, deliberately. hydra's gate runner uses its exit code as a
 * failure COUNT and prints a summary line only after every gate has been
 * reached — so "did it pass" is a question about the output, and only the caller
 * knows which line to look for. Deciding it here would bake one consumer's
 * convention into the transport.
 *
 * @param {object} args Stage arguments.
 * @param {string} args.repo Clone URL, e.g. `https://github.com/owner/name`.
 * @param {string} args.ref The ref to check out.
 * @param {Array<string>} args.command The command and arguments, relative to the clone.
 * @param {string} [args.forgeToken] Token for the clone. Reaches git via GIT_ASKPASS only.
 * @param {string} [args.forgeUser] Username half of the clone URL. Not a secret.
 * @param {number} [args.timeoutMs] Overall ceiling.
 * @param {object} [args.env] Extra non-secret env for the command.
 * @returns {Promise<{exitCode: number, output: string, ref: string}>} The result.
 */
async function runStage({ repo, ref, command, forgeToken, forgeUser, timeoutMs, env }) {
    if (typeof repo !== 'string' || repo === '') {
        throw new Error('a stage needs a repo');
    }

    if (typeof ref !== 'string' || ref === '') {
        throw new Error('a stage needs a ref');
    }

    if (!isAllowedCommand(command)) {
        // Naming the offending command is safe (it is not a secret) and is the
        // only way an author can tell this apart from a crash.
        throw new Error(
            `command not allowed: ${Array.isArray(command) ? command[0] : String(command)}. `
            + `Allowed: ${ALLOWED_COMMANDS.join(', ')}`
        );
    }

    const deadline = Number(timeoutMs) > 0 ? Number(timeoutMs) : DEFAULT_STAGE_TIMEOUT_MS;
    const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'hydra-stage-'));
    const workdir = path.join(scratch, 'repo');

    try {
        const childEnv = {
            PATH: process.env.PATH,
            HOME: scratch,
            // Never prompt. Without this a missing credential HANGS until the
            // timeout instead of failing in a way the caller can read.
            GIT_TERMINAL_PROMPT: '0',
            ...(env && typeof env === 'object' ? env : {}),
        };

        // Carry the proxy vars through: in the hardened deployment they are the
        // container's ONLY route out, and a clone without them simply cannot
        // reach the forge.
        for (const name of ['HTTPS_PROXY', 'HTTP_PROXY', 'https_proxy', 'http_proxy']) {
            if (process.env[name]) {
                childEnv[name] = process.env[name];
            }
        }

        if (typeof forgeToken === 'string' && forgeToken !== '') {
            childEnv.GIT_FORGE_TOKEN = forgeToken;
            childEnv.GIT_ASKPASS = writeAskpass(scratch);
        }

        // A shallow clone would be faster and is WRONG here: the gates diff
        // against a base, and with no history `--scope-to-diff` sees an empty
        // change set and reports zero failures — indistinguishable from a clean
        // run. Depth is the one economy not worth making.
        const cloneUrl = (typeof forgeUser === 'string' && forgeUser !== '')
            ? repo.replace('://', `://${forgeUser}@`)
            : repo;

        const clone = await spawnCollect(
            'git',
            ['clone', '--no-tags', cloneUrl, workdir],
            { cwd: scratch, env: childEnv, timeoutMs: deadline }
        );

        if (clone.code !== 0) {
            throw new Error(`clone failed (exit ${clone.code})`);
        }

        // A branch name that exists only on the remote does NOT resolve for
        // `--detach`: after a clone, `development` is `origin/development` and
        // only the default branch has a local head. Measured — the first
        // version of this failed `checkout --detach development` on a repo
        // whose default branch is `main`.
        //
        // A sha or tag resolves directly, so try the ref as given first and
        // fall back to the remote-tracking name rather than assuming either.
        let checkout = await spawnCollect(
            'git',
            ['checkout', '--detach', ref],
            { cwd: workdir, env: childEnv, timeoutMs: deadline }
        );

        if (checkout.code !== 0) {
            checkout = await spawnCollect(
                'git',
                ['checkout', '--detach', `origin/${ref}`],
                { cwd: workdir, env: childEnv, timeoutMs: deadline }
            );
        }

        if (checkout.code !== 0) {
            throw new Error(`checkout of "${ref}" failed (exit ${checkout.code}) — tried "${ref}" and "origin/${ref}"`);
        }

        const [bin, ...rest] = command;
        const result = await spawnCollect(bin, rest, { cwd: workdir, env: childEnv, timeoutMs: deadline });

        return { exitCode: result.code, output: result.output, ref };
    } finally {
        // The token lives in the child env and the askpass helper lives in the
        // scratch dir; removing the tree is what stops the second outliving the
        // run.
        cleanup(scratch);
    }
}

module.exports = { runStage, isAllowedCommand, ALLOWED_COMMANDS };
