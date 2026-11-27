<?php

/**
 * Hermiq FeatureTransition
 *
 * The lifecycle state an AI feature moves to, and the states it may move from.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

/**
 * The lifecycle state an AI feature moves to, and the states it may move from.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class FeatureTransition {

	/**
	 * Constructor.
	 *
	 * @param string $target The target lifecycle value.
	 * @param array<int,string> $from The lifecycle values from which the transition is allowed.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $target,
		public readonly array $from,
	) {
	}//end __construct()
}//end class
