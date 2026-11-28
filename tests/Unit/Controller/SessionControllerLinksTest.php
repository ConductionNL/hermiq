<?php

/**
 * Unit tests for the session links on SessionController.
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
 * @spec openspec/specs/session-entity-links/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use DateTime;
use OCA\Hermiq\Controller\SessionController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Chat\SessionCollaborators;
use OCA\Hermiq\Service\Chat\SessionLinks;
use OCA\Hermiq\Service\Chat\SessionPendingApprovals;
use OCA\Hermiq\Service\Chat\SessionSubject;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Alice has sessions about `subsidiebesluit.docx` (file 24753) and one about
 * nothing; Bob owns one about it too and shares it with her.
 *
 * @covers \OCA\Hermiq\Controller\SessionController
 * @uses   \OCA\Hermiq\Service\Chat\SessionLinks
 * @uses   \OCA\Hermiq\Service\Chat\SessionCollaborators
 * @uses   \OCA\Hermiq\Service\Chat\SessionSubject
 */
final class SessionControllerLinksTest extends TestCase {

	/**
	 * The stored sessions by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $sessions = [];

	/**
	 * What the controller saved.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Store one session.
	 *
	 * @param string               $uuid    The session.
	 * @param array<string, mixed> $data    Its payload.
	 * @param string               $updated When it was last updated.
	 *
	 * @return void
	 */
	private function store(string $uuid, array $data, string $updated): void {
		$session = new ObjectEntity();
		$session->setUuid($uuid);
		$session->setObject($data + ['agentId' => 'agent-1']);
		$session->setUpdated(new DateTime($updated));
		$this->sessions[$uuid] = $session;
	}//end store()

	/**
	 * A link to the decision document, last used at the given time.
	 *
	 * @param string $at The time.
	 *
	 * @return array<string, mixed>
	 */
	private static function decision(string $at): array {
		return ['type' => 'file', 'id' => '24753', 'label' => 'subsidiebesluit.docx', 'linkedAt' => $at, 'lastUsedAt' => $at];
	}//end decision()

	protected function setUp(): void {
		parent::setUp();
		$this->store('s-about', ['userId' => 'alice', 'links' => [self::decision('2026-11-10T09:00:00+00:00')]], '2026-11-10');
		$this->store('s-recent', ['userId' => 'alice', 'links' => [self::decision('2026-11-16T09:00:00+00:00')]], '2026-11-16');
		$this->store('s-plain', ['userId' => 'alice'], '2026-11-17');
		$this->store('s-bob', ['userId' => 'bob', 'links' => [self::decision('2026-11-15T09:00:00+00:00')]], '2026-11-15');
	}//end setUp()

	/**
	 * The controller as a user, with the query.
	 *
	 * @param string               $uid       The user.
	 * @param array<string, mixed> $params    The query.
	 * @param bool                 $withLinks Whether the links service is bound.
	 *
	 * @return SessionController
	 */
	private function controllerAs(string $uid, array $params = [], bool $withLinks = true): SessionController {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => ($this->sessions[$id] ?? null));
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => (isset($config['filters']['sessionId']) === true ? [] : array_values($this->sessions))
		);
		$objectService->method('searchObjectsPaginated')->willReturn(['total' => 0]);
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed ...$args): ObjectEntity {
				$this->saved[] = $args[0];
				$entity = new ObjectEntity();
				$entity->setUuid((string)($args[1]->uuid ?? 'saved'));
				$entity->setObject($args[0]);
				return $entity;
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$links = null;
		if ($withLinks === true) {
			$links = new SessionLinks($objectService, $this->createMock(IRootFolder::class), new NullLogger());
		}

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
				pendingApprovals: $this->createMock(SessionPendingApprovals::class),
				links: $links
			)
		);
	}//end controllerAs()

	public function testAContextNarrowsTheListAndNamesItsScope(): void {
		$data = $this->controllerAs('alice', ['linkedType' => 'file', 'linkedId' => '24753'])->index()->getData();

		$this->assertSame(['s-recent', 's-about'], array_column($data['results'], 'uuid'), 'the most recently used link first, only readable sessions');
		$this->assertSame(2, $data['total']);
		$this->assertSame(['type' => 'file', 'id' => '24753', 'label' => 'subsidiebesluit.docx'], $data['scope']);
		$this->assertSame('subsidiebesluit.docx', $data['results'][0]['links'][0]['label']);
	}//end testAContextNarrowsTheListAndNamesItsScope()

	public function testAScopeWithNoSessionYetIsStillNamed(): void {
		$data = $this->controllerAs('alice', ['linkedType' => 'file', 'linkedId' => '1'])->index()->getData();

		$this->assertSame([], $data['results']);
		$this->assertSame(['type' => 'file', 'id' => '1', 'label' => null], $data['scope']);
	}//end testAScopeWithNoSessionYetIsStillNamed()

	public function testNoContextMeansTheUnscopedList(): void {
		$data = $this->controllerAs('alice')->index()->getData();

		$this->assertSame(3, $data['total']);
		$this->assertArrayNotHasKey('scope', $data);
		$this->assertSame([], array_column($data['results'], 'links', 'uuid')['s-plain']);
	}//end testNoContextMeansTheUnscopedList()

	public function testTheOwnerRemovesALinkAndTheSessionLeavesTheScopedList(): void {
		$response = $this->controllerAs('alice', ['type' => 'file', 'id' => '24753'])->removeLink('s-about');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $response->getData()['links']);
		$this->assertCount(1, $this->saved);
		$this->assertSame([], $this->saved[0]['links']);

		$this->sessions['s-about']->setObject($this->saved[0]);
		$data = $this->controllerAs('alice', ['linkedType' => 'file', 'linkedId' => '24753'])->index()->getData();
		$this->assertSame(['s-recent'], array_column($data['results'], 'uuid'));
	}//end testTheOwnerRemovesALinkAndTheSessionLeavesTheScopedList()

	public function testOnlyTheOwnerMayRemoveALink(): void {
		$response = $this->controllerAs('alice', ['type' => 'file', 'id' => '24753'])->removeLink('s-bob');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->saved);
	}//end testOnlyTheOwnerMayRemoveALink()

	public function testRemovingNeedsTheLinkAndAnExistingSession(): void {
		$this->assertSame(400, $this->controllerAs('alice', ['type' => 'file'])->removeLink('s-about')->getStatus());
		$this->assertSame(404, $this->controllerAs('alice', ['type' => 'file', 'id' => '9'])->removeLink('s-about')->getStatus());
		$this->assertSame(404, $this->controllerAs('alice', ['type' => 'file', 'id' => '24753'])->removeLink('s-missing')->getStatus());
		$this->assertSame(503, $this->controllerAs('alice', ['type' => 'file', 'id' => '24753'], false)->removeLink('s-about')->getStatus());
		$this->assertSame([], $this->saved);
	}//end testRemovingNeedsTheLinkAndAnExistingSession()
}//end class
