#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// agent-context.spec.js — fail-closed parity test for src/utils/agentContext.js,
// the JS mirror of the PHP AgentContextBuilder (agent-object-leaf).
//
// Usage:
//   node tests/agent-context.spec.js
//
// Exit codes:
//   0 — the bounded, fail-closed allowlist logic behaves as specified.
//   1 — one or more expectations failed.
//
// The source uses ES `export`; this harness strips the export keywords and
// evaluates the module in a vm sandbox (the same approach registry.spec.js uses
// for Vue single-file components) so no bundler/ESM runner is required.
//
// @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-declarative-bounded-agent-context-allowlist

const fs = require('fs')
const path = require('path')
const vm = require('vm')

const SRC = path.join(__dirname, '..', 'src', 'utils', 'agentContext.js')

function loadModule() {
	let src = fs.readFileSync(SRC, 'utf8')
	// Turn `export const X` / `export function Y` into plain declarations, then
	// collect them onto module.exports.
	src = src.replace(/export\s+const\s+/g, 'const ')
	src = src.replace(/export\s+function\s+/g, 'function ')
	src += '\nmodule.exports = { buildAgentContext, AGENT_CONTEXT_KEYWORD }\n'
	const sandbox = { module: { exports: {} }, console }
	sandbox.module.exports = {}
	vm.createContext(sandbox)
	vm.runInContext(src, sandbox, { filename: 'agentContext.js' })
	return sandbox.module.exports
}

let failures = 0
function assert(cond, message) {
	if (cond) {
		console.log('  ✓ ' + message)
	} else {
		failures++
		console.error('  ✗ ' + message)
	}
}

const { buildAgentContext } = loadModule()

console.log('agentContext — fail-closed allowlist')

// Only allowlisted fields returned; confidential field never included.
{
	const ctx = buildAgentContext(
		{ title: 'Permit', status: 'open', bsn: '123456789' },
		{ 'x-openregister-agent-context': ['title', 'status'] },
	)
	assert(ctx.title === 'Permit' && ctx.status === 'open', 'returns allowlisted fields')
	assert(!('bsn' in ctx), 'omits an unlisted confidential field')
}

// No allowlist → empty context (fail-closed).
{
	const ctx = buildAgentContext({ title: 'x', bsn: '9' }, {})
	assert(Object.keys(ctx).length === 0, 'no allowlist yields an empty context')
}

// Empty allowlist → empty context.
{
	const ctx = buildAgentContext({ title: 'x' }, { 'x-openregister-agent-context': [] })
	assert(Object.keys(ctx).length === 0, 'empty allowlist yields an empty context')
}

// Missing listed field omitted, not errored.
{
	const ctx = buildAgentContext({ title: 'x' }, { 'x-openregister-agent-context': ['title', 'deadline'] })
	assert(Object.keys(ctx).length === 1 && ctx.title === 'x', 'missing listed field is omitted')
}

// maxLength cap, multibyte-safe.
{
	const ctx = buildAgentContext(
		{ description: 'ëëëëëworld' },
		{ 'x-openregister-agent-context': { description: { maxLength: 5 } } },
	)
	assert(ctx.description === 'ëëëëë…', 'applies a multibyte-safe maxLength cap')
}

// Allowlist read from a nested `configuration` bag (OR schema shape).
{
	const ctx = buildAgentContext(
		{ title: 'T', secret: 's' },
		{ configuration: { 'x-openregister-agent-context': ['title'] } },
	)
	assert(ctx.title === 'T' && !('secret' in ctx), 'reads the allowlist from a nested configuration bag')
}

if (failures > 0) {
	console.error(`\nagent-context.spec.js: ${failures} expectation(s) failed`)
	process.exit(1)
}
console.log('\nagent-context.spec.js: all expectations passed')
