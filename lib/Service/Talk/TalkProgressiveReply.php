<?php

/**
 * Hermiq TalkProgressiveReply.
 *
 * The delivery sink of one Talk answer: post early, edit often, finish exact.
 * The first content posts the message; later content edits it, at most once
 * per throttle interval (Talk pushes every edit to every participant); the
 * end edits it to the exact complete answer.
 *
 * Progressive delivery can never lose an answer: without edit support nothing
 * is posted until the end, and the whole answer goes out as one message; once
 * an edit fails, editing stops and the complete answer is posted as a message
 * of its own at the end.
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
 * @spec openspec/changes/instant-talk/tasks.md#3-progressive-replies
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use Closure;
use Psr\Log\LoggerInterface;

/**
 * Delivers one answer into a room, progressively where Talk can edit.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-replies-stream-progressively-where-talk-supports-message-editing
 */
class TalkProgressiveReply {

	/**
	 * The text appended to a partial reply, so a reader can tell it is still growing.
	 *
	 * @var string
	 */
	public const PENDING_MARK = ' …';

	/**
	 * The answer so far.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * The posted message's id, once posted.
	 *
	 * @var string|null
	 */
	private ?string $messageId = null;

	/**
	 * When the message was last posted or edited (seconds).
	 *
	 * @var float
	 */
	private float $lastWrite = 0.0;

	/**
	 * Whether an edit failed, which stops editing for the rest of the answer.
	 *
	 * @var boolean
	 */
	private bool $editFailed = false;

	/**
	 * Start a reply.
	 *
	 * @param TalkBridge      $bridge          The room I/O.
	 * @param string          $roomToken       The room to answer in.
	 * @param string|null     $agentId         The answering agent's bot identity.
	 * @param boolean         $canEdit         Whether this Talk can edit bot messages.
	 * @param float           $throttleSeconds The least time between two edits.
	 * @param LoggerInterface $logger          PSR-3 logger.
	 * @param Closure|null    $clock           Returns the current time in seconds; microtime by default.
	 */
	public function __construct(
		private readonly TalkBridge $bridge,
		private readonly string $roomToken,
		private readonly ?string $agentId,
		private readonly bool $canEdit,
		private readonly float $throttleSeconds,
		private readonly LoggerInterface $logger,
		private readonly ?Closure $clock = null,
	) {
	}//end __construct()

	/**
	 * Take the next piece of the answer as it is generated.
	 *
	 * @param string $delta The generated text since the last call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-replies-stream-progressively-where-talk-supports-message-editing
	 */
	public function append(string $delta): void {
		$this->buffer .= $delta;
		if ($this->canEdit === false || $this->editFailed === true || trim($this->buffer) === '') {
			return;
		}

		if ($this->messageId === null) {
			$this->messageId = $this->bridge->postToRoomReturningId(
				roomToken: $this->roomToken,
				message: $this->buffer . self::PENDING_MARK,
				agentId: $this->agentId
			);
			$this->lastWrite = $this->now();
			if ($this->messageId === null) {
				// The early post did not land: deliver the whole answer at the end.
				$this->editFailed = true;
			}

			return;
		}

		if (($this->now() - $this->lastWrite) < $this->throttleSeconds) {
			return;
		}

		$this->edit(text: $this->buffer . self::PENDING_MARK);
	}//end append()

	/**
	 * Deliver the exact complete answer.
	 *
	 * @param string $answer The complete answer.
	 *
	 * @return bool True when the complete answer is in the room.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-replies-stream-progressively-where-talk-supports-message-editing
	 */
	public function finish(string $answer): bool {
		if ($this->messageId !== null && $this->editFailed === false && $this->edit(text: $answer) === true) {
			return true;
		}

		if ($this->messageId !== null) {
			$this->logger->info(
				message: '[TalkProgressiveReply] Editing stopped mid-answer; the complete answer is posted as a message',
				context: ['file' => __FILE__, 'line' => __LINE__, 'roomToken' => $this->roomToken]
			);
		}

		return $this->bridge->postToRoom(roomToken: $this->roomToken, message: $answer, agentId: $this->agentId);
	}//end finish()

	/**
	 * Whether a partial message was posted.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-replies-stream-progressively-where-talk-supports-message-editing
	 */
	public function started(): bool {
		return $this->messageId !== null;
	}//end started()

	/**
	 * Edit the posted message; a failure stops editing.
	 *
	 * @param string $text The new text.
	 *
	 * @return bool True when the edit landed.
	 */
	private function edit(string $text): bool {
		$edited = $this->bridge->editMessage(
			roomToken: $this->roomToken,
			messageId: (string)$this->messageId,
			newText: $text,
			agentId: $this->agentId
		);
		$this->lastWrite = $this->now();
		if ($edited === false) {
			$this->editFailed = true;
		}

		return $edited;
	}//end edit()

	/**
	 * The current time in seconds.
	 *
	 * @return float
	 */
	private function now(): float {
		if ($this->clock !== null) {
			return (float)($this->clock)();
		}

		return microtime(true);
	}//end now()
}//end class
