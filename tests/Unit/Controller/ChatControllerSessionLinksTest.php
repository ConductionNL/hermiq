<?php

/**
 * Unit tests for recording session links when a turn is sent.
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

use OCA\Hermiq\Controller\ChatController;
use OCA\Hermiq\Service\Chat\SessionLinks;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Session-entity-links D2: a SENT turn records what its page shows; a turn
 * that fails records nothing.
 *
 * @covers \OCA\Hermiq\Controller\ChatController
 */
final class ChatControllerSessionLinksTest extends TestCase {

	/**
	 * The engine.
	 *
	 * @var Engine&MockObject
	 */
	private Engine $engine;

	/**
	 * The links service.
	 *
	 * @var SessionLinks&MockObject
	 */
	private SessionLinks $links;

	/**
	 * The controller for a turn from the given page context.
	 *
	 * @param array<string, mixed> $context The page context.
	 *
	 * @return ChatController
	 */
	private function controller(array $context): ChatController {
		$params = ['conversation' => 'conv-1', 'message' => 'Wat staat er in dit besluit?', 'context' => $context];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$session = new ObjectEntity();
		$session->setUuid('conv-1');
		$session->setObject(['userId' => 'alice', 'agentId' => 'agent-1']);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturn($session);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$this->engine = $this->createMock(Engine::class);
		$this->links = $this->createMock(SessionLinks::class);

		return new ChatController(
			request: $request,
			engine: $this->engine,
			objectService: $objectService,
			userSession: $userSession,
			l10n: $l10n,
			runStepBus: $this->createMock(RunStepBus::class),
			providerFactory: $this->createMock(ProviderFactory::class),
			accessRequests: $this->createMock(ToolAccessRequestService::class),
			logger: new NullLogger(),
			sessionLinks: $this->links
		);
	}//end controller()

	public function testASentTurnRecordsItsPageContext(): void {
		$controller = $this->controller(['pageKind' => 'file', 'fileId' => '24753']);
		$this->engine->method('processMessage')->willReturn(['message' => 'Het besluit verleent...', 'messageId' => 'msg-1']);
		$this->links->expects($this->once())->method('recordTurn')->with('conv-1', ['pageKind' => 'file', 'fileId' => '24753'], 'alice');

		$this->assertSame(200, $controller->sendMessage()->getStatus());
	}//end testASentTurnRecordsItsPageContext()

	public function testAFailedTurnRecordsNothing(): void {
		$controller = $this->controller(['fileId' => '24753']);
		$this->engine->method('processMessage')->willThrowException(new RuntimeException('provider down'));
		$this->links->expects($this->never())->method('recordTurn');

		$this->assertSame(500, $controller->sendMessage()->getStatus());
	}//end testAFailedTurnRecordsNothing()
}//end class
