<?php

/**
 * Hermiq ParallelDelegation
 *
 * Runs the passing parts of one hermiq.delegateAgents call at the same time
 * (delegation-runs-in-parallel D2, D3). Each part is written as a DelegatedRun
 * and queued as a DelegatedRunJob, at most `delegation.maxParallel` at once.
 * The parent polls the records: a part no worker claimed within
 * `delegation.parallelGraceSeconds` is claimed by the parent and run inline,
 * so an instance without a free worker runs the parts one after another; a part
 * still open at `delegation.parallelTimeoutSeconds` times out.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Delegation
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
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Delegation;

use OCA\Hermiq\BackgroundJob\DelegatedRunJob;
use OCA\Hermiq\Kernel\AppIdentity;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;

/**
 * Hands parts to workers and waits for them, in call order.
 *
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
 */
class ParallelDelegation {

	/**
	 * The error code of a part that timed out.
	 *
	 * @var string
	 */
	public const TIMEOUT_CODE = 'delegation_timeout';

	/**
	 * Settings and their defaults (design D2, D3).
	 *
	 * @var array<string, int>
	 */
	private const SETTINGS = [
		'delegation.maxParallel'            => 3,
		'delegation.parallelGraceSeconds'   => 10,
		'delegation.parallelTimeoutSeconds' => 600,
	];

	/**
	 * Milliseconds between two polls.
	 *
	 * @var int
	 */
	private const POLL_MS = 500;

	/**
	 * Constructor.
	 *
	 * @param DelegatedRunStore $store     The parts' records.
	 * @param IJobList          $jobList   Queues a DelegatedRunJob per part.
	 * @param PollClock         $clock     Time and the pause between polls.
	 * @param IAppConfig        $appConfig The three settings.
	 */
	public function __construct(
		private readonly DelegatedRunStore $store,
		private readonly IJobList $jobList,
		private readonly PollClock $clock,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The parts' records.
	 *
	 * @return DelegatedRunStore
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
	 */
	public function store(): DelegatedRunStore {
		return $this->store;
	}//end store()

	/**
	 * How many parts may be handed out at once (`delegation.maxParallel`, default 3).
	 *
	 * @return int At least 1.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function maxParallel(): int {
		return $this->setting(key: 'delegation.maxParallel');
	}//end maxParallel()

	/**
	 * Hand every part out and wait until each has an outcome.
	 *
	 * @param array<int, array<string, mixed>> $records   The parts' records, keyed by their slot.
	 * @param callable                         $runInline Runs a part the parent claimed: takes the claimed
	 *                                                    record, returns its outcome.
	 *
	 * @return array<int, array<string, mixed>> One outcome per slot: `{result, effectiveReach}`, or an error envelope.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
	 */
	public function run(array $records, callable $runInline): array {
		$waiting  = $records;
		$active   = [];
		$outcomes = [];
		while ($waiting !== [] || $active !== []) {
			while ($waiting !== [] && count($active) < $this->maxParallel()) {
				$slot          = array_key_first($waiting);
				$active[$slot] = $this->handOut(record: $waiting[$slot]);
				unset($waiting[$slot]);
			}

			foreach ($active as $slot => $part) {
				$outcome = $this->poll(part: $part, runInline: $runInline);
				if ($outcome !== null) {
					$outcomes[$slot] = $outcome;
					unset($active[$slot]);
				}
			}

			if ($active !== []) {
				$this->clock->wait(milliseconds: self::POLL_MS);
			}
		}//end while

		return $outcomes;
	}//end run()

	/**
	 * Write a part, queue its job, and note when.
	 *
	 * @param array<string, mixed> $record The part's record.
	 *
	 * @return array{id: string, since: int}
	 */
	private function handOut(array $record): array {
		$id = $this->store->queue(record: $record);
		$this->jobList->add(DelegatedRunJob::class, ['delegatedRunId' => $id]);

		return ['id' => $id, 'since' => $this->clock->now()];
	}//end handOut()

	/**
	 * One look at a handed-out part: its outcome when it ended, else null.
	 *
	 * Past the grace period a part still queued is claimed and run here; past
	 * the timeout a part still open times out.
	 *
	 * @param array{id: string, since: int} $part      The handed-out part.
	 * @param callable                      $runInline Runs a part the parent claimed.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
	 */
	private function poll(array $part, callable $runInline): ?array {
		$record = $this->store->read(id: $part['id']);
		if ($record === null) {
			return self::error(code: 'delegation_failed', message: 'The delegated part is gone.');
		}

		$ended = self::outcomeOf(record: $record);
		if ($ended !== null) {
			return $ended;
		}

		$waited = ($this->clock->now() - $part['since']);
		if (($record['state'] ?? '') === 'queued' && $waited >= $this->setting(key: 'delegation.parallelGraceSeconds')) {
			$claimed = $this->store->claim(id: $part['id'], claimant: 'parent');
			if ($claimed !== null) {
				return $runInline($claimed);
			}
		}

		if ($waited >= $this->setting(key: 'delegation.parallelTimeoutSeconds') && $this->store->timeOut(id: $part['id']) === true) {
			return self::error(code: self::TIMEOUT_CODE, message: 'The delegated part did not finish in time.');
		}

		return null;
	}//end poll()

	/**
	 * The outcome of a part that ended, or null while it is open.
	 *
	 * @param array<string, mixed> $record The part's record.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function outcomeOf(array $record): ?array {
		return match ($record['state'] ?? '') {
			'done' => ['result' => (string)($record['result'] ?? ''), 'effectiveReach' => (string)($record['effectiveReach'] ?? '')],
			'failed' => self::error(code: 'delegation_failed', message: 'The delegated agent run failed.'),
			'timed-out' => self::error(code: self::TIMEOUT_CODE, message: 'The delegated part did not finish in time.'),
			default => null,
		};
	}//end outcomeOf()

	/**
	 * An error envelope.
	 *
	 * @param string $code    The machine code.
	 * @param string $message The message.
	 *
	 * @return array<string, mixed>
	 */
	private static function error(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end error()

	/**
	 * A positive integer setting, or its default.
	 *
	 * @param string $key The setting.
	 *
	 * @return int
	 */
	private function setting(string $key): int {
		$value = (int)$this->appConfig->getValueString(AppIdentity::APP_ID, $key, (string)self::SETTINGS[$key]);
		if ($value < 1) {
			return self::SETTINGS[$key];
		}

		return $value;
	}//end setting()
}//end class
