<?php

/**
 * Hermiq SummaryWrite
 *
 * The agent, prompt and schema a record summary is written with.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The agent, prompt and schema a record summary is written with.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class SummaryWrite {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $agent The answering agent.
	 * @param string $prompt The summary prompt.
	 * @param string $schema The record's schema, named to the model as its type.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ObjectEntity $agent,
		public readonly string $prompt,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
