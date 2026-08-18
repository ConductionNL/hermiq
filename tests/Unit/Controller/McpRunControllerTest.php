<?php

/**
 * Unit tests for McpRunController — the governed MCP server route
 * (cli-runner-governed-mcp-and-egress).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\McpRunController;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;

/**
 * Governed `initialize` / `tools/list` / `tools/call` behaviour.
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
 */
final class McpRunControllerTest extends TestCase {

	/**
	 * The catalog descriptors the facade returns — a read pair (search/get) plus a write
	 * verb the read-only wildcard grant must default-deny. `get` is argument-less, so its
	 * `properties` must serialise as an object.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function catalog(): array {
		return [
			[
				'name' => 'openregister_contact_search',
				'mcpId' => 'openregister.contact.search',
				'description' => 'Search contacts',
				'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
			],
			[
				'name' => 'openregister_contact_get',
				'mcpId' => 'openregister.contact.get',
				'description' => 'Get a contact',
				// Argument-less: an EMPTY properties array — the exact []-vs-{} bug surface.
				'parameters' => ['type' => 'object', 'properties' => []],
			],
			[
				'name' => 'openregister_contact_create',
				'mcpId' => 'openregister.contact.create',
				'description' => 'Create a contact',
				'parameters' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
			],
		];

	}//end catalog()

	/**
	 * An agent object granting exactly the read-only wildcard.
	 *
	 * @return ObjectEntity
	 */
	private function agent(): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['tools' => ['openregister.contact.*'], 'organisation' => '']);
		return $agent;
	}//end agent()

	/**
	 * Set by a test that asserts on brute-force bookkeeping; otherwise every
	 * controller gets a fresh do-nothing throttler.
	 *
	 * @var IThrottler|null
	 */
	private ?IThrottler $throttlerOverride = null;

	/**
	 * The throttler to build the controller with.
	 *
	 * @return IThrottler
	 */
	private function throttlerFor(): IThrottler {
		return ($this->throttlerOverride ?? $this->createMock(IThrottler::class));
	}//end throttlerFor()

	/**
	 * Build the controller with an overridable raw body and configurable collaborators.
	 *
	 * @param string $auth The Authorization header.
	 * @param string $body The raw JSON-RPC body.
	 * @param RunTokenService $tokens The token service.
	 * @param ObjectService $objects The object service.
	 * @param ToolRegistryFacade $facade The tool facade.
	 * @param ToolLoop $toolLoop The tool loop.
	 * @param ToolSearchService $search The tool-search service.
	 *
	 * @return McpRunController
	 */
	private function controller(
		string $auth,
		string $body,
		RunTokenService $tokens,
		ObjectService $objects,
		ToolRegistryFacade $facade,
		ToolLoop $toolLoop,
		ToolSearchService $search,
	): McpRunController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($auth): string {
				return ($name === 'Authorization') ? $auth : '';
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$userSession = $this->createMock(IUserSession::class);

		return new class($request, $tokens, $objects, $facade, new ToolGrantResolver(), $toolLoop, $search, $userManager, $userSession, $this->throttlerFor(), $this->createMock(RunStepBus::class), new NullLogger(), $body) extends McpRunController {
			// phpcs:ignore
			public function __construct(
				$request,
				$tokens,
				$objects,
				$facade,
				$grant,
				$toolLoop,
				$search,
				$userManager,
				$userSession,
				$throttler,
				$runStepBus,
				$logger,
				private string $rawBody,
			) {
				parent::__construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $runStepBus, $logger);
			}
			protected function readRawBody(): string {
				return $this->rawBody;
			}
		};

	}//end controller()

	/**
	 * A token service recognising exactly one token, bound to agent-1 / alice.
	 *
	 * @param string $valid The valid token.
	 *
	 * @return RunTokenService
	 */
	private function tokens(string $valid): RunTokenService {
		$tokens = $this->createMock(RunTokenService::class);
		$tokens->method('verify')->willReturnCallback(
			static function (string $token) use ($valid): ?array {
				if ($token === $valid) {
					return ['runId' => 'r', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => ''];
				}
				return null;
			}
		);
		return $tokens;
	}//end tokens()

	/**
	 * No/invalid token → 401 before any tool is resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-request-without-a-valid-token-is-rejected-before-any-tool-work
	 */
	public function testMissingTokenIsRejected(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		// The facade MUST NOT be consulted when the token is invalid.
		$facade->expects($this->never())->method('listTools');

		$controller = $this->controller(
			'',
			'{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
			$this->tokens('good'),
			$this->createMock(ObjectService::class),
			$facade,
			$this->createMock(ToolLoop::class),
			$this->createMock(ToolSearchService::class)
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->handle()->getStatus());

	}//end testMissingTokenIsRejected()

	/**
	 * A rejected run token is RECORDED, under the SAME action as
	 * EgressAuthorizeController — one token space, one counter, so an attacker
	 * cannot halve the cost by alternating between the two endpoints.
	 *
	 * @return void
	 */
	public function testARejectedTokenIsRegisteredUnderTheSharedAction(): void {
		$throttler = $this->createMock(IThrottler::class);
		$throttler->expects($this->once())
			->method('registerAttempt')
			->with('hermiq_run_token', $this->anything());
		$this->throttlerOverride = $throttler;

		$controller = $this->controller(
			'',
			'{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
			$this->tokens('good'),
			$this->createMock(ObjectService::class),
			$this->createMock(ToolRegistryFacade::class),
			$this->createMock(ToolLoop::class),
			$this->createMock(ToolSearchService::class)
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->handle()->getStatus());

	}//end testARejectedTokenIsRegisteredUnderTheSharedAction()

	/**
	 * A throttler that BLOWS UP must not change the answer.
	 *
	 * Brute-force bookkeeping is not the endpoint's job — if the counter fails
	 * (cache down, backend gone), the caller must still get the fail-closed 401
	 * rather than a 500 that leaks an internal fault and, worse, distinguishes
	 * a bad token from a broken cache.
	 *
	 * @return void
	 */
	public function testAFailingThrottlerStillYieldsTheFailClosed401(): void {
		$throttler = $this->createMock(IThrottler::class);
		$throttler->method('registerAttempt')->willThrowException(new \RuntimeException('cache down'));
		$this->throttlerOverride = $throttler;

		$controller = $this->controller(
			'',
			'{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
			$this->tokens('good'),
			$this->createMock(ObjectService::class),
			$this->createMock(ToolRegistryFacade::class),
			$this->createMock(ToolLoop::class),
			$this->createMock(ToolSearchService::class)
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->handle()->getStatus());

	}//end testAFailingThrottlerStillYieldsTheFailClosed401()

	/**
	 * `initialize` returns the protocol/capabilities/serverInfo and an Mcp-Session-Id header.
	 *
	 * @return void
	 */
	public function testInitializeReturnsSessionHeader(): void {
		$controller = $this->controller(
			'Bearer good',
			'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
			$this->tokens('good'),
			$this->createMock(ObjectService::class),
			$this->createMock(ToolRegistryFacade::class),
			$this->createMock(ToolLoop::class),
			$this->createMock(ToolSearchService::class)
		);

		$response = $controller->handle();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		// Read the custom header off the Response's own store (getHeaders() merges in
		// \OC-backed defaults unavailable in a unit run).
		$headersProp = (new \ReflectionClass(\OCP\AppFramework\Http\Response::class))->getProperty('headers');
		$headersProp->setAccessible(true);
		$this->assertArrayHasKey('Mcp-Session-Id', $headersProp->getValue($response));

		$data = $response->getData();
		$this->assertSame('2025-06-18', $data['result']['protocolVersion']);
		$this->assertSame('Hermiq', $data['result']['serverInfo']['name']);

	}//end testInitializeReturnsSessionHeader()

	/**
	 * `tools/list` returns ONLY the granted read verbs, and an argument-less tool's
	 * `inputSchema.properties` serialises as an object `{}` — never `[]` (the exact shape
	 * the Claude CLI's strict validator rejects).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-only-granted-tools-are-listed
	 */
	public function testToolsListReturnsGrantedToolsWithPropertiesAsObject(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn($this->catalog());

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($this->agent());

		$controller = $this->controller(
			'Bearer good',
			'{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}',
			$this->tokens('good'),
			$objects,
			$facade,
			$this->createMock(ToolLoop::class),
			$this->createMock(ToolSearchService::class)
		);

		$response = $controller->handle();
		$data = $response->getData();
		$names = array_column($data['result']['tools'], 'name');

		// Read verbs only — the write verb is default-denied by the read-only wildcard.
		sort($names);
		$this->assertSame(['openregister_contact_get', 'openregister_contact_search'], $names);

		// The argument-less `get` tool's properties MUST be an object.
		$get = null;
		foreach ($data['result']['tools'] as $tool) {
			if ($tool['name'] === 'openregister_contact_get') {
				$get = $tool;
			}
		}
		$this->assertInstanceOf(stdClass::class, $get['inputSchema']['properties']);

		// And it MUST json-encode as `{}`, never `[]`.
		$encoded = json_encode($data);
		$this->assertStringContainsString('"properties":{}', $encoded);
		$this->assertStringNotContainsString('"properties":[]', $encoded);

	}//end testToolsListReturnsGrantedToolsWithPropertiesAsObject()

	/**
	 * A THROWING agent lookup grants nothing — it does not 500.
	 *
	 * `ObjectService::find()` documents `@throws Exception If the object is not
	 * found`, and both callers invoke `loadAgent()` OUTSIDE their own try block —
	 * so before the fix the throw escaped to the dispatcher as a framework 500
	 * with a stack trace. An agent that cannot be loaded grants no tools, which
	 * is exactly what the helper's null already means: the catalogue comes back
	 * empty and the transport stays a well-formed JSON-RPC result.
	 *
	 * @return void
	 */
	public function testThrowingAgentLookupGrantsNoTools(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn($this->catalog());

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new DoesNotExistException('no such object'));

		$controller = $this->controller(
			'Bearer good',
			'{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}',
			$this->tokens('good'),
			$objects,
			$facade,
			$this->createMock(ToolLoop::class),
			$this->createMock(ToolSearchService::class)
		);

		$data = $controller->handle()->getData();

		$this->assertSame([], $data['result']['tools']);

	}//end testThrowingAgentLookupGrantsNoTools()

	/**
	 * `tools/call` for an ungranted tool returns `isError: true` and executes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-governed-refusal-is-visible-to-the-model-not-a-transport-failure
	 */
	public function testToolsCallUngrantedToolIsError(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn($this->catalog());

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($this->agent());

		$toolLoop = $this->createMock(ToolLoop::class);
		// The write verb is ungranted → never built or dispatched.
		$toolLoop->expects($this->never())->method('buildFunctionInfos');

		$controller = $this->controller(
			'Bearer good',
			'{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"openregister_contact_create","arguments":{"name":"x"}}}',
			$this->tokens('good'),
			$objects,
			$facade,
			$toolLoop,
			$this->createMock(ToolSearchService::class)
		);

		$data = $controller->handle()->getData();
		$this->assertTrue($data['result']['isError']);

	}//end testToolsCallUngrantedToolIsError()

	/**
	 * `tools/call` for a granted tool dispatches through the invoker; a governed refusal
	 * (isError in the invoker's JSON) surfaces as `result.isError: true`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-tool-call-is-governed-not-passed-through
	 */
	public function testToolsCallGrantedToolDispatchesAndMapsRefusal(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn($this->catalog());

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($this->agent());

		// A FunctionInfo-like double whose governed dispatch REFUSES (isError JSON).
		$functionInfo = new class {
			public string $name = 'openregister_contact_search';
			public function callWithArguments(array $args): string {
				return '{"isError":true,"error":"tool_denied_by_policy","message":"denied"}';
			}
		};

		$toolLoop = $this->createMock(ToolLoop::class);
		$toolLoop->method('buildFunctionInfos')->willReturn([$functionInfo]);

		$search = $this->createMock(ToolSearchService::class);
		$search->expects($this->once())->method('registerResolved');

		$controller = $this->controller(
			'Bearer good',
			'{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"openregister_contact_search","arguments":{"q":"x"}}}',
			$this->tokens('good'),
			$objects,
			$facade,
			$toolLoop,
			$search
		);

		$data = $controller->handle()->getData();
		$this->assertTrue($data['result']['isError']);
		$this->assertSame('text', $data['result']['content'][0]['type']);

	}//end testToolsCallGrantedToolDispatchesAndMapsRefusal()

	/**
	 * A body naming a DIFFERENT agentId cannot redirect the run — identity comes from the
	 * token only. The agent loaded is the token's (`agent-1`), never the body's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-token-cannot-reach-another-runs-tools
	 */
	public function testBodyCannotRedirectTheRun(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn($this->catalog());

		$objects = $this->createMock(ObjectService::class);
		// The controller MUST resolve the token's agent id, never a body-supplied one.
		$seenIds = [];
		$agent = $this->agent();
		$objects->method('find')->willReturnCallback(
			static function ($id) use (&$seenIds, $agent) {
				$seenIds[] = $id;
				return $agent;
			}
		);

		$controller = $this->controller(
			'Bearer good',
			'{"jsonrpc":"2.0","id":5,"method":"tools/list","params":{"agentId":"attacker-agent","userId":"mallory"}}',
			$this->tokens('good'),
			$objects,
			$facade,
			$this->createMock(ToolLoop::class),
			$this->createMock(ToolSearchService::class)
		);

		$this->assertSame(Http::STATUS_OK, $controller->handle()->getStatus());
		// Every agent lookup used the token's agent id, never the body's.
		foreach ($seenIds as $id) {
			$this->assertSame('agent-1', $id);
		}
		$this->assertNotEmpty($seenIds);

	}//end testBodyCannotRedirectTheRun()
}//end class
