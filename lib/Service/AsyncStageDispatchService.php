<?php

/**
 * Starts a stage and collects it later, instead of holding the call open.
 *
 * A SEPARATE CLASS RATHER THAN TWO FLAGS
 * -------------------------------------
 * The first version of this added `bool $async` to `dispatch()` and to
 * `buildParams()`, and phpmd was right to refuse it: a boolean argument that
 * changes what a method RETURNS is not a flag, it is two methods sharing a
 * name. `dispatch()` promises `{exitCode, output, ref}`; an accepted async
 * dispatch has no exit code at all, because nothing has run yet. Declaring one
 * return type for both would have been a lie that static analysis caught
 * immediately — and a caller reading `exitCode` off an acknowledgement would
 * have read "accepted" as "exited 0", the single most dangerous confusion this
 * transport can make.
 *
 * Extending rather than duplicating: `buildParams()`, `mapResult()` and
 * `reasonFrom()` are the shared half and stay in one place, so a field added to
 * the payload reaches both paths. Only the two things that genuinely differ —
 * how a dispatch is acknowledged, and how an outcome is later read — live here.
 *
 * WHY ASYNC AT ALL
 * ----------------
 * `FlowRunWorker` advances queued runs SERIALLY in one PHP process:
 *
 *     foreach ($this->mapper->findQueued(limit: 25) as $run) { $this->advance($run); }
 *
 * so a synchronous stage blocks every other flow in that pass — the lock reaper
 * included — and makes a slot pool decorative, because N slots cannot produce N
 * agents while the thing holding a slot occupies the only worker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCP\Server;
use RuntimeException;

/**
 * The async half of the stage transport.
 */
class AsyncStageDispatchService extends StageDispatchService {

	/**
	 * Start a stage and return a handle to collect it with.
	 *
	 * @param string $repo Clone URL of the tree the command runs OVER.
	 * @param string $ref The ref to check out.
	 * @param array $command The command and its arguments.
	 * @param string|null $uid The acting user's UID.
	 * @param string $credentialId Broker credential, or '' for a public repo.
	 * @param int $timeoutMs Ceiling for the stage; 0 for the default.
	 * @param string $toolRepo Tool tree URL, or ''.
	 * @param string $toolRef Tool tree ref, or ''.
	 * @param array $push Push declaration, or [] for a read-only stage.
	 * @param string $pushCredentialId The injectable forge credential, or ''.
	 * @param string $llmCredentialId The injectable model credential, or ''.
	 *
	 * @return array{job: array{id: string, status: string}} The handle.
	 *
	 * @throws RuntimeException When the stage could not be started.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)           Mirrors `dispatch()`; AppAPI is resolved lazily.
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Mirrors `dispatch()` exactly, deliberately:
	 *   the two must accept the same stage, and a bundled array is the shape in which a field
	 *   has already been silently dropped at this boundary once.
	 */
	public function dispatchAsync(
		string $repo,
		string $ref,
		array $command,
		?string $uid = null,
		string $credentialId = '',
		int $timeoutMs = 0,
		string $toolRepo = '',
		string $toolRef = '',
		array $push = [],
		string $pushCredentialId = '',
		string $llmCredentialId = '',
	): array {
		$ceiling = ($timeoutMs > 0) ? $timeoutMs : self::DEFAULT_STAGE_TIMEOUT_MS;

		$params = $this->buildParams(
			repo: $repo,
			ref: $ref,
			command: $command,
			uid: $uid,
			credentialId: $credentialId,
			ceiling: $ceiling,
			toolRepo: $toolRepo,
			toolRef: $toolRef,
			push: $push,
			pushCredentialId: $pushCredentialId,
			llmCredentialId: $llmCredentialId
		);

		// The one field that differs from a synchronous dispatch. Added HERE
		// rather than inside `buildParams()` so the shared builder keeps one
		// behaviour and cannot be made to produce two payload shapes.
		$params['async'] = true;

		$result = Server::get(self::APP_API_PUBLIC_FUNCTIONS)->exAppRequest(
			self::RUNNER_EXAPP_ID,
			self::RUNNER_ROUTE,
			$uid,
			'POST',
			$params,
			// Seconds, not minutes: this call returns as soon as the stage is
			// ACCEPTED. Waiting the stage's own ceiling here would reintroduce
			// exactly the blocking this class exists to remove.
			['timeout' => self::TRANSPORT_SLACK_SECONDS]
		);

		$this->assertReachable(result: $result);
		$this->assertAccepted(result: $result, what: 'start the stage');

		return $this->mapAccepted(body: (string)$result->getBody());
	}//end dispatchAsync()

	/**
	 * Collect a started stage by its handle.
	 *
	 * ⚠️ THE THREE TERMINAL ANSWERS ARE NOT INTERCHANGEABLE:
	 *
	 *   done     the stage RAN. `result` carries the exit code, which may be
	 *            non-zero — that is a stage that ran and failed.
	 *   failed   the stage could NOT be carried out. A refused push lands here
	 *            with its stable `code`, and must never be read as a completed
	 *            stage with an unlucky field.
	 *   unknown  this runner has no such job — it restarted, or the result aged
	 *            out. TERMINAL, never "still running": a poller that cannot tell
	 *            those apart waits forever.
	 *
	 * @param string $jobId The handle from `dispatchAsync()`.
	 * @param string|null $uid The acting user's UID.
	 *
	 * @return array{status: string, result?: array, error?: string, code?: string} The job state.
	 *
	 * @throws RuntimeException When the runner cannot be reached or answers nonsense.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Mirrors `dispatch()`; AppAPI is resolved lazily.
	 */
	public function collect(string $jobId, ?string $uid = null): array {
		$jobId = trim($jobId);
		if ($jobId === '') {
			throw new RuntimeException('Cannot collect a stage without a job id.');
		}

		$result = Server::get(self::APP_API_PUBLIC_FUNCTIONS)->exAppRequest(
			self::RUNNER_EXAPP_ID,
			self::RUNNER_ROUTE . '?jobId=' . rawurlencode($jobId),
			$uid,
			'GET',
			[],
			['timeout' => self::TRANSPORT_SLACK_SECONDS]
		);

		$this->assertReachable(result: $result);
		$this->assertAccepted(result: $result, what: 'answer for job ' . $jobId);

		$decoded = json_decode((string)$result->getBody(), true);
		if (is_array($decoded) === false || array_key_exists('status', $decoded) === false) {
			throw new RuntimeException('The runner answered with something that is not a job state.');
		}

		$state = ['status' => (string)$decoded['status']];

		if ($state['status'] === 'done') {
			// The SAME mapper the synchronous path uses, so an async result and
			// a sync one are indistinguishable downstream. Two mappers for one
			// shape is how they drift.
			$state['result'] = $this->mapResult(body: (string)json_encode(($decoded['result'] ?? [])));
		}

		if ($state['status'] === 'failed') {
			$state['error'] = (string)($decoded['error'] ?? 'the stage could not be carried out');
			$state['code'] = (string)($decoded['code'] ?? '');
		}

		return $state;
	}//end collect()

	/**
	 * Map the 202 that acknowledges a started stage.
	 *
	 * Deliberately a DIFFERENT shape from a stage result — `job`, not
	 * `exitCode` — so nothing downstream can read an acknowledgement as an
	 * outcome. A stage that has been accepted has produced no verdict at all.
	 *
	 * @param string $body The response body.
	 *
	 * @return array{job: array{id: string, status: string}} The handle.
	 *
	 * @throws RuntimeException When the body carries no usable handle.
	 */
	protected function mapAccepted(string $body): array {
		$decoded = json_decode($body, true);
		$jobId = is_array($decoded) === true ? trim((string)($decoded['jobId'] ?? '')) : '';

		if ($jobId === '') {
			// Fail loudly. A dispatch whose handle was lost is a stage running
			// somewhere with nothing able to collect it — worse than one that
			// never started, because it holds a slot and spends a model budget
			// while being invisible.
			throw new RuntimeException('The runner accepted the stage but returned no job id to collect it with.');
		}

		return ['job' => ['id' => $jobId, 'status' => (string)($decoded['status'] ?? 'running')]];
	}//end mapAccepted()

	/**
	 * AppAPI never throws — failure is the RETURN VALUE. Check that first.
	 *
	 * @param mixed $result Whatever `exAppRequest()` gave back.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the ExApp could not be reached.
	 */
	private function assertReachable(mixed $result): void {
		if (is_array($result) === true) {
			throw new RuntimeException(
				'The workload could not reach the "' . self::RUNNER_EXAPP_ID . '" ExApp. '
				. 'Check that the ExApp is running.'
			);
		}
	}//end assertReachable()

	/**
	 * `http_errors => false`, so a 4xx/5xx is an ordinary response.
	 *
	 * @param object $result The response.
	 * @param string $what What was being attempted, for the message.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the runner refused.
	 */
	private function assertAccepted(object $result, string $what): void {
		$status = $result->getStatusCode();
		if ($status < 200 || $status > 299) {
			throw new RuntimeException(
				'The runner could not ' . $what . ': ' . $this->reasonFrom(body: (string)$result->getBody())
			);
		}
	}//end assertAccepted()
}//end class
