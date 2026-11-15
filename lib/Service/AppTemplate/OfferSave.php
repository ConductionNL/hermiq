<?php

/**
 * Hermiq OfferSave
 *
 * Which app offered a template, and which stored offer it replaces.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AppTemplate
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

namespace OCA\Hermiq\Service\AppTemplate;

/**
 * Which app offered a template, and which stored offer it replaces.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class OfferSave {

	/**
	 * Constructor.
	 *
	 * @param string $appId The offering app.
	 * @param string $offerHash The sha256 of the offered package.
	 * @param string|null $replaceUuid The earlier template of this offer, or null for a new one.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $offerHash,
		public readonly ?string $replaceUuid = null,
	) {
	}//end __construct()
}//end class
