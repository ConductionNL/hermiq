<?php

/**
 * SessionController for a share recipient (chat-fork-and-share-link): a user
 * the session is shared with reads it and its turns, read-only; anyone else
 * gets the 404 of a missing session; the owner's and a participant's reads
 * are unchanged.
 *
 * The object service here behaves like OpenRegister's read rule on
 * `agentsession`: a read with RBAC throws for anyone but the owner and the
 * participants, a read without RBAC answers the row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use Exception;
use OCA\Hermiq\Controller\SessionController;
use OCA\Hermiq\Kernel\Contracts\SessionShareServiceInterface;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Chat\SessionCollaborators;
use OCA\Hermiq\Service\Chat\SessionPendingApprovals;
use OCA\Hermiq\Service\Chat\SessionSubject;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Recipient reads, stranger 404s and the "Shared with you" listing.
 */
class SessionControllerShareTest extends TestCase {

	private const SHARE = ['type' => 'group', 'id' => 'team-inkoop', 'sharedBy' => 'anne', 'sharedAt' => '2026-09-12T10:04:00+02:00'];

	/**
	 * Objects saved, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $saved = [];

	/**
	 * Whether the pending-approvals lookup ran.
	 *
	 * @var boolean
	 */
	private bool $askedApprovals = false;

	/**
	 * The controller as the given user.
	 *
	 * @param string               $uid    The caller.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return SessionController
	 */
	private function controllerAs(string $uid, array $params = []): SessionController {
		$session = new ObjectEntity();
		$session->setUuid('sess-1');
		$session->setObject([
			'agentId' => 'a1',
			'userId' => 'anne',
			'title' => 'Offerte dakrenovatie Stadskantoor',
			'participants' => ['bram'],
			'sharedWith' => [self::SHARE],
			'shareKeys' => ['group:team-inkoop'],
		]);

		$turn = new ObjectEntity();
		$turn->setUuid('turn-1');
		$turn->setObject(['sessionId' => 'sess-1', 'role' => 'assistant', 'content' => 'De offerte ligt binnen het budget.', 'sources' => [['title' => 'Offerte.pdf']]]);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true) use ($session, $uid): ?ObjectEntity {
				if ($id !== 'sess-1') {
					return null;
				}

				if ($_rbac === true && in_array($uid, ['anne', 'bram'], true) === false) {
					throw new Exception('Not allowed to read this object');
				}

				return $session;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($turn, $session, $uid): array {
				$filters = ($config['filters'] ?? []);
				if (isset($filters['sessionId']) === true) {
					return [$turn];
				}

				if (($filters['userId'] ?? null) === 'anne' && $uid === 'anne') {
					return [$session];
				}

				return [];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(function (mixed $object) use ($session): ObjectEntity {
			$this->saved[] = $object;
			return $session;
		});

		$shares = $this->createMock(SessionShareServiceInterface::class);
		$shares->method('shareFor')->willReturnCallback(
			static fn (array $data, string $reader): ?array => in_array($reader, ['carla', 'bram'], true) ? self::SHARE + ['sharedByDisplayName' => 'Anne de Vries'] : null
		);
		$shares->method('sharedWith')->willReturnCallback(static fn (string $reader): array => in_array($reader, ['carla', 'bram'], true) ? [$session] : []);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$pending = $this->createMock(SessionPendingApprovals::class);
		$pending->method('forSession')->willReturnCallback(function (): array {
			$this->askedApprovals = true;
			return [];
		});

		return new SessionController(
			request: $request,
			engine: $this->createMock(Engine::class),
			objectService: $objectService,
			userSession: $userSession,
			logger: new NullLogger(),
			collaborators: new SessionCollaborators(
				sessionRoom: $this->createMock(TalkSessionRoom::class),
				agentAccess: $this->createMock(AgentAccessService::class),
				subject: new SessionSubject(objectService: $objectService),
				pendingApprovals: $pending
			),
			shares: $shares
		);
	}//end controllerAs()

	/**
	 * A member of the shared group reads the session read-only: the share that
	 * admits them, no roster, no share count.
	 *
	 * @return void
	 */
	public function testARecipientReadsTheSession(): void {
		$response = $this->controllerAs('carla')->show('sess-1');

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('recipient', $data['role']);
		$this->assertSame('Offerte dakrenovatie Stadskantoor', $data['title']);
		$this->assertSame('Anne de Vries', $data['share']['sharedByDisplayName']);
		$this->assertSame('2026-09-12T10:04:00+02:00', $data['share']['sharedAt']);
		$this->assertSame([], $data['participants'], 'A recipient does not see who takes part.');
		$this->assertSame(0, $data['shareCount']);
		$this->assertArrayHasKey('messageCount', $data);
	}//end testARecipientReadsTheSession()

	/**
	 * A recipient reads the turns with their sources, and is offered no approvals.
	 *
	 * @return void
	 */
	public function testARecipientReadsTheTurns(): void {
		$response = $this->controllerAs('carla')->messages('sess-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('De offerte ligt binnen het budget.', $response->getData()['results'][0]['content']);
		$this->assertSame([['title' => 'Offerte.pdf']], $response->getData()['results'][0]['sources']);
		$this->assertSame([], $response->getData()['pendingApprovals']);
		$this->assertFalse($this->askedApprovals);
	}//end testARecipientReadsTheTurns()

	/**
	 * Anyone the session is not shared with gets the 404 of a missing session,
	 * without the title.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-revoke-a-share-at-once-req-sshare-003
	 */
	public function testAnyoneElseGetsTheMissingSessionAnswer(): void {
		$controller = $this->controllerAs('dirk');
		$missing = $this->controllerAs('dirk')->show('sess-unknown');

		foreach ([$controller->show('sess-1'), $controller->messages('sess-1')] as $response) {
			$this->assertSame(404, $response->getStatus());
			$this->assertSame($missing->getData(), $response->getData());
			$this->assertStringNotContainsString('Offerte', (string)json_encode($response->getData()));
		}
	}//end testAnyoneElseGetsTheMissingSessionAnswer()

	/**
	 * The owner and a participant read as before; a participant who is also
	 * a share recipient stays a participant.
	 *
	 * @return void
	 */
	public function testOwnerAndParticipantReadAsBefore(): void {
		$owner = $this->controllerAs('anne')->show('sess-1')->getData();
		$this->assertSame('owner', $owner['role']);
		$this->assertSame(['bram'], $owner['participants']);
		$this->assertSame(1, $owner['shareCount']);

		$participant = $this->controllerAs('bram')->show('sess-1')->getData();
		$this->assertSame('participant', $participant['role']);
		$this->assertSame(0, $participant['shareCount'], 'Only the owner sees how many shares there are.');
		$this->controllerAs('bram')->messages('sess-1');
		$this->assertTrue($this->askedApprovals, 'A participant still gets the pending approvals.');
	}//end testOwnerAndParticipantReadAsBefore()

	/**
	 * "Shared with you" lists what is shared with the caller, as recipient;
	 * the owner's own list and a participant's shared list leave it out.
	 *
	 * @return void
	 */
	public function testSharedWithYouListsTheRecipientsSessions(): void {
		$listed = $this->controllerAs('carla', ['shared' => 'true'])->index()->getData();
		$this->assertSame(1, $listed['total']);
		$this->assertSame('sess-1', $listed['results'][0]['uuid']);
		$this->assertSame('recipient', $listed['results'][0]['role']);

		$this->assertSame(0, $this->controllerAs('bram', ['shared' => 'true'])->index()->getData()['total'], 'A participant finds it under their own sessions.');
		$this->assertSame(0, $this->controllerAs('dirk', ['shared' => 'true'])->index()->getData()['total']);
		$own = $this->controllerAs('carla')->index()->getData();
		$this->assertSame(0, $own['total'], 'A shared session is not one of the recipient\'s own.');
	}//end testSharedWithYouListsTheRecipientsSessions()

	/**
	 * A recipient cannot rename, archive, restore or delete the session: every
	 * write path stays owner-only and nothing is saved.
	 *
	 * @return void
	 */
	public function testARecipientChangesNothing(): void {
		$controller = $this->controllerAs('carla', ['title' => 'Mijn titel']);

		foreach ([$controller->update('sess-1'), $controller->destroy('sess-1'), $controller->restore('sess-1'), $controller->destroyPermanent('sess-1')] as $response) {
			$this->assertNotSame(200, $response->getStatus());
		}

		$this->assertSame([], $this->saved);
	}//end testARecipientChangesNothing()
}//end class
