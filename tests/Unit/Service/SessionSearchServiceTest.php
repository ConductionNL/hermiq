<?php

/**
 * Unit tests for SessionSearchService.
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
 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SessionSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Two identities: alice's search never answers with bob's session or turns.
 *
 * @covers \OCA\Hermiq\Service\SessionSearchService
 *
 * @spec openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001
 */
class SessionSearchServiceTest extends TestCase {

	/**
	 * An entity fixture.
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
	 * A service whose turn search answers $turns, recording each config.
	 *
	 * @param list<mixed>                       $turns   The turns found.
	 * @param array<int, array<string, mixed>> $configs Receives each findAll config.
	 *
	 * @return SessionSearchService
	 */
	private function service(array $turns, array &$configs): SessionSearchService {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->any())->method('setRegister')->with('hermiq')->willReturnSelf();
		$objects->expects($this->any())->method('setSchema')->with('agentsessionturn')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($turns, &$configs): array {
				$configs[] = $config;
				return $turns;
			}
		);

		return new SessionSearchService(objectService: $objects);
	}//end service()

	/**
	 * The first matching turn per own session wins; a turn in someone else's session is dropped.
	 *
	 * @return void
	 */
	public function testTheFirstOwnTurnPerSessionMatches(): void {
		$configs = [];
		$sessions = [
			$this->entity(uuid: 'a1', payload: ['userId' => 'alice', 'title' => 'Eén']),
			$this->entity(uuid: 'b1', payload: ['userId' => 'bob', 'title' => 'monument']),
			$this->entity(uuid: 'a2', payload: ['userId' => 'alice', 'title' => 'Twee']),
		];
		$turns = [
			$this->entity(uuid: 't1', payload: ['sessionId' => 'a2', 'content' => "eerste\nmonument"]),
			$this->entity(uuid: 't2', payload: ['sessionId' => 'a2', 'content' => 'tweede monument']),
			$this->entity(uuid: 't3', payload: ['sessionId' => 'b1', 'content' => 'monument']),
			'not an entity',
		];

		$hits = $this->service(turns: $turns, configs: $configs)->search(userId: 'alice', term: 'monument', sessions: $sessions);

		$this->assertCount(1, $hits);
		$this->assertSame('a2', $hits[0]['session']->getUuid());
		$this->assertSame(['turnUuid' => 't1', 'excerpt' => 'eerste monument'], $hits[0]['match']);
		$this->assertSame(
			[['filters' => ['@self.owner' => 'alice'], 'search' => 'monument', 'sort' => ['created' => 'ASC'], 'limit' => 1000]],
			$configs
		);
	}//end testTheFirstOwnTurnPerSessionMatches()

	/**
	 * A title matches case-insensitively when no turn does.
	 *
	 * @return void
	 */
	public function testATitleMatchesWhenNoTurnDoes(): void {
		$configs = [];
		$sessions = [$this->entity(uuid: 'a1', payload: ['userId' => 'alice', 'title' => 'Monumentenzorg'])];

		$hits = $this->service(turns: [], configs: $configs)->search(userId: 'alice', term: 'MONUMENT', sessions: $sessions);

		$this->assertSame(['title' => true], $hits[0]['match']);
	}//end testATitleMatchesWhenNoTurnDoes()

	/**
	 * A short term, or no own session, searches nothing.
	 *
	 * @return void
	 */
	public function testNothingIsSearchedWithoutATermOrOwnSessions(): void {
		$configs = [];
		$service = $this->service(turns: [], configs: $configs);
		$bobs = [$this->entity(uuid: 'b1', payload: ['userId' => 'bob', 'title' => 'monument'])];

		$this->assertSame([], $service->search(userId: 'alice', term: ' m ', sessions: [$this->entity(uuid: 'a1', payload: ['userId' => 'alice', 'title' => 'm'])]));
		$this->assertSame([], $service->search(userId: 'alice', term: 'monument', sessions: $bobs));
		$this->assertSame([], $configs);
		$this->assertFalse(SessionSearchService::searches(term: ' x '));
		$this->assertTrue(SessionSearchService::searches(term: 'xy'));
	}//end testNothingIsSearchedWithoutATermOrOwnSessions()

	/**
	 * An excerpt keeps 60 characters each side of the term and marks what was cut.
	 *
	 * @return void
	 */
	public function testTheExcerptIsCutAroundTheTerm(): void {
		$text = str_repeat('a', 100) . ' monument ' . str_repeat('b', 100);

		$excerpt = SessionSearchService::excerpt(text: $text, term: 'monument');

		$this->assertSame('…' . str_repeat('a', 59) . ' monument ' . str_repeat('b', 59) . '…', $excerpt);
		$this->assertSame('kort', SessionSearchService::excerpt(text: 'kort', term: 'monument'));
		$this->assertSame(str_repeat('c', 128) . '…', SessionSearchService::excerpt(text: str_repeat('c', 200), term: 'monument'));
	}//end testTheExcerptIsCutAroundTheTerm()
}//end class
