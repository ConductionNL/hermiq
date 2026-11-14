<?php

/**
 * Hermiq MirrorTurnToTalkJob.
 *
 * Posts a Chat-page turn into its session's Talk room out of the request, so
 * the person on the Chat page never waits on Talk. All logic lives in
 * TalkTurnMirror; this class stays a pure wrapper.
 *
 * @category Cron
 * @package  OCA\Hermiq\BackgroundJob
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
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Kernel\Contracts\TalkTurnMirrorInterface;
use OCA\Hermiq\Service\Talk\TalkTurnMirror;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Runs one queued mirror.
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
 */
class MirrorTurnToTalkJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory            $time   The time factory.
	 * @param TalkTurnMirrorInterface $mirror Posts the turn into the room.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TalkTurnMirrorInterface $mirror,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the queued mirror.
	 *
	 * @param mixed $argument The queued turn.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			return;
		}

		$turn = [];
		foreach (TalkTurnMirror::TURN_FIELDS as $field) {
			$turn[$field] = (string)($argument[$field] ?? '');
		}

		$this->mirror->mirror(turn: $turn);
	}//end run()
}//end class
