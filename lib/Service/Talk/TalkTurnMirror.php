<?php

/**
 * Hermiq TalkTurnMirror.
 *
 * A turn typed on the Chat page of a session that has a Talk room is posted
 * into that room through the agent's bot: the question, prefixed with who
 * asked it and "via Hermiq", and the agent's answer. The request only queues
 * the mirror (MirrorTurnToTalkJob), so the Chat page never waits on Talk, and
 * a Talk failure is logged and never fails the turn.
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
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\Hermiq\BackgroundJob\MirrorTurnToTalkJob;
use OCA\Hermiq\Kernel\Contracts\TalkTurnMirrorInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues and posts the mirror of a Chat-page turn into the bound Talk room.
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
 */
class TalkTurnMirror implements TalkTurnMirrorInterface {

	/**
	 * The longest answer posted as it is; a longer one is cut and linked.
	 */
	public const MAX_MESSAGE = 30000;

	/**
	 * The fields a queued mirror carries.
	 *
	 * @var array<int, string>
	 */
	public const TURN_FIELDS = ['sessionId', 'roomToken', 'agentId', 'userId', 'question', 'answer'];

	/**
	 * Constructor.
	 *
	 * @param TalkBridge      $bridge Posts into the room as the agent's bot.
	 * @param IJobList        $jobs   Queues the mirror out of the request.
	 * @param IUserManager    $users  The asker's display name.
	 * @param IURLGenerator   $urls   The link to the session for a cut answer.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TalkBridge $bridge,
		private readonly IJobList $jobs,
		private readonly IUserManager $users,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Queue the mirror of a Chat-page turn into the session's Talk room.
	 *
	 * Nothing is queued for a session without a room, or for an empty question
	 * or answer. A failure to queue is logged and answered false.
	 *
	 * @param array<string, mixed> $session   The session object.
	 * @param string               $sessionId The session's uuid.
	 * @param string               $userId    Who typed the question.
	 * @param string               $question  The question.
	 * @param string               $answer    The agent's answer.
	 *
	 * @return bool True when a mirror was queued.
	 *
	 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
	 */
	public function enqueue(array $session, string $sessionId, string $userId, string $question, string $answer): bool {
		$turn = [
			'sessionId' => $sessionId,
			'roomToken' => (string)($session['talkRoomToken'] ?? ''),
			'agentId' => (string)($session['agentId'] ?? ''),
			'userId' => $userId,
			'question' => trim($question),
			'answer' => trim($answer),
		];
		if (self::isComplete(turn: $turn) === false) {
			return false;
		}

		try {
			$this->jobs->add(MirrorTurnToTalkJob::class, $turn);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TalkTurnMirror] Could not queue the mirror of a chat turn into its Talk room',
				context: ['sessionId' => $sessionId, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end enqueue()

	/**
	 * Post the question and the answer into the room as the agent's bot.
	 *
	 * The answer is posted only after the question was; a failure of either is
	 * logged. The turn itself is already stored and stays as it is.
	 *
	 * @param array<string, string> $turn The queued turn.
	 *
	 * @return bool True when both messages were posted.
	 *
	 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
	 */
	public function mirror(array $turn): bool {
		if (self::isComplete(turn: $turn) === false) {
			return false;
		}

		$agentId = $turn['agentId'];
		if ($agentId === '') {
			$agentId = null;
		}

		$posted = $this->bridge->postToRoom(roomToken: $turn['roomToken'], message: $this->questionLine(turn: $turn), agentId: $agentId)
			&& $this->bridge->postToRoom(roomToken: $turn['roomToken'], message: $this->answerText(turn: $turn), agentId: $agentId);
		if ($posted === false) {
			$this->logger->warning(
				message: '[TalkTurnMirror] Talk did not take the mirror of a chat turn; the turn stays stored',
				context: ['sessionId' => $turn['sessionId']]
			);
		}

		return $posted;
	}//end mirror()

	/**
	 * "Anne de Vries via Hermiq: <question>".
	 *
	 * @param array<string, string> $turn The queued turn.
	 *
	 * @return string
	 */
	private function questionLine(array $turn): string {
		$name = (string)($this->users->getDisplayName($turn['userId']) ?? '');
		if (trim($name) === '') {
			$name = $turn['userId'];
		}

		return self::cut(text: $name . ' via Hermiq: ' . $turn['question'], link: $this->sessionLink(sessionId: $turn['sessionId']));
	}//end questionLine()

	/**
	 * The answer, cut at the message limit with a link to the rest.
	 *
	 * @param array<string, string> $turn The queued turn.
	 *
	 * @return string
	 */
	private function answerText(array $turn): string {
		return self::cut(text: $turn['answer'], link: $this->sessionLink(sessionId: $turn['sessionId']));
	}//end answerText()

	/**
	 * The session on the Chat page.
	 *
	 * @param string $sessionId The session's uuid.
	 *
	 * @return string
	 */
	private function sessionLink(string $sessionId): string {
		return $this->urls->getAbsoluteURL('/apps/hermiq/chat?session=' . rawurlencode($sessionId));
	}//end sessionLink()

	/**
	 * A text cut at the message limit, with a link to the rest when cut.
	 *
	 * @param string $text The text.
	 * @param string $link Where the rest is.
	 *
	 * @return string
	 */
	private static function cut(string $text, string $link): string {
		if (mb_strlen($text) <= self::MAX_MESSAGE) {
			return $text;
		}

		return mb_substr($text, 0, self::MAX_MESSAGE) . "…\n\n" . $link;
	}//end cut()

	/**
	 * Whether a turn names its session, room, asker, question and answer.
	 *
	 * The agent may be empty: the shared bot then posts.
	 *
	 * @param array<string, mixed> $turn The turn.
	 *
	 * @return bool
	 */
	private static function isComplete(array $turn): bool {
		foreach (['sessionId', 'roomToken', 'userId', 'question', 'answer'] as $field) {
			if (is_string($turn[$field] ?? null) === false || $turn[$field] === '') {
				return false;
			}
		}

		return true;
	}//end isComplete()
}//end class
