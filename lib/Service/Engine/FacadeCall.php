<?php

/**
 * Hermiq FacadeCall
 *
 * The arguments a facade tool is called with, and any outcome that overrides it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
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

namespace OCA\Hermiq\Service\Engine;

/**
 * The arguments a facade tool is called with, and any outcome that overrides it.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class FacadeCall {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 * @param string|null $outcomeOverride A fixed trace-step outcome to record
	 *                                     instead of the facade's own ok/error
	 *                                     (agent-guardrails): `invoked_after_approval`
	 *                                     for a `confirm`-classified tool's authorised
	 *                                     retry, so the run history distinguishes it
	 *                                     from an ordinary `auto` invocation. Null
	 *                                     (every pre-existing caller) keeps the
	 *                                     original ok/error derivation unchanged.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly array $arguments,
		public readonly ?string $outcomeOverride = null,
	) {
	}//end __construct()
}//end class
