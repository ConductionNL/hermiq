<?php

/**
 * Unit tests for SessionController's failure answers and fallbacks.
 *
 * The other SessionController tests cover the happy paths, the ownership
 * guards and the golden fixtures for create/delete/messages. This file pins
 * what index/show/update/restore answer when OpenRegister throws (500 with the
 * fixed error body and the failure logged), that update and restore answer 404
 * for a missing session, that restore clears a non-array metadata value, and
 * that a turn's provenance record is rendered into sentences when the
 * provenance writer is wired.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SessionController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Chat\SessionCollaborators;
use OCA\Hermiq\Service\Chat\SessionPendingApprovals;
use OCA\Hermiq\Service\Chat\SessionSubject;
use OCA\Hermiq\Service\Engine\AnswerProvenanceWriter;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure answers and fallbacks of SessionController.
 *
 * @covers \OCA\Hermiq\Controller\SessionController
 * @uses   \OCA\Hermiq\Service\Chat\SessionCollaborators
 * @uses   \OCA\Hermiq\Service\Chat\SessionSubject
 * @uses   \OCA\Hermiq\Service\Talk\ConversationParticipation
 * @uses   \OCA\Hermiq\Service\Engine\AnswerProvenanceWriter
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-1
 */
final class SessionControllerUncoveredPathsTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var SessionPendingApprovals&MockObject
	 */
	private SessionPendingApprovals $pendingApprovals;

	/**
	 * Set up the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->pendingApprovals = $this->createMock(SessionPendingApprovals::class);
	}//end setUp()

	/**
	 * The controller acting as alice with the given request params.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param AnswerProvenanceWriter|null $provenanceWriter The provenance writer, if wired.
	 *
	 * @return SessionController
	 */
	private function controller(array $params = [], ?AnswerProvenanceWriter $provenanceWriter = null): SessionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getPathInfo')->willReturn('/apps/hermiq/api/sessions');

		return new SessionController(
			request: $request,
			engine: $this->createMock(Engine::class),
			objectService: $this->objectService,
			userSession: $userSession,
			logger: $this->logger,
			collaborators: new SessionCollaborators(
				sessionRoom: $this->createMock(TalkSessionRoom::class),
				agentAccess: $this->createMock(AgentAccessService::class),
				subject: new SessionSubject(objectService: $this->objectService),
				pendingApprovals: $this->pendingApprovals
			),
			provenanceWriter: $provenanceWriter
		);
	}//end controller()

	/**
	 * A stored session object.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function session(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end session()

	/**
	 * Expect exactly one error log line with the given message and error text.
	 *
	 * @param string $message The expected log message.
	 * @param string $error The expected exception message in the context.
	 *
	 * @return void
	 */
	private function expectErrorLogged(string $message, string $error): void {
		$this->logger->expects(self::once())
			->method('error')
			->with(
				$message,
				self::callback(static fn (array $ctx): bool => ($ctx['error'] ?? null) === $error)
			);
	}//end expectErrorLogged()

	/**
	 * index() answers 500 and logs when the session read throws.
	 *
	 * @return void
	 */
	public function testIndexReadFailureIsServerError(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged('[SessionController] Failed to list conversations', 'db down');

		$response = $this->controller()->index();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to fetch conversations', 'message' => 'db down'], $response->getData());
	}//end testIndexReadFailureIsServerError()

	/**
	 * show() answers 500 and logs when the session lookup throws.
	 *
	 * @return void
	 */
	public function testShowLookupFailureIsServerError(): void {
		$this->objectService->expects(self::once())
			->method('find')
			->with('s-1')
			->willThrowException(new RuntimeException('lookup broke'));
		$this->expectErrorLogged('[SessionController] Failed to get conversation', 'lookup broke');

		$response = $this->controller()->show('s-1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to fetch conversation', 'message' => 'lookup broke'], $response->getData());
	}//end testShowLookupFailureIsServerError()

	/**
	 * update() answers 404 for a missing session and never saves.
	 *
	 * @return void
	 */
	public function testUpdateMissingSessionIsNotFound(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller(['title' => 'New'])->update('s-missing');

		self::assertSame(404, $response->getStatus());
		self::assertSame('Conversation not found', $response->getData()['error']);
	}//end testUpdateMissingSessionIsNotFound()

	/**
	 * update() answers 500 and logs when the save throws.
	 *
	 * @return void
	 */
	public function testUpdateSaveFailureIsServerError(): void {
		$this->objectService->method('find')->willReturn($this->session('s-1', ['userId' => 'alice', 'title' => 'Old']));
		$this->objectService->expects(self::once())
			->method('saveObject')
			->willThrowException(new RuntimeException('save broke'));
		$this->expectErrorLogged('[SessionController] Failed to update conversation', 'save broke');

		$response = $this->controller(['title' => 'New'])->update('s-1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to update conversation', 'message' => 'save broke'], $response->getData());
	}//end testUpdateSaveFailureIsServerError()

	/**
	 * restore() answers 404 for a missing session and never saves.
	 *
	 * @return void
	 */
	public function testRestoreMissingSessionIsNotFound(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->restore('s-missing');

		self::assertSame(404, $response->getStatus());
		self::assertSame('Conversation not found', $response->getData()['error']);
	}//end testRestoreMissingSessionIsNotFound()

	/**
	 * restore() replaces a non-array metadata value with null when saving.
	 *
	 * @return void
	 */
	public function testRestoreClearsNonArrayMetadata(): void {
		$this->objectService->method('find')->willReturn($this->session('s-1', ['userId' => 'alice', 'metadata' => 'garbage']));
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(
				self::callback(static fn (array $object): bool => array_key_exists('metadata', $object) && $object['metadata'] === null && $object['userId'] === 'alice'),
				self::anything(),
				'hermiq',
				'agentsession',
				's-1'
			)
			->willReturnCallback(fn (array $object): ObjectEntity => $this->session('s-1', $object));

		$response = $this->controller()->restore('s-1');

		self::assertSame(200, $response->getStatus());
		self::assertNull($response->getData()['metadata']);
		self::assertNull($response->getData()['deletedAt']);
	}//end testRestoreClearsNonArrayMetadata()

	/**
	 * restore() answers 500 and logs when the save throws.
	 *
	 * @return void
	 */
	public function testRestoreSaveFailureIsServerError(): void {
		$this->objectService->method('find')->willReturn(
			$this->session('s-1', ['userId' => 'alice', 'metadata' => ['deletedAt' => '2026-11-01', 'deletedBy' => 'alice']])
		);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('save broke'));
		$this->expectErrorLogged('[SessionController] Failed to restore conversation', 'save broke');

		$response = $this->controller()->restore('s-1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to restore conversation', 'message' => 'save broke'], $response->getData());
	}//end testRestoreSaveFailureIsServerError()

	/**
	 * messages() renders each turn's provenance record into sentences when the writer is wired.
	 *
	 * @return void
	 */
	public function testMessagesRenderProvenanceSentences(): void {
		$this->objectService->method('find')->willReturn($this->session('s-1', ['userId' => 'alice']));
		$turn = $this->session('t-1', ['sessionId' => 's-1', 'role' => 'assistant', 'content' => 'Hi', 'provenance' => ['steps' => []]]);
		$this->objectService->method('findAll')->willReturn([$turn]);
		$this->objectService->method('searchObjectsPaginated')->willReturn(['total' => 1]);
		$this->pendingApprovals->method('forSession')->willReturn([]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$response = $this->controller([], new AnswerProvenanceWriter(l10n: $l10n))->messages('s-1');

		self::assertSame(200, $response->getStatus());
		$result = $response->getData()['results'][0];
		self::assertSame(['steps' => []], $result['provenance']);
		self::assertSame(
			[
				'Which model answered was not recorded.',
				'No documents were used.',
				'No tools were called.',
				'You decide what to do with this answer.',
			],
			$result['provenanceAccount']
		);
	}//end testMessagesRenderProvenanceSentences()
}//end class
