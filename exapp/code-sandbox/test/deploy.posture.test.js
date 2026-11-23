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

/**
 * The block of one service, up to the next top-level key.
 *
 * @param {string} name The service.
 * @return {string}
 */
function service(name) {
	const all = body.split('\n')
	const start = all.indexOf(`  ${name}:`)
	assert.notEqual(start, -1, `service ${name} is declared`)
	const block = []
	for (const line of all.slice(start + 1)) {
		if (/^ {0,2}\S/.test(line)) {
			break
		}
		block.push(line)
	}
	return block.join('\n') + '\n'
}

/**
 * The networks a service joins.
 *
 * @param {string} block The service block.
 * @return {string[]}
 */
function networksOf(block) {
	const match = block.match(/networks:\n((?:\s+- [\w-]+\n?)+)/)
	return match === null ? [] : match[1].split('\n').map((line) => line.replace(/^\s*-\s*/, '').trim()).filter(Boolean).sort()
}

/**
 * Whether a declared network is internal.
 *
 * @param {string} name The network.
 * @return {boolean}
 */
function isInternal(name) {
	const networks = body.slice(body.lastIndexOf('\nnetworks:\n'))
	return new RegExp(`\\n  ${name}:\\n\\s+internal: true`).test(networks)
}

test('the sandbox joins internal networks only, and none with an egress route', () => {
	const sandbox = service('hermiq-code-sandbox')
	assert.deepEqual(networksOf(sandbox), ['browser-control', 'installer-control', 'sandbox-internal'])
	assert.ok(isInternal('sandbox-internal') && isInternal('browser-control') && isInternal('installer-control'))
	assert.doesNotMatch(sandbox, /egress|proxy/i)
	assert.doesNotMatch(body, /network_mode|ports:/)
})

test('the browser shares no network with the code runs except the control network, and leaves only through its proxy', () => {
	const browser = service('hermiq-code-sandbox-browser')
	assert.deepEqual(networksOf(browser), ['browser-control', 'browser-jail'])
	assert.ok(isInternal('browser-jail'))
	assert.match(browser, /BROWSER_EGRESS_PROXY: "http:\/\/browser-egress-proxy:3128"/)
	assert.match(browser, /read_only: true/)
	assert.match(browser, /cap_drop:\n\s+- ALL/)
	assert.doesNotMatch(browser, /cap_add/)
})

test('the installer shares no network with the code runs except the control network, and leaves only through its allowlist proxy', () => {
	const installer = service('hermiq-code-sandbox-installer')
	assert.deepEqual(networksOf(installer), ['installer-control', 'installer-jail'])
	assert.ok(isInternal('installer-jail'))
	assert.ok(!networksOf(service('hermiq-code-sandbox')).includes('installer-jail'), 'code runs have no route to the installer\'s network')
	assert.match(installer, /HTTPS_PROXY: "http:\/\/installer-egress-proxy:3128"/)
	assert.match(installer, /user: "10000:10000"/)
	assert.match(installer, /read_only: true/)
	assert.match(installer, /cap_drop:\n\s+- ALL/)
	assert.doesNotMatch(installer, /cap_add/)

	const proxy = service('installer-egress-proxy')
	assert.deepEqual(networksOf(proxy), ['installer-jail', 'installer-out'])
	assert.equal(isInternal('installer-out'), false)
	assert.match(proxy, /EGRESS_ALLOWED_HOSTS: \$\{INSTALLER_REGISTRY_HOSTS:-pypi\.org,files\.pythonhosted\.org,registry\.npmjs\.org\}/)
	assert.match(proxy, /EGRESS_ALLOWED_PORTS: "443"/)
	for (const name of ['hermiq-code-sandbox', 'hermiq-code-sandbox-browser', 'hermiq-code-sandbox-installer']) {
		assert.ok(!networksOf(service(name)).includes('installer-out'), `${name} has no route out`)
	}
})

test('only the egress proxy reaches a network with a route out', () => {
	const proxy = service('browser-egress-proxy')
	assert.deepEqual(networksOf(proxy), ['browser-jail', 'browser-out'])
	assert.equal(isInternal('browser-out'), false)
	for (const name of ['hermiq-code-sandbox', 'hermiq-code-sandbox-browser']) {
		assert.ok(!networksOf(service(name)).includes('browser-out'), `${name} has no route out`)
	}
	assert.match(proxy, /EGRESS_PDP_URL/)
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
