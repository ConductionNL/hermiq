/**
 * Denylist integrity (hermiq-runner-git-capability task 8).
 *
 * Code editing reaches an agent ONLY through Hermiq's governed workspace tools,
 * never by relaxing the CLI's built-in denylist. This guard reads the denylist
 * from the argv the anthropic adapter actually builds for a governed turn, not
 * from the source text, so a list that is edited, or no longer passed, fails it.
 *
 * Run: `node --test test/denylist-integrity.test.js`.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-cli-built-in-denylist-is-never-relaxed-to-provide-code-editing-capability
 */

'use strict'

const test = require('node:test')
const assert = require('node:assert/strict')
const { PROVIDERS } = require('../src/providers.js')

/**
 * The denied built-ins of a governed anthropic turn.
 *
 * @return {Array<string>}
 */
function deniedBuiltins() {
	const argv = PROVIDERS.anthropic.args('claude-sonnet', { mcpConfigPath: '/tmp/scratch/mcp.json' })
	const at = argv.indexOf('--disallowedTools')
	assert.notEqual(at, -1, 'a governed turn passes --disallowedTools')
	return argv[at + 1].split(',')
}

test('every shell and filesystem built-in stays denied', () => {
	const denied = deniedBuiltins()
	for (const name of ['Bash', 'BashOutput', 'KillShell', 'Read', 'Write', 'Edit', 'NotebookEdit', 'Glob', 'Grep', 'WebFetch', 'WebSearch']) {
		assert.ok(denied.includes(name), `${name} must stay on the denylist`)
	}
})

test('tool search stays available, or the governed tools become unreachable', () => {
	assert.equal(deniedBuiltins().includes('ToolSearch'), false)
})

test('a governed turn is locked to Hermiq\'s MCP server', () => {
	const argv = PROVIDERS.anthropic.args('claude-sonnet', { mcpConfigPath: '/tmp/scratch/mcp.json' })
	assert.ok(argv.includes('--strict-mcp-config'))
	assert.equal(argv[argv.indexOf('--allowedTools') + 1], 'mcp__hermiq__*')
	assert.equal(argv.includes('--tools'), false)
})
