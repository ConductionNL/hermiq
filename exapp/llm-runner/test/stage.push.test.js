/**
 * The push phase, end to end, against a REAL git remote that demands a credential.
 *
 * WHY THIS FILE IS THE ONE THAT MATTERS
 * ------------------------------------
 * `pushGuard.test.js` drives the fence functions directly. That proves they
 * refuse — it proves nothing about whether `runStage()` ever calls them, which
 * is the failure mode this repository has already paid for twice: `toolRepo`
 * existed on both sides of the HTTP boundary and not in it, and the iptables
 * jail was asserted only by grepping its own source while being unable to start.
 *
 * So every assertion here is made at the DESTINATION. A refused push is proved
 * by the bare repository still pointing at the same commit, not by an exception
 * having been raised — because an exception raised after the push would look
 * identical from the caller's side.
 *
 * And the remote demands HTTP Basic auth. With a `file://` remote every push
 * succeeds regardless of credentials, so the central claim — "the command child
 * cannot push because it does not hold the credential" — would be untestable
 * and the suite would pass either way.
 *
 * Run: `node --test test/stage.push.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const test = require('node:test')
const assert = require('node:assert')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')

const { startGitServer } = require('./helpers/gitServer')

const PASSWORD = 'test-forge-token-never-on-argv'

/**
 * Run git, throwing with its own output when it fails.
 *
 * @param {Array<string>} args Arguments.
 * @param {string} cwd Working directory.
 * @returns {string} stdout.
 */
function git(args, cwd) {
	return execFileSync('git', args, {
		cwd,
		encoding: 'utf8',
		env: {
			PATH: process.env.PATH,
			HOME: cwd,
			GIT_AUTHOR_NAME: 'test',
			GIT_AUTHOR_EMAIL: 'test@example.com',
			GIT_COMMITTER_NAME: 'test',
			GIT_COMMITTER_EMAIL: 'test@example.com',
			GIT_CONFIG_GLOBAL: '/dev/null',
			GIT_CONFIG_SYSTEM: '/dev/null',
		},
	})
}

/**
 * Create a bare repository with one commit on `main`, plus a seeded worktree.
 *
 * @param {string} root Where to create it.
 * @param {string} name Repository name.
 * @param {object} [files] Extra files to seed, path → contents.
 * @returns {string} The bare repository path.
 */
function seedRepo(root, name, files = {}) {
	const bare = path.join(root, `${name}.git`)
	const work = path.join(root, `${name}-seed`)

	// `--initial-branch` needs git ≥ 2.28 and the runner image ships 2.39, but
	// developer machines and CI runners do not all agree (this repo's own host
	// has 2.25). `symbolic-ref` after `init` is the portable spelling and works
	// identically on both.
	git(['init', '--bare', bare], root)
	git(['symbolic-ref', 'HEAD', 'refs/heads/main'], bare)
	git(['init', work], root)
	git(['symbolic-ref', 'HEAD', 'refs/heads/main'], work)

	fs.writeFileSync(path.join(work, 'README.md'), `# ${name}\n`)
	for (const [file, contents] of Object.entries(files)) {
		fs.mkdirSync(path.join(work, path.dirname(file)), { recursive: true })
		// 0755: git records the executable bit from the filesystem, and a
		// command the runner cannot execute fails with ENOENT — which reads
		// exactly like a missing file and points at the wrong thing entirely.
		fs.writeFileSync(path.join(work, file), contents, { mode: 0o755 })
		fs.chmodSync(path.join(work, file), 0o755)
	}

	git(['add', '-A'], work)
	git(['commit', '-m', 'seed'], work)
	git(['remote', 'add', 'origin', bare], work)
	git(['push', 'origin', 'main'], work)

	return bare
}

/**
 * The commit `main` points at in a bare repository.
 *
 * @param {string} bare The bare repository.
 * @returns {string} The sha.
 */
function mainSha(bare) {
	return git(['rev-parse', 'refs/heads/main'], bare).trim()
}

/**
 * Whether a ref exists in a bare repository.
 *
 * @param {string} bare The bare repository.
 * @param {string} ref  The ref.
 * @returns {boolean} True when it resolves.
 */
function refExists(bare, ref) {
	try {
		git(['rev-parse', '--verify', ref], bare)
		return true
	} catch (err) {
		return false
	}
}

/**
 * The path, inside the repository, of the stub "builder".
 *
 * A REPOSITORY-RELATIVE path, because that is the only shape `runStage()`
 * accepts for a command with a slash in it — it rebases such a path into the
 * clone. Seeding the script into the repository is also the honest arrangement:
 * the stand-in for the model is a process running inside the checked-out tree,
 * with exactly the reach a real one would have.
 *
 * @type {string}
 */
const BUILDER = 'tools/builder.sh'

/**
 * Load `stage.js` with a command allowlist that admits the stub builder.
 *
 * `ALLOWED_COMMANDS` is read at module load, so the environment must be set
 * before the require — and the module cache cleared, or a previous test's
 * allowlist wins.
 *
 * @returns {object} The stage module.
 */
function loadStage() {
	process.env.RUNNER_STAGE_COMMANDS = BUILDER
	delete require.cache[require.resolve('../src/stage')]
	// eslint-disable-next-line global-require
	return require('../src/stage')
}

/**
 * Set up a scratch root, a server and two repositories.
 *
 * The stub builder is committed into the target repository, so it is TRACKED
 * and unmodified — it never appears in the change set and cannot accidentally
 * satisfy or trip a scope rule.
 *
 * @param {string|Function} body The builder script body, or a function given the
 *                               scratch root — for the tests whose stub writes a
 *                               file OUTSIDE the clone, which is deleted on the
 *                               way out and would otherwise be unreadable.
 * @returns {Promise<object>} The fixture.
 */
async function fixture(body) {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'stage-push-test-'))
	const script = `#!/bin/sh\n${typeof body === 'function' ? body(root) : body}\n`

	const target = seedRepo(root, 'target', { [BUILDER]: script })
	const other = seedRepo(root, 'other', { [BUILDER]: script })
	const server = await startGitServer({ root, password: PASSWORD })

	return {
		root,
		target,
		other,
		server,
		targetUrl: server.url('target'),
		otherUrl: server.url('other'),
		/**
		 * Tear the fixture down.
		 *
		 * @returns {Promise<void>} Resolves when torn down.
		 */
		cleanup: async () => {
			await server.close()
			fs.rmSync(root, { recursive: true, force: true })
		},
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// POSITIVE CONTROL — first, because a runner that refuses everything passes
// every refusal test below.
// ─────────────────────────────────────────────────────────────────────────────

test('the happy path: a scoped change reaches the feature branch at the remote', async () => {
	const f = await fixture('mkdir -p lib && echo "changed" > lib/Thing.php')
	try {
		const { runStage } = loadStage()

		const result = await runStage({
			repo: f.targetUrl,
			ref: 'main',
			command: [BUILDER],
			forgeToken: PASSWORD,
			forgeUser: 'x-access-token',
			timeoutMs: 60000,
			push: {
				branch: 'feature/493/thing',
				issue: 493,
				scope: ['lib'],
				allowedRepo: f.targetUrl,
				message: 'feat: a thing',
			},
		})

		assert.strictEqual(result.push.pushed, true, 'the stage reports a push')

		// ASSERTED AT THE DESTINATION. The bare repository is the only witness
		// that cannot be satisfied by the runner reporting success.
		assert.ok(
			refExists(f.target, 'refs/heads/feature/493/thing'),
			'the feature branch exists at the remote',
		)
		const pushed = git(
			['show', '--name-only', '--format=', 'refs/heads/feature/493/thing'],
			f.target,
		)
		assert.match(pushed, /lib\/Thing\.php/, 'the change is in the pushed commit')
	} finally {
		await f.cleanup()
	}
})

test('the happy path survives repetition', async () => {
	// Run repeatedly. A control that works once and then breaks — on a second
	// connection, a rotated address, a leftover credential helper — gets
	// switched off, which is how the iptables jail became worse than nothing.
	const f = await fixture('mkdir -p lib && echo "$$" > lib/Run.php')
	try {
		const { runStage } = loadStage()

		for (let i = 1; i <= 3; i += 1) {
			const result = await runStage({
				repo: f.targetUrl,
				ref: 'main',
				command: [BUILDER],
				forgeToken: PASSWORD,
				forgeUser: 'x-access-token',
				timeoutMs: 60000,
				push: {
					branch: `feature/493/run-${i}`,
					issue: 493,
					scope: ['lib'],
					allowedRepo: f.targetUrl,
				},
			})

			assert.strictEqual(result.push.pushed, true, `run ${i} pushed`)
			assert.ok(
				refExists(f.target, `refs/heads/feature/493/run-${i}`),
				`run ${i} landed`,
			)
		}
	} finally {
		await f.cleanup()
	}
})

// ─────────────────────────────────────────────────────────────────────────────
// The fences, proved by the remote NOT moving.
// ─────────────────────────────────────────────────────────────────────────────

test('a push to a protected branch is refused, and the remote does not move', async () => {
	const f = await fixture('mkdir -p lib && echo x > lib/A.php')
	try {
		const before = mainSha(f.target)
		const { runStage } = loadStage()

		await assert.rejects(
			runStage({
				repo: f.targetUrl,
				ref: 'main',
				command: [BUILDER],
				forgeToken: PASSWORD,
				forgeUser: 'x-access-token',
				timeoutMs: 60000,
				push: {
					branch: 'main',
					issue: 493,
					scope: ['lib'],
					allowedRepo: f.targetUrl,
				},
			}),
			(err) => err.code === 'protected_branch',
		)

		assert.strictEqual(
			mainSha(f.target),
			before,
			'main is untouched at the remote',
		)
	} finally {
		await f.cleanup()
	}
})

test('a push to a different repository is refused, and that repository is untouched', async () => {
	const f = await fixture('mkdir -p lib && echo x > lib/A.php')
	try {
		const before = mainSha(f.other)
		const { runStage } = loadStage()

		await assert.rejects(
			runStage({
				// The stage is dispatched against the OTHER repository while the
				// allowlist names the target — the shape a redirected builder
				// produces.
				repo: f.otherUrl,
				ref: 'main',
				command: [BUILDER],
				forgeToken: PASSWORD,
				forgeUser: 'x-access-token',
				timeoutMs: 60000,
				push: {
					branch: 'feature/493/x',
					issue: 493,
					scope: ['lib'],
					allowedRepo: f.targetUrl,
				},
			}),
			(err) => err.code === 'repo_not_allowed',
		)

		assert.strictEqual(
			mainSha(f.other),
			before,
			'the other repository is untouched',
		)
		assert.ok(
			refExists(f.other, 'refs/heads/feature/493/x') === false,
			'no branch was created on the other repository',
		)
	} finally {
		await f.cleanup()
	}
})

test('a diff touching .github/workflows is refused before anything is pushed', async () => {
	const f = await fixture(
		'mkdir -p .github/workflows && printf "on: push\\njobs: {}\\n" > .github/workflows/pwn.yml',
	)
	try {
		const { runStage } = loadStage()

		await assert.rejects(
			runStage({
				repo: f.targetUrl,
				ref: 'main',
				command: [BUILDER],
				forgeToken: PASSWORD,
				forgeUser: 'x-access-token',
				timeoutMs: 60000,
				push: {
					branch: 'feature/493/x',
					issue: 493,
					allowedRepo: f.targetUrl,
				},
			}),
			(err) => err.code === 'workflow_definition',
		)

		// The file was UNTRACKED — newly created, never in a diff. This is the
		// assertion that would have failed against a `git diff`-only change set,
		// and the gate would have waved a brand-new workflow straight through.
		assert.ok(
			refExists(f.target, 'refs/heads/feature/493/x') === false,
			'nothing reached the remote',
		)
	} finally {
		await f.cleanup()
	}
})

test('a change outside the declared scope is refused', async () => {
	const f = await fixture('mkdir -p src && echo x > src/Sneak.vue')
	try {
		const { runStage } = loadStage()

		await assert.rejects(
			runStage({
				repo: f.targetUrl,
				ref: 'main',
				command: [BUILDER],
				forgeToken: PASSWORD,
				forgeUser: 'x-access-token',
				timeoutMs: 60000,
				push: {
					branch: 'feature/493/x',
					issue: 493,
					scope: ['lib'],
					allowedRepo: f.targetUrl,
				},
			}),
			(err) => err.code === 'out_of_scope',
		)

		assert.ok(
			refExists(f.target, 'refs/heads/feature/493/x') === false,
			'nothing reached the remote',
		)
	} finally {
		await f.cleanup()
	}
})

// ─────────────────────────────────────────────────────────────────────────────
// The property everything else rests on.
// ─────────────────────────────────────────────────────────────────────────────

test('the command child does NOT hold the credential when the stage may push', async () => {
	let dump = ''
	const f = await fixture((root) => {
		dump = path.join(root, 'child-env.txt')

		return (
			'mkdir -p lib && echo x > lib/A.php\n'
			+ `{ echo "GIT_FORGE_TOKEN=[\${GIT_FORGE_TOKEN:-}]"; echo "GIT_ASKPASS=[\${GIT_ASKPASS:-}]"; } > ${dump}`
		)
	})
	try {
		const { runStage } = loadStage()

		await runStage({
			repo: f.targetUrl,
			ref: 'main',
			command: [BUILDER],
			forgeToken: PASSWORD,
			forgeUser: 'x-access-token',
			timeoutMs: 60000,
			push: {
				branch: 'feature/493/env',
				issue: 493,
				scope: ['lib'],
				allowedRepo: f.targetUrl,
			},
		})

		const seen = fs.readFileSync(dump, 'utf8')
		assert.match(
			seen,
			/GIT_FORGE_TOKEN=\[\]/,
			'the token is absent from the child environment',
		)
		assert.match(
			seen,
			/GIT_ASKPASS=\[\]/,
			'the askpass helper is absent from the child environment',
		)
		assert.ok(
			seen.includes(PASSWORD) === false,
			'the token value appears nowhere in the child environment',
		)
	} finally {
		await f.cleanup()
	}
})

test('an injected instruction cannot push: the child has no credential to push WITH', async () => {
	// The prompt-injection control, stated as an experiment rather than a claim.
	// The stub builder does exactly what repository content would talk a model
	// into doing — it runs `git push` at a protected branch itself, bypassing
	// every fence in `pushGuard` by not going through them at all.
	//
	// It fails anyway, because the remote demands a credential and the child was
	// not given one. That is the difference between a control that depends on
	// the model's compliance and one that does not.
	let log = ''
	const f = await fixture((root) => {
		log = path.join(root, 'injection.log')

		return (
			'mkdir -p lib && echo x > lib/A.php\n'
			+ 'git -c user.name=x -c user.email=x@e.com add -A >/dev/null 2>&1\n'
			+ 'git -c user.name=x -c user.email=x@e.com commit -m pwn >/dev/null 2>&1\n'
			+ `git push origin HEAD:refs/heads/main > ${log} 2>&1; echo "exit=$?" >> ${log}\n`
			+ 'exit 0'
		)
	})
	try {
		const before = mainSha(f.target)
		const { runStage } = loadStage()

		await runStage({
			repo: f.targetUrl,
			ref: 'main',
			command: [BUILDER],
			forgeToken: PASSWORD,
			forgeUser: 'x-access-token',
			timeoutMs: 60000,
			push: {
				branch: 'feature/493/inject',
				issue: 493,
				scope: ['lib'],
				allowedRepo: f.targetUrl,
			},
		}).catch(() => {
			/* the stage's own push may also refuse; irrelevant here */
		})

		const attempt = fs.readFileSync(log, 'utf8')
		assert.ok(
			attempt.includes('exit=0') === false,
			`the injected push should have failed, got: ${attempt}`,
		)
		assert.strictEqual(
			mainSha(f.target),
			before,
			'main is untouched at the remote',
		)
	} finally {
		await f.cleanup()
	}
})

test('a stage that declares NO push keeps the credential in the command env', async () => {
	// The compatibility half. The gating stages that have shipped since the
	// workload plane landed pass no `push` and have always had the token in
	// their command environment; removing it unconditionally would break the
	// running pipeline in order to harden a path it does not take.
	let dump = ''
	const f = await fixture((root) => {
		dump = path.join(root, 'ro-env.txt')

		return `echo "TOKEN=[\${GIT_FORGE_TOKEN:-}]" > ${dump}`
	})
	try {
		const { runStage } = loadStage()

		await runStage({
			repo: f.targetUrl,
			ref: 'main',
			command: [BUILDER],
			forgeToken: PASSWORD,
			forgeUser: 'x-access-token',
			timeoutMs: 60000,
		})

		assert.match(
			fs.readFileSync(dump, 'utf8'),
			new RegExp(`TOKEN=\\[${PASSWORD}\\]`),
		)
	} finally {
		await f.cleanup()
	}
})

test('nothing to push is reported as nothing pushed, not as a push', async () => {
	const f = await fixture('true')
	try {
		const { runStage } = loadStage()

		const result = await runStage({
			repo: f.targetUrl,
			ref: 'main',
			command: [BUILDER],
			forgeToken: PASSWORD,
			forgeUser: 'x-access-token',
			timeoutMs: 60000,
			push: {
				branch: 'feature/493/empty',
				issue: 493,
				allowedRepo: f.targetUrl,
			},
		})

		assert.strictEqual(result.push.pushed, false)
		assert.ok(refExists(f.target, 'refs/heads/feature/493/empty') === false)
	} finally {
		await f.cleanup()
	}
})

test('the credential never appears on argv during a push stage', async () => {
	// `stage.test.js` asserts this for the clone. The push is a second place a
	// credential could reach the process table, and it needs its own assertion:
	// a `git push https://user:token@host` would be the shortest way to write
	// it and would put the secret in `/proc`, which is world-readable.
	const f = await fixture('mkdir -p lib && echo x > lib/A.php')
	try {
		const seen = []
		// eslint-disable-next-line global-require
		const cp = require('child_process')
		const realSpawn = cp.spawn
		cp.spawn = function patched(bin, args, opts) {
			seen.push([bin, ...(args || [])].join(' '))
			return realSpawn.call(cp, bin, args, opts)
		}

		try {
			const { runStage } = loadStage()

			await runStage({
				repo: f.targetUrl,
				ref: 'main',
				command: [BUILDER],
				forgeToken: PASSWORD,
				forgeUser: 'x-access-token',
				timeoutMs: 60000,
				push: {
					branch: 'feature/493/argv',
					issue: 493,
					scope: ['lib'],
					allowedRepo: f.targetUrl,
				},
			})
		} finally {
			cp.spawn = realSpawn
		}

		assert.ok(seen.length > 0, 'the recorder saw the spawns')
		for (const line of seen) {
			assert.ok(
				line.includes(PASSWORD) === false,
				`credential found on argv: ${line}`,
			)
		}
		assert.ok(
			seen.some((l) => l.includes('push')),
			'a push actually happened',
		)
	} finally {
		await f.cleanup()
	}
})
