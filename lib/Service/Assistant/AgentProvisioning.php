<?php

/**
 * Hermiq AgentProvisioning
 *
 * The name, description and prompt of an assistant agent being provisioned.
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
 * The name, description and prompt of an assistant agent being provisioned.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class AgentProvisioning {

	/**
	 * Constructor.
	 *
	 * @param string $name The agent's fixed name.
	 * @param string $description The agent's fixed description.
	 * @param string $prompt The agent's fixed system prompt.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly string $prompt,
	) {
	}//end __construct()
}//end class
