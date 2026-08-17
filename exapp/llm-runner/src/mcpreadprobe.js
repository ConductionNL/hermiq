/**
 * MCP config re-read probe — DEV INSTRUMENT, NOT A SERVING PATH.
 *
 * Answers design D0.1 option 3, which the design explicitly refuses to assume:
 * **does a live `claude` process re-read its `--mcp-config` file between turns?**
 *
 * It decides how narrow the run-token window can be. If the CLI re-reads, a
 * pooled governed process can be handed a FRESH token per turn and the per-run
 * token contract survives pooling intact. If it does not, the token's lifetime
 * must follow the process (option 1), which widens the window per-run minting
 * exists to narrow.
 *
 * ## Why this needs a stub server rather than the real endpoint
 *
 * The discriminator must not depend on tokens at all, or the experiment measures
 * token validity instead of config re-reading. So: stand up TWO local MCP
 * servers exposing DIFFERENTLY NAMED tools, point the config at server A, take a
 * turn, rewrite the config to point at server B, and take another turn asking for
 * B's tool.
 *
 *   - Turn 2 reaches server B  => the CLI re-read the config.
 *   - Turn 2 reaches server A, or reports B's tool missing => it did not.
 *
 * Which server is contacted is observed at the SERVER, not asked of the model — a
 * model asked "what tools do you have" will happily narrate a plausible answer.
 *
 * Enabled only by the file flag `/tmp/mcp-reread-probe-armed`, self-disabling
 * after one run. Binds loopback only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

'use strict'

const http = require('http')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { spawn } = require('child_process')

const PROBE_TIMEOUT_MS = 240000

let alreadyRan = false

/**
 * Stand up a minimal MCP-over-HTTP server exposing exactly one tool.
 *
 * Implements only what a handshake needs: `initialize`, `tools/list`,
 * `tools/call`. Records every method it is asked for, which is the observation
 * the probe actually rests on.
 *
 * @param {string} label    Server label ('A' or 'B'), used in the tool name.
 * @param {string} toolName The single tool this server exposes.
 * @returns {Promise<object>} Resolves to {port, hits, close()}.
 */
function startStub(label, toolName) {
	const hits = []
	const server = http.createServer((req, res) => {
		let body = ''
		req.on('data', (c) => { body += c })
		req.on('end', () => {
			let rpc = {}
			try {
				rpc = JSON.parse(body || '{}')
			} catch { /* keep {} */ }
			hits.push(rpc.method || '(unparsed)')

			const reply = (result) => {
				const payload = JSON.stringify({ jsonrpc: '2.0', id: rpc.id, result })
				res.writeHead(200, { 'Content-Type': 'application/json' })
				res.end(payload)
			}

			if (rpc.method === 'initialize') {
				reply({
					protocolVersion: '2024-11-05',
					capabilities: { tools: {} },
					serverInfo: { name: `probe-${label}`, version: '1.0.0' },
				})
				return
			}
			if (rpc.method === 'tools/list') {
				reply({
					tools: [{
						name: toolName,
						description: `Probe tool on server ${label}. Returns a fixed string.`,
						inputSchema: { type: 'object', properties: {}, required: [] },
					}],
				})
				return
			}
			if (rpc.method === 'tools/call') {
				reply({ content: [{ type: 'text', text: `SERVER_${label}_ANSWERED` }] })
				return
			}
			// notifications and anything else: accept silently.
			res.writeHead(202, { 'Content-Type': 'application/json' })
			res.end('{}')
		})
	})

	return new Promise((resolve) => {
		server.listen(0, '127.0.0.1', () => {
			resolve({
				port: server.address().port,
				hits,
				close: () => { try { server.close() } catch { /* noop */ } },
			})
		})
	})
}

/**
 * Frame a user message for `--input-format stream-json`.
 *
 * @param {string} text The message text.
 * @returns {string} Newline-terminated JSON frame.
 */
function userFrame(text) {
	return `${JSON.stringify({
		type: 'user',
		message: { role: 'user', content: [{ type: 'text', text }] },
	})}\n`
}

/**
 * Write an mcp-config naming exactly one server.
 *
 * @param {string} file  Destination path.
 * @param {number} port  Loopback port to point at.
 * @returns {void}
 */
function writeConfig(file, port) {
	fs.writeFileSync(
		file,
		JSON.stringify({
			mcpServers: { probe: { type: 'http', url: `http://127.0.0.1:${port}/` } },
		}),
		{ mode: 0o600 },
	)
}

/**
 * Run the config re-read probe.
 *
 * @param {object}   opts               Options.
 * @param {object}   opts.provider      Resolved provider (for `bin`).
 * @param {string}   [opts.model]       Model id.
 * @param {object}   opts.credentialEnv Credential env for the child.
 * @param {Function} opts.log           Logger (level, message).
 * @returns {Promise<void>} Resolves when reported.
 */
async function runProbe({ provider, model, credentialEnv, log }) {
	if (alreadyRan) {
		return
	}
	alreadyRan = true

	const a = await startStub('A', 'probe_alpha')
	const b = await startStub('B', 'probe_beta')
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mcpreread-'))
	const cfg = path.join(dir, 'mcp.json')
	writeConfig(cfg, a.port)

	log('info', `MCP REREAD PROBE serverA=${a.port} serverB=${b.port} cfg=${cfg}`)

	await new Promise((resolve) => {
		const args = [
			'-p',
			'--input-format', 'stream-json',
			'--output-format', 'stream-json',
			'--verbose',
			'--strict-mcp-config',
			'--mcp-config', cfg,
			'--allowedTools', 'mcp__probe__probe_alpha,mcp__probe__probe_beta',
		]
		if (model) {
			args.push('--model', model)
		}

		let turn = 1
		let buffer = ''
		let settled = false
		let swappedAt = -1
		const t0 = Date.now()
		let child

		const finish = (verdict) => {
			if (settled) {
				return
			}
			settled = true
			try { child && child.kill('SIGKILL') } catch { /* noop */ }
			const bAfterSwap = b.hits.length > 0
			log(
				'info',
				`MCP REREAD PROBE ${verdict} elapsed=${Date.now() - t0}ms swappedAt=${swappedAt}ms `
					+ `serverA_methods=[${a.hits.join('|')}] serverB_methods=[${b.hits.join('|')}] `
					+ `REREAD=${bAfterSwap}`,
			)
			a.close(); b.close()
			try { fs.rmSync(dir, { recursive: true, force: true }) } catch { /* noop */ }
			resolve()
		}

		const timer = setTimeout(() => finish('TIMEOUT'), PROBE_TIMEOUT_MS)
		timer.unref && timer.unref()

		try {
			child = spawn(provider.bin, args, {
				env: { ...process.env, ...credentialEnv },
				stdio: ['pipe', 'pipe', 'pipe'],
			})
		} catch (err) {
			clearTimeout(timer)
			log('error', `MCP REREAD PROBE spawn failed: ${err.message}`)
			finish('SPAWN_FAILED')
			return
		}

		// Write turn 1 immediately: the CLI does not necessarily announce itself
		// before it has input (see poolprobe.js).
		child.stdin.write(userFrame(
			'Call the tool probe_alpha with no arguments, then reply with exactly what it returned.',
		))

		child.stdout.on('data', (chunk) => {
			buffer += chunk.toString('utf8')
			const lines = buffer.split('\n')
			buffer = lines.pop() || ''
			for (const line of lines) {
				if (line.trim() === '') {
					continue
				}
				let evt
				try { evt = JSON.parse(line) } catch { continue }
				if (evt.type !== 'result') {
					continue
				}
				if (turn === 1) {
					// Swap the config under the live process, then ask for the tool
					// that ONLY server B has.
					writeConfig(cfg, b.port)
					swappedAt = Date.now() - t0
					turn = 2
					child.stdin.write(userFrame(
						'Now call the tool probe_beta with no arguments, then reply with exactly '
							+ 'what it returned. If that tool does not exist, reply exactly: NO_SUCH_TOOL',
					))
					continue
				}
				clearTimeout(timer)
				finish('OK')
				return
			}
		})

		child.stderr.on('data', () => { /* ignored */ })
		child.on('close', () => finish('PROCESS_EXITED'))
		child.on('error', (err) => {
			log('error', `MCP REREAD PROBE child error: ${err.message}`)
			finish('CHILD_ERROR')
		})
	})
}

module.exports = { runProbe }
