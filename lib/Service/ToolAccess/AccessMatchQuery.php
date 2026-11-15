<?php

/**
 * Hermiq AccessMatchQuery
 *
 * Who asks for tool access, what they hold, and the search terms.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ToolAccess
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

namespace OCA\Hermiq\Service\ToolAccess;

/**
 * Who asks for tool access, what they hold, and the search terms.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class AccessMatchQuery {

	/**
	 * Constructor.
	 *
	 * @param string $uid The acting user.
	 * @param array<string, mixed> $held The ids the agent already holds.
	 * @param array<int, string> $tokens The query tokens; [] matches everything.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly array $held,
		public readonly array $tokens,
	) {
	}//end __construct()
}//end class
