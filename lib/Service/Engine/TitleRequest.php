<?php

/**
 * Hermiq TitleRequest
 *
 * The turn a conversation title is written from.
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
 * The turn a conversation title is written from.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class TitleRequest {

	/**
	 * Constructor.
	 *
	 * @param string $userMessage The first user message to name it from.
	 * @param string $userId The impersonated owner's UID.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $userMessage,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
