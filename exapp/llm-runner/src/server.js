/**
 * hermiq-llm-runner ExApp — HTTP entrypoint.
 *
 * A tiny, dependency-free Node HTTP service exposing a single work route,
 * `POST /run`, `POST /stage`, plus the AppAPI lifecycle stubs (`/heartbeat`). It is the LLM
 * transport half of the `llm-cli-runner-exapp` change: Hermiq POSTs a fully
 * assembled turn, the runner shells out to the matching vendor CLI in
 * non-interactive mode with the credential injected via env only, and returns
 * `{text, toolCalls, usage}`.
 *
 * Security posture (see README.md for the full hardening model):
 *   - Every /run request is authenticated against the AppAPI shared secret
 *     BEFORE any CLI is spawned (see auth.js).
 *   - The runner executes no tools, writes only to a throwaway temp scratch,
 *     reads no host/Nextcloud paths, and is stateless between calls.
 *   - Credentials arrive per-call and are never logged or persisted.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 */

'use strict'

const http = require('http')

const auth = require('./auth')
const { getProvider } = require('./providers')
const { run } = require('./runner')
const { runStage } = require('./stage')
const jobs = require('./jobs')

const HOST = process.env.RUNNER_HOST || '0.0.0.0'
const PORT = Number(process.env.RUNNER_PORT || process.env.APP_PORT || '9000')
const MAX_BODY_BYTES = Number(
	process.env.RUNNER_MAX_BODY_BYTES || String(4 * 1024 * 1024),
)

/**
 * Body cap for `/stage`, which may carry a tool tree as a base64 archive.
 *
 * @type {number}
 */
const MAX_STAGE_BODY_BYTES = Number(
	process.env.RUNNER_MAX_STAGE_BODY_BYTES || String(96 * 1024 * 1024),
)

/**
 * Emit a terse, credential-free log line.
 *
 * @param {string} level Log level.
 * @param {string} message Message text.
 * @returns {void}
 */
function log(level, message) {
	// eslint-disable-next-line no-console
	console.log(`[hermiq-llm-runner] ${level}: ${message}`)
}

/**
 * Send a JSON response.
 *
 * @param {http.ServerResponse} res The response.
 * @param {number} status HTTP status.
 * @param {object} body JSON-serialisable body.
 * @returns {void}
 */
function sendJson(res, status, body) {
	const payload = JSON.stringify(body)
	res.writeHead(status, { 'Content-Type': 'application/json' })
	res.end(payload)
}

/**
 * Collect the raw request body up to a hard size cap.
 *
 * The cap is per-route rather than global. A turn is text and 4 MB is already
 * generous; a stage may carry a TOOL TREE as a base64 archive, and hydra's is
 * 5.8 MB before encoding — so one shared limit either starves the stage or
 * hands `/run` a much larger amplification surface than it needs. Both routes
 * authenticate first, so this bounds a caller that is already trusted, not an
 * anonymous one.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {number} [limit] Byte cap; the default body cap when omitted.
 * @returns {Promise<Buffer>} The raw body.
 */
function readBody(req, limit = MAX_BODY_BYTES) {
	return new Promise((resolve, reject) => {
		const chunks = []
		let size = 0
		req.on('data', (chunk) => {
			size += chunk.length
			if (size > limit) {
				reject(new Error('request body too large'))
				req.destroy()
				return
			}
			chunks.push(chunk)
		})
		req.on('end', () => resolve(Buffer.concat(chunks)))
		req.on('error', reject)
	})
}

/**
 * Handle `POST /run`. Authenticates first, then dispatches one CLI turn.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @param {Buffer} rawBody The raw request body.
 * @returns {Promise<void>} Resolves when the response has been sent.
 */
async function handleRun(req, res, rawBody) {
	// 1. AUTH — before any parsing or CLI invocation.
	const verdict = auth.verify(lowerHeaders(req.headers), rawBody)
	if (!verdict.ok) {
		log('warn', `/run rejected: ${verdict.reason}`)
		sendJson(res, verdict.status, { error: 'unauthorised' })
		return
	}

	// 2. Parse the assembled turn.
	const turn = readTurn(res, rawBody)
	if (turn === null) {
		return
	}

	await runTurn(res, turn)
}

/**
 * Parse a request body as JSON; an empty body is `{}`.
 *
 * @param {http.ServerResponse} res The response, answered 400 when the body is not JSON.
 * @param {Buffer} rawBody The raw request body.
 * @returns {*} The parsed body, or `undefined` once a 400 has been sent.
 */
function parseJsonBody(res, rawBody) {
	try {
		return JSON.parse(rawBody.toString('utf8') || '{}')
	} catch (e) {
		sendJson(res, 400, { error: 'invalid JSON body' })
		return undefined
	}
}

// The fields a /run body hands to the CLI turn besides the provider.
const RUN_FIELDS = ['model', 'messages', 'credentialEnv', 'mcpConfig', 'runToken']

/**
 * Read the assembled turn from a `/run` body, answering 400 for one that
 * cannot be run.
 *
 * @param {http.ServerResponse} res The response, answered 400 for a bad turn.
 * @param {Buffer} rawBody The raw request body.
 * @returns {{providerId: *, args: object}|null} The turn, or null once a 400 has been sent.
 */
function readTurn(res, rawBody) {
	const payload = parseJsonBody(res, rawBody)
	if (payload === undefined) {
		return null
	}

	const { provider: providerId } = payload
	const fields = Object.fromEntries(RUN_FIELDS.map((field) => [field, payload[field]]))
	const turn = { providerId, args: { provider: getProvider(providerId), ...fields } }
	const problem = turnProblem(turn)
	if (problem !== null) {
		sendJson(res, 400, { error: problem })
		return null
	}

	return turn
}

/**
 * Why a turn cannot be run, if it cannot.
 *
 * @param {{providerId: *, args: object}} turn The turn.
 * @returns {string|null} The 400 message, or null for a runnable turn.
 */
function turnProblem(turn) {
	if (!turn.args.provider) {
		return `unknown provider '${turn.providerId}'`
	}
	const { messages } = turn.args
	if (!Array.isArray(messages) || messages.length === 0) {
		return 'messages must be a non-empty array'
	}
	return null
}

/**
 * The log line that records which turn is about to run.
 *
 * @param {{providerId: *, args: object}} turn The turn.
 * @returns {string} The log line.
 */
function runLogLine(turn) {
	const { model, messages, mcpConfig } = turn.args
	return `/run provider=${turn.providerId} model=${model || '(default)'} messages=${messages.length} governed=${mcpConfig ? 'yes' : 'no'}`
}

/**
 * Run exactly one turn and answer with its result, or 502 when it fails.
 *
 * Custom tools reach the CLI ONLY via governed MCP
 * (cli-runner-governed-mcp-and-egress): a tool-requiring turn carries `mcpConfig`
 * — the {mcpServers:{hermiq:{type:"http",...}}} block Hermiq assembled, whose
 * headers carry the per-run bearer token. run() writes it to a 0600 scratch file
 * and locks the CLI down with `--tools "" --strict-mcp-config --mcp-config <path>`.
 * A text-only turn omits `mcpConfig` and is served exactly as link 2 built it.
 *
 * @param {http.ServerResponse} res The response.
 * @param {{providerId: *, args: object}} turn The turn.
 * @returns {Promise<void>}
 */
async function runTurn(res, turn) {
	log('info', runLogLine(turn))
	try {
		const result = await run(turn.args)
		sendJson(res, 200, {
			text: result.text,
			toolCalls: result.toolCalls,
			usage: result.usage,
		})
	} catch (err) {
		// err.message is already redacted in runner.js.
		log('error', `/run failed: ${err.message}`)
		sendJson(res, 502, { error: 'runner execution failed', detail: err.message })
	}
}

/**
 * Handle `POST /stage` — one piece of work that needs a FILESYSTEM.
 *
 * The counterpart to `/run`. That endpoint executes an LLM turn; this clones a
 * ref and runs a command over it, which is what hydra's builder, reviewer and
 * security stages actually are: analysis over a checked-out tree.
 *
 * It returns the command's EXIT CODE and its output rather than a verdict.
 * hydra's gate runner uses its exit code as a failure COUNT and prints a
 * summary line only once every gate has been reached — so "did it pass" is a
 * question about the output, and only the caller knows which line answers it.
 * Deciding that here would bake one consumer's convention into the transport.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @param {Buffer} rawBody The raw request body.
 * @returns {Promise<void>}
 */
async function handleStage(req, res, rawBody) {
	// AUTH first, before parsing and long before anything is cloned.
	const verdict = auth.verify(lowerHeaders(req.headers), rawBody)
	if (!verdict.ok) {
		log('warn', `/stage rejected: ${verdict.reason}`)
		sendJson(res, verdict.status, { error: 'unauthorised' })
		return
	}

	const payload = parseJsonBody(res, rawBody)
	if (payload === undefined) {
		return
	}

	await dispatchStage(res, payload)
}

/**
 * Hand a parsed `/stage` body to the workload, synchronously or as an async job.
 *
 * @param {http.ServerResponse} res The response.
 * @param {object} payload The parsed request body.
 * @returns {Promise<void>}
 */
async function dispatchStage(res, payload) {
	const args = stageArgs(payload)
	const wantsAsync = payload.async
	const jobKey = payload.jobKey
	log('info', stageLogLine(args))

	// ASYNC: hand back a handle and let the caller go.
	//
	// A stage is minutes long and OpenRegister's FlowRunWorker advances queued
	// runs SERIALLY in one process, so a synchronous stage blocks every other
	// flow in that pass — including the lock reaper that exists to clean up
	// after stuck work. It also makes hydra's slot pool decorative: four slots
	// cannot produce four agents while the thing holding a slot occupies the
	// only worker.
	if (wantsAsync === true) {
		startAsyncStage(res, { args, jobKey })
		return
	}

	await runSyncStage(res, args)
}

// The fields a /stage body may hand to the workload, in runStage()'s order.
const STAGE_FIELDS = [
	'repo',
	'ref',
	'command',
	'toolRepo',
	'toolRef',
	'toolTarball',
	'collect',
	'forgeToken',
	'forgeUser',
	'timeoutMs',
	'env',
	'runToken',
	'push',
	'credentialEnv',
]

/**
 * The fields a `/stage` body may hand to the workload, and nothing else.
 *
 * ⚠️ This destructuring is a FILTER, and it silently dropped `toolRepo` for
 * an entire release: the field was added to the caller and to `runStage()`,
 * both were tested, and neither test crossed this line — the unit tests call
 * `runStage()` directly and the PHP tests mock the transport. A parameter
 * that exists on both sides of a boundary and not IN it fails with the
 * symptom of a missing FILE (`spawn scripts/... ENOENT`), which points at
 * the clone rather than at the route.
 *
 * Kept explicit rather than spreading the payload into `runStage()`: the
 * request body is untrusted, and an allowlist of fields is the reason a
 * caller cannot reach arguments this endpoint never meant to expose. The
 * cost is exactly this failure mode, so the route test crosses the boundary
 * for every field.
 *
 * @param {object} payload The parsed request body.
 * @returns {object} The workload arguments, one key per field (undefined when absent).
 */
function stageArgs(payload) {
	return Object.fromEntries(STAGE_FIELDS.map((field) => [field, payload[field]]))
}

/**
 * The log line that records what a stage was asked to do.
 *
 * The repo and ref are safe to log — they are how an operator finds this run
 * again. The token is not, and is never touched here. The tool repo is logged
 * too, because its absence is what a dropped field looks like from the outside
 * and the log is the first place anyone looks.
 *
 * The push INTENT is logged (branch and issue are not secrets) because "this
 * stage was allowed to write" is the single most important fact about a run
 * when reconstructing one afterwards, and its absence from the log is
 * indistinguishable from a stage that could not write at all.
 *
 * @param {object} args The workload arguments.
 * @returns {string} The log line.
 */
function stageLogLine(args) {
	const command = Array.isArray(args.command) ? args.command[0] : '(none)'
	return (
		`/stage repo=${args.repo} ref=${args.ref} `
		+ `tool=${toolLabel(args)} `
		+ `command=${command} `
		+ `push=${pushLabel(args.push)}`
	)
}

/**
 * Where a stage's command comes from, for the log.
 *
 * @param {object} args `{toolRepo, toolTarball}`.
 * @returns {string} The tool repo, the archive's size, or '(none)'.
 */
function toolLabel({ toolRepo, toolTarball }) {
	if (toolRepo) {
		return toolRepo
	}
	return toolTarball ? `archive(${toolTarball.length}b)` : '(none)'
}

/**
 * A stage's push intent, for the log.
 *
 * @param {object} [push] The push declaration.
 * @returns {string} Branch and issue, or '(none)'.
 */
function pushLabel(push) {
	return push && typeof push === 'object'
		? `${push.branch} (issue ${push.issue})`
		: '(none)'
}

/**
 * Start the stage and answer 202 with the handle to poll.
 *
 * ⚠️ THE WORK IS STARTED HERE, NOT BY THE POLLER. `runStage()` is invoked
 * exactly as in the synchronous path and its promise is handed to the
 * registry unawaited. A design where the first poll starts the work would
 * make "dispatched" mean nothing until someone asked again, which is the
 * kind of gap that reads as a slow stage rather than as a stage that never
 * began.
 *
 * 202, not 200: the request was accepted, the stage has NOT finished, and a
 * caller that reads only the status must not be able to mistake the two.
 *
 * @param {http.ServerResponse} res The response.
 * @param {object} dispatch `{args, jobKey}`: the workload arguments and the caller's dedup key.
 * @returns {void}
 */
function startAsyncStage(res, { args, jobKey }) {
	const jobId = jobs.start(runStage(args), jobKey)
	log(
		'info',
		`/stage accepted async job=${jobId} repo=${args.repo} ref=${args.ref}`,
	)
	sendJson(res, 202, { jobId, status: jobs.RUNNING })
}

/**
 * Run the stage and answer with its result, or 502 when it could not be carried out.
 *
 * @param {http.ServerResponse} res The response.
 * @param {object} args The workload arguments.
 * @returns {Promise<void>} Resolves once the answer is sent.
 */
async function runSyncStage(res, args) {
	try {
		const result = await runStage(args)
		const pushed = result.push
			? ` pushed=${result.push.pushed} branch=${result.push.branch}`
			: ''
		log('info', `/stage finished exit=${result.exitCode}${pushed}`)
		sendJson(res, 200, result)
	} catch (err) {
		sendStageFailure(res, err)
	}
}

/**
 * Answer 502 for a stage that was dispatched and could not be carried out.
 *
 * It is NOT a 400 — the request was well formed — and not a 200 with a
 * failure field, because a caller reading only the status must not mistake
 * "could not run" for "ran and failed".
 *
 * A REFUSED PUSH lands here too, and deliberately so: a fence that returned
 * 200 with `pushed: false` would be recorded by every caller that reads only
 * the status as a stage that completed. `code` is carried out so a consumer
 * can route on the refusal without matching prose — the assertion that stops
 * testing anything the day somebody rewords a message.
 *
 * @param {http.ServerResponse} res The response.
 * @param {Error} err The failure, with an optional refusal `code`.
 * @returns {void}
 */
function sendStageFailure(res, err) {
	log('warn', `/stage failed${err.code ? ` (${err.code})` : ''}: ${err.message}`)
	sendJson(res, 502, {
		error: err.message,
		...(err.code ? { code: err.code } : {}),
	})
}

/**
 * Lower-case all header names for case-insensitive lookups.
 *
 * @param {object} headers Raw headers.
 * @returns {object} Header map with lower-cased keys.
 */
function lowerHeaders(headers) {
	const out = {}
	for (const key of Object.keys(headers)) {
		out[key.toLowerCase()] = headers[key]
	}
	return out
}

/**
 * Handle `PUT /enabled?enabled=0|1` — the AppAPI enable/disable lifecycle call.
 *
 * AppAPI reads the response body's `error` key to decide success: a non-empty
 * `error` disables the ExApp and fails the enable (AppAPIService::enableExApp).
 * The generic 404 fallback returns `{error: 'not found'}`, so WITHOUT this
 * handler every `occ app_api:app:enable` fails with "Failed to enable ExApp".
 * The runner is a stateless transport — there is nothing to start or stop — so
 * it authenticates the call and acknowledges with no error.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @param {Buffer} rawBody The raw request body (empty for this call).
 * @returns {void}
 */
function handleEnabled(req, res, rawBody) {
	const verdict = auth.verify(lowerHeaders(req.headers), rawBody)
	if (!verdict.ok) {
		log('warn', `/enabled rejected: ${verdict.reason}`)
		sendJson(res, verdict.status, { error: 'unauthorised' })
		return
	}
	const enabled =
		new URL(req.url, 'http://localhost').searchParams.get('enabled') === '1'
	log('info', `/enabled -> ${enabled ? 'enabled' : 'disabled'}`)
	// Empty/absent `error` = success. There is no `/init` handler on purpose:
	// AppAPI treats a 404/501 on POST /init as "nothing to initialise" and sets
	// init progress to 100 (AppAPIService::dispatchExAppInitInternal). A naive
	// 200 without the progress callback would instead leave init stuck at 0.
	sendJson(res, 200, {})
}

/**
 * Handle `GET /heartbeat`, the AppAPI health probe — no auth, invokes no CLI.
 *
 * Job counts ride along: "is anything in flight" is the first question asked
 * of a runner whose flows look stalled, and it is otherwise invisible from
 * outside the process.
 *
 * @param {http.IncomingMessage} _req The request (unused).
 * @param {http.ServerResponse} res The response.
 * @returns {void}
 */
function handleHeartbeat(_req, res) {
	sendJson(res, 200, { status: 'ok', jobs: jobs.stats() })
}

/**
 * Log the collection of a job in a terminal state, and forget it once its
 * result has been handed over. A running job is neither logged nor forgotten.
 *
 * ⚠️ `unknown` IS TERMINAL and must be handled as such by the caller. The
 * registry is in this process's memory, so a runner restart loses every job.
 * Reporting that as `running` would hang a flow forever waiting for a result
 * that no longer exists.
 *
 * @type {Object<string, function(string, object): void>}
 */
const POLL_OUTCOMES = {
	[jobs.DONE]: (jobId, state) => {
		log(
			'info',
			`/stage collected job=${jobId} exit=${state.result?.exitCode}`,
		)
		jobs.forget(jobId)
	},
	[jobs.FAILED]: (jobId, state) => {
		log(
			'warn',
			`/stage collected job=${jobId} FAILED${state.code ? ` (${state.code})` : ''}: ${state.error}`,
		)
		jobs.forget(jobId)
	},
	[jobs.UNKNOWN]: (jobId) => {
		log(
			'warn',
			`/stage poll for unknown job=${jobId} — restarted runner, or a result that aged out`,
		)
	},
}

/**
 * Handle `GET /stage`: collect an async stage.
 *
 * GET, because asking is not doing — a poller that could accidentally
 * re-dispatch by retrying is a poller that duplicates pushes.
 *
 * 200 for every ANSWERABLE state, including `failed` and `unknown`. The HTTP
 * status says whether the question was answered; the body says what happened
 * to the stage. Conflating the two is how a poller ends up treating a
 * transport hiccup as a verdict.
 *
 * @param {http.IncomingMessage} req The request.
 * @param {http.ServerResponse} res The response.
 * @returns {void}
 */
function handleStagePoll(req, res) {
	const verdict = auth.verify(lowerHeaders(req.headers), Buffer.alloc(0))
	if (!verdict.ok) {
		log('warn', `/stage poll rejected: ${verdict.reason}`)
		sendJson(res, verdict.status, { error: 'unauthorised' })
		return
	}

	const jobId =
		new URL(req.url, 'http://localhost').searchParams.get('jobId') || ''
	if (jobId === '') {
		sendJson(res, 400, { error: 'jobId is required' })
		return
	}

	answerPoll(res, jobId)
}

/**
 * Answer a poll for one job with its state, logging and forgetting it when terminal.
 *
 * @param {http.ServerResponse} res The response.
 * @param {string} jobId The job id.
 * @returns {void}
 */
function answerPoll(res, jobId) {
	const state = jobs.get(jobId)
	const outcome = POLL_OUTCOMES[state.status]
	if (outcome !== undefined) {
		outcome(jobId, state)
	}

	sendJson(res, 200, state)
}

/**
 * Wrap a body-reading handler: read the body up to `limit`, then hand it on;
 * a body that cannot be read is answered 413 unless a response already went out.
 *
 * @param {function(http.IncomingMessage, http.ServerResponse, Buffer): *} handler The handler.
 * @param {number} [limit] Byte cap; the default body cap when omitted.
 * @returns {function(http.IncomingMessage, http.ServerResponse): void} The route handler.
 */
function withBody(handler, limit) {
	return (req, res) => {
		readBody(req, limit)
			.then((rawBody) => handler(req, res, rawBody))
			.catch((err) => {
				log('warn', `request error: ${err.message}`)
				if (!res.headersSent) {
					sendJson(res, 413, { error: err.message })
				}
			})
	}
}

/**
 * A path matcher for the whole request URL, query string included.
 *
 * @param {string} path The path.
 * @returns {function(string): boolean} The matcher.
 */
function exactUrl(path) {
	return (url) => url === path
}

/**
 * A path matcher that ignores the query string.
 *
 * @param {string} path The path.
 * @returns {function(string): boolean} The matcher.
 */
function pathOnly(path) {
	return (url) => url.split('?')[0] === path
}

/**
 * The routes, in the order they are tried.
 *
 * - `GET /heartbeat`: AppAPI health probe.
 * - `PUT /enabled`: AppAPI enable/disable lifecycle call.
 * - `POST /stage`: run a stage (body may carry a tool tree, so its own cap).
 * - `GET /stage`: collect an async stage.
 * - `POST /run`: one CLI turn.
 *
 * @type {Array<{method: string, matches: function(string): boolean, handle: function(http.IncomingMessage, http.ServerResponse): void}>}
 */
const ROUTES = [
	{ method: 'GET', matches: exactUrl('/heartbeat'), handle: handleHeartbeat },
	{ method: 'PUT', matches: pathOnly('/enabled'), handle: withBody(handleEnabled) },
	{
		method: 'POST',
		matches: exactUrl('/stage'),
		handle: withBody(handleStage, MAX_STAGE_BODY_BYTES),
	},
	{ method: 'GET', matches: pathOnly('/stage'), handle: handleStagePoll },
	{ method: 'POST', matches: exactUrl('/run'), handle: withBody(handleRun) },
]

const server = http.createServer((req, res) => {
	const route = ROUTES.find(
		(candidate) => candidate.method === req.method && candidate.matches(req.url),
	)
	if (route === undefined) {
		sendJson(res, 404, { error: 'not found' })
		return
	}

	route.handle(req, res)
})

if (require.main === module) {
	server.listen(PORT, HOST, () => {
		log('info', `listening on ${HOST}:${PORT} (app-id=${auth.APP_ID})`)
	})
}

// `handleStage` is exported for the ROUTE test. It is the one seam where a
// field can exist on both sides of the boundary and not in it — which is
// exactly what happened to `toolRepo` — and a test that cannot reach the
// handler can only assert the function behind it, which is where the bug
// already wasn't.
module.exports = { server, handleStage }
