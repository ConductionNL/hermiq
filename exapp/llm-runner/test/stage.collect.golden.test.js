/**
 * Golden test for what a stage's `collect` hands back: every shape `collect`
 * may take (absent, null, a scalar, an empty list, an empty map, a list, an
 * alias map), and per file the text, the parsed JSON, the unparseable JSON kept
 * as text, and null for a file that is not there.
 *
 * `stage.test.js` and `stage.golden.test.js` cover the common shapes; this
 * file adds the empty and scalar ones and pins the exact map returned, so the
 * reader can be split into named steps without moving a key.
 *
 * Run: `node --test test/stage.collect.golden.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

// Set BEFORE the require: the allowlist is read at module load.
process.env.RUNNER_STAGE_COMMANDS = './probe.sh'

const test = require('node:test')
const assert = require('node:assert')
const { execFileSync } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')

const { runStage } = require('../src/stage')

/**
 * A bare remote whose `main` holds a probe and a few files to collect.
 *
 * @returns {{remote: string, root: string}} The remote and its scratch root.
 */
function makeRemote() {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'stage-collect-'))
	const work = path.join(root, 'work')
	const remote = path.join(root, 'remote.git')
	fs.mkdirSync(path.join(work, 'out'), { recursive: true })
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
	git('checkout', '--quiet', '-b', 'main')
	fs.writeFileSync(path.join(work, 'probe.sh'), '#!/bin/sh\nexit 0\n', { mode: 0o755 })
	fs.writeFileSync(path.join(work, 'note.txt'), 'plain text\n')
	fs.writeFileSync(path.join(work, 'out', 'result.json'), '{"code_review":{"findings":[1,2]}}')
	fs.writeFileSync(path.join(work, 'broken.json'), '{not json')
	git('add', '-A')
	git('commit', '--quiet', '-m', 'fixture')
	execFileSync('git', ['clone', '--quiet', '--bare', work, remote])

	return { remote, root }
}

const fixture = makeRemote()

test.after(() => {
	fs.rmSync(fixture.root, { recursive: true, force: true })
})

/**
 * Run the probe stage with one `collect` value.
 *
 * @param {*} collect The collect value.
 * @returns {Promise<object>} The stage result.
 */
function stageWith(collect) {
	return runStage({
		repo: fixture.remote,
		ref: 'main',
		command: ['./probe.sh'],
		collect,
		timeoutMs: 60000,
	})
}

test('nothing to collect yields an empty map', async () => {
	for (const collect of [undefined, null, 'note.txt', 7, true, [], {}]) {
		const result = await stageWith(collect)
		assert.deepStrictEqual(result.files, {}, JSON.stringify(collect))
	}
})

test('a list is keyed by path: text, parsed JSON, JSON kept as text, null when absent', async () => {
	const result = await stageWith(['note.txt', 'out/result.json', 'broken.json', 'missing.json', './note.txt', 3])
	assert.deepStrictEqual(result.files, {
		'note.txt': 'plain text\n',
		'out/result.json': { code_review: { findings: [1, 2] } },
		'broken.json': '{not json',
		'missing.json': null,
		'./note.txt': 'plain text\n',
		3: null,
	})
})

test('a map is keyed by alias', async () => {
	const result = await stageWith({ findings: 'out/result.json', text: 'note.txt', gone: 'nope.txt' })
	assert.deepStrictEqual(result.files, {
		findings: { code_review: { findings: [1, 2] } },
		text: 'plain text\n',
		gone: null,
	})
})

test('a path that escapes the clone is refused, even after a good one', async () => {
	await assert.rejects(() => stageWith(['note.txt', '../outside']), /collect path escapes the clone: \.\.\/outside/)
	await assert.rejects(() => stageWith({ ok: 'note.txt', bad: '.' }), /collect path escapes the clone: bad/)
})
