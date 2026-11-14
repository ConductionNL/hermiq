<?php

/**
 * Hermiq DelegatedRunStore
 *
 * The DelegatedRun records of a parallel delegation (delegation-runs-in-parallel
 * D2, D3): one per part, written when the part is handed out and read back by
 * the parent. A claim moves a record from queued to running under an exclusive
 * Nextcloud lock on the record, so a part runs once, whether a worker or the
 * parent claims it.
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

use OCA\OpenRegister\Service\ObjectService;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Throwable;

/**
 * Reads, writes and claims DelegatedRun records.
 *
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
 */
class DelegatedRunStore {

	/**
	 * The register the records live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'hermiq';

	/**
	 * The records' schema.
	 *
	 * @var string
	 */
	private const SCHEMA = 'delegatedrun';

	/**
	 * Prefix of the lock a claim takes on one record.
	 *
	 * @var string
	 */
	private const LOCK_PREFIX = 'hermiq/delegated-run/';

	/**
	 * States a part can still be claimed or timed out from.
	 *
	 * @var list<string>
	 */
	private const OPEN_STATES = ['queued', 'running'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService    $objectService OpenRegister's object service (server-side, no user context in a worker).
	 * @param ILockingProvider $locking       The instance-wide lock a claim is taken under.
	 * @param PollClock        $clock         Timestamps.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ILockingProvider $locking,
		private readonly PollClock $clock,
	) {
	}//end __construct()

	/**
	 * Write a part as queued.
	 *
	 * @param array<string, mixed> $record The part: parent run, target agent, task, owner and the rest of the schema.
	 *
	 * @return string The record's id.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function queue(array $record): string {
		$record['state']    = 'queued';
		$record['queuedAt'] = $this->timestamp();

		return (string)$this->save(record: $record)->getUuid();
	}//end queue()

	/**
	 * A record, or null when it is not there.
	 *
	 * @param string $id The record's id.
	 *
	 * @return array<string, mixed>|null The record with its id.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
	 */
	public function read(string $id): ?array {
		try {
			$found = $this->objectService->find(
				id: $id,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if ($found === null) {
			return null;
		}

		return ['id' => $id] + $found->getObject();
	}//end read()

	/**
	 * Claim a queued part: it moves to running, claimed by the worker or the parent.
	 *
	 * Only one claim of a part succeeds. A part that is held by another claim at
	 * this moment, or is no longer queued, is not claimed.
	 *
	 * @param string $id       The record's id.
	 * @param string $claimant worker or parent.
	 *
	 * @return array<string, mixed>|null The claimed record, or null when this claim lost.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
	 */
	public function claim(string $id, string $claimant): ?array {
		return $this->underLock(
			id: $id,
			change: function (array $record) use ($claimant): ?array {
				if (($record['state'] ?? '') !== 'queued') {
					return null;
				}

				$record['state']     = 'running';
				$record['claimedBy'] = $claimant;
				$record['claimedAt'] = $this->timestamp();

				return $record;
			}
		);
	}//end claim()

	/**
	 * Mark a part that is still open as timed out.
	 *
	 * @param string $id The record's id.
	 *
	 * @return bool True when the part was still open and is now timed out.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-a-part-no-worker-picks-up-runs-inline-req-dlgpar-002
	 */
	public function timeOut(string $id): bool {
		$changed = $this->underLock(
			id: $id,
			change: function (array $record): ?array {
				if (in_array(($record['state'] ?? ''), self::OPEN_STATES, true) === false) {
					return null;
				}

				$record['state']   = 'timed-out';
				$record['endedAt'] = $this->timestamp();

				return $record;
			}
		);

		return $changed !== null;
	}//end timeOut()

	/**
	 * Record how a running part ended. A part that timed out meanwhile stays timed out.
	 *
	 * @param string               $id     The record's id.
	 * @param array<string, mixed> $fields state (done or failed), result and runId.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function finish(string $id, array $fields): void {
		$this->underLock(
			id: $id,
			change: function (array $record) use ($fields): ?array {
				if (($record['state'] ?? '') !== 'running') {
					return null;
				}

				return array_merge($record, $fields, ['endedAt' => $this->timestamp()]);
			}
		);
	}//end finish()

	/**
	 * Read a record under its lock, change it, and save the change.
	 *
	 * @param string   $id     The record's id.
	 * @param callable $change Takes the record, returns the changed record or null for no change.
	 *
	 * @return array<string, mixed>|null The saved record, or null when nothing was saved.
	 */
	private function underLock(string $id, callable $change): ?array {
		$lock = self::LOCK_PREFIX.$id;
		try {
			$this->locking->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return null;
		}

		try {
			$record = $this->read(id: $id);
			if ($record === null) {
				return null;
			}

			$changed = $change($record);
			if ($changed === null) {
				return null;
			}

			$this->save(record: $changed);

			return $changed;
		} finally {
			$this->locking->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}//end try
	}//end underLock()

	/**
	 * Save a record (a new one when it has no id yet).
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity The saved object.
	 */
	private function save(array $record): \OCA\OpenRegister\Db\ObjectEntity {
		$id = $record['id'] ?? null;
		unset($record['id']);

		return $this->objectService->saveObject(
			object: $record,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $id,
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * Now, as ISO 8601 UTC.
	 *
	 * @return string
	 */
	private function timestamp(): string {
		return gmdate('Y-m-d\TH:i:s\Z', $this->clock->now());
	}//end timestamp()
}//end class
