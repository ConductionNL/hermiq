<?php

/**
 * Hermiq BotFailure.
 *
 * What the Talk bot installer logs when a spreed call throws: the message and
 * the identifiers that say which bot and room it was about.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

/**
 * One failure warning of the bot installer.
 *
 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity
 */
final class BotFailure {

	/**
	 * The identifiers logged between the file/line and the error: agentId, roomToken.
	 *
	 * @var array<string, string>
	 */
	public array $context = [];

	/**
	 * Build the warning.
	 *
	 * @param string $message The log message.
	 */
	public function __construct(
		public readonly string $message,
	) {
	}//end __construct()
}//end class
