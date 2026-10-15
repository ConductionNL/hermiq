<?php

/**
 * Hermiq IntakeSettings.
 *
 * The one number that decides whether the intake files or hands over: the confidence
 * below which the assistant says it does not know.
 *
 * It is administered and readable, because a wrong confident filing loses more than
 * an abstention. A bezwaar filed as a melding loses a statutory term, and nobody
 * notices until the term has run.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Intake
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
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-a-classification-must-carry-a-confidence-and-must-be-able-to-abstain
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Intake;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and writes the abstention threshold.
 *
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-the-threshold-is-readable
 */
class IntakeSettings {

	/**
	 * The confidence an intake needs before it files rather than hands over. Set
	 * high on purpose: an abstention costs a person a short wait, and a bezwaar
	 * filed as a melding costs them a statutory term.
	 *
	 * @var float
	 */
	public const DEFAULT_THRESHOLD = 0.8;

	/**
	 * The IAppConfig key holding the settings as JSON.
	 *
	 * @var string
	 */
	private const CONFIG_KEY = 'intake';

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
	 * The confidence below which the intake abstains.
	 *
	 * @return float The threshold.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-the-threshold-is-readable
	 */
	public function abstentionThreshold(): float {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return self::DEFAULT_THRESHOLD;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false || is_numeric($decoded['abstentionThreshold'] ?? null) === false) {
			return self::DEFAULT_THRESHOLD;
		}

		$threshold = (float)$decoded['abstentionThreshold'];
		if ($threshold < 0.0 || $threshold > 1.0) {
			return self::DEFAULT_THRESHOLD;
		}

		return $threshold;
	}//end abstentionThreshold()

	/**
	 * Set the abstention threshold.
	 *
	 * @param float $threshold The new threshold, between 0 and 1.
	 *
	 * @return float The stored threshold.
	 *
	 * @throws InvalidArgumentException When the value is outside 0..1.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-a-classification-must-carry-a-confidence-and-must-be-able-to-abstain
	 */
	public function setAbstentionThreshold(float $threshold): float {
		if ($threshold < 0.0 || $threshold > 1.0) {
			throw new InvalidArgumentException(
				sprintf('A confidence threshold is between 0 and 1, and %s is outside that.', (string)$threshold)
			);
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			(string)json_encode(['abstentionThreshold' => $threshold])
		);

		return $threshold;
	}//end setAbstentionThreshold()

	/**
	 * Everything an administrator reads.
	 *
	 * @return array{abstentionThreshold: float, default: float} The settings.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-the-threshold-is-readable
	 */
	public function all(): array {
		return [
			'abstentionThreshold' => $this->abstentionThreshold(),
			'default' => self::DEFAULT_THRESHOLD,
		];

	}//end all()
}//end class
