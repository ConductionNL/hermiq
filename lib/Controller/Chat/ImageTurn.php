<?php

/**
 * Hermiq ImageTurn
 *
 * The person's image request and the image created for it.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller\Chat
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

namespace OCA\Hermiq\Controller\Chat;

/**
 * The person's image request and the image created for it.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ImageTurn {

	/**
	 * Constructor.
	 *
	 * @param string $uid The person.
	 * @param string $displayName Their display name at send time.
	 * @param string $prompt The description.
	 * @param array<string, mixed> $created What the service created.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly string $displayName,
		public readonly string $prompt,
		public readonly array $created,
	) {
	}//end __construct()
}//end class
