<?php

/**
 * Hermiq WouldHaveCalled
 *
 * The arguments a dry run would have called a tool with.
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
 * The arguments a dry run would have called a tool with.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class WouldHaveCalled {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 * @param int|null $traceToken The trace step token already started
	 *                             by `dispatchToFacade()`, or null when
	 *                             no collector is attached.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly array $arguments,
		public readonly ?int $traceToken,
	) {
	}//end __construct()
}//end class
