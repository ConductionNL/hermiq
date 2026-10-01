<?php

/**
 * The non-streaming chat endpoint opens a fresh chat in an app with the app's
 * agent, through the same resolver as the streaming one (agents-bound-to-their-app,
 * REQ-APPAG-002). Before this change it refused a request without an agent.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\AppAssistantResolver;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class ChatControllerAppAssistantTest extends TestCase {

	private function agentFor(array $params): string {
		$assistant = new ObjectEntity();
		$assistant->setUuid('subsidy-assistant');
		$assistant->setOwner('admin');
		$assistant->setObject(['isPrivate' => false, 'applicationSlug' => 'subsidies', 'appAssistant' => true]);
		$other = new ObjectEntity();
		$other->setUuid('hydra');
		$other->setOwner('admin');
		$other->setObject(['isPrivate' => false, 'applicationSlug' => 'hydra-console']);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn([$other, $assistant]);
		$logger = $this->createMock(LoggerInterface::class);

		$controller = new ChatController(
			request: $this->createMock(IRequest::class),
			engine: $this->createMock(Engine::class),
			objectService: $objects,
			userSession: $this->createMock(IUserSession::class),
			l10n: $this->createMock(IL10N::class),
			runStepBus: $this->createMock(RunStepBus::class),
			providerFactory: $this->createMock(ProviderFactory::class),
			accessRequests: $this->createMock(ToolAccessRequestService::class),
			logger: $logger,
			assistants: new AppAssistantResolver(
				objectService: $objects,
				agentAccess: new AgentAccessService($objects, $logger, $this->createMock(IGroupManager::class)),
				logger: $logger
			)
		);

		$method = new ReflectionMethod(ChatController::class, 'agentForRequest');
		return (string)$method->invoke($controller, $params, 'alice');
	}//end agentFor()

	public function testAFreshChatInAnAppGetsTheAppsAssistant(): void {
		self::assertSame(
			'subsidy-assistant',
			$this->agentFor(['agentUuid' => '', 'conversationUuid' => '', 'context' => ['appId' => 'subsidies']])
		);
	}//end testAFreshChatInAnAppGetsTheAppsAssistant()

	public function testANamedAgentIsKept(): void {
		self::assertSame('chosen', $this->agentFor(['agentUuid' => 'chosen', 'conversationUuid' => '', 'context' => ['appId' => 'subsidies']]));
	}//end testANamedAgentIsKept()

	public function testAnExistingConversationNeedsNoAgent(): void {
		self::assertSame('', $this->agentFor(['agentUuid' => '', 'conversationUuid' => 'c1', 'context' => ['appId' => 'subsidies']]));
	}//end testAnExistingConversationNeedsNoAgent()
}//end class
