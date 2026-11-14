<?php

/**
 * Hermiq SessionForkService.
 *
 * "Fork from here": a new session for the owner with the same agent and a copy of
 * every turn up to and including one answer, recording where it came from in
 * `forkedFrom`. The source session and its turns are only read, never written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Hermiq\Kernel\Contracts\TalkSessionRoomInterface;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Forks a session at an assistant turn; the owner only.
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
 */
class SessionForkService {
	use SanitizesForSaveTrait;

	/**
	 * Register slug.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Session schema slug.
	 *
	 * @var string
	 */
	private const SESSION_SCHEMA = 'agentsession';

	/**
	 * Session turn schema slug.
	 *
	 * @var string
	 */
	private const TURN_SCHEMA = 'agentsessionturn';

	/**
	 * The most turns of a source session read for one fork.
	 *
	 * @var int
	 */
	public const MAX_TURNS = 1000;

	/**
	 * The turn fields a copy keeps; feedback and ids are not copied.
	 *
	 * @var list<string>
	 */
	private const COPIED_TURN_FIELDS = ['agentId', 'role', 'content', 'sources', 'context', 'authorId', 'authorDisplayName', 'attachments'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService                 $objectService OpenRegister object read and write.
	 * @param TalkSessionRoomInterface|null $sessionRoom   Gives the new session its Talk room, like any new session;
	 *                                                     null leaves it without one.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ?TalkSessionRoomInterface $sessionRoom = null,
	) {
	}//end __construct()

	/**
	 * Fork the caller's session at one of its answers.
	 *
	 * @param string $uuid      The source session uuid.
	 * @param string $turnUuid  The assistant turn to fork at.
	 * @param string $callerUid The caller, who must own the source session.
	 *
	 * @return array{session: ObjectEntity, turns: int} The new session and how many turns it holds.
	 *
	 * @throws SessionForkException 404 when the caller does not own the session, 400 when the
	 *                              turn is not an answer in it.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
	 */
	public function fork(string $uuid, string $turnUuid, string $callerUid): array {
		$source = $this->ownedSession(uuid: $uuid, callerUid: $callerUid);
		$turns = $this->turnsUpTo(uuid: $uuid, turnUuid: $turnUuid);
		$sourceData = $source->getObject();

		$fork = $this->objectService->saveObject(
			object: $this->sanitizeForSave(
				data: [
					'userId' => $callerUid,
					'agentId' => (string)($sourceData['agentId'] ?? ''),
					'title' => $this->forkTitle(title: (string)($sourceData['title'] ?? '')),
					'triggerOrigin' => 'human',
					'forkedFrom' => [
						'sessionId' => $uuid,
						'turnId' => $turnUuid,
						'forkedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
					],
				]
			),
			register: self::REGISTER_SLUG,
			schema: self::SESSION_SCHEMA,
			_rbac: false
		);

		foreach ($turns as $turn) {
			$this->copyTurn(turn: $turn, forkUuid: (string)$fork->getUuid());
		}

		$fork = $this->withRoom(fork: $fork, callerUid: $callerUid);

		return ['session' => $fork, 'turns' => count($turns)];
	}//end fork()

	/**
	 * The session, when the caller owns it; a participant or anyone else gets 404.
	 *
	 * @param string $uuid      The session uuid.
	 * @param string $callerUid The caller.
	 *
	 * @return ObjectEntity The session.
	 *
	 * @throws SessionForkException 404.
	 */
	private function ownedSession(string $uuid, string $callerUid): ObjectEntity {
		$session = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: self::SESSION_SCHEMA);
		if ($session === null || $callerUid === '' || ($session->getObject()['userId'] ?? null) !== $callerUid) {
			throw new SessionForkException('Session not found.', 404);
		}

		return $session;
	}//end ownedSession()

	/**
	 * The session's turns in order, up to and including the answer forked at.
	 *
	 * @param string $uuid     The session uuid.
	 * @param string $turnUuid The turn forked at.
	 *
	 * @return list<ObjectEntity> The turns to copy.
	 *
	 * @throws SessionForkException 400 when the turn is not an assistant turn of this session.
	 */
	private function turnsUpTo(string $uuid, string $turnUuid): array {
		if ($turnUuid === '') {
			throw new SessionForkException('Choose the answer to fork from.', 400);
		}

		// _rbac false: in a session with participants their turns are owned by them;
		// ownedSession() has already decided the caller may read the whole thread.
		$turns = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::TURN_SCHEMA)
			->findAll(
				config: [
					'filters' => ['sessionId' => $uuid],
					'sort' => ['created' => 'ASC'],
					'limit' => self::MAX_TURNS,
				],
				_rbac: false
			);

		$kept = [];
		foreach ($turns as $turn) {
			if ($turn instanceof ObjectEntity === false) {
				continue;
			}

			$kept[] = $turn;
			if ((string)$turn->getUuid() !== $turnUuid) {
				continue;
			}

			if (($turn->getObject()['role'] ?? null) !== 'assistant') {
				break;
			}

			return $kept;
		}

		throw new SessionForkException('Only an answer of this session can be forked from.', 400);
	}//end turnsUpTo()

	/**
	 * Save a copy of one turn into the fork, with its full payload.
	 *
	 * @param ObjectEntity $turn     The source turn.
	 * @param string       $forkUuid The new session.
	 *
	 * @return void
	 */
	private function copyTurn(ObjectEntity $turn, string $forkUuid): void {
		$data = $turn->getObject();
		$copy = ['sessionId' => $forkUuid];
		foreach (self::COPIED_TURN_FIELDS as $field) {
			if (array_key_exists($field, $data) === true && $data[$field] !== null) {
				$copy[$field] = $data[$field];
			}
		}

		// _rbac false: SessionTurn lists no create grant (hermiq#319); the fork's
		// owner check above is the guard, as for every stored turn.
		$this->objectService->saveObject(
			object: $this->sanitizeForSave(data: $copy),
			register: self::REGISTER_SLUG,
			schema: self::TURN_SCHEMA,
			_rbac: false
		);
	}//end copyTurn()

	/**
	 * The fork with its Talk room recorded, when rooms are made for new sessions.
	 *
	 * @param ObjectEntity $fork      The new session.
	 * @param string       $callerUid Its owner.
	 *
	 * @return ObjectEntity The session.
	 */
	private function withRoom(ObjectEntity $fork, string $callerUid): ObjectEntity {
		if ($this->sessionRoom === null) {
			return $fork;
		}

		$data = $fork->getObject();
		return $this->sessionRoom->attachToSession(
			conversation: $fork,
			title: (string)($data['title'] ?? ''),
			ownerUid: $callerUid,
			agentId: (string)($data['agentId'] ?? '')
		);
	}//end withRoom()

	/**
	 * The fork's title: the source title with " (fork)".
	 *
	 * @param string $title The source title.
	 *
	 * @return string The title.
	 */
	private function forkTitle(string $title): string {
		if (trim($title) === '') {
			return 'Session (fork)';
		}

		return $title . ' (fork)';
	}//end forkTitle()
}//end class
