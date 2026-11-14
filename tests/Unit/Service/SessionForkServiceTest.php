<?php

/**
 * Hermiq SessionForkService: "Fork from here".
 *
 * Drives the real service over a recording ObjectService double: the new
 * session's payload, the full payload of every copied turn, and that the
 * source is never written.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Kernel\Contracts\TalkSessionRoomInterface;
use OCA\Hermiq\Service\SessionForkException;
use OCA\Hermiq\Service\SessionForkService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for forking a session.
 */
class SessionForkServiceTest extends TestCase {

	/**
	 * Every save: schema, payload, uuid and rbac flag.
	 *
	 * @var list<array{schema: mixed, object: array<string, mixed>, uuid: ?string, rbac: bool}>
	 */
	private array $saves = [];

	/**
	 * The findAll configs asked for.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * An entity with a uuid and payload.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * Alice's session "Omgevingsvisie 2040" with two questions and two answers.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$session = $this->entity('s-1', ['userId' => 'alice', 'agentId' => 'agent-1', 'title' => 'Omgevingsvisie 2040', 'participants' => ['bob']]);
		$turns = [
			$this->entity('t-1', ['sessionId' => 's-1', 'role' => 'user', 'content' => 'Wat staat erin?', 'authorId' => 'alice', 'authorDisplayName' => 'Alice']),
			$this->entity('t-2', ['sessionId' => 's-1', 'role' => 'assistant', 'agentId' => 'agent-1', 'content' => 'Wonen en groen.', 'sources' => [['id' => 7, 'type' => 'file']], 'context' => ['app' => 'hermiq'], 'attachments' => [['fileId' => 9, 'name' => 'visie.pdf']], 'feedback' => 'positive']),
			$this->entity('t-3', ['sessionId' => 's-1', 'role' => 'user', 'content' => 'En mobiliteit?', 'authorId' => 'bob']),
			$this->entity('t-4', ['sessionId' => 's-1', 'role' => 'assistant', 'content' => 'Meer fietsroutes.']),
		];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static fn (int|string $id): ?ObjectEntity => ($id === 's-1' ? $session : null)
		);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true) use ($turns): array {
				$this->queries[] = $config + ['_rbac' => $_rbac];
				return $turns;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntity {
				$this->saves[] = ['schema' => $schema, 'object' => $object, 'uuid' => $uuid, 'rbac' => $_rbac];
				return $this->entity('new-' . count($this->saves), $object);
			}
		);

		return $objects;
	}//end objects()

	/**
	 * Forking at the first answer makes a new session with the same agent, a
	 * "(fork)" title and forkedFrom, and copies the two turns up to that answer
	 * with their full payload; nothing is saved onto the source.
	 *
	 * @return void
	 */
	public function testAForkCopiesTheTurnsUpToTheAnswer(): void {
		$fork = (new SessionForkService(objectService: $this->objects()))->fork(uuid: 's-1', turnUuid: 't-2', callerUid: 'alice');

		$this->assertSame(2, $fork['turns']);
		$this->assertCount(3, $this->saves);
		$session = $this->saves[0];
		$this->assertSame('agentsession', $session['schema']);
		$this->assertNull($session['uuid'], 'A new session, never a save onto the source');
		$this->assertFalse($session['rbac']);
		$this->assertSame('alice', $session['object']['userId']);
		$this->assertSame('agent-1', $session['object']['agentId']);
		$this->assertSame('Omgevingsvisie 2040 (fork)', $session['object']['title']);
		$this->assertSame('human', $session['object']['triggerOrigin']);
		$this->assertSame(['sessionId' => 's-1', 'turnId' => 't-2'], array_intersect_key($session['object']['forkedFrom'], ['sessionId' => 1, 'turnId' => 1]));
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $session['object']['forkedFrom']['forkedAt']);
		$this->assertArrayNotHasKey('participants', $session['object'], 'The fork is the caller\'s alone');

		$this->assertSame(
			['sessionId' => 'new-1', 'role' => 'user', 'content' => 'Wat staat erin?', 'authorId' => 'alice', 'authorDisplayName' => 'Alice'],
			$this->saves[1]['object']
		);
		$this->assertSame(
			[
				'sessionId' => 'new-1',
				'agentId' => 'agent-1',
				'role' => 'assistant',
				'content' => 'Wonen en groen.',
				'sources' => [['id' => 7, 'type' => 'file']],
				'context' => ['app' => 'hermiq'],
				'attachments' => [['fileId' => 9, 'name' => 'visie.pdf']],
			],
			$this->saves[2]['object'],
			'Every kept field is carried; feedback is not'
		);
		$this->assertSame(['agentsessionturn', 'agentsessionturn'], [$this->saves[1]['schema'], $this->saves[2]['schema']]);
		$this->assertSame([null, null], [$this->saves[1]['uuid'], $this->saves[2]['uuid']]);
		$this->assertSame(['sessionId' => 's-1'], $this->queries[0]['filters']);
		$this->assertSame(['created' => 'ASC'], $this->queries[0]['sort']);
		$this->assertFalse($this->queries[0]['_rbac']);
	}

	/**
	 * Forking at the last answer copies every turn.
	 *
	 * @return void
	 */
	public function testForkingAtTheLastAnswerCopiesEveryTurn(): void {
		$fork = (new SessionForkService(objectService: $this->objects()))->fork(uuid: 's-1', turnUuid: 't-4', callerUid: 'alice');

		$this->assertSame(4, $fork['turns']);
		$this->assertSame(['Wat staat erin?', 'Wonen en groen.', 'En mobiliteit?', 'Meer fietsroutes.'], array_map(static fn (array $save): string => $save['object']['content'], array_slice($this->saves, 1)));
	}

	/**
	 * The refusals.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
	 */
	public static function refusals(): array {
		return [
			'a question' => ['s-1', 't-3', 'alice', 400],
			'no turn named' => ['s-1', '', 'alice', 400],
			'a turn of another session' => ['s-1', 't-99', 'alice', 400],
			'a participant' => ['s-1', 't-2', 'bob', 404],
			'a stranger' => ['s-1', 't-2', 'carol', 404],
			'no such session' => ['s-404', 't-2', 'alice', 404],
		];
	}

	/**
	 * A question, a turn outside the session, a non-owner or a missing session is
	 * refused, and nothing is created.
	 *
	 * @param string $uuid      The session.
	 * @param string $turnUuid  The turn.
	 * @param string $caller    The caller.
	 * @param int    $status    The expected status.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
	public function testRefusalsCreateNothing(string $uuid, string $turnUuid, string $caller, int $status): void {
		try {
			(new SessionForkService(objectService: $this->objects()))->fork(uuid: $uuid, turnUuid: $turnUuid, callerUid: $caller);
			$this->fail('The fork was allowed');
		} catch (SessionForkException $e) {
			$this->assertSame($status, $e->getCode());
		}

		$this->assertSame([], $this->saves);
	}

	/**
	 * Like any new session, the fork gets its Talk room, named after its title.
	 *
	 * @return void
	 */
	public function testTheForkGetsItsRoom(): void {
		$room = $this->createMock(TalkSessionRoomInterface::class);
		$room->expects($this->once())->method('attachToSession')
			->with($this->isInstanceOf(ObjectEntity::class), 'Omgevingsvisie 2040 (fork)', 'alice', 'agent-1')
			->willReturnCallback(fn (ObjectEntity $session): ObjectEntity => $this->entity('with-room', $session->getObject()));

		$fork = (new SessionForkService(objectService: $this->objects(), sessionRoom: $room))->fork(uuid: 's-1', turnUuid: 't-2', callerUid: 'alice');

		$this->assertSame('with-room', $fork['session']->getUuid());
	}
}
