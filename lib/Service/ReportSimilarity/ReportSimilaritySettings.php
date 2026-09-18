<?php

/**
 * Hermiq ReportSimilaritySettings.
 *
 * The two thresholds and the comparison window, administered rather than compiled
 * in. The cost of each kind of mistake is the municipality's to weigh: a genuinely
 * separate report buried inside a group of two hundred, or two hundred and one items
 * where there was one event.
 *
 * The window is per report type, because a streetlight reported in March and one in
 * October are not one event however alike the text, and an unbounded comparison is
 * both expensive and wrong.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ReportSimilarity
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
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#requirement-a-report-must-fall-into-one-of-three-bands-not-two
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ReportSimilarity;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and writes the grouping thresholds and windows.
 *
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
 */
class ReportSimilaritySettings {

	/**
	 * At or above this score a report joins a group and is counted into it.
	 *
	 * @var float
	 */
	public const DEFAULT_UPPER = 0.85;

	/**
	 * Below this score a report starts its own group.
	 *
	 * @var float
	 */
	public const DEFAULT_LOWER = 0.55;

	/**
	 * The default comparison window, in minutes: one day.
	 *
	 * @var int
	 */
	public const DEFAULT_WINDOW_MINUTES = 1440;

	/**
	 * The IAppConfig key holding the settings as JSON.
	 *
	 * @var string
	 */
	private const CONFIG_KEY = 'reportSimilarity';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config holding the settings.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The upper threshold: at or above it, the same event.
	 *
	 * @return float The threshold.
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
	 */
	public function upper(): float {
		return $this->score(key: 'upper', default: self::DEFAULT_UPPER);
	}//end upper()

	/**
	 * The lower threshold: below it, a report of its own.
	 *
	 * @return float The threshold.
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
	 */
	public function lower(): float {
		return $this->score(key: 'lower', default: self::DEFAULT_LOWER);
	}//end lower()

	/**
	 * The comparison window for one report type, in minutes.
	 *
	 * @param string $reportType The report type, as the owning app names it.
	 *
	 * @return int The window in minutes.
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#requirement-the-comparison-window-must-be-bounded-and-administered
	 */
	public function windowMinutes(string $reportType): int {
		$windows = ($this->stored()['windows'] ?? []);
		if (is_array($windows) === false) {
			return self::DEFAULT_WINDOW_MINUTES;
		}

		$value = ($windows[$reportType] ?? null);
		if (is_numeric($value) === false || (int)$value < 1) {
			return self::DEFAULT_WINDOW_MINUTES;
		}

		return (int)$value;
	}//end windowMinutes()

	/**
	 * Everything an administrator reads and edits.
	 *
	 * @return array{upper: float, lower: float, windows: array<string, int>} The settings.
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
	 */
	public function all(): array {
		$windows = [];
		foreach (($this->stored()['windows'] ?? []) as $type => $minutes) {
			if (is_numeric($minutes) === true) {
				$windows[(string)$type] = (int)$minutes;
			}
		}

		return [
			'upper' => $this->upper(),
			'lower' => $this->lower(),
			'windows' => $windows,
			'defaultWindowMinutes' => self::DEFAULT_WINDOW_MINUTES,
		];

	}//end all()

	/**
	 * Store the thresholds and the per-type windows.
	 *
	 * @param float|null $upper The upper threshold, or null to leave it.
	 * @param float|null $lower The lower threshold, or null to leave it.
	 * @param array<string, int>|null $windows The per-type windows, or null to leave them.
	 *
	 * @return array{upper: float, lower: float, windows: array<string, int>} The stored settings.
	 *
	 * @throws InvalidArgumentException When a threshold is outside 0..1, or the lower is not below the upper.
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
	 */
	public function store(?float $upper, ?float $lower, ?array $windows): array {
		$stored = $this->stored();

		$newUpper = ($upper ?? $this->upper());
		$newLower = ($lower ?? $this->lower());

		foreach (['upper' => $newUpper, 'lower' => $newLower] as $name => $value) {
			if ($value < 0.0 || $value > 1.0) {
				throw new InvalidArgumentException(
					sprintf('The %s threshold is a score between 0 and 1, and %s is outside that.', $name, (string)$value)
				);
			}
		}

		if ($newLower >= $newUpper) {
			throw new InvalidArgumentException(
				'The lower threshold has to sit below the upper one, or there is no middle band for a '
				. 'near-duplicate to fall into, which is the band a human is meant to look at.'
			);
		}

		$stored['upper'] = $newUpper;
		$stored['lower'] = $newLower;

		if ($windows !== null) {
			$clean = [];
			foreach ($windows as $type => $minutes) {
				if ((int)$minutes < 1) {
					throw new InvalidArgumentException(
						sprintf("The window for '%s' is a number of minutes, and it has to be at least one.", (string)$type)
					);
				}

				$clean[(string)$type] = (int)$minutes;
			}

			$stored['windows'] = $clean;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, (string)json_encode($stored));

		return $this->all();
	}//end store()

	/**
	 * One stored score, or its default.
	 *
	 * @param string $key The setting key.
	 * @param float $default The default.
	 *
	 * @return float The score.
	 */
	private function score(string $key, float $default): float {
		$value = ($this->stored()[$key] ?? null);
		if (is_numeric($value) === false) {
			return $default;
		}

		$score = (float)$value;
		if ($score < 0.0 || $score > 1.0) {
			return $default;
		}

		return $score;
	}//end score()

	/**
	 * The raw stored settings.
	 *
	 * @return array<string, mixed> The settings.
	 */
	private function stored(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end stored()
}//end class
