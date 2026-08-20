/**
 * The DEPLOYED hardening posture, as a ratchet.
 *
 * ⚠️ These are static assertions over `deploy/docker-compose.yml` and they are
 * NOT the proof — the same limitation that let hermiq#96's iptables jail pass
 * review while being unable to start. The proof is a live container, and it is
 * recorded in the change; what these do is stop a property from being silently
 * dropped afterwards.
 *
 * The one that earns its place is `exec` on the scratch tmpfs. Docker mounts a
 * tmpfs `noexec` by DEFAULT, and the scratch tree is executed from twice:
 *
 *   1. `stage.js` writes the `GIT_ASKPASS` helper into it and git EXECUTES it
 *      to obtain the credential;
 *   2. the stage's command child is a script inside the CLONED tree.
 *
 * Measured 2026-08-02 on one image with only the mount options varying:
 *
 *   noexec  -> `fatal: cannot exec '.../askpass.sh': Permission denied`, then
 *              `could not read Username ...: terminal prompts disabled`; the
 *              command child fails `EACCES`.
 *   exec    -> the credential reaches the forge and the command child runs.
 *
 * So `tmpfs: [- /tmp]` — which every `docker inspect` assertion accepts — is a
 * sidecar that cannot authenticate and cannot run a gate suite. The mutation
 * check for this file is therefore to delete `exec` from the compose and watch
 * the corresponding case go red.
 *
 * Run: `node --test test/deploy.posture.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const test = require('node:test')
const assert = require('node:assert')
const fs = require('node:fs')
const path = require('node:path')

const COMPOSE = path.join(__dirname, '..', 'deploy', 'docker-compose.yml')
const compose = fs.readFileSync(COMPOSE, 'utf8')

/**
 * Return the lines belonging to one top-level service block, so an assertion
 * about the runner cannot be satisfied by the proxy's copy of the same key.
 *
 * @param {string} name The service key, e.g. `hermiq-llm-runner`.
 *
 * @returns {string} The service's own lines, newline-joined.
 */
function serviceBlock(name) {
	const lines = compose.split('\n')
	const start = lines.findIndex((l) => l.trimEnd() === `  ${name}:`)
	assert.notStrictEqual(start, -1, `service ${name} not found in ${COMPOSE}`)

	const out = []
	for (let i = start + 1; i < lines.length; i += 1) {
		const l = lines[i]
		// A new top-level key (`networks:`) or a sibling service ends the block.
		if (/^\S/.test(l) === true) {
			break
		}

		if (/^ {2}\S/.test(l) === true) {
			break
		}

		out.push(l)
	}

	return out.join('\n')
}

/**
 * Collect the tmpfs entries declared for a service.
 *
 * @param {string} block The service block.
 *
 * @returns {string[]} One entry per mount, e.g. `/tmp:rw,exec,...`.
 */
function tmpfsEntries(block) {
	const lines = block.split('\n')
	const start = lines.findIndex((l) => l.trim() === 'tmpfs:')
	if (start === -1) {
		return []
	}

	const out = []
	for (let i = start + 1; i < lines.length; i += 1) {
		const m = lines[i].match(/^\s+- (\S+)\s*$/)
		if (m === null) {
			break
		}

		out.push(m[1])
	}

	return out
}

const runner = serviceBlock('hermiq-llm-runner')
const proxy = serviceBlock('egress-proxy')

test('the runner drops every capability and runs on a read-only root', () => {
	assert.match(runner, /cap_drop:\s*\n\s+- ALL/, 'cap_drop: [ALL] missing')
	assert.match(runner, /read_only:\s*true/, 'read_only: true missing')
	assert.match(
		runner,
		/security_opt:\s*\n\s+- no-new-privileges:true/,
		'no-new-privileges missing',
	)
	assert.doesNotMatch(runner, /cap_add:/, 'a capability is being added back')
	assert.doesNotMatch(runner, /privileged:\s*true/, 'privileged container')
})

test('the runner mounts NO volumes — no host paths, no Nextcloud data', () => {
	assert.doesNotMatch(runner, /^\s+volumes:/m, 'the runner declares a volume')
})

test('the scratch tmpfs is mounted exec — Docker defaults it to noexec', () => {
	const entries = tmpfsEntries(runner)
	assert.notStrictEqual(entries.length, 0, 'the runner declares no tmpfs at all')

	for (const entry of entries) {
		const [mount, opts = ''] = entry.split(':')
		const flags = opts.split(',').map((o) => o.trim())

		// THE assertion. A bare `- /tmp` yields rw,nosuid,nodev,NOEXEC and the
		// GIT_ASKPASS helper cannot be executed, so the credential never
		// reaches git and the stage's command child fails EACCES.
		assert.ok(
			flags.includes('exec') === true,
			`${mount} is not mounted exec — GIT_ASKPASS and the command child will fail`,
		)
		assert.ok(
			flags.includes('noexec') === false,
			`${mount} is explicitly noexec`,
		)

		// The default tmpfs size is 64M, which cannot hold a repository clone.
		assert.ok(
			flags.some((f) => f.startsWith('size=')) === true,
			`${mount} has no size — the 64M default cannot hold a clone`,
		)
	}
})

test('the writable scratch is the ONLY exception to the read-only root', () => {
	const mounts = tmpfsEntries(runner).map((e) => e.split(':')[0])
	for (const m of mounts) {
		assert.ok(
			m === '/tmp' || m === '/app/scratch',
			`${m} is writable but is not a scratch mount`,
		)
	}
})

test('the runner has no route out except the proxy', () => {
	// `jailed` is `internal: true`, so Docker installs no gateway. The runner
	// must be on that network and ONLY that network; the proxy is the only
	// component attached to both.
	assert.match(
		runner,
		/networks:\s*\n(\s+#[^\n]*\n)*\s+- jailed\s*\n/,
		'runner not confined to `jailed`',
	)
	assert.doesNotMatch(
		runner,
		/^\s+- egress\s*$/m,
		'the runner is attached to the egress network',
	)
	assert.match(
		compose,
		/jailed:\s*\n(\s+#[^\n]*\n)*\s+internal:\s*true/,
		'`jailed` is not internal',
	)
	assert.match(proxy, /- jailed/, 'proxy not on jailed')
	assert.match(proxy, /- egress/, 'proxy not on egress')
})

test('a slow PDP denies rather than hangs, and the proxy is hardened too', () => {
	assert.match(proxy, /EGRESS_PDP_TIMEOUT_MS/, 'the PDP call is unbounded')
	assert.match(proxy, /cap_drop:\s*\n\s+- ALL/, 'proxy keeps capabilities')
	assert.match(proxy, /read_only:\s*true/, 'proxy root is writable')
})

test('both containers restart always — a silent Exited sidecar stops the pipeline', () => {
	assert.match(
		runner,
		/restart:\s*always/,
		'runner restart policy is not `always`',
	)
	assert.match(proxy, /restart:\s*always/, 'proxy restart policy is not `always`')
})
