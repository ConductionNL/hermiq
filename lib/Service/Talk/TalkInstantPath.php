<?php

/**
 * Hermiq TalkInstantPath
 *
 * What the dispatcher needs to run a turn in this process once the sender's
 * response is out (instant-talk path B): the executor that claims and runs the
 * turn, the claim store with its concurrency cap, the post-response runner, and
 * the switch and cap read from the app config. Grouped so the dispatcher takes
 * the whole path, or none of it, as one optional collaborator.
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

use OCP\IAppConfig;

/**
 * The instant path's collaborators and settings.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */
final class TalkInstantPath {

	/**
	 * The app config key switching the instant path, and its default.
	 */
	public const ENABLED_KEY = 'talk.instantTurns';

	/**
	 * The app config key capping concurrent instant turns, and its default.
	 */
	public const CAP_KEY = 'talk.instantTurnsMax';

	public const DEFAULT_CAP = 2;

	/**
	 * Constructor.
	 *
	 * @param TalkTurnExecutor       $executor     Claims and runs a turn.
	 * @param TalkTurnClaims         $claims       The claim store and the concurrency slots.
	 * @param TalkPostResponseRunner $postResponse Runs work after the response.
	 * @param IAppConfig             $appConfig    Reads the switch and the cap.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function __construct(
		public readonly TalkTurnExecutor $executor,
		public readonly TalkTurnClaims $claims,
		public readonly TalkPostResponseRunner $postResponse,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether post-response instant execution is switched on (default on).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#2-immediate-execution-paths
	 */
	public function enabled(): bool {
		return $this->appConfig->getValueBool('hermiq', self::ENABLED_KEY, true);
	}//end enabled()

	/**
	 * The most instant turns one instance runs at once (default 2).
	 *
	 * @return int
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#2-immediate-execution-paths
	 */
	public function cap(): int {
		return $this->appConfig->getValueInt('hermiq', self::CAP_KEY, self::DEFAULT_CAP);
	}//end cap()
}//end class
