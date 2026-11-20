<?php

/**
 * Unit tests for SessionLinks.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/session-entity-links/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\Chat\SessionLinks;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A session opened on `subsidiebesluit.docx` (file 24753) and on Alice's
 * letter (an OpenRegister object): what is recorded, when, and how a context
 * narrows the session list.
 *
 * @covers \OCA\Hermiq\Service\Chat\SessionLinks
 */
final class SessionLinksTest extends TestCase {

	/**
	 * The objects the service saved, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The ids the service read, in order.
	 *
	 * @var array<int, string>
	 */
	private array $read = [];

	/**
	 * A session or object entity.
	 *
	 * @param string               $uuid    Its uuid.
	 * @param array<string, mixed> $payload Its payload.
	 * @param string               $schema  Its schema.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $payload = [], string $schema = 'agentsession'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		$entity->setRegister('docudesk');
		$entity->setSchema($schema);

		return $entity;
	}//end entity()

	/**
	 * The service over a register of entities and a file tree.
	 *
	 * @param array<string, ObjectEntity> $objects The entities by uuid.
	 * @param array<int, string>          $files   The display name of each file the user can read, by id.
	 * @param LoggerInterface|null        $logger  The logger.
	 *
	 * @return SessionLinks
	 */
	private function service(array $objects = [], array $files = [], ?LoggerInterface $logger = null): SessionLinks {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (mixed ...$args) use ($objects): ?ObjectEntity {
				$this->read[] = (string)$args[0];
				return ($objects[(string)$args[0]] ?? null);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed ...$args): ObjectEntity {
				$this->saved[] = $args[0];
				return self::entity(uuid: (string)$args[1]->uuid, payload: $args[0]);
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturnCallback(
			function (int $id) use ($files): ?File {
				if (isset($files[$id]) === false) {
					return null;
				}

				$file = $this->createMock(File::class);
				$file->method('getName')->willReturn($files[$id]);
				$file->method('getPath')->willReturn('/alice/files/Projecten/Kade 12/Besluiten/'.$files[$id]);

				return $file;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($folder);

		return new SessionLinks($objectService, $root, ($logger ?? new NullLogger()));
	}//end service()

	public function testASentTurnOnAFileLinksTheSessionToItByDisplayNameOnly(): void {
		$links = $this->service(
			objects: ['s-1' => self::entity('s-1', ['userId' => 'alice', 'title' => 'Besluit'])],
			files: [24753 => 'subsidiebesluit.docx']
		)->recordTurn(sessionUuid: 's-1', context: ['pageKind' => 'file', 'fileId' => '24753'], userId: 'alice');

		$this->assertCount(1, $links);
		$this->assertSame(['type' => 'file', 'id' => '24753', 'label' => 'subsidiebesluit.docx'], array_intersect_key($links[0], array_flip(['type', 'id', 'label'])));
		$this->assertStringNotContainsString('/', $links[0]['label'], 'a label never carries the folder path');
		$this->assertSame($links[0]['linkedAt'], $links[0]['lastUsedAt']);
		$this->assertCount(1, $this->saved);
		$this->assertSame('Besluit', $this->saved[0]['title'], 'the rest of the session is kept');
		$this->assertSame($links, $this->saved[0]['links']);
	}//end testASentTurnOnAFileLinksTheSessionToItByDisplayNameOnly()

	public function testAContextNamingNothingRecordsNothingAndReadsNoSession(): void {
		$service = $this->service(objects: ['s-1' => self::entity('s-1')], files: [24753 => 'subsidiebesluit.docx']);

		$this->assertSame([], $service->recordTurn(sessionUuid: 's-1', context: ['pageKind' => 'custom'], userId: 'alice'));
		$this->assertSame([], $service->recordTurn(sessionUuid: 's-1', context: ['fileId' => '../24753'], userId: 'alice'));
		$this->assertSame([], $service->recordTurn(sessionUuid: 's-1', context: ['fileId' => '99999'], userId: 'alice'), 'a file the sender cannot read');
		$this->assertSame([], $this->read);
		$this->assertSame([], $this->saved);
	}//end testAContextNamingNothingRecordsNothingAndReadsNoSession()

	public function testReSendingInTheSameContextKeepsOneLinkAndTouchesItsTime(): void {
		$stored = [['type' => 'file', 'id' => '24753', 'label' => 'subsidiebesluit.docx', 'linkedAt' => '2026-11-01T09:00:00+00:00', 'lastUsedAt' => '2026-11-01T09:00:00+00:00']];

		$links = SessionLinks::merge(
			stored: $stored,
			found: [['type' => 'file', 'id' => '24753', 'label' => 'subsidiebesluit (2).docx']],
			now: '2026-11-17T14:00:00+00:00'
		);

		$this->assertSame(
			[['type' => 'file', 'id' => '24753', 'label' => 'subsidiebesluit.docx', 'linkedAt' => '2026-11-01T09:00:00+00:00', 'lastUsedAt' => '2026-11-17T14:00:00+00:00']],
			$links
		);
	}//end testReSendingInTheSameContextKeepsOneLinkAndTouchesItsTime()

	public function testLinksOfDifferentKindsLiveSideBySide(): void {
		$links = $this->service(
			objects: [
				's-1' => self::entity('s-1', ['userId' => 'alice', 'links' => [['type' => 'file', 'id' => '24753', 'label' => 'subsidiebesluit.docx']]]),
				'b08fe950-1111-4222-8333-444455556666' => self::entity('b08fe950-1111-4222-8333-444455556666', ['naam' => "Alice's letter"], 'template'),
			]
		)->recordTurn(
			sessionUuid: 's-1',
			context: ['objectUuid' => 'b08fe950-1111-4222-8333-444455556666', 'register' => 'docudesk', 'schema' => 'template'],
			userId: 'alice'
		);

		$this->assertSame(['file', 'object'], array_column($links, 'type'));
		$this->assertSame("Alice's letter", $links[1]['label']);
		$this->assertSame('docudesk/template', $links[1]['schema']);
	}//end testLinksOfDifferentKindsLiveSideBySide()

	public function testAnObjectTheSenderCannotReadIsNotLinked(): void {
		$service = $this->service(objects: ['s-1' => self::entity('s-1')]);

		$this->assertSame([], $service->fromContext(context: ['objectUuid' => 'gone'], userId: 'alice'));
	}//end testAnObjectTheSenderCannotReadIsNotLinked()

	public function testAFailingSaveIsLoggedAndNeverFailsTheTurn(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(self::entity('s-1'));
		$objectService->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$folder = $this->createMock(Folder::class);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('a.docx');
		$folder->method('getFirstNodeById')->willReturn($file);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('Could not record what a session is about'));

		$this->assertSame([], (new SessionLinks($objectService, $root, $logger))->recordTurn('s-1', ['fileId' => '7'], 'alice'));
	}//end testAFailingSaveIsLoggedAndNeverFailsTheTurn()

	public function testTheScopedListFindsALinkedSessionFarDownTheList(): void {
		$sessions = [];
		for ($n = 1; $n <= 60; $n++) {
			$payload = ['userId' => 'alice'];
			if ($n === 55) {
				$payload['links'] = [['type' => 'file', 'id' => '24753', 'label' => 'subsidiebesluit.docx', 'lastUsedAt' => '2026-11-17T10:00:00+00:00']];
			}

			$sessions[] = self::entity('s-'.$n, $payload);
		}

		$found = SessionLinks::about(sessions: $sessions, type: 'file', id: '24753');

		$this->assertSame(['s-55'], array_map(static fn (ObjectEntity $s): string => (string)$s->getUuid(), $found));
		$this->assertCount(60, SessionLinks::about(sessions: $sessions, type: '', id: ''), 'no context, no scoping');
		$this->assertSame([], SessionLinks::about(sessions: $sessions, type: 'object', id: '24753'), 'the kind is part of the link');
	}//end testTheScopedListFindsALinkedSessionFarDownTheList()

	public function testTheScopedListPutsTheMostRecentlyUsedLinkFirst(): void {
		$link = static fn (string $at): array => ['links' => [['type' => 'file', 'id' => '7', 'label' => 'a', 'lastUsedAt' => $at]]];
		$sessions = [
			self::entity('old', $link('2026-11-01T09:00:00+00:00')),
			self::entity('new', $link('2026-11-17T09:00:00+00:00')),
			self::entity('same-a', $link('2026-11-10T09:00:00+00:00')),
			self::entity('same-b', $link('2026-11-10T09:00:00+00:00')),
		];

		$order = array_map(static fn (ObjectEntity $s): string => (string)$s->getUuid(), SessionLinks::about($sessions, 'file', '7'));

		$this->assertSame(['new', 'same-a', 'same-b', 'old'], $order);
	}//end testTheScopedListPutsTheMostRecentlyUsedLinkFirst()

	public function testARemovedLinkIsGoneAndTheSessionLeavesTheScopedList(): void {
		$session = self::entity('s-1', ['userId' => 'alice', 'links' => [
			['type' => 'file', 'id' => '24753', 'label' => 'subsidiebesluit.docx'],
			['type' => 'object', 'id' => 'o-1', 'schema' => 'docudesk/template', 'label' => 'Brief'],
		]]);
		$service = $this->service();

		$updated = $service->remove(session: $session, type: 'file', id: '24753');

		$this->assertNotNull($updated);
		$this->assertSame([['type' => 'object', 'id' => 'o-1', 'label' => 'Brief', 'schema' => 'docudesk/template']], SessionLinks::linksOf($updated->getObject()));
		$this->assertSame([], SessionLinks::about([$updated], 'file', '24753'));
		$this->assertNull($service->remove(session: $updated, type: 'file', id: '24753'), 'removing it again changes nothing');
		$this->assertCount(1, $this->saved);
	}//end testARemovedLinkIsGoneAndTheSessionLeavesTheScopedList()

	public function testMalformedStoredLinksAreDropped(): void {
		$this->assertSame(
			[['type' => 'file', 'id' => '1', 'label' => '']],
			SessionLinks::linksOf(['links' => ['junk', ['type' => 'file'], ['id' => '2'], ['type' => ['x'], 'id' => '3'], ['type' => 'file', 'id' => 1]]])
		);
		$this->assertSame([], SessionLinks::linksOf(['title' => 'no links']));
		$this->assertNull(SessionLinks::linkTo(['links' => []], 'file', '1'));
	}//end testMalformedStoredLinksAreDropped()
}//end class
