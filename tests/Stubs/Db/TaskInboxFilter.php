<?php

/**
 * Value-object stub for OpenRegister's TaskInboxFilter.
 *
 * Mirrors the REAL constructor of openregister development so hermiq's
 * ApprovalTaskInbox builds the same criteria it builds against the real
 * class; it only stores what it is given and decides nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Row filters of an inbox query.
 */
final class TaskInboxFilter {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $states     Task states.
	 * @param bool|null          $isTerminal Terminal or open; null for both.
	 * @param string|null        $priority   A priority.
	 * @param string|null        $kind       A task kind.
	 * @param string|null        $term       A free-text term.
	 */
	public function __construct(
		public readonly array $states = [],
		public readonly ?bool $isTerminal = null,
		public readonly ?string $priority = null,
		public readonly ?string $kind = null,
		public readonly ?string $term = null,
	) {
	}//end __construct()
}//end class
