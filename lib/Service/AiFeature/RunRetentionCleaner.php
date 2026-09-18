<?php

/**
 * Hermiq RunRetentionCleaner.
 *
 * The job half of retention. A retention setting without something that enforces it
 * is worse than neither: the screen says ninety days and the data is still there in
 * year three.
 *
 * What it removes is the payload, never the entry. The audit trail is a `hash` and
 * `previousHash` chain, so deleting a row invalidates every hash after it and
 * destroys the property the chain exists for. What is left behind is a tombstone:
 * that a run happened, when, for which feature and provider, and that its payload
 * was deleted under retention on a stated date. The article 30 record of the
 * processing survives; the personal data does not, which is what storage limitation
 * asks for.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AiFeature
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
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-retention-must-remove-the-payload-and-must-not-break-the-chain
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes expired run payloads and leaves a tombstone in their place.
 *
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-a-scheduled-job-must-enforce-retention-and-must-report-that-it-did
 */
class RunRetentionCleaner {

	/**
	 * The audit actions hermiq writes a run under.
	 *
	 * @var array<int, string>
	 */
	public const RUN_ACTIONS = ['run', 'agent-run'];

	/**
	 * The marker a cleaned entry carries, so a tombstone is recognisable as one
	 * rather than as an empty run.
	 *
	 * @var string
	 */
	public const TOMBSTONE_MARKER = 'deleted-under-retention';

	/**
	 * The fields a tombstone keeps. Everything else is the payload, and the payload
	 * is what retention removes.
	 *
	 * @var array<int, string>
	 */
	private const KEPT_FIELDS = ['feature', 'provider', 'model', 'residency', 'retentionDays', 'retentionExpiresAt', 'startedAt'];

	/**
	 * How many entries one pass reads, so a cleanup on a large instance is bounded
	 * and finishes the rest on the next tick.
	 *
	 * @var int
	 */
	private const BATCH = 500;

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper The audit chain this reads and updates.
	 * @param RunRetentionPolicy $policy Resolves and reports retention.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly RunRetentionPolicy $policy,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Remove the payload of every run entry past its own recorded retention, and
	 * record that the job ran.
	 *
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return int How many entries were tombstoned.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-expired-runs-lose-their-payload
	 */
	public function clean(?DateTimeImmutable $now = null): int {
		$at = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$removed = 0;

		foreach (self::RUN_ACTIONS as $action) {
			foreach ($this->entriesFor(action: $action) as $entry) {
				if ($this->isExpired(entry: $entry, at: $at) === false) {
					continue;
				}

				$entry->setChanged($this->tombstone(changed: (array)$entry->getChanged(), at: $at));

				try {
					$this->auditTrailMapper->update($entry);
					$removed++;
				} catch (Throwable $e) {
					$this->logger->warning(
						'Hermiq could not tombstone an expired run entry: ' . $e->getMessage(),
						['exception' => $e, 'entry' => (string)$entry->getUuid()]
					);
				}
			}//end foreach
		}//end foreach

		$this->policy->recordCleanup(removed: $removed, at: $at);

		return $removed;
	}//end clean()

	/**
	 * Build the tombstone that replaces a run's payload: what happened, when, under
	 * which feature and provider, and that the rest was deleted under retention.
	 *
	 * @param array<string, mixed> $changed The entry's current payload.
	 * @param DateTimeImmutable $at The deletion moment.
	 *
	 * @return array<string, mixed> The tombstone.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-the-processing-is-still-recorded-after-the-data-is-gone
	 */
	public function tombstone(array $changed, DateTimeImmutable $at): array {
		$tombstone = [
			'retention' => self::TOMBSTONE_MARKER,
			'deletedUnderRetentionAt' => $at->format('c'),
		];

		foreach (self::KEPT_FIELDS as $field) {
			if (array_key_exists($field, $changed) === true && is_scalar($changed[$field]) === true) {
				$tombstone[$field] = $changed[$field];
			}
		}

		// The disclosure a run records about which model saw the case is kept in the
		// same shape it was written, minus anything that is not a plain label: the
		// question "which model saw this, and where" must stay answerable after the
		// text is gone, and none of those five fields is personal data.
		$disclosure = ($changed['providerDisclosure'] ?? null);
		if (is_array($disclosure) === true) {
			$tombstone['providerDisclosure'] = array_intersect_key(
				$disclosure,
				array_flip(['feature', 'provider', 'model', 'residency', 'location'])
			);
		}

		return $tombstone;
	}//end tombstone()

	/**
	 * Whether one entry is past the retention it was written with. An entry with no
	 * recorded retention is left alone: it was written before this change, and
	 * deleting it under a promise it never carried would be a rule applied
	 * backwards.
	 *
	 * @param AuditTrail $entry The audit entry.
	 * @param DateTimeImmutable $at The moment to judge against.
	 *
	 * @return bool True when the payload should be removed.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-expired-runs-lose-their-payload
	 */
	public function isExpired(AuditTrail $entry, DateTimeImmutable $at): bool {
		$changed = (array)$entry->getChanged();

		if (($changed['retention'] ?? '') === self::TOMBSTONE_MARKER) {
			return false;
		}

		$expiresAt = (string)($changed['retentionExpiresAt'] ?? '');
		if ($expiresAt === '') {
			return false;
		}

		try {
			$expiry = new DateTimeImmutable($expiresAt);
		} catch (Throwable $e) {
			return false;
		}

		return $expiry <= $at;
	}//end isExpired()

	/**
	 * Read one batch of run entries for an action.
	 *
	 * @param string $action The audit action.
	 *
	 * @return array<int, AuditTrail> The entries.
	 */
	private function entriesFor(string $action): array {
		try {
			$entries = $this->auditTrailMapper->findAll(
				limit: self::BATCH,
				offset: 0,
				filters: ['action' => $action],
				sort: ['created' => 'ASC']
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not read run entries for retention: ' . $e->getMessage(),
				['exception' => $e, 'action' => $action]
			);

			return [];
		}

		return array_values(array_filter($entries, static fn ($entry): bool => $entry instanceof AuditTrail));
	}//end entriesFor()
}//end class
