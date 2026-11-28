<?php

/**
 * Hermiq TaskSource
 *
 * The calendar object and list a task is read from.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
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

namespace OCA\Hermiq\Service\NcNative;

use OCP\Calendar\ICalendar;

/**
 * The calendar object and list a task is read from.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class TaskSource {

	/**
	 * Constructor.
	 *
	 * @param mixed $object The row's VTODO.
	 * @param ICalendar $list The list it came from.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly mixed $object,
		public readonly ICalendar $list,
	) {
	}//end __construct()
}//end class
