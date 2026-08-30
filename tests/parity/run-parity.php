<?php

/**
 * Hermiq agent-engine parity harness (task 7.1).
 *
 * Runs the same `(agent, prompt)` pair through BOTH engine paths of a LIVE
 * Nextcloud instance — old: OpenRegister's chat surface at
 * `/apps/openregister/api/...`; new: Hermiq's in-app Engine mirror at
 * `/apps/hermiq/api/...` — and reports STRUCTURAL parity per the 2026-07-06
 * decision (proposal.md "Decisions"): tool-call sequence, persistence shape,
 * usage/timings key shapes, and (in `--gate-check` mode) identical
 * kill-switch refusal. Response TEXT is diffed and logged for human review,
 * never asserted.
 *
 * Self-contained CLI: no Nextcloud bootstrap, no composer dependencies — pure
 * PHP streams HTTP. Requires PHP >= 8.1 with the json extension (always
 * compiled in). See tests/parity/README.md for prerequisites and PASS
 * semantics.
 *
 * Observation channels (ground truth, verified against both controllers at
 * HEAD 2026-07-06):
 * - Send envelope: POST `/api/chat/send` returns
 *   `{message, messageId, sources, timings, usage, conversation}` on both
 *   paths — sources/timings/usage shapes are read here.
 * - Persistence: GET `/api/conversations/{uuid}/messages` returns
 *   `{results[], total, limit, offset}` on both paths — message roles and the
 *   persisted assistant `sources` shape are read here.
 * - Tool-call sequence: the SSE `tool_call` events on POST `/api/chat/stream`
 *   (payload `{toolId, arguments}` on both paths). This is the ONLY channel
 *   where either path surfaces tool invocations: the `/chat/send` response
 *   omits them, and neither path persists tool-role messages (both engines
 *   store only the user and assistant turns; LLPhant runs the tool loop
 *   in-process). The stream leg therefore runs in its own fresh conversation
 *   so the send-leg observation is not contaminated by a second LLM turn.
 * - Gate refusal: POST `/apps/hermiq/api/schedules/{id}/run` returns
 *   `{scheduleId, status, error, nextRun}`; with the organisation
 *   kill-switch engaged, ScheduleService::dispatch() short-circuits BEFORE
 *   either engine path is reached and records `status='skipped_killswitch'`.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Parity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Parity;

use RuntimeException;

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "run-parity.php is a CLI tool.\n");
	exit(2);
}

require_once __DIR__ . '/lib/StructuralComparator.php';

/**
 * Fixed documented default prompt, used when neither PROMPT nor --prompt-set
 * is given. Deliberately generic: no tool, no RAG dependency, short answer.
 */
const DEFAULT_PROMPT = 'Reply in one short sentence: what can you help me with?';

/**
 * HTTP read timeout in seconds. Live LLM turns on a cold Ollama load can take
 * minutes; the SSE endpoint heartbeats but the plain send endpoint blocks.
 */
const HTTP_TIMEOUT = 600;

// ---------------------------------------------------------------------------
// Configuration (env + argv).
// ---------------------------------------------------------------------------

/**
 * Read configuration from environment variables and CLI arguments.
 *
 * @param array $argv CLI arguments.
 *
 * @return array<string, mixed> Normalized configuration.
 */
function readConfig(array $argv): array {
	$cfg = [
		'baseUrl' => rtrim((string)getenv('NEXTCLOUD_URL'), '/'),
		'user' => (string)getenv('NC_USER'),
		'pass' => (string)getenv('NC_PASS'),
		'agentOr' => (string)getenv('AGENT_UUID_OR'),
		'agentHermiq' => (string)getenv('AGENT_UUID_HERMIQ'),
		'prompt' => (string)(getenv('PROMPT') !== false ? getenv('PROMPT') : ''),
		'promptSet' => '',
		'gateCheck' => false,
		'scheduleId' => (string)getenv('SCHEDULE_ID'),
		'organisation' => (string)getenv('ORGANISATION'),
		'engineFlagState' => (string)getenv('ENGINE_FLAG_STATE'),
		'outDir' => __DIR__ . '/out',
	];

	foreach (array_slice($argv, 1) as $arg) {
		if ($arg === '--gate-check') {
			$cfg['gateCheck'] = true;
		} elseif (str_starts_with($arg, '--prompt-set=') === true) {
			$cfg['promptSet'] = substr($arg, strlen('--prompt-set='));
		} elseif (str_starts_with($arg, '--prompt=') === true) {
			$cfg['prompt'] = substr($arg, strlen('--prompt='));
		} elseif (str_starts_with($arg, '--schedule-id=') === true) {
			$cfg['scheduleId'] = substr($arg, strlen('--schedule-id='));
		} elseif (str_starts_with($arg, '--organisation=') === true) {
			$cfg['organisation'] = substr($arg, strlen('--organisation='));
		} elseif (str_starts_with($arg, '--engine-flag-state=') === true) {
			$cfg['engineFlagState'] = substr($arg, strlen('--engine-flag-state='));
		} elseif ($arg === '--help' || $arg === '-h') {
			usage();
			exit(0);
		} else {
			fwrite(STDERR, "Unknown argument: {$arg}\n\n");
			usage();
			exit(2);
		}
	}

	return $cfg;
}//end readConfig()

/**
 * Print usage help.
 *
 * @return void
 */
function usage(): void {
	$help = <<<'TXT'
Hermiq agent-engine parity harness (agent-engine-port task 7.1)

Usage:
  php tests/parity/run-parity.php [--prompt-set=tests/parity/prompts.json | --prompt="..."]
  php tests/parity/run-parity.php --gate-check --schedule-id=<uuid> --organisation=<uuid> \
      --engine-flag-state=<on|off>

Environment (required):
  NEXTCLOUD_URL       Base URL of the live instance (e.g. http://localhost:8080)
  NC_USER             Nextcloud login
  NC_PASS             App password for NC_USER

Environment (parity mode):
  AGENT_UUID_OR       Agent UUID on the OpenRegister path
  AGENT_UUID_HERMIQ   Equivalent agent object UUID in the hermiq register
  PROMPT              Single prompt (default: a fixed documented prompt)

Environment (gate-check mode, or use the flags above):
  SCHEDULE_ID         A schedule object UUID owned by NC_USER
  ORGANISATION       The schedule's organisation UUID
  ENGINE_FLAG_STATE   'on' or 'off' — the CURRENT value of hermiq
                      engine.enabled on the instance (the harness cannot flip
                      an occ appconfig over HTTP; run gate-check once per state)

Output: report on stdout, raw JSON dumps under tests/parity/out/ (gitignored).
Exit code 0 = all structural checks passed; 1 = at least one failed; 2 = usage error.
TXT;
	fwrite(STDOUT, $help . "\n");
}//end usage()

/**
 * Abort with a configuration error.
 *
 * @param string $message What is missing or wrong.
 *
 * @return never
 */
function configError(string $message): never {
	fwrite(STDERR, 'Configuration error: ' . $message . "\n\n");
	usage();
	exit(2);
}//end configError()

// ---------------------------------------------------------------------------
// HTTP (pure streams, basic auth, non-2xx tolerated).
// ---------------------------------------------------------------------------

/**
 * Perform an HTTP request against the live instance.
 *
 * @param array $cfg Harness configuration.
 * @param string $method HTTP method.
 * @param string $url Absolute URL.
 * @param array|null $jsonBody JSON body, or null for none.
 * @param string $accept Accept header value.
 *
 * @return array{status: int, body: string, json: array|null} Response.
 *
 * @throws RuntimeException When the request cannot be performed at all.
 */
function httpRequest(array $cfg, string $method, string $url, ?array $jsonBody, string $accept = 'application/json'): array {
	$headers = [
		'Authorization: Basic ' . base64_encode($cfg['user'] . ':' . $cfg['pass']),
		'Accept: ' . $accept,
		'OCS-APIRequest: true',
	];

	$content = '';
	if ($jsonBody !== null) {
		$encoded = json_encode($jsonBody);
		if (is_string($encoded) === false) {
			throw new RuntimeException('Could not encode request body for ' . $url);
		}

		$content = $encoded;
		$headers[] = 'Content-Type: application/json';
	}

	$context = stream_context_create(
		[
			'http' => [
				'method' => $method,
				'header' => implode("\r\n", $headers),
				'content' => $content,
				'ignore_errors' => true,
				'timeout' => HTTP_TIMEOUT,
				'follow_location' => 0,
			],
			'ssl' => [
				// Dev instances are self-signed as a rule; this harness is a
				// test tool, not production transport.
				'verify_peer' => false,
				'verify_peer_name' => false,
			],
		]
	);

	$body = @file_get_contents($url, false, $context);
	if ($body === false) {
		throw new RuntimeException($method . ' ' . $url . ' failed: ' . (error_get_last()['message'] ?? 'unknown stream error'));
	}

	$status = 0;
	// $http_response_header is populated by the stream wrapper in this scope.
	foreach (($http_response_header ?? []) as $line) {
		if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
			$status = (int)$m[1];
		}
	}

	$json = json_decode($body, associative: true);
	if (is_array($json) === false) {
		$json = null;
	}

	return [
		'status' => $status,
		'body' => $body,
		'json' => $json,
	];
}//end httpRequest()

/**
 * Build an app-route URL: {base}/index.php/apps/{app}{path}.
 *
 * @param array $cfg Harness configuration.
 * @param string $app App id ('openregister' | 'hermiq').
 * @param string $path Route path beginning with '/'.
 *
 * @return string Absolute URL.
 */
function appUrl(array $cfg, string $app, string $path): string {
	return $cfg['baseUrl'] . '/index.php/apps/' . $app . $path;
}//end appUrl()

/**
 * Parse an SSE response body into a list of {event, data} frames.
 *
 * @param string $body Raw SSE body.
 *
 * @return array<int, array{event: string, data: array}> Parsed frames.
 */
function parseSse(string $body): array {
	$frames = [];
	foreach (preg_split('/\n\n+/', str_replace("\r\n", "\n", $body)) as $block) {
		$event = '';
		$data = null;
		foreach (explode("\n", $block) as $line) {
			if (str_starts_with($line, 'event:') === true) {
				$event = trim(substr($line, strlen('event:')));
			} elseif (str_starts_with($line, 'data:') === true) {
				$decoded = json_decode(trim(substr($line, strlen('data:'))), associative: true);
				if (is_array($decoded) === true) {
					$data = $decoded;
				}
			}
		}

		if ($event !== '') {
			$frames[] = [
				'event' => $event,
				'data' => ($data ?? []),
			];
		}
	}

	return $frames;
}//end parseSse()

// ---------------------------------------------------------------------------
// Per-path observation.
// ---------------------------------------------------------------------------

/**
 * Run one prompt through one engine path and collect all observations.
 *
 * @param array $cfg Harness configuration.
 * @param string $app App id ('openregister' | 'hermiq').
 * @param string $agentUuid Agent UUID valid on that path.
 * @param string $prompt The prompt text.
 *
 * @return array<string, mixed> Raw observations (send, messages, stream, ...).
 *
 * @throws RuntimeException When a step fails hard (non-2xx on create/send).
 */
function runPath(array $cfg, string $app, string $agentUuid, string $prompt): array {
	// 1. Create the send-leg conversation.
	$create = httpRequest(
		$cfg,
		'POST',
		appUrl($cfg, $app, '/api/conversations'),
		['agentUuid' => $agentUuid]
	);
	if ($create['status'] < 200 || $create['status'] >= 300 || $create['json'] === null) {
		throw new RuntimeException(
			"[{$app}] conversation create failed (HTTP {$create['status']}): " . $create['body']
		);
	}

	$conversationUuid = (string)($create['json']['uuid'] ?? '');
	if ($conversationUuid === '') {
		throw new RuntimeException("[{$app}] conversation create returned no uuid: " . $create['body']);
	}

	// 2. Blocking send — yields the {message, messageId, sources, timings,
	// usage, conversation} envelope.
	$send = httpRequest(
		$cfg,
		'POST',
		appUrl($cfg, $app, '/api/chat/send'),
		[
			'conversation' => $conversationUuid,
			'message' => $prompt,
		]
	);
	if ($send['status'] !== 200 || $send['json'] === null) {
		throw new RuntimeException(
			"[{$app}] chat send failed (HTTP {$send['status']}): " . $send['body']
		);
	}

	// 3. Persistence read-back of the send-leg conversation.
	$messages = httpRequest(
		$cfg,
		'GET',
		appUrl($cfg, $app, '/api/conversations/' . rawurlencode($conversationUuid) . '/messages')
	);
	if ($messages['status'] !== 200 || $messages['json'] === null) {
		throw new RuntimeException(
			"[{$app}] messages fetch failed (HTTP {$messages['status']}): " . $messages['body']
		);
	}

	// 4. Stream leg in its own fresh conversation (tool-call observation
	// channel — see the file docblock). A stream failure is captured, not
	// fatal: the structural report will show the terminal-event mismatch.
	$streamConversationUuid = '';
	$frames = [];
	$streamError = null;
	try {
		$create2 = httpRequest(
			$cfg,
			'POST',
			appUrl($cfg, $app, '/api/conversations'),
			['agentUuid' => $agentUuid]
		);
		$streamConversationUuid = (string)($create2['json']['uuid'] ?? '');
		if ($streamConversationUuid === '') {
			throw new RuntimeException(
				"[{$app}] stream-leg conversation create failed (HTTP {$create2['status']})"
			);
		}

		$stream = httpRequest(
			$cfg,
			'POST',
			appUrl($cfg, $app, '/api/chat/stream'),
			[
				'conversationUuid' => $streamConversationUuid,
				'message' => $prompt,
			],
			'text/event-stream'
		);
		$frames = parseSse($stream['body']);
	} catch (RuntimeException $e) {
		$streamError = $e->getMessage();
	}//end try

	return [
		'app' => $app,
		'conversationUuid' => $conversationUuid,
		'streamConversation' => $streamConversationUuid,
		'create' => $create['json'],
		'send' => $send['json'],
		'messages' => $messages['json'],
		'streamFrames' => $frames,
		'streamError' => $streamError,
	];
}//end runPath()

/**
 * Extract the ordered tool-call payloads from parsed SSE frames.
 *
 * @param array $frames Parsed SSE frames.
 *
 * @return array<int, array> The `tool_call` payloads in emission order.
 */
function extractToolCalls(array $frames): array {
	$calls = [];
	foreach ($frames as $frame) {
		if (($frame['event'] ?? '') === 'tool_call') {
			$calls[] = ($frame['data'] ?? []);
		}
	}

	return $calls;
}//end extractToolCalls()

/**
 * Extract the terminal SSE event type ('final' | 'error' | '' when absent).
 *
 * @param array $frames Parsed SSE frames.
 *
 * @return string Terminal event type.
 */
function terminalEvent(array $frames): string {
	$terminal = '';
	foreach ($frames as $frame) {
		$event = (string)($frame['event'] ?? '');
		if ($event === 'final' || $event === 'error') {
			$terminal = $event;
		}
	}

	return $terminal;
}//end terminalEvent()

/**
 * Extract role sequence and the last assistant message from a messages fetch.
 *
 * @param array $messagesResponse The `/conversations/{uuid}/messages` JSON.
 *
 * @return array{roles: array, finalRole: string|null, assistantSources: array} Extract.
 */
function persistenceShape(array $messagesResponse): array {
	$results = ($messagesResponse['results'] ?? []);
	if (is_array($results) === false) {
		$results = [];
	}

	$roles = [];
	$finalRole = null;
	$assistantSources = [];
	foreach ($results as $message) {
		if (is_array($message) === false) {
			continue;
		}

		$role = (string)($message['role'] ?? '');
		$roles[] = $role;
		if ($role === 'assistant') {
			$sources = ($message['sources'] ?? []);
			if (is_array($sources) === true) {
				$assistantSources = $sources;
			}
		}
	}

	if ($roles !== []) {
		$finalRole = $roles[(count($roles) - 1)];
	}

	return [
		'roles' => $roles,
		'finalRole' => $finalRole,
		'assistantSources' => $assistantSources,
	];
}//end persistenceShape()

// ---------------------------------------------------------------------------
// Output helpers.
// ---------------------------------------------------------------------------

/**
 * Dump a raw observation to tests/parity/out/ for post-hoc review.
 *
 * @param array $cfg Harness configuration.
 * @param string $runStamp Timestamped run directory name.
 * @param string $name File basename (without extension).
 * @param mixed $payload JSON-encodable payload.
 *
 * @return void
 */
function dumpJson(array $cfg, string $runStamp, string $name, mixed $payload): void {
	$dir = $cfg['outDir'] . '/' . $runStamp;
	if (is_dir($dir) === false) {
		mkdir($dir, 0775, true);
	}

	file_put_contents(
		$dir . '/' . $name . '.json',
		json_encode($payload, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
	);
}//end dumpJson()

/**
 * Load the prompt set for this run.
 *
 * @param array $cfg Harness configuration.
 *
 * @return array<int, array{id: string, prompt: string}> Prompts to run.
 */
function loadPrompts(array $cfg): array {
	if ($cfg['promptSet'] !== '') {
		$raw = @file_get_contents($cfg['promptSet']);
		if ($raw === false) {
			configError('cannot read prompt set: ' . $cfg['promptSet']);
		}

		$decoded = json_decode($raw, associative: true);
		$list = ($decoded['prompts'] ?? null);
		if (is_array($list) === false || $list === []) {
			configError('prompt set has no "prompts" array: ' . $cfg['promptSet']);
		}

		$prompts = [];
		foreach ($list as $i => $entry) {
			$prompts[] = [
				'id' => (string)($entry['id'] ?? ('prompt-' . $i)),
				'prompt' => (string)($entry['prompt'] ?? ''),
			];
		}

		return array_values(array_filter($prompts, static fn (array $p): bool => $p['prompt'] !== ''));
	}//end if

	$single = ($cfg['prompt'] !== '') ? $cfg['prompt'] : DEFAULT_PROMPT;

	return [
		[
			'id' => 'single',
			'prompt' => $single,
		],
	];
}//end loadPrompts()

// ---------------------------------------------------------------------------
// Parity mode.
// ---------------------------------------------------------------------------

/**
 * Run the dual-path structural parity comparison for every prompt.
 *
 * @param array $cfg Harness configuration.
 *
 * @return int Exit code (0 = all structural checks passed).
 */
function runParity(array $cfg): int {
	if ($cfg['baseUrl'] === '' || $cfg['user'] === '' || $cfg['pass'] === '') {
		configError('NEXTCLOUD_URL, NC_USER and NC_PASS are required');
	}

	if ($cfg['agentOr'] === '' || $cfg['agentHermiq'] === '') {
		configError('AGENT_UUID_OR and AGENT_UUID_HERMIQ are required in parity mode');
	}

	$comparator = new StructuralComparator();
	$prompts = loadPrompts($cfg);
	$runStamp = gmdate('Ymd\THis\Z');
	$allPassed = true;

	fwrite(STDOUT, 'Parity run ' . $runStamp . ' against ' . $cfg['baseUrl'] . "\n");
	fwrite(STDOUT, 'Prompts: ' . count($prompts) . "\n\n");

	foreach ($prompts as $promptEntry) {
		$promptId = $promptEntry['id'];
		$prompt = $promptEntry['prompt'];
		fwrite(STDOUT, '### Prompt "' . $promptId . '": ' . $prompt . "\n");

		try {
			$old = runPath($cfg, 'openregister', $cfg['agentOr'], $prompt);
			$new = runPath($cfg, 'hermiq', $cfg['agentHermiq'], $prompt);
		} catch (RuntimeException $e) {
			fwrite(STDOUT, '[FAIL] run aborted: ' . $e->getMessage() . "\n\n");
			$allPassed = false;
			continue;
		}

		dumpJson($cfg, $runStamp, $promptId . '-openregister', $old);
		dumpJson($cfg, $runStamp, $promptId . '-hermiq', $new);

		$oldPersist = persistenceShape($old['messages']);
		$newPersist = persistenceShape($new['messages']);

		$oldSend = $old['send'];
		$newSend = $new['send'];

		$checks = [];
		$checks[] = $comparator->compareKeySet(
			'send-envelope-keys',
			$oldSend,
			$newSend
		);
		$checks[] = $comparator->compareKeySet(
			'usage-keys',
			is_array($oldSend['usage'] ?? null) ? $oldSend['usage'] : [],
			is_array($newSend['usage'] ?? null) ? $newSend['usage'] : []
		);
		$checks[] = $comparator->compareKeySet(
			'timings-keys',
			is_array($oldSend['timings'] ?? null) ? $oldSend['timings'] : [],
			is_array($newSend['timings'] ?? null) ? $newSend['timings'] : []
		);
		$checks[] = $comparator->compareSources(
			'send-sources',
			is_array($oldSend['sources'] ?? null) ? $oldSend['sources'] : [],
			is_array($newSend['sources'] ?? null) ? $newSend['sources'] : []
		);
		$checks[] = $comparator->compareSources(
			'persisted-assistant-sources',
			$oldPersist['assistantSources'],
			$newPersist['assistantSources']
		);
		$checks[] = $comparator->compareSequence(
			'persisted-role-sequence',
			$oldPersist['roles'],
			$newPersist['roles']
		);
		$checks[] = $comparator->compareScalar(
			'final-message-role',
			$oldPersist['finalRole'],
			$newPersist['finalRole']
		);
		$checks[] = $comparator->compareToolSequence(
			'tool-call-sequence',
			extractToolCalls($old['streamFrames']),
			extractToolCalls($new['streamFrames'])
		);
		$checks[] = $comparator->compareScalar(
			'stream-terminal-event',
			terminalEvent($old['streamFrames']),
			terminalEvent($new['streamFrames'])
		);

		// Text diffs: logged for human review, NEVER part of pass/fail.
		$infos = [];
		$infos[] = $comparator->textDiffInfo(
			'response-text (send leg) — old vs new',
			(string)($oldSend['message'] ?? ''),
			(string)($newSend['message'] ?? '')
		);

		if ($old['streamError'] !== null || $new['streamError'] !== null) {
			$infos[] = [
				'label' => 'stream-leg transport errors',
				'text' => 'old: ' . ($old['streamError'] ?? '-') . "\nnew: " . ($new['streamError'] ?? '-'),
			];
		}

		$report = $comparator->renderReport($checks, $infos);
		fwrite(STDOUT, $report . "\n");
		dumpJson(
			$cfg,
			$runStamp,
			$promptId . '-report',
			[
				'prompt' => $promptEntry,
				'checks' => $checks,
				'infos' => $infos,
				'pass' => $comparator->allPass($checks),
			]
		);

		if ($comparator->allPass($checks) === false) {
			$allPassed = false;
		}
	}//end foreach

	fwrite(STDOUT, 'Raw observations dumped to tests/parity/out/' . $runStamp . "/\n");
	fwrite(STDOUT, ($allPassed === true) ? "OVERALL: PASS\n" : "OVERALL: FAIL\n");

	return ($allPassed === true) ? 0 : 1;
}//end runParity()

// ---------------------------------------------------------------------------
// Gate-check mode.
// ---------------------------------------------------------------------------

/**
 * Verify the kill-switch refusal envelope for the CURRENT engine-flag state.
 *
 * Engages the organisation kill-switch, fires the schedule's run-now
 * endpoint, asserts the run was refused (`status='skipped_killswitch'`), and
 * restores the switch. The refusal envelope is persisted as
 * out/gate-<state>.json; once BOTH gate-on.json and gate-off.json exist (one
 * run per `hermiq engine.enabled` state, flipped via occ between runs —
 * appconfig is not writable over this HTTP surface), the two envelopes are
 * compared structurally: identical HTTP status, identical key set, identical
 * `status` value. That is the "gate behavior identical on both engine paths"
 * check of the 2026-07-06 decision — the ScheduleService gate short-circuits
 * before either engine is reached, and this proves it observationally.
 *
 * @param array $cfg Harness configuration.
 *
 * @return int Exit code (0 = all structural checks passed).
 */
function runGateCheck(array $cfg): int {
	if ($cfg['baseUrl'] === '' || $cfg['user'] === '' || $cfg['pass'] === '') {
		configError('NEXTCLOUD_URL, NC_USER and NC_PASS are required');
	}

	if ($cfg['scheduleId'] === '' || $cfg['organisation'] === '') {
		configError('SCHEDULE_ID and ORGANISATION are required in gate-check mode');
	}

	$state = strtolower($cfg['engineFlagState']);
	if (in_array($state, ['on', 'off'], true) === false) {
		configError("ENGINE_FLAG_STATE must be 'on' or 'off' (the current hermiq engine.enabled value)");
	}

	$comparator = new StructuralComparator();
	$orgPath = '/api/tenant-control/' . rawurlencode($cfg['organisation']);

	// 1. Record the prior kill-switch state so we can restore it.
	$prior = httpRequest($cfg, 'GET', appUrl($cfg, 'hermiq', $orgPath), null);
	$priorEngaged = (bool)(($prior['json']['engaged'] ?? false));
	fwrite(STDOUT, 'Prior kill-switch state for ' . $cfg['organisation'] . ': ' . (($priorEngaged === true) ? 'engaged' : 'disengaged') . "\n");

	// 2. Engage the kill-switch.
	$engage = httpRequest(
		$cfg,
		'POST',
		appUrl($cfg, 'hermiq', $orgPath . '/toggle'),
		[
			'engaged' => true,
			'reason' => 'parity-harness gate-check (agent-engine-port task 7.1)',
		]
	);
	if ($engage['status'] !== 200 || (($engage['json']['engaged'] ?? false)) !== true) {
		fwrite(STDERR, 'Could not engage kill-switch (HTTP ' . $engage['status'] . '): ' . $engage['body'] . "\n");
		fwrite(STDERR, "Note: the toggle endpoint requires an instance admin or the organisation owner.\n");
		return 1;
	}

	try {
		// 3. Fire run-now; the gate must refuse before either engine runs.
		$run = httpRequest(
			$cfg,
			'POST',
			appUrl($cfg, 'hermiq', '/api/schedules/' . rawurlencode($cfg['scheduleId']) . '/run'),
			[]
		);
	} finally {
		// 4. Always restore the prior kill-switch state.
		$restore = httpRequest(
			$cfg,
			'POST',
			appUrl($cfg, 'hermiq', $orgPath . '/toggle'),
			[
				'engaged' => $priorEngaged,
				'reason' => 'parity-harness gate-check restore',
			]
		);
		if ($restore['status'] !== 200) {
			fwrite(STDERR, 'WARNING: could not restore kill-switch state (HTTP ' . $restore['status'] . ") — restore it manually!\n");
		}
	}

	$envelope = [
		'engineFlagState' => $state,
		'httpStatus' => $run['status'],
		'keys' => array_map(strval(...), array_keys(($run['json'] ?? []))),
		'status' => (($run['json']['status'] ?? null)),
		'raw' => $run['json'],
	];
	sort($envelope['keys']);

	// Single-state assertion: the gate refused this run.
	$checks = [];
	$checks[] = $comparator->compareScalar('gate-refusal-status (' . $state . ')', 'skipped_killswitch', $envelope['status']);
	$checks[] = $comparator->compareScalar('gate-http-status (' . $state . ')', 200, $envelope['httpStatus']);

	if (is_dir($cfg['outDir']) === false) {
		mkdir($cfg['outDir'], 0775, true);
	}

	file_put_contents(
		$cfg['outDir'] . '/gate-' . $state . '.json',
		json_encode($envelope, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n"
	);
	fwrite(STDOUT, 'Refusal envelope written to tests/parity/out/gate-' . $state . ".json\n");

	// Cross-state comparison when both envelopes exist.
	$otherState = ($state === 'on') ? 'off' : 'on';
	$otherFile = $cfg['outDir'] . '/gate-' . $otherState . '.json';
	if (is_file($otherFile) === true) {
		$other = json_decode((string)file_get_contents($otherFile), associative: true);
		if (is_array($other) === true) {
			$onEnv = ($state === 'on') ? $envelope : $other;
			$offEnv = ($state === 'off') ? $envelope : $other;

			$checks[] = $comparator->compareScalar('gate-http-status (on vs off)', $offEnv['httpStatus'], $onEnv['httpStatus']);
			$checks[] = $comparator->compareSequence('gate-envelope-keys (on vs off)', ($offEnv['keys'] ?? []), ($onEnv['keys'] ?? []));
			$checks[] = $comparator->compareScalar('gate-status-value (on vs off)', ($offEnv['status'] ?? null), ($onEnv['status'] ?? null));
		}
	} else {
		fwrite(
			STDOUT,
			'NOTE: no gate-' . $otherState . '.json yet — flip the engine flag ('
			. 'occ config:app:set hermiq engine.enabled --value=' . (($otherState === 'on') ? 'true' : 'false')
			. ') and re-run --gate-check with --engine-flag-state=' . $otherState . " to complete the cross-path comparison.\n"
		);
	}//end if

	fwrite(STDOUT, "\n" . $comparator->renderReport($checks));

	return ($comparator->allPass($checks) === true) ? 0 : 1;
}//end runGateCheck()

// ---------------------------------------------------------------------------
// Entry point.
// ---------------------------------------------------------------------------

$cfg = readConfig($argv);
if ($cfg['gateCheck'] === true) {
	exit(runGateCheck($cfg));
}

exit(runParity($cfg));
