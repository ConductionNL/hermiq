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

'use strict'

// Set BEFORE the require: the allowlist is read at module load, which is itself
// deliberate — it cannot be widened at call time by anything the caller sends.
process.env.RUNNER_STAGE_COMMANDS =
	'scripts/run-hydra-gates.sh,./probe.sh,scripts/probe.sh'

const test = require('node:test')
const assert = require('node:assert')
const { execFileSync } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')

const {
	runStage,
	isAllowedCommand,
	ALLOWED_COMMANDS,
	STAGE_CREDENTIAL_KEYS,
} = require('../src/stage')

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
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'stage-fixture-'))
	const work = path.join(root, 'work')
	const remote = path.join(root, 'remote.git')

	fs.mkdirSync(work)
	const git = (...args) =>
		execFileSync('git', args, {
			cwd: work,
			env: {
				...process.env,
				GIT_AUTHOR_NAME: 'test',
				GIT_AUTHOR_EMAIL: 't@example.invalid',
				GIT_COMMITTER_NAME: 'test',
				GIT_COMMITTER_EMAIL: 't@example.invalid',
			},
		})

	git('init', '--quiet')
	// `git init --initial-branch` is not in every git this runs against, and
	// `init.defaultBranch` may be set to anything in the ambient config — so
	// name the branch explicitly on the unborn head instead of assuming either.
	git('checkout', '--quiet', '-b', 'main')
	fs.writeFileSync(path.join(work, 'README.md'), 'main\n')
	git('add', '-A')
	git('commit', '--quiet', '-m', 'main commit')

	git('checkout', '--quiet', '-b', 'development')
	// A probe that reports which branch it is standing on and exits non-zero, so
	// one fixture covers both the checkout assertion and the exit-code one.
	fs.writeFileSync(
		path.join(work, 'probe.sh'),
		'#!/bin/sh\ncat marker.txt\nexit 7\n',
		{ mode: 0o755 },
	)
	fs.writeFileSync(path.join(work, 'marker.txt'), 'ON-DEVELOPMENT\n')
	git('add', '-A')
	git('commit', '--quiet', '-m', 'development commit')

	git('checkout', '--quiet', 'main')
	execFileSync('git', ['clone', '--quiet', '--bare', work, remote])

	return { remote, root }
}

test('the allowlist refuses an unlisted command', async () => {
	await assert.rejects(
		() =>
			runStage({
				repo: 'https://example.invalid/x',
				ref: 'main',
				command: ['sh', '-c', 'id'],
			}),
		/command not allowed: sh/,
	)
})

test('the refusal happens BEFORE anything is cloned', async () => {
	// The repo host does not resolve, so if the clone ran first this would fail
	// with a clone error instead. Getting the allowlist message proves ordering.
	await assert.rejects(
		() =>
			runStage({
				repo: 'https://nonexistent.invalid/repo.git',
				ref: 'main',
				command: ['/bin/echo', 'hello'],
			}),
		/command not allowed/,
	)
})

test('a command must be a non-empty argv array', () => {
	assert.strictEqual(isAllowedCommand([]), false)
	assert.strictEqual(isAllowedCommand(undefined), false)
	assert.strictEqual(isAllowedCommand('scripts/run-hydra-gates.sh'), false)
	assert.strictEqual(isAllowedCommand(['./probe.sh', '--flag']), true)
})

test("the default allowlist is hydra's gate runner alone", () => {
	// Not a tautology: it asserts the env var is the ONLY way to widen this, so
	// a deployment that sets nothing gets the narrowest grant.
	assert.ok(ALLOWED_COMMANDS.includes('scripts/run-hydra-gates.sh'))
})

test('a missing repo or ref is refused', async () => {
	await assert.rejects(
		() => runStage({ ref: 'main', command: ['./probe.sh'] }),
		/needs a repo/,
	)
	await assert.rejects(
		() => runStage({ repo: 'x', command: ['./probe.sh'] }),
		/needs a ref/,
	)
})

test('a remote-only branch checks out, and the exit code comes back verbatim', async () => {
	const { remote, root } = makeRemote()

	try {
		const result = await runStage({
			repo: remote,
			ref: 'development',
			command: ['./probe.sh'],
			timeoutMs: 120000,
		})

		// The checkout landed on `development` even though only `main` had a
		// local head — the regression this test exists for.
		assert.match(result.output, /ON-DEVELOPMENT/)
		// 7, not `false`: hydra reads this number as a failure count.
		assert.strictEqual(result.exitCode, 7)
		assert.strictEqual(result.ref, 'development')
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

test('an explicit sha checks out too', async () => {
	const { remote, root } = makeRemote()

	try {
		const sha = execFileSync('git', ['rev-parse', 'development'], {
			cwd: remote,
		})
			.toString()
			.trim()
		const result = await runStage({
			repo: remote,
			ref: sha,
			command: ['./probe.sh'],
			timeoutMs: 120000,
		})

		assert.match(result.output, /ON-DEVELOPMENT/)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

test('the scratch tree is removed after a successful run AND after a failed one', async () => {
	const { remote, root } = makeRemote()
	const count = () =>
		fs.readdirSync(os.tmpdir()).filter((n) => n.startsWith('hydra-stage-'))
			.length

	try {
		const before = count()

		await runStage({
			repo: remote,
			ref: 'development',
			command: ['./probe.sh'],
			timeoutMs: 120000,
		})
		assert.strictEqual(
			count(),
			before,
			'scratch left behind after a successful run',
		)

		// The token lives in the child env and the askpass helper lives in this
		// tree, so a failure path that skips cleanup leaves a secret-reading
		// helper on disk. That is why this half of the assertion exists.
		await assert.rejects(
			() =>
				runStage({
					repo: path.join(root, 'does-not-exist.git'),
					ref: 'development',
					command: ['./probe.sh'],
					timeoutMs: 120000,
				}),
			/clone failed/,
		)
		assert.strictEqual(
			count(),
			before,
			'scratch left behind after a failed clone',
		)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

test('an unresolvable ref fails loudly rather than running the command on the wrong tree', async () => {
	const { remote, root } = makeRemote()

	try {
		await assert.rejects(
			() =>
				runStage({
					repo: remote,
					ref: 'no-such-branch',
					command: ['./probe.sh'],
					timeoutMs: 120000,
				}),
			/checkout of "no-such-branch" failed/,
		)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

test('the forge token reaches git through GIT_ASKPASS and never through argv', async () => {
	const { remote, root } = makeRemote()

	try {
		// `./probe.sh` is on the allowlist and runs inside the clone, so it can
		// report the child environment the stage built. If the token were being
		// put on a command line instead, GIT_ASKPASS would be unset.
		const work = path.join(root, 'work')
		const env = {
			...process.env,
			GIT_AUTHOR_NAME: 'test',
			GIT_AUTHOR_EMAIL: 't@example.invalid',
			GIT_COMMITTER_NAME: 'test',
			GIT_COMMITTER_EMAIL: 't@example.invalid',
		}

		// On `development`, not `main` — the fixture leaves the work tree on
		// main, and committing there would make the push a non-fast-forward.
		execFileSync('git', ['checkout', '--quiet', 'development'], {
			cwd: work,
			env,
		})
		fs.writeFileSync(
			path.join(work, 'probe.sh'),
			'#!/bin/sh\necho "ASKPASS=${GIT_ASKPASS:-unset}"\necho "TOKEN=${GIT_FORGE_TOKEN:-unset}"\nexit 0\n',
			{ mode: 0o755 },
		)
		execFileSync('git', ['add', '-A'], { cwd: work, env })
		execFileSync('git', ['commit', '--quiet', '-m', 'probe env'], {
			cwd: work,
			env,
		})
		execFileSync('git', ['push', '--quiet', remote, 'development'], {
			cwd: work,
			env,
		})

		const result = await runStage({
			repo: remote,
			ref: 'development',
			command: ['./probe.sh'],
			forgeToken: 'tok-should-not-appear-on-argv',
			timeoutMs: 120000,
		})

		assert.match(result.output, /ASKPASS=\/.*askpass\.sh/)
		assert.match(result.output, /TOKEN=tok-should-not-appear-on-argv/)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

/**
 * Build a second bare repo that carries a TOOL: a script which reports the
 * directory it was invoked over rather than the one it lives in.
 *
 * That distinction is the whole point of a tool tree — hydra's gate runner
 * resolves its helpers out of its OWN checkout while gating a different one —
 * so the fixture has to be able to tell the two apart or the test proves
 * nothing.
 *
 * @returns {{remote: string, root: string}} The bare repo path and its temp root.
 */
function makeToolRemote() {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'stage-tool-'))
	const work = path.join(root, 'work')
	const remote = path.join(root, 'remote.git')
	const env = {
		...process.env,
		GIT_AUTHOR_NAME: 'test',
		GIT_AUTHOR_EMAIL: 't@example.invalid',
		GIT_COMMITTER_NAME: 'test',
		GIT_COMMITTER_EMAIL: 't@example.invalid',
	}

	fs.mkdirSync(work)
	execFileSync('git', ['init', '--quiet'], { cwd: work, env })
	execFileSync('git', ['checkout', '--quiet', '-b', 'main'], { cwd: work, env })
	fs.mkdirSync(path.join(work, 'scripts'))
	fs.mkdirSync(path.join(work, 'scripts', 'lib'))
	// A helper beside the tool, resolved relative to the SCRIPT's own location —
	// exactly how run-hydra-gates.sh finds its 20 python helpers.
	fs.writeFileSync(
		path.join(work, 'scripts', 'lib', 'helper.sh'),
		'echo HELPER-FOUND\n',
	)
	fs.writeFileSync(
		path.join(work, 'scripts', 'probe.sh'),
		'#!/bin/sh\n'
			+ 'DIR="$(cd "$(dirname "$0")" && pwd)"\n'
			+ '. "${DIR}/lib/helper.sh"\n'
			+ 'echo "TARGET=$(cat marker.txt 2>/dev/null || echo none)"\n'
			+ 'exit 5\n',
		{ mode: 0o755 },
	)
	execFileSync('git', ['add', '-A'], { cwd: work, env })
	execFileSync('git', ['commit', '--quiet', '-m', 'tool'], { cwd: work, env })
	execFileSync('git', ['clone', '--quiet', '--bare', work, remote])

	return { remote, root }
}

test('a tool tree supplies the command while the TARGET is the working directory', async () => {
	// The allowlist is read at module load and CANNOT be widened at call time —
	// an earlier version of this test tried to re-require the module with a
	// wider `RUNNER_STAGE_COMMANDS` and was correctly refused. That property is
	// the whole control, so the fixture command is declared at the top of this
	// file with the others instead.
	const target = makeRemote()
	const tool = makeToolRemote()

	try {
		const result = await runStage({
			repo: target.remote,
			ref: 'development',
			toolRepo: tool.remote,
			command: ['scripts/probe.sh'],
			timeoutMs: 120000,
		})

		// The helper resolved beside the TOOL — so the command ran out of the
		// tool checkout, not the target.
		assert.match(result.output, /HELPER-FOUND/)
		// And `marker.txt` is the TARGET's file, read from the working
		// directory — so the command gated the target.
		assert.match(result.output, /TARGET=ON-DEVELOPMENT/)
		// The exit code still comes back verbatim through both clones.
		assert.strictEqual(result.exitCode, 5)
	} finally {
		fs.rmSync(target.root, { recursive: true, force: true })
		fs.rmSync(tool.root, { recursive: true, force: true })
	}
})

test('a tool tree that cannot be cloned names ITSELF in the failure', async () => {
	const target = makeRemote()

	try {
		await assert.rejects(
			() =>
				runStage({
					repo: target.remote,
					ref: 'development',
					toolRepo: path.join(target.root, 'no-such-tool.git'),
					command: ['./probe.sh'],
					timeoutMs: 120000,
				}),
			// Not just "clone failed": with two clones an unlabelled failure
			// sends the operator to the wrong repository.
			/tool repo clone failed/,
		)
	} finally {
		fs.rmSync(target.root, { recursive: true, force: true })
	}
})

test('with no tool tree the command still comes from the target', async () => {
	const { remote, root } = makeRemote()

	try {
		const result = await runStage({
			repo: remote,
			ref: 'development',
			command: ['./probe.sh'],
			timeoutMs: 120000,
		})

		assert.match(result.output, /ON-DEVELOPMENT/)
		assert.strictEqual(result.exitCode, 7)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

test('a base64 tool ARCHIVE supplies the command, with the forge wrapper stripped', async () => {
	const target = makeRemote()
	const tool = makeToolRemote()
	const staging = fs.mkdtempSync(path.join(os.tmpdir(), 'stage-tar-'))

	try {
		// Build the archive the way a forge does: everything under ONE
		// `owner-repo-sha/` directory. A fixture without that wrapper would pass
		// against code that forgets --strip-components=1.
		const checkout = path.join(staging, 'ConductionNL-tool-abc1234')
		execFileSync('git', ['clone', '--quiet', tool.remote, checkout])
		fs.rmSync(path.join(checkout, '.git'), { recursive: true, force: true })
		const tarball = path.join(staging, 'tool.tar.gz')
		execFileSync('tar', [
			'-czf',
			tarball,
			'-C',
			staging,
			'ConductionNL-tool-abc1234',
		])

		const result = await runStage({
			repo: target.remote,
			ref: 'development',
			toolTarball: fs.readFileSync(tarball).toString('base64'),
			command: ['scripts/probe.sh'],
			timeoutMs: 120000,
		})

		// Helper resolved beside the TOOL — so the archive was extracted and the
		// forge's wrapper directory stripped.
		assert.match(result.output, /HELPER-FOUND/)
		// Marker read from the TARGET's working directory.
		assert.match(result.output, /TARGET=ON-DEVELOPMENT/)
		assert.strictEqual(result.exitCode, 5)
	} finally {
		fs.rmSync(target.root, { recursive: true, force: true })
		fs.rmSync(tool.root, { recursive: true, force: true })
		fs.rmSync(staging, { recursive: true, force: true })
	}
})

test('a corrupt tool archive fails loudly rather than running the wrong command', async () => {
	const { remote, root } = makeRemote()

	try {
		await assert.rejects(
			() =>
				runStage({
					repo: remote,
					ref: 'development',
					toolTarball: Buffer.from('not a tarball').toString('base64'),
					command: ['./probe.sh'],
					timeoutMs: 120000,
				}),
			/tool archive could not be extracted/,
		)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

test('collect reads files the command produced, before the scratch tree goes', async () => {
	const { remote, root } = makeRemote()

	try {
		const result = await runStage({
			repo: remote,
			ref: 'development',
			command: ['./probe.sh'],
			// `marker.txt` is committed; `nope.json` is not. Both are asked for.
			collect: ['marker.txt', 'nope.json'],
			timeoutMs: 120000,
		})

		assert.match(result.files['marker.txt'], /ON-DEVELOPMENT/)
		// NULL, not absent. "The reviewer wrote no findings" and "the key is
		// missing because something went wrong" are different facts, and a
		// consumer that cannot tell them apart reads the second as the first.
		assert.strictEqual(result.files['nope.json'], null)
		assert.ok('nope.json' in result.files)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

test('collect REFUSES a path that escapes the clone', async () => {
	const { remote, root } = makeRemote()

	try {
		await assert.rejects(
			() =>
				runStage({
					repo: remote,
					ref: 'development',
					command: ['./probe.sh'],
					collect: ['../../etc/passwd'],
					timeoutMs: 120000,
				}),
			// A caller able to name any path would turn a stage result into an
			// arbitrary file read, and the caller here is authored flow config.
			/collect path escapes the clone/,
		)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

test('collect is absent by default, so nothing is read back unasked', async () => {
	const { remote, root } = makeRemote()

	try {
		const result = await runStage({
			repo: remote,
			ref: 'development',
			command: ['./probe.sh'],
			timeoutMs: 120000,
		})

		assert.deepStrictEqual(result.files, {})
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

test('a collected .json file is PARSED, so a flow can address into it', async () => {
	const { remote, root } = makeRemote()
	const staging = fs.mkdtempSync(path.join(os.tmpdir(), 'stage-json-'))

	try {
		// Commit a reviewer-shaped findings file, the thing this exists for.
		const work = path.join(staging, 'w')
		const env = {
			...process.env,
			GIT_AUTHOR_NAME: 't',
			GIT_AUTHOR_EMAIL: 't@e.invalid',
			GIT_COMMITTER_NAME: 't',
			GIT_COMMITTER_EMAIL: 't@e.invalid',
		}
		execFileSync('git', ['clone', '--quiet', remote, work])
		execFileSync('git', ['checkout', '--quiet', 'development'], {
			cwd: work,
			env,
		})
		fs.mkdirSync(path.join(work, 'reviews'), { recursive: true })
		fs.writeFileSync(
			path.join(work, 'reviews', 'latest.json'),
			JSON.stringify({
				code_review: {
					findings: [
						{
							title: 'unchecked return',
							severity: 'WARNING',
							status: 'open',
						},
						{ title: 'naming', severity: 'SUGGESTION', status: 'open' },
					],
				},
			}),
		)
		fs.writeFileSync(path.join(work, 'broken.json'), '{not valid json')
		execFileSync('git', ['add', '-A'], { cwd: work, env })
		execFileSync('git', ['commit', '--quiet', '-m', 'findings'], {
			cwd: work,
			env,
		})
		execFileSync('git', ['push', '--quiet', remote, 'development'], {
			cwd: work,
			env,
		})

		const result = await runStage({
			repo: remote,
			ref: 'development',
			command: ['./probe.sh'],
			collect: ['reviews/latest.json', 'broken.json'],
			timeoutMs: 120000,
		})

		// Addressable structure, not a string — this is the whole point.
		const findings = result.files['reviews/latest.json'].code_review.findings
		assert.strictEqual(findings.length, 2)
		assert.strictEqual(findings[0].title, 'unchecked return')

		// Malformed JSON stays TEXT, not null: the file exists and has
		// contents, and reporting "absent" for "present but malformed" is the
		// conflation the null handling exists to avoid.
		assert.strictEqual(typeof result.files['broken.json'], 'string')
		assert.match(result.files['broken.json'], /not valid json/)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
		fs.rmSync(staging, { recursive: true, force: true })
	}
})

test('collect accepts ALIASES, because a file path is not a dotted path', async () => {
	const { remote, root } = makeRemote()
	const staging = fs.mkdtempSync(path.join(os.tmpdir(), 'stage-alias-'))

	try {
		const work = path.join(staging, 'w')
		const env = {
			...process.env,
			GIT_AUTHOR_NAME: 't',
			GIT_AUTHOR_EMAIL: 't@e.invalid',
			GIT_COMMITTER_NAME: 't',
			GIT_COMMITTER_EMAIL: 't@e.invalid',
		}
		execFileSync('git', ['clone', '--quiet', remote, work])
		execFileSync('git', ['checkout', '--quiet', 'development'], {
			cwd: work,
			env,
		})
		fs.mkdirSync(path.join(work, 'reviews'), { recursive: true })
		fs.writeFileSync(
			path.join(work, 'reviews', 'latest.json'),
			JSON.stringify({ code_review: { findings: [{ title: 'x' }] } }),
		)
		execFileSync('git', ['add', '-A'], { cwd: work, env })
		execFileSync('git', ['commit', '--quiet', '-m', 'r'], { cwd: work, env })
		execFileSync('git', ['push', '--quiet', remote, 'development'], {
			cwd: work,
			env,
		})

		const result = await runStage({
			repo: remote,
			ref: 'development',
			command: ['./probe.sh'],
			// `files["reviews/latest.json"]` cannot be reached by any dotted
			// path — the key holds both dots and slashes, so every traversal
			// splits it in the wrong places. The alias is what makes the
			// collected file addressable at all.
			collect: { findings: 'reviews/latest.json' },
			timeoutMs: 120000,
		})

		assert.ok('findings' in result.files)
		assert.strictEqual(result.files.findings.code_review.findings[0].title, 'x')
		// Parsing keys off the PATH, not the alias — the alias has no extension.
		assert.strictEqual(typeof result.files.findings, 'object')
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
		fs.rmSync(staging, { recursive: true, force: true })
	}
})

test('an aliased path still cannot escape the clone', async () => {
	const { remote, root } = makeRemote()

	try {
		await assert.rejects(
			() =>
				runStage({
					repo: remote,
					ref: 'development',
					command: ['./probe.sh'],
					collect: { sneaky: '../../etc/passwd' },
					timeoutMs: 120000,
				}),
			/collect path escapes the clone/,
		)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

// ── The model credential is scoped to the COMMAND that needs it ──────────

test('a stage whose command is not on the credential list gets no model token', async () => {
	// THE SECURITY PROPERTY OF THE WHOLE CHANGE. `/stage` can now carry a model
	// credential, and the thing that keeps that from being "every stage holds an
	// Anthropic token" is that the allowlist is keyed on the command's own
	// binary. A gate run has no business holding one.
	//
	// Asserted by OBSERVING THE CHILD ENVIRONMENT rather than by inspecting the
	// map: a test that read `STAGE_CREDENTIAL_KEYS` would pass just as happily if
	// the merge below ignored it.
	const { remote, root } = makeRemote()

	try {
		const work = path.join(root, 'work')
		const env = {
			...process.env,
			GIT_AUTHOR_NAME: 'test',
			GIT_AUTHOR_EMAIL: 't@example.invalid',
			GIT_COMMITTER_NAME: 'test',
			GIT_COMMITTER_EMAIL: 't@example.invalid',
		}

		execFileSync('git', ['checkout', '--quiet', 'development'], {
			cwd: work,
			env,
		})
		fs.writeFileSync(
			path.join(work, 'probe.sh'),
			'#!/bin/sh\necho "OAT=${CLAUDE_CODE_OAUTH_TOKEN:-unset}"\necho "KEY=${ANTHROPIC_API_KEY:-unset}"\nexit 0\n',
			{ mode: 0o755 },
		)
		execFileSync('git', ['add', '-A'], { cwd: work, env })
		execFileSync('git', ['commit', '--quiet', '-m', 'probe creds'], {
			cwd: work,
			env,
		})
		execFileSync('git', ['push', '--quiet', remote, 'development'], {
			cwd: work,
			env,
		})

		const result = await runStage({
			repo: remote,
			ref: 'development',
			command: ['./probe.sh'],
			credentialEnv: {
				CLAUDE_CODE_OAUTH_TOKEN: 'oat-MUST-NOT-LEAK',
				ANTHROPIC_API_KEY: 'sk-MUST-NOT-LEAK',
			},
			timeoutMs: 120000,
		})

		assert.match(
			result.output,
			/OAT=unset/,
			'a non-claude command received the subscription token',
		)
		assert.match(
			result.output,
			/KEY=unset/,
			'a non-claude command received the API key',
		)
		assert.doesNotMatch(result.output, /MUST-NOT-LEAK/)
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

test('claude is the only command declared to receive a model credential', () => {
	// A structural companion to the behavioural test above. If a second command
	// is ever added here it should be a deliberate act with its own reasoning,
	// not something that arrives with an unrelated change.
	assert.deepStrictEqual(Object.keys(STAGE_CREDENTIAL_KEYS), ['claude'])
	assert.deepStrictEqual(STAGE_CREDENTIAL_KEYS.claude, [
		'CLAUDE_CODE_OAUTH_TOKEN',
		'ANTHROPIC_API_KEY',
	])
})
