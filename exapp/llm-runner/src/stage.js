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
 * The tail of a child's output, for an error message.
 *
 * git puts the reason on its LAST lines and progress on everything before, so
 * the tail is the informative end. Bounded because this travels into a flow
 * run's failure text.
 *
 * @param {string} output The collected output.
 * @param {number} [lines] How many trailing lines to keep.
 * @returns {string} A single-line excerpt.
 */
function lastLines(output, lines = 3) {
    return String(output || '')
        .split('\n')
        .map((line) => line.replace(/\r.*$/, '').trim())
        .filter((line) => line !== '')
        .slice(-lines)
        .join(' | ')
        .slice(0, 400);
}

/**
 * Clone a repository at a ref into a directory.
 *
 * Shared by the target tree and the tool tree so both get the same treatment —
 * notably the remote-only-ref fallback, which is the kind of thing that gets
 * fixed in one caller and not the other.
 *
 * @param {object} args `{url, ref, into, scratch, env, deadline, label}`.
 * @returns {Promise<void>} Resolves when the tree is checked out.
 */
async function cloneAt({ url, ref, into, scratch, env, deadline, label }) {
    // NOT a shallow clone. The gates diff against a base, and with no history
    // `--scope-to-diff` sees an empty change set and reports zero failures —
    // indistinguishable from a clean run. Depth is the one economy not worth
    // making.
    const clone = await spawnCollect('git', ['clone', '--no-tags', url, into], {
        cwd: scratch,
        env,
        timeoutMs: deadline,
    });

    if (clone.code !== 0) {
        // Carry git's OWN words. `clone failed (exit 128)` names the one thing
        // the caller already knows and withholds the only thing that identifies
        // the cause — a missing credential, a blocked host and a bad ref all
        // exit 128 and are indistinguishable without this.
        //
        // Bounded, and the token is never in this output: it reaches git
        // through GIT_ASKPASS, so git echoes a username at most.
        throw new Error(`${label} clone failed (exit ${clone.code}): ${lastLines(clone.output)}`);
    }

    if (ref === 'HEAD') {
        return;
    }

    // A branch name that exists only on the remote does NOT resolve for
    // `--detach`: after a clone, `development` is `origin/development` and only
    // the default branch has a local head. Measured — the first version of this
    // failed `checkout --detach development` on a repo whose default branch is
    // `main`, with an error that read exactly like a bad ref.
    //
    // A sha or tag resolves directly, so try the ref as given first and fall
    // back to the remote-tracking name rather than assuming either.
    let checkout = await spawnCollect('git', ['checkout', '--detach', ref], {
        cwd: into,
        env,
        timeoutMs: deadline,
    });

    if (checkout.code !== 0) {
        checkout = await spawnCollect('git', ['checkout', '--detach', `origin/${ref}`], {
            cwd: into,
            env,
            timeoutMs: deadline,
        });
    }

    if (checkout.code !== 0) {
        throw new Error(
            `${label} checkout of "${ref}" failed (exit ${checkout.code}) — tried "${ref}" `
            + `and "origin/${ref}": ${lastLines(checkout.output)}`
        );
    }
}

/**
 * Extract a base64 tool archive and return the directory to resolve commands in.
 *
 * A forge tarball wraps everything in a single `owner-repo-sha/` directory, so
 * `--strip-components=1` is what makes `scripts/run-hydra-gates.sh` mean the
 * same thing whether the tool arrived as an archive or a clone. Without it every
 * caller would have to know the sha, which is exactly the coupling the stage
 * exists to remove.
 *
 * @param {object} args `{base64, scratch, env, deadline}`.
 * @returns {Promise<string>} The extracted tool root.
 */
async function extractToolArchive({ base64, scratch, env, deadline }) {
    const root = path.join(scratch, 'tool');
    const archive = path.join(scratch, 'tool.tar.gz');

    fs.mkdirSync(root, { recursive: true });
    fs.writeFileSync(archive, Buffer.from(base64, 'base64'), { mode: 0o600 });

    const extracted = await spawnCollect(
        'tar',
        ['-xzf', archive, '-C', root, '--strip-components=1'],
        { cwd: scratch, env, timeoutMs: deadline }
    );

    // The archive is removed whether or not extraction worked. It is the
    // largest thing in the scratch tree and there is no reason to keep it once
    // its contents are on disk.
    try {
        fs.rmSync(archive, { force: true });
    } catch (err) {
        // Non-fatal: the whole scratch tree is removed on the way out anyway.
    }

    if (extracted.code !== 0) {
        throw new Error(`tool archive could not be extracted (exit ${extracted.code}): ${lastLines(extracted.output)}`);
    }

    return root;
}

/**
 * Read back files the command produced, before the scratch tree is removed.
 *
 * Each path is resolved INSIDE the clone and refused if it escapes — a caller
 * able to name `../../etc/passwd` would turn a stage result into an arbitrary
 * file read, and the caller here is authored flow config.
 *
 * A missing file is recorded as null rather than omitted. "The reviewer wrote no
 * findings" and "the key is absent because something went wrong" are different
 * facts, and a consumer that cannot tell them apart will read the second as the
 * first — which is the failure this whole endpoint keeps being bitten by.
 *
 * @param {object} args `{paths, workdir}`.
 * @returns {object} A map of path to contents, or null where absent.
 */
function readCollected({ paths, workdir }) {
    const isList = Array.isArray(paths);
    const isMap  = (paths !== null && typeof paths === 'object' && isList === false);
    if ((isList === false && isMap === false) || (isList === true && paths.length === 0)) {
        return {};
    }

    const root = path.resolve(workdir);
    const out = {};

    // An ARRAY keys each file by its own path; an OBJECT keys it by an ALIAS.
    //
    // The alias form exists because a flow addresses data with a dotted path,
    // and a file path is not one: `files.reviews/latest.json.code_review` is
    // unresolvable — the key contains both dots and slashes, so every traversal
    // splits it in the wrong places. Collecting a file a flow then cannot reach
    // is the whole feature failing quietly at the last step.
    //
    // So `collect: {findings: "reviews/latest.json"}` yields `files.findings`,
    // which a flow can walk.
    const entries = (Array.isArray(paths) === true)
        ? paths.map((p) => [String(p), String(p)])
        : Object.entries(paths).map(([alias, p]) => [String(alias), String(p)]);

    for (const [name, requested] of entries) {
        const resolved = path.resolve(root, requested);

        // `startsWith(root + sep)` and not `startsWith(root)`: the latter admits
        // a sibling directory whose name merely begins with the same characters.
        if (resolved.startsWith(root + path.sep) === false) {
            throw new Error(`collect path escapes the clone: ${name}`);
        }

        try {
            const raw = fs.readFileSync(resolved, 'utf8').slice(0, MAX_OUTPUT_BYTES);

            // A `.json` file is PARSED, because the consumer is a flow and a
            // flow cannot address into a string. hydra's reviewer writes
            // `{code_review: {findings: [...]}}`; handing that back as text
            // means the one thing anybody wants to do with it — iterate the
            // findings and file an issue each — is impossible without a node
            // that parses JSON, which does not exist.
            //
            // Unparseable JSON stays TEXT rather than becoming null: the file
            // was found and has contents, and reporting "absent" for "present
            // but malformed" is the same conflation this function's null
            // handling exists to avoid. The consumer can then see what it
            // actually got.
            out[name] = raw;
            if (requested.endsWith('.json') === true) {
                try {
                    out[name] = JSON.parse(raw);
                } catch (err) {
                    // Intentionally keeps the text. See above.
                }
            }
        } catch (err) {
            out[name] = null;
        }
    }

    return out;
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
 * @param {string} args.repo Clone URL of the tree the command runs OVER.
 * @param {string} args.ref The ref to check out.
 * @param {Array<string>} args.command The command and arguments.
 * @param {string} [args.toolRepo] Clone URL of the tree the COMMAND comes from, when it is
 *                                 not the target — hydra's gate runner is the case this
 *                                 exists for. Omit and the command comes from the target.
 * @param {string} [args.toolRef] Ref for the tool tree; its default branch when omitted.
 * @param {Array<string>} [args.collect] Paths inside the clone to read back after the command,
 *                                       returned as `files`. The scratch tree is deleted on the
 *                                       way out, so anything the command wrote is otherwise lost.
 * @param {string} [args.toolTarball] The tool tree as a base64 `.tar.gz`, for a PRIVATE tool
 *                                    tree whose credential must not leave OpenRegister. The
 *                                    broker fetches it server-side; only bytes arrive here.
 *                                    Takes precedence over `toolRepo`.
 * @param {string} [args.forgeToken] Token for the clones. Reaches git via GIT_ASKPASS only.
 * @param {string} [args.forgeUser] Username half of the clone URL. Not a secret.
 * @param {number} [args.timeoutMs] Overall ceiling.
 * @param {object} [args.env] Extra non-secret env for the command.
 * @returns {Promise<{exitCode: number, output: string, ref: string}>} The result.
 */
async function runStage({
    repo,
    ref,
    command,
    toolRepo,
    toolRef,
    toolTarball,
    collect,
    forgeToken,
    forgeUser,
    timeoutMs,
    env,
}) {
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

        const cloneUrl = (typeof forgeUser === 'string' && forgeUser !== '')
            ? repo.replace('://', `://${forgeUser}@`)
            : repo;

        await cloneAt({
            url: cloneUrl,
            ref,
            into: workdir,
            scratch,
            env: childEnv,
            deadline,
            label: 'repo',
        });

        // THE TOOL TREE — why a stage may need TWO clones.
        //
        // hydra's gate runner takes the tree it gates as an argument and
        // resolves its own 20 python helpers relative to `BASH_SOURCE`, i.e.
        // out of hydra's `scripts/lib`. So gating an app needs hydra's scripts
        // AND the app's tree at once, and a stage that clones one repo and runs
        // a command from inside it can express only half of that.
        //
        // Measured against `run-hydra-gates.sh`: `APP_DIR` defaults to `pwd`,
        // so cloning the tool tree separately and running its command with the
        // TARGET as the working directory gates the target with the tool's
        // helpers — no argument juggling, and the default does the right thing.
        //
        // Without this the only workable arrangement is every target repo
        // vendoring a copy of the gate runner, which is 3,599 lines duplicated
        // per app and guaranteed to drift.
        let commandRoot = workdir;

        // THE TOOL TREE AS AN ARCHIVE — how a PRIVATE tool tree gets here
        // without its credential ever leaving OpenRegister.
        //
        // The broker holds a host-locked proxy credential: `resolveInjectable()`
        // returns null for it by design, so no token can be handed to this
        // container for `git clone`. But the broker CAN fetch, and
        // `GET /repos/*/tarball/*` is already covered by its existing
        // `GET /repos/*` rule — so OpenRegister fetches the archive server-side
        // and passes the BYTES.
        //
        // The objection to a tarball is that it has no git history, so
        // `--scope-to-diff` would diff against nothing and report zero failures.
        // That objection is about the TARGET, and it does not apply here: the
        // tool tree is scripts. The target is cloned normally, with its full
        // history, and needs no credential because the repositories being gated
        // are public. Splitting the two is what makes the credential question
        // go away rather than get traded off.
        if (typeof toolTarball === 'string' && toolTarball !== '') {
            commandRoot = await extractToolArchive({
                base64: toolTarball,
                scratch,
                env: childEnv,
                deadline,
            });
        } else if (typeof toolRepo === 'string' && toolRepo !== '') {
            commandRoot = path.join(scratch, 'tool');
            const toolUrl = (typeof forgeUser === 'string' && forgeUser !== '')
                ? toolRepo.replace('://', `://${forgeUser}@`)
                : toolRepo;

            await cloneAt({
                url: toolUrl,
                ref: (typeof toolRef === 'string' && toolRef !== '' ? toolRef : 'HEAD'),
                into: commandRoot,
                scratch,
                env: childEnv,
                deadline,
                label: 'tool repo',
            });
        }

        const [bin, ...rest] = command;

        // The command is resolved inside the TOOL tree when there is one, and
        // runs with the TARGET as its working directory. A bare name (`sh`)
        // stays a PATH lookup — only a repo-relative path is rebased, which is
        // what the allowlist admits anyway.
        const executable = (bin.includes('/') === true ? path.join(commandRoot, bin) : bin);
        const result = await spawnCollect(executable, rest, {
            cwd: workdir,
            env: childEnv,
            timeoutMs: deadline,
        });

        // FILES THE COMMAND PRODUCED, read back before the scratch tree goes.
        //
        // This is the half of "the workload has a filesystem, the flow does
        // not" that was missing. hydra's reviewer writes its findings to a JSON
        // file and `create_finding_issues` reads them back — but that file lives
        // in the clone, which is deleted moments later, so a flow could never
        // see it. Without this the pipeline can RUN a review and still have no
        // way to act on what it found.
        //
        // Paths are resolved inside the clone and refused if they escape it: a
        // caller that could name `../../etc/passwd` would turn a stage result
        // into an arbitrary file read.
        return {
            exitCode: result.code,
            output: result.output,
            ref,
            files: readCollected({ paths: collect, workdir }),
        };
    } finally {
        // The token lives in the child env and the askpass helper lives in the
        // scratch dir; removing the tree is what stops the second outliving the
        // run.
        cleanup(scratch);
    }
}

module.exports = { runStage, isAllowedCommand, ALLOWED_COMMANDS };
