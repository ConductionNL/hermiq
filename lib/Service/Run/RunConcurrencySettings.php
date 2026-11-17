<?php

/**
 * Hermiq RunConcurrencySettings
 *
 * How many scheduled runs may go at once, for the instance and per organisation.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Run
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Run;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * The administrator's limits on parallel scheduled runs, kept in the app config
 * key `run_concurrency` as `{maxParallel, maxParallelPerOrganisation}`.
 *
 * A missing or unreadable value reads as the defaults, 4 for the instance and 2
 * per organisation. The time-to-live of a claim is fixed here as well, because
 * the claim, the sweep and the limit count must agree on it.
 *
 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
 */
class RunConcurrencySettings {

	/**
	 * The app config key.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'run_concurrency';

	/**
	 * The default number of scheduled runs at once on the instance.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_PARALLEL = 4;

	/**
	 * The default number of scheduled runs at once per organisation.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_PER_ORGANISATION = 2;

	/**
	 * The largest limit an administrator can set.
	 *
	 * @var int
	 */
	public const MAXIMUM = 64;

	/**
	 * How long a claim holds, in seconds: longer than the longest allowed turn.
	 *
	 * @var int
	 */
	public const CLAIM_TTL_SECONDS = 1800;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the limits.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The limits in force.
	 *
	 * @return array{maxParallel: int, maxParallelPerOrganisation: int}
	 *
	 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
	 */
	public function limits(): array {
		$stored = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, ''), true);
		if (is_array($stored) === false) {
			$stored = [];
		}

		return [
			'maxParallel' => self::validOr(value: ($stored['maxParallel'] ?? null), fallback: self::DEFAULT_MAX_PARALLEL),
			'maxParallelPerOrganisation' => self::validOr(
				value: ($stored['maxParallelPerOrganisation'] ?? null),
				fallback: self::DEFAULT_MAX_PER_ORGANISATION
			),
		];
	}//end limits()

	/**
	 * Store new limits.
	 *
	 * @param mixed $maxParallel                The instance limit.
	 * @param mixed $maxParallelPerOrganisation The per-organisation limit.
	 *
	 * @return array{maxParallel: int, maxParallelPerOrganisation: int} The limits stored.
	 *
	 * @throws InvalidArgumentException When a limit is not a whole number from 1 to 64, or the
	 *                                  organisation limit is above the instance limit.
	 *
	 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
	 */
	public function setLimits(mixed $maxParallel, mixed $maxParallelPerOrganisation): array {
		$instance = self::parsed(value: $maxParallel);
		$organisation = self::parsed(value: $maxParallelPerOrganisation);
		if ($instance === null || $organisation === null) {
			throw new InvalidArgumentException('A limit is a whole number from 1 to ' . self::MAXIMUM . '.');
		}

		if ($organisation > $instance) {
			throw new InvalidArgumentException('The limit per organisation cannot be above the limit for the instance.');
		}

		$limits = ['maxParallel' => $instance, 'maxParallelPerOrganisation' => $organisation];
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, (string)json_encode($limits));

		return $limits;
	}//end setLimits()

	/**
	 * Whether one more run fits under the limits.
	 *
	 * @param int $runningOnInstance     Scheduled runs running now on the instance.
	 * @param int $runningInOrganisation Scheduled runs running now in the run's organisation.
	 *
	 * @return bool True when the run may start.
	 *
	 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
	 */
	public function admits(int $runningOnInstance, int $runningInOrganisation): bool {
		$limits = $this->limits();

		return $runningOnInstance < $limits['maxParallel']
			&& $runningInOrganisation < $limits['maxParallelPerOrganisation'];
	}//end admits()

	/**
	 * A stored limit, or the fallback when it is not a valid one.
	 *
	 * @param mixed $value    The stored value.
	 * @param int   $fallback The default.
	 *
	 * @return int The limit.
	 */
	private static function validOr(mixed $value, int $fallback): int {
		return (self::parsed(value: $value) ?? $fallback);
	}//end validOr()

	/**
	 * A limit as a whole number from 1 to MAXIMUM, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null The limit.
	 */
	private static function parsed(mixed $value): ?int {
		if (is_int($value) === false && (is_string($value) === false || preg_match('/^\d+$/', $value) !== 1)) {
			return null;
		}

		$limit = (int)$value;
		if ($limit < 1 || $limit > self::MAXIMUM) {
			return null;
		}

		return $limit;
	}//end parsed()
}//end class
