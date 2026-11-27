<?php

/**
 * Hermiq TalkTurnOptions
 *
 * The optional collaborators of a Talk turn: the literacy requirement a speaker
 * must meet, the app config the edit throttle is read from, the agent chain an
 * answer is followed through, and the URL generator for the link to the session
 * on the Chat page. Each may be absent; the turn then skips what it serves.
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
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * The optional collaborators of a Talk turn.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */
final class TalkTurnOptions {

	/**
	 * Constructor.
	 *
	 * @param LiteracyRequirement|null $literacy   The course requirement (compliance-ai-literacy).
	 * @param IAppConfig|null          $appConfig  Reads the edit throttle (instant-talk).
	 * @param TalkAgentChain|null      $agentChain Follows an answer to the agents it mentions (instant-talk).
	 * @param IURLGenerator|null       $urls       Builds the link to the session on the Chat page.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function __construct(
		public readonly ?LiteracyRequirement $literacy = null,
		public readonly ?IAppConfig $appConfig = null,
		public readonly ?TalkAgentChain $agentChain = null,
		public readonly ?IURLGenerator $urls = null,
	) {
	}//end __construct()
}//end class
