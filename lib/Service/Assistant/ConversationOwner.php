<?php

/**
 * Hermiq ConversationOwner
 *
 * The person and app a conversation belongs to.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Assistant
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

namespace OCA\Hermiq\Service\Assistant;

/**
 * The person and app a conversation belongs to.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ConversationOwner {

	/**
	 * Constructor.
	 *
	 * @param string $userId Current user id.
	 * @param string $app Calling app id (used to find/provision the dedicated Agent).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $app,
	) {
	}//end __construct()
}//end class
