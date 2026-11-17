<?php

/**
 * Hermiq MigratedConversation
 *
 * One conversation being moved to a session, and its source and target schemas.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One conversation being moved to a session, and its source and target schemas.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class MigratedConversation {

	/**
	 * Constructor.
	 *
	 * @param string $sourceSchema Source schema slug, for the archive-marker copy.
	 * @param string $targetSchema Target schema slug.
	 * @param string $uuid The uuid to preserve.
	 * @param ObjectEntity $source The source object.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $sourceSchema,
		public readonly string $targetSchema,
		public readonly string $uuid,
		public readonly ObjectEntity $source,
	) {
	}//end __construct()
}//end class
