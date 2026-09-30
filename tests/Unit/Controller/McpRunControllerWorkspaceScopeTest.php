<?php

/**
 * The governed MCP endpoint hands the workspace toolset the run of the VERIFIED
 * token, and only for the length of the dispatch.
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

use OCA\Hermiq\Controller\McpRunController;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
 */
final class McpRunControllerWorkspaceScopeTest extends TestCase {

	/**
	 * The run binding seen from inside the dispatch.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $seen = null;

	public function testTheDispatchRunsInsideTheTokensRunAndLeavesItAfterwards(): void {
		$scope = new WorkspaceRunScope();
		$tokens = $this->createMock(RunTokenService::class);
		$tokens->method('verify')->willReturn(['runId' => 'run-7', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => '']);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function () use ($scope) {
				$this->seen = $scope->current();
				return null;
			}
		);

		$body = '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"runId":"run-other","agentId":"agent-9"}}';
		$this->controller(body: $body, tokens: $tokens, objects: $objects, scope: $scope)->handle();

		self::assertSame(['runId' => 'run-7', 'agentId' => 'agent-1', 'userId' => 'alice'], $this->seen, 'The body cannot name the run.');

		$this->expectException(WorkspaceException::class);
		$scope->current();
	}//end testTheDispatchRunsInsideTheTokensRunAndLeavesItAfterwards()

	/**
	 * Build the controller.
	 *
	 * @param string            $body    The raw JSON-RPC body.
	 * @param RunTokenService   $tokens  The token service.
	 * @param ObjectService     $objects The object service.
	 * @param WorkspaceRunScope $scope   The run scope.
	 *
	 * @return McpRunController
	 */
	private function controller(string $body, RunTokenService $tokens, ObjectService $objects, WorkspaceRunScope $scope): McpRunController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Authorization') ? 'Bearer good' : ''
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		return new class($request, $tokens, $objects, $this->createMock(ToolRegistryFacade::class), new ToolGrantResolver(), $this->createMock(ToolLoop::class), $this->createMock(ToolSearchService::class), $userManager, $this->createMock(IUserSession::class), $this->createMock(IThrottler::class), $this->createMock(RunStepBus::class), new NullLogger(), $scope, $body) extends McpRunController {
			// phpcs:ignore
			public function __construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $runStepBus, $logger, $scope, private string $rawBody) {
				parent::__construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $runStepBus, $logger, $scope);
			}

			protected function readRawBody(): string {
				return $this->rawBody;
			}
		};
	}//end controller()
}//end class
