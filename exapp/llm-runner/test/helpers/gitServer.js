/**
 * A minimal authenticated git-over-HTTP server, for tests.
 *
 * WHY THIS EXISTS RATHER THAN A `file://` REMOTE
 * ---------------------------------------------
 * The property under test is "the command child cannot push, because it does
 * not hold the credential". A `file://` remote needs NO credential, so every
 * push succeeds and the test proves the opposite of what it claims: it would
 * pass identically whether the credential was withheld or handed straight to
 * the child. That is the shape of a test that has stopped testing anything.
 *
 * So the remote demands HTTP Basic auth and rejects anonymous access with 401.
 * A push then works only through `GIT_ASKPASS`, which is exactly the mechanism
 * the runner uses and the child is denied.
 *
 * It wraps `git http-backend`, git's own CGI server, so the protocol is real —
 * no reimplementation to get subtly wrong.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const http = require('http')
const { spawn } = require('child_process')

/**
 * Start a git HTTP server over a directory of bare repositories.
 *
 * @param {object} args `{root, password}` — the directory holding `<name>.git`
 *                      trees, and the password every push must present.
 * @returns {Promise<{port: number, url: Function, close: Function, anonymousReads: number}>} The server.
 */
function startGitServer({ root, password }) {
	const state = { denied: 0 }

	const server = http.createServer((req, res) => {
		const auth = String(req.headers.authorization || '')
		const match = /^Basic\s+(.+)$/i.exec(auth)
		const presented =
			match === null
				? ''
				: Buffer.from(match[1], 'base64')
						.toString('utf8')
						.split(':')
						.slice(1)
						.join(':')

		// Every path is authenticated, reads included. A real forge would allow
		// anonymous reads of a public repo, but here the point is that a request
		// WITHOUT the credential gets nowhere — and making reads free would let
		// a fetch-then-push sequence look half-successful for the wrong reason.
		if (presented !== password) {
			state.denied += 1
			res.writeHead(401, {
				'WWW-Authenticate': 'Basic realm="git"',
				'Content-Type': 'text/plain',
			})
			res.end('authentication required\n')
			return
		}

		const url = new URL(req.url, 'http://localhost')

		const child = spawn('git', ['http-backend'], {
			env: {
				PATH: process.env.PATH,
				GIT_PROJECT_ROOT: root,
				// Without this `git http-backend` serves fetches and refuses
				// pushes, and every push test would fail for a reason that has
				// nothing to do with the code under test.
				GIT_HTTP_EXPORT_ALL: '1',
				REQUEST_METHOD: req.method,
				PATH_INFO: url.pathname,
				QUERY_STRING: url.search.replace(/^\?/, ''),
				CONTENT_TYPE: req.headers['content-type'] || '',
				CONTENT_LENGTH: req.headers['content-length'] || '',
				REMOTE_USER: 'test',
				REMOTE_ADDR: '127.0.0.1',
				// `git push` sends its pack chunked; http-backend needs to be
				// told, or it reads zero bytes and reports a broken pack.
				HTTP_CONTENT_ENCODING: req.headers['content-encoding'] || '',
			},
		})

		req.pipe(child.stdin)

		let headerDone = false
		let buffered = Buffer.alloc(0)

		child.stdout.on('data', (chunk) => {
			if (headerDone === true) {
				res.write(chunk)
				return
			}

			buffered = Buffer.concat([buffered, chunk])
			const split = buffered.indexOf('\r\n\r\n')
			if (split === -1) {
				return
			}

			const headers = {}
			let status = 200
			for (const line of buffered
				.slice(0, split)
				.toString('utf8')
				.split('\r\n')) {
				const colon = line.indexOf(':')
				if (colon === -1) {
					continue
				}
				const name = line.slice(0, colon).trim()
				const value = line.slice(colon + 1).trim()
				if (name.toLowerCase() === 'status') {
					status = Number(value.split(' ')[0]) || 200
					continue
				}
				headers[name] = value
			}

			headerDone = true
			res.writeHead(status, headers)
			res.write(buffered.slice(split + 4))
		})

		child.on('close', () => {
			if (headerDone === false) {
				res.writeHead(500, { 'Content-Type': 'text/plain' })
			}
			res.end()
		})

		child.on('error', () => {
			if (res.headersSent === false) {
				res.writeHead(500, { 'Content-Type': 'text/plain' })
			}
			res.end('git http-backend could not be started\n')
		})
	})

	return new Promise((resolve) => {
		server.listen(0, '127.0.0.1', () => {
			const { port } = server.address()
			resolve({
				port,
				/**
				 * The clone URL for a repository on this server.
				 *
				 * @param {string} name Repository name, without `.git`.
				 * @returns {string} The URL.
				 */
				url: (name) => `http://127.0.0.1:${port}/${name}.git`,
				/**
				 * How many requests were refused for want of a credential.
				 *
				 * @returns {number} The count.
				 */
				deniedCount: () => state.denied,
				/**
				 * Stop the server.
				 *
				 * @returns {Promise<void>} Resolves when closed.
				 */
				close: () => new Promise((done) => server.close(done)),
			})
		})
	})
}

module.exports = { startGitServer }
