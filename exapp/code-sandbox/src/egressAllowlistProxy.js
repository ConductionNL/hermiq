// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// egressAllowlistProxy.js — the installer's only way out
// (tools-agent-workspace-and-installed-tools D4).
//
// An HTTPS CONNECT proxy that tunnels to a host only when that exact host is on
// EGRESS_ALLOWED_HOSTS (default: PyPI and npm) and the port is on
// EGRESS_ALLOWED_PORTS (default 443). Everything else is refused with 403:
// plain HTTP requests, other hosts, other ports, IP literals. It reads no
// content; a log line names the host, the verdict and nothing more.
//
// @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004

'use strict'

const http = require('http')
const net = require('net')

const DEFAULT_HOSTS = 'pypi.org,files.pythonhosted.org,registry.npmjs.org'

/**
 * The allowlist from the environment.
 *
 * @param {object} env The environment.
 * @return {{hosts: string[], ports: number[]}}
 */
function allowlist(env = process.env) {
	const hosts = String(env.EGRESS_ALLOWED_HOSTS || DEFAULT_HOSTS)
		.split(/[,\s]+/).map((h) => h.trim().toLowerCase()).filter((h) => h !== '')
	const ports = String(env.EGRESS_ALLOWED_PORTS || '443')
		.split(/[,\s]+/).map((p) => parseInt(p, 10)).filter((p) => Number.isInteger(p) && p > 0 && p < 65536)
	return { hosts, ports }
}

/**
 * The host and port of a CONNECT target, when the proxy may tunnel to it.
 *
 * @param {string} target The CONNECT request target, `host:port`.
 * @param {{hosts: string[], ports: number[]}} allowed The allowlist.
 * @return {{host: string, port: number}|null}
 */
function decide(target, allowed) {
	const match = /^([A-Za-z0-9.-]+):(\d{1,5})$/.exec(String(target || ''))
	if (match === null) {
		return null
	}
	const host = match[1].toLowerCase().replace(/\.$/, '')
	const port = parseInt(match[2], 10)
	if (net.isIP(host) !== 0 || !allowed.hosts.includes(host) || !allowed.ports.includes(port)) {
		return null
	}
	return { host, port }
}

/**
 * One log line.
 *
 * @param {string} message The message.
 * @return {void}
 */
function log(message) {
	process.stdout.write(JSON.stringify({ level: 'info', message, time: new Date().toISOString() }) + '\n')
}

/**
 * Create the proxy (not listening).
 *
 * @param {object} env The environment.
 * @return {http.Server}
 */
function createProxy(env = process.env) {
	const allowed = allowlist(env)
	const server = http.createServer((req, res) => {
		req.resume()
		res.writeHead(403, { 'Content-Type': 'text/plain' })
		res.end('Only HTTPS to an allowlisted registry is allowed.\n')
	})

	server.on('connect', (req, socket, head) => {
		const target = decide(req.url, allowed)
		if (target === null) {
			log('refused ' + String(req.url).slice(0, 255))
			socket.end('HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n')
			return
		}
		const upstream = net.connect(target.port, target.host, () => {
			socket.write('HTTP/1.1 200 Connection Established\r\n\r\n')
			if (head && head.length > 0) {
				upstream.write(head)
			}
			upstream.pipe(socket)
			socket.pipe(upstream)
		})
		upstream.on('error', () => socket.end('HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\n\r\n'))
		socket.on('error', () => upstream.destroy())
		log('tunnel ' + target.host + ':' + target.port)
	})

	return server
}

if (require.main === module) {
	const port = parseInt(process.env.PROXY_PORT || '3128', 10)
	createProxy().listen(port, '0.0.0.0', () => log('installer egress proxy listening on ' + port))
}

module.exports = { allowlist, decide, createProxy }
