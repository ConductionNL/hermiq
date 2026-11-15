<?php

/**
 * Unit tests for AgentsController's remaining guard and failure answers.
 *
 * Pins what destroy() answers without a session user, for a missing agent and
 * when the delete itself throws, and what stats() and tools() answer when
 * their collaborator fails: the status code, the error body, and the log.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentsController;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentCatalogInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guard and failure answers of AgentsController's destroy, stats and tools.
 *
 * @covers \OCA\Hermiq\Controller\AgentsController
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
 */
final class AgentsControllerUncoveredPathsTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * @var ToolRegistryFacade&MockObject
	 */
	private ToolRegistryFacade $toolRegistry;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var AgentAccessServiceInterface&MockObject
	 */
	private AgentAccessServiceInterface $access;

	/**
	 * Build the doubles; by default the session user is alice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->toolRegistry = $this->createMock(ToolRegistryFacade::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->access = $this->createMock(AgentAccessServiceInterface::class);
	}//end setUp()

	/**
	 * Make alice the session user.
	 *
	 * @return void
	 */
	private function signInAlice(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
	}//end signInAlice()

	/**
	 * The controller over the doubles.
	 *
	 * @return AgentsController
	 */
	private function controller(): AgentsController {
		return new AgentsController(
			$this->createMock(IRequest::class),
			$this->objectService,
			$this->toolRegistry,
			$this->session,
			$this->logger,
			$this->access,
			$this->createMock(AgentCatalogInterface::class)
		);
	}//end controller()

	/**
	 * Without a session user destroy() answers 403 and touches no object.
	 *
	 * @return void
	 */
	public function testDestroyWithoutUserIsForbidden(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->objectService->expects($this->never())->method('find');
		$this->objectService->expects($this->never())->method('deleteObject');

		$response = $this->controller()->destroy('agent-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'User not authenticated'], $response->getData());
	}//end testDestroyWithoutUserIsForbidden()

	/**
	 * A missing agent on destroy is a 400 and nothing is deleted.
	 *
	 * @return void
	 */
	public function testDestroyOfAMissingAgentIsBadRequest(): void {
		$this->signInAlice();
		$this->objectService->expects($this->once())->method('find')->willReturn(null);
		$this->access->expects($this->never())->method('canUserModifyAgent');
		$this->objectService->expects($this->never())->method('deleteObject');

		$response = $this->controller()->destroy('agent-gone');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to delete agent'], $response->getData());
	}//end testDestroyOfAMissingAgentIsBadRequest()

	/**
	 * A delete that throws is logged with the agent id and answered 400.
	 *
	 * @return void
	 */
	public function testDestroyThatThrowsIsLoggedAndBadRequest(): void {
		$this->signInAlice();
		$agent = new ObjectEntity();
		$agent->setUuid('agent-own');
		$agent->setOwner('alice');
		$this->objectService->method('find')->willReturn($agent);
		$this->access->expects($this->once())->method('canUserModifyAgent')
			->with($agent, 'alice')
			->willReturn(true);
		$this->objectService->expects($this->once())->method('deleteObject')
			->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('error')->with(
			'[AgentsController] Failed to delete agent',
			$this->callback(
				static function (array $context): bool {
					return $context['id'] === 'agent-own' && $context['error'] === 'locked';
				}
			)
		);

		$response = $this->controller()->destroy('agent-own');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to delete agent'], $response->getData());
	}//end testDestroyThatThrowsIsLoggedAndBadRequest()

	/**
	 * A failing count makes stats() answer 500 and log the cause.
	 *
	 * @return void
	 */
	public function testStatsFailureIsLoggedAndServerError(): void {
		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->willThrowException(new RuntimeException('SQLSTATE[22P02]'));
		$this->logger->expects($this->once())->method('error')->with(
			'[AgentsController] Failed to get agent statistics',
			$this->callback(
				static function (array $context): bool {
					return $context['error'] === 'SQLSTATE[22P02]';
				}
			)
		);

		$response = $this->controller()->stats();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve statistics'], $response->getData());
	}//end testStatsFailureIsLoggedAndServerError()

	/**
	 * A failing tool catalogue makes tools() answer 500 and log the cause.
	 *
	 * @return void
	 */
	public function testToolsFailureIsLoggedAndServerError(): void {
		$this->toolRegistry->expects($this->once())->method('describeTools')
			->willThrowException(new RuntimeException('registry offline'));
		$this->logger->expects($this->once())->method('error')->with(
			'[AgentsController] Failed to get available tools',
			$this->callback(
				static function (array $context): bool {
					return $context['error'] === 'registry offline' && is_string($context['trace']);
				}
			)
		);

		$response = $this->controller()->tools();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve tools'], $response->getData());
	}//end testToolsFailureIsLoggedAndServerError()
}//end class
