<?php

/**
 * Hermiq PromptVariableScope
 *
 * The agent, organisation and context a turn's prompt variables are resolved in.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The agent, organisation and context a turn's prompt variables are resolved in.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class PromptVariableScope {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity|null $agent The session's agent.
	 * @param string $organisation The session's organisation.
	 * @param array<string, mixed> $appContext The companion's snapshot.
	 * @param array<string, mixed> $conversationData The session's data.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ?ObjectEntity $agent,
		public readonly string $organisation,
		public readonly array $appContext,
		public readonly array $conversationData,
	) {
	}//end __construct()
}//end class
