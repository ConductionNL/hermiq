<?php

/**
 * Contract of ReportSimilaritySettings
 *
 * The public surface of ReportSimilaritySettings that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of ReportSimilaritySettings.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportSimilaritySettingsInterface {

	/**
	 * Everything an administrator reads and edits.
	 *
	 * @return array{upper: float, lower: float, windows: array<string, int>} The settings.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
	 */
	public function all(): array;

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
	 * @spec openspec/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
	 */
	public function store(?float $upper, ?float $lower, ?array $windows): array;
}//end interface
