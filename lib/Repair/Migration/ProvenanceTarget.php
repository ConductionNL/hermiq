<?php

/**
 * Hermiq ProvenanceTarget
 *
 * The object whose provenance is carried to its new schema.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair\Migration
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

namespace OCA\Hermiq\Repair\Migration;

/**
 * The object whose provenance is carried to its new schema.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ProvenanceTarget {

	/**
	 * Constructor.
	 *
	 * @param string $targetSchema Target schema slug, written to.
	 * @param string $uuid The uuid on both sides.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $targetSchema,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
