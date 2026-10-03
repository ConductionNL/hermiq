<?php

/**
 * Hermiq RunRetentionPolicy.
 *
 * How long an AI run's record is kept, and the report that says the keeping is
 * actually enforced. An AVG verwerkingsregister has to say what personal data a
 * model was shown and for how long, and a retention nobody set is a retention of
 * forever, so this instance always has a default.
 *
 * The resolved period is written onto the run rather than referenced. Changing the
 * default next month must not shorten or extend what an existing run was promised,
 * which is the same reasoning the residency label uses: the record of what was true
 * then survives what is true now.
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
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-every-ai-run-must-carry-the-retention-that-applied-when-it-was-written
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AiFeatureService;
use OCP\IAppConfig;
use Throwable;

/**
 * Resolves, stamps and reports the retention of AI run records.
 *
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-every-ai-run-must-carry-the-retention-that-applied-when-it-was-written
 */
class RunRetentionPolicy {

	/**
	 * The retention this instance keeps until an administrator says otherwise.
	 * Ninety days is the period the openproject passer ships and the one an FG
	 * recognises; what matters is that a number exists rather than which.
	 *
	 * @var int
	 */
	public const DEFAULT_DAYS = 90;

	/**
	 * The shortest retention an administrator may set. A retention of zero would
	 * delete a run before anybody could read it, which is not storage limitation
	 * but an absent audit trail.
	 *
	 * @var int
	 */
	public const MINIMUM_DAYS = 1;

	/**
	 * The longest retention an administrator may set, ten years, matching the
	 * ceiling the audit trail itself supports.
	 *
	 * @var int
	 */
	public const MAXIMUM_DAYS = 3650;

	/**
	 * IAppConfig key holding the instance default, in days.
	 *
	 * @var string
	 */
	private const CONFIG_DEFAULT = 'runRetentionDays';

	/**
	 * IAppConfig key holding the last cleanup report, as JSON.
	 *
	 * @var string
	 */
	private const CONFIG_LAST_CLEANUP = 'runRetentionLastCleanup';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config holding the default and the report.
	 * @param AiFeatureService $features Reads a feature's own shorter retention.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AiFeatureService $features,
	) {
	}//end __construct()

	/**
	 * The instance default, in days. Never absent: an unset or unusable value reads
	 * as the shipped default rather than as no retention at all.
	 *
	 * @return int The default retention in days.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-every-ai-run-must-carry-the-retention-that-applied-when-it-was-written
	 */
	public function defaultDays(): int {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_DEFAULT, '');
		if ($raw === '' || is_numeric($raw) === false) {
			return self::DEFAULT_DAYS;
		}

		$days = (int)$raw;
		if ($days < self::MINIMUM_DAYS || $days > self::MAXIMUM_DAYS) {
			return self::DEFAULT_DAYS;
		}

		return $days;
	}//end defaultDays()

	/**
	 * Set the instance default.
	 *
	 * @param int $days The new default, in days.
	 *
	 * @return int The stored default.
	 *
	 * @throws InvalidArgumentException When the value is outside the permitted range.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-every-ai-run-must-carry-the-retention-that-applied-when-it-was-written
	 */
	public function setDefaultDays(int $days): int {
		if ($days < self::MINIMUM_DAYS || $days > self::MAXIMUM_DAYS) {
			throw new InvalidArgumentException(
				sprintf(
					'A retention must be between %d and %d days, and %d is outside that.',
					self::MINIMUM_DAYS,
					self::MAXIMUM_DAYS,
					$days
				)
			);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_DEFAULT, (string)$days);

		return $days;
	}//end setDefaultDays()

	/**
	 * The retention that applies to a run of one feature: the feature's own
	 * override when it sets one, otherwise the instance default.
	 *
	 * @param string|null $featureSlug The AI feature this run belongs to, when it names one.
	 *
	 * @return int The retention in days.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-a-feature-may-keep-less
	 */
	public function retentionDaysFor(?string $featureSlug): int {
		$default = $this->defaultDays();

		if ($featureSlug === null || trim($featureSlug) === '') {
			return $default;
		}

		try {
			$feature = $this->features->findBySlug(slug: trim($featureSlug));
		} catch (Throwable $e) {
			return $default;
		}

		if ($feature === null) {
			return $default;
		}

		$override = ($feature->getObject()['retentionDays'] ?? null);
		if (is_numeric($override) === false) {
			return $default;
		}

		$days = (int)$override;
		if ($days < self::MINIMUM_DAYS || $days > self::MAXIMUM_DAYS) {
			return $default;
		}

		return $days;
	}//end retentionDaysFor()

	/**
	 * Write the resolved retention onto a run's context, as a number of days and as
	 * the moment it expires. Both are copied, so a later change to the default
	 * cannot move a promise already made.
	 *
	 * @param array<string, mixed> $context The run context about to be persisted.
	 * @param string|null $featureSlug The AI feature this run belongs to.
	 * @param DateTimeImmutable|null $now The moment of the run, for a deterministic test.
	 *
	 * @return array<string, mixed> The context with its retention stamped on.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-a-run-knows-its-own-expiry
	 */
	public function stamp(array $context, ?string $featureSlug = null, ?DateTimeImmutable $now = null): array {
		$at = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$days = $this->retentionDaysFor(featureSlug: $featureSlug);

		$context['retentionDays'] = $days;
		$context['retentionExpiresAt'] = $at->modify(sprintf('+%d days', $days))->format('c');

		return $context;
	}//end stamp()

	/**
	 * Record that the cleanup job ran, and what it did.
	 *
	 * @param int $removed How many run payloads it removed.
	 * @param DateTimeImmutable|null $at When it ran.
	 *
	 * @return array{ran: bool, at: string, removed: int} The stored report.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-a-scheduled-job-must-enforce-retention-and-must-report-that-it-did
	 */
	public function recordCleanup(int $removed, ?DateTimeImmutable $at = null): array {
		$report = [
			'ran' => true,
			'at' => ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
			'removed' => $removed,
		];

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_LAST_CLEANUP,
			(string)json_encode($report)
		);

		return $report;
	}//end recordCleanup()

	/**
	 * When retention last ran and how much it removed. A job that has never run
	 * reads as never run, rather than as a successful run of zero: those are
	 * different answers to an administrator asking whether retention is enforced.
	 *
	 * @return array{ran: bool, at: string, removed: int|null} The report.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-a-job-that-has-never-run-is-visible-as-such
	 */
	public function lastCleanup(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_LAST_CLEANUP, '');
		$decoded = null;
		if ($raw !== '') {
			$decoded = json_decode($raw, true);
		}

		if (is_array($decoded) === false || ($decoded['ran'] ?? false) !== true) {
			return [
				'ran' => false,
				'at' => '',
				'removed' => null,
			];
		}

		return [
			'ran' => true,
			'at' => (string)($decoded['at'] ?? ''),
			'removed' => (int)($decoded['removed'] ?? 0),
		];

	}//end lastCleanup()
}//end class
