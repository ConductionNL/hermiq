// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The deployment posture the sandbox's guarantees rest on: no route out, no
// volumes, capabilities dropped, read-only root, a work tmpfs.

'use strict'

const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('fs')
const path = require('path')

const compose = fs.readFileSync(path.join(__dirname, '..', 'deploy', 'docker-compose.yml'), 'utf8')
const lines = compose.split('\n').filter((line) => !line.trim().startsWith('#'))
const body = lines.join('\n')

test('the only network is internal', () => {
	assert.match(body, /networks:\n\s+sandbox-internal:\n\s+internal: true/)
	assert.doesNotMatch(body, /network_mode|ports:|egress/)
})

test('no volume is mounted', () => {
	assert.doesNotMatch(body, /volumes:/)
})

test('capabilities are dropped and the root is read-only', () => {
	assert.match(body, /read_only: true/)
	assert.match(body, /cap_drop:\n\s+- ALL/)
	assert.match(body, /no-new-privileges:true/)
	assert.match(body, /pids_limit: \d+/)
	const added = [...body.matchAll(/cap_add:\n((?:\s+- \w+\n)+)/g)].flatMap((m) => m[1].trim().split(/\s*-\s*/).filter(Boolean))
	assert.deepEqual(added.sort(), ['SETGID', 'SETUID'])
})

test('runs work in a tmpfs', () => {
	assert.match(body, /tmpfs:\n\s+- \/work:rw,noexec,nosuid,nodev/)
})

test('the image runs each program as the separate runner uid', () => {
	const dockerfile = fs.readFileSync(path.join(__dirname, '..', 'Dockerfile'), 'utf8')
	assert.match(dockerfile, /SANDBOX_RUN_UID=10001/)
	assert.match(dockerfile, /SANDBOX_WORK_ROOT=\/work/)
	assert.match(dockerfile, /--reuid=sandbox/)
})
