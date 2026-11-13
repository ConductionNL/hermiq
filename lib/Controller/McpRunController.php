<?php

/**
 * Hermiq McpRunController — the governed MCP server route scoped to a single run
 * (Endpoint 1 of cli-runner-governed-mcp-and-egress).
 *
 * When a Hermiq turn runs through the `claude` CLI (`executionMode: cli`), the CLI
 * owns its own agent loop and its own MCP client — inverting the `http` path where
 * Hermiq is the MCP client. This route makes Hermiq the MCP *server*, so ALL
 * governance stays in Hermiq across that inversion: `tools/list` returns exactly
 * the tools `ToolGrantResolver::resolve($agent->tools, $catalog)` yields for the
 * run's agent, and `tools/call` dispatches through the existing
 * `FacadeToolInvoker` — the SAME path the `http` tool loop uses — so guardrail
 * classification, the human-approval gate, redaction and the resolved-grant check
 * all apply. There is no second tool-execution path. The CLI never reaches
 * OpenRegister's MCP server directly.
 *
 * **The per-run token IS the authorization** (ADR-005 semantic-auth). This route
 * is `#[PublicPage]` + `#[NoCSRFRequired]` deliberately: the caller is the CLI's
 * MCP client running in a container with no Nextcloud session and no cookie jar,
 * so there is nothing for NC to authenticate at the framework layer and nothing
 * for CSRF to attack. The body calls no `requireAdmin()`/`isAdmin()`. Identity —
 * the acting user AND agent — is resolved FROM the token (`RunTokenService`)
 * only; a runId/agentId/userId supplied in the request body can never redirect
 * which run is served. A missing/invalid/expired/consumed token is rejected 401
 * BEFORE any tool is resolved. This mirrors the ADR's own named `#[PublicPage]`
 * exemplars (OAuth callbacks, webhook receivers).
 *
 * The token resolves the acting user, whom this controller impersonates
 * (`IUserSession::setUser()`, restored in a `finally`) for the dispatch, so all
 * OpenRegister RBAC applies to that user exactly as on the `http` path.
 *
 * Every tool's `inputSchema.properties` is serialised as an OBJECT (`{}`), never
 * an array (`[]`): the `claude` CLI's MCP client is a strict JSON-Schema
 * validator and rejects the whole `tools/list` when an argument-less tool
 * serialises `properties` as `[]` (verified — discovery.md; the same class of bug
 * OpenRegister #456 fixed). PHP's `json_encode` emits an empty array as `[]`, so
 * an empty properties map is forced to a `stdClass`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\Engine\RunToolCallCounter;
use OCA\Hermiq\Service\Engine\TurnGuard;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\Hermiq\Service\Workspace\RepoEffectingGrants;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The token-gated, per-run governed MCP server: `initialize`, `tools/list`,
 * `tools/call`, all under Hermiq's existing governance.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   A JSON-RPC dispatcher over the
 *   existing engine collaborators — the coupling tracks the governance surfaces
 *   the http tool loop already threads, reused here rather than reimplemented.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Sum of many small JSON-RPC
 *   method handlers (initialize, tools/list, tools/call) plus their per-branch
 *   governance guards — a dispatch surface, not one tangled algorithm.
 *
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
 */
class McpRunController extends Controller {

	/**
	 * Brute-force throttler action for rejected per-run tokens.
	 *
	 * Deliberately the SAME action string as EgressAuthorizeController — one
	 * token space, one counter.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'hermiq_run_token';


	/**
	 * OpenRegister register slug that holds Hermiq agent objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for agent objects (agent-engine-port).
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Schema slug for the conversation (session) objects.
	 *
	 * @var string
	 */
	private const CONVERSATION_SCHEMA = 'agentsession';

	/**
	 * The chat policy for a run whose conversation cannot be read: the chat shows
	 * the withheld placeholder for every tool step's arguments and result.
	 *
	 * @var array<string,string>
	 */
	private const WITHHOLD_ALL = ['source' => 'unreadable-conversation'];

	/**
	 * The MCP protocol version this server defaults to when the client names none.
	 *
	 * @var string
	 */
	private const DEFAULT_PROTOCOL_VERSION = '2025-06-18';

	/**
	 * The run id of this request, from the verified run token.
	 *
	 * @var string
	 */
	private string $runId = '';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param RunTokenService $runTokenService Verifies the per-run bearer token.
	 * @param ObjectService $objectService Loads the run's agent (under the impersonated user).
	 * @param ToolRegistryFacade $toolRegistryFacade OR's public tool read/invoke surface.
	 * @param ToolGrantResolver $grantResolver Expands `Agent.tools` against the catalog.
	 * @param ToolLoop $toolLoop Builds the governed `FacadeToolInvoker` (reused,
	 *                           not reimplemented).
	 * @param ToolSearchService $toolSearchService Holds the run's resolved set for the approval gate.
	 * @param IUserManager $userManager Resolves the token's user to an `IUser`.
	 * @param IUserSession $userSession Impersonates that user for RBAC on dispatch.
	 * @param IThrottler $throttler Brute-force protection for run-token authentication.
	 * @param RunStepBus $runStepBus Publishes run steps to whichever surface is watching.
	 * @param LoggerInterface $logger PSR-3 logger (never receives a token value).
	 * @param WorkspaceRunScope|null $workspaceScope Carries the token's run to the governed
	 *                                              workspace tools for the length of one dispatch.
	 * @param RunToolCallCounter|null $toolCallCounter Counts the run's tool calls across requests,
	 *                                                 for the agent's tool call cap.
	 * @param GuardrailPolicyService|null $guardrailService Applies the output filters to
	 *                                                      the steps shown in the chat.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct injected collaborator, not a logic-bearing argument list.
	 */
	public function __construct(
		IRequest $request,
		private readonly RunTokenService $runTokenService,
		private readonly ObjectService $objectService,
		private readonly ToolRegistryFacade $toolRegistryFacade,
		private readonly ToolGrantResolver $grantResolver,
		private readonly ToolLoop $toolLoop,
		private readonly ToolSearchService $toolSearchService,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly IThrottler $throttler,
		private readonly RunStepBus $runStepBus,
		private readonly LoggerInterface $logger,
		private readonly ?WorkspaceRunScope $workspaceScope = null,
		private readonly ?RunToolCallCounter $toolCallCounter = null,
		private readonly ?GuardrailPolicyService $guardrailService = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Handle one MCP JSON-RPC 2.0 request for the token's run.
	 *
	 * Order is load-bearing: token auth (401) → envelope parse (400) →
	 * notification (202) / initialize / dispatch. A guardrail deny, a pending
	 * approval, an ungranted tool or a non-allowlisted URL is a TOOL-level error —
	 * HTTP 200 with `result.isError: true`, nothing executed — not a transport
	 * failure.
	 *
	 * @return Response The JSON-RPC response (200), a 202 for a notification, or a
	 *                  400/401 transport error.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-a-request-without-a-valid-token-is-rejected-before-any-tool-work
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-a-token-cannot-reach-another-runs-tools
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function handle(): Response {
		// AUTH FIRST — the per-run token is the authorization. Reject before any
		// body is parsed or any tool is resolved.
		$token = $this->bearerToken();
		$binding = $this->runTokenService->verify(token: $token);
		if ($binding === null) {
			return $this->refuseToken(token: $token);
		}

		$body = json_decode($this->readRawBody(), true);
		$refusal = $this->jsonRpcRefusal(body: $body);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->handleRequest(body: $body, binding: $binding);

	}//end handle()

	/**
	 * Refuse a body that is not a JSON-RPC 2.0 request: not JSON at all (parse error),
	 * or without the version or a method (invalid request).
	 *
	 * @param mixed $body The decoded body.
	 *
	 * @return Response|null The JSON-RPC error, or null for a well-formed request.
	 */
	private function jsonRpcRefusal(mixed $body): ?Response {
		if (is_array($body) === false) {
			return $this->jsonRpcError(id: null, code: -32700, message: 'Parse error', status: Http::STATUS_BAD_REQUEST);
		}

		if (($body['jsonrpc'] ?? null) !== '2.0' || isset($body['method']) === false) {
			return $this->jsonRpcError(
				id: ($body['id'] ?? null),
				code: -32600,
				message: 'Invalid request',
				status: Http::STATUS_BAD_REQUEST
			);
		}

		return null;

	}//end jsonRpcRefusal()

	/**
	 * Answer a well-formed request: a notification (no id) is acknowledged, initialize
	 * is answered directly, anything else is dispatched as the token's user.
	 *
	 * @param array<string, mixed> $body The JSON-RPC request.
	 * @param array<string, mixed> $binding The verified token's run binding.
	 *
	 * @return Response The answer.
	 */
	private function handleRequest(array $body, array $binding): Response {
		// Notifications (no id) — acknowledge with 202, no body (e.g.
		// notifications/initialized).
		if (($body['id'] ?? null) === null) {
			$response = new Response();
			$response->setStatus(Http::STATUS_ACCEPTED);
			return $response;
		}

		if ((string)$body['method'] === 'initialize') {
			return $this->handleInitialize(id: $body['id'], params: $this->requestParams(body: $body));
		}

		return $this->dispatchAsTokenUser(body: $body, binding: $binding);

	}//end handleRequest()

	/**
	 * The request's params when they are an object, none otherwise.
	 *
	 * @param array<string, mixed> $body The JSON-RPC request.
	 *
	 * @return array<mixed> The params.
	 */
	private function requestParams(array $body): array {
		$params = $body['params'] ?? [];
		if (is_array($params) === false) {
			return [];
		}

		return $params;

	}//end requestParams()

	/**
	 * Dispatch a request as the token's user.
	 *
	 * Identity comes from the token ONLY — the request body cannot redirect the run.
	 * Impersonate the token's user so OR RBAC applies to that user.
	 *
	 * @param array<string, mixed> $body The JSON-RPC request.
	 * @param array<string, mixed> $binding The verified token's run binding.
	 *
	 * @return Response The answer.
	 */
	private function dispatchAsTokenUser(array $body, array $binding): Response {
		return $this->withImpersonatedUser(
			userId: $binding['userId'],
			work: fn (): Response => $this->dispatchInRunWorkspace(body: $body, binding: $binding)
		);

	}//end dispatchAsTokenUser()

	/**
	 * Dispatch a request inside the token's run workspace, leaving it afterwards.
	 *
	 * @param array<string, mixed> $body The JSON-RPC request.
	 * @param array<string, mixed> $binding The verified token's run binding.
	 *
	 * @return Response The answer.
	 */
	private function dispatchInRunWorkspace(array $body, array $binding): Response {
		$this->enterRunWorkspace(binding: $binding);
		try {
			return $this->dispatch(
				id: $body['id'],
				method: (string)$body['method'],
				params: $this->requestParams(body: $body),
				agentId: $binding['agentId'],
				conversationId: $binding['conversationId']
			);
		} finally {
			$this->workspaceScope?->leave();
		}

	}//end dispatchInRunWorkspace()

	/**
	 * Enter the token's run: the workspace tools address the run's checkout by THIS
	 * run id, taken from the verified token, never from the body.
	 *
	 * @param array<string, mixed> $binding The verified token's run binding.
	 *
	 * @return void
	 */
	private function enterRunWorkspace(array $binding): void {
		$this->runId = (string)$binding['runId'];
		$this->workspaceScope?->enter(
			runId: $binding['runId'],
			agentId: $binding['agentId'],
			userId: $binding['userId']
		);

	}//end enterRunWorkspace()

	/**
	 * Refuse a token that failed verification, and count it with the brute-force
	 * throttler only when this instance never issued it.
	 *
	 * @param string $token The presented bearer token. Never logged.
	 *
	 * @return JSONResponse The fail-closed 401.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-a-request-without-a-valid-token-is-rejected-before-any-tool-work
	 */
	private function refuseToken(string $token): JSONResponse {
		// Same run-token action as EgressAuthorizeController on purpose: an
		// attacker guessing run tokens can probe either endpoint, so both
		// must feed one counter (ADR-082).
		//
		// Only a token this instance never issued is counted. A spent or
		// expired token that WAS issued is a legitimate component arriving
		// late (a CLI flushing after its turn ended), and it is refused all
		// the same. Counting it let one stale caller throttle this endpoint
		// for every run behind the same proxy IP. A guesser cannot reach the
		// uncounted branch: it needs the preimage of a stored digest.
		if ($this->runTokenService->isKnown(token: $token) === true) {
			return new JSONResponse(['error' => 'invalid_token'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (\Throwable $throttlerFailure) {
			unset($throttlerFailure);
		}

		return new JSONResponse(['error' => 'invalid_token'], Http::STATUS_UNAUTHORIZED);
	}//end refuseToken()

	/**
	 * The MCP `initialize` handshake: return the negotiated protocol version,
	 * server capabilities and an `Mcp-Session-Id` the CLI reuses on subsequent
	 * calls. The token remains the real authorization on every later call.
	 *
	 * @param mixed $id The JSON-RPC request id.
	 * @param array<string, mixed> $params The initialize params (may name a protocolVersion).
	 *
	 * @return JSONResponse The initialize result, with the session-id header.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
	 */
	private function handleInitialize(mixed $id, array $params): JSONResponse {
		$protocolVersion = (string)($params['protocolVersion'] ?? '');
		if ($protocolVersion === '') {
			$protocolVersion = self::DEFAULT_PROTOCOL_VERSION;
		}

		$result = [
			'protocolVersion' => $protocolVersion,
			'capabilities' => ['tools' => ['listChanged' => false]],
			'serverInfo' => ['name' => 'Hermiq', 'version' => '1.0.0'],
		];

		$response = $this->jsonRpcSuccess(id: $id, result: $result);
		// A stable, opaque session id derived from (but not revealing) the token.
		$response->addHeader('Mcp-Session-Id', substr($this->bearerSessionId(), 0, 32));

		return $response;
	}//end handleInitialize()

	/**
	 * Dispatch a session-scoped method to its handler.
	 *
	 * @param mixed $id The JSON-RPC request id.
	 * @param string $method The JSON-RPC method.
	 * @param array<string, mixed> $params The method params.
	 * @param string $agentId The run's agent UUID (from the token).
	 * @param string $conversationId The conversation this run belongs to, so a step
	 *                               published on the bus reaches the right stream.
	 *
	 * @return Response The JSON-RPC response.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-a-tool-call-is-governed-not-passed-through
	 */
	private function dispatch(mixed $id, string $method, array $params, string $agentId, string $conversationId = ''): Response {
		if ($method === 'tools/list') {
			return $this->jsonRpcSuccess(id: $id, result: ['tools' => $this->resolveMcpTools(agentId: $agentId)]);
		}

		if ($method === 'tools/call') {
			return $this->handleToolCall(id: $id, params: $params, agentId: $agentId, conversationId: $conversationId);
		}

		return $this->jsonRpcError(
			id: $id,
			code: -32601,
			message: 'Method not found',
			status: Http::STATUS_BAD_REQUEST
		);

	}//end dispatch()

	/**
	 * `tools/list`: exactly `ToolGrantResolver::resolve($agent->tools, $catalog)`
	 * for the run's agent, each descriptor rendered as an MCP tool whose
	 * `inputSchema.properties` is an object (never `[]`). An agent granted nothing
	 * yields `[]` — which the runner treats as a hard error (design.md "Fail
	 * loudly").
	 *
	 * @param string $agentId The run's agent UUID.
	 *
	 * @return array<int, array<string, mixed>> The MCP tool list.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-only-granted-tools-are-listed
	 */
	private function resolveMcpTools(string $agentId): array {
		$tools = [];
		foreach ($this->resolvedDescriptors(agentId: $agentId) as $descriptor) {
			$tools[] = [
				'name' => (string)$descriptor['name'],
				'description' => (string)($descriptor['description'] ?? ''),
				'inputSchema' => (new McpInputSchema())->normalise(schema: ($descriptor['parameters'] ?? [])),
			];
		}

		return $tools;
	}//end resolveMcpTools()

	/**
	 * The call's arguments, with the calling agent stamped in.
	 *
	 * WHO IS CALLING, decided here rather than trusted from the model. Several
	 * tools need the calling agent's identity: memory, and the tool-access
	 * request path, which cannot raise a request "from an agent" without knowing
	 * which. They read it from `arguments['agentId']`, which on this transport
	 * the MODEL would have to supply: it has no way to know its own uuid, so it
	 * sent nothing and the request was refused with "an access request must come
	 * from an agent". The token already binds this run to an agent, so the
	 * identity is authoritative here and is stamped in. Overwriting rather than
	 * defaulting is deliberate: a model-supplied agentId would otherwise let one
	 * agent act as another.
	 *
	 * @param array<string, mixed> $params  The JSON-RPC params.
	 * @param string               $agentId The agent from the verified token.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function callArguments(array $params, string $agentId): array {
		$arguments = $params['arguments'] ?? [];
		if (is_array($arguments) === false) {
			$arguments = [];
		}

		if ($agentId !== '') {
			$arguments['agentId'] = $agentId;
		}

		return $arguments;
	}//end callArguments()

	/**
	 * `tools/call`: dispatch through the SAME governed `FacadeToolInvoker` the
	 * `http` tool loop uses. A tool outside the agent's grants, a guardrail deny
	 * or a pending approval returns `result.isError: true` and executes nothing.
	 *
	 * @param mixed $id The JSON-RPC request id.
	 * @param array<string, mixed> $params The call params (`name`, `arguments`).
	 * @param string $agentId The run's agent UUID.
	 * @param string $conversationId The conversation this run belongs to, so a step
	 *                               published on the bus reaches the right stream.
	 *
	 * @return Response The JSON-RPC response (200; tool-level errors carry isError).
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-a-governed-refusal-is-visible-to-the-model-not-a-transport-failure
	 */
	private function handleToolCall(mixed $id, array $params, string $agentId, string $conversationId = ''): Response {
		$name = (string)($params['name'] ?? '');
		if ($name === '') {
			return $this->jsonRpcError(id: $id, code: -32602, message: 'Invalid params', status: Http::STATUS_BAD_REQUEST);
		}

		$arguments = $this->callArguments(params: $params, agentId: $agentId);
		$agent = $this->loadAgent(agentId: $agentId);

		// Agents-switch-off-and-stop: every call is its own request on this
		// transport, so the turn's tool call cap is counted per run id.
		$cap = (new AgentAvailability())->maxToolCalls(agent: $agent);
		if ($this->toolCallCounter?->admit(runId: $this->runId, cap: $cap) === false) {
			return $this->jsonRpcSuccess(
				id: $id,
				result: $this->toolError(text: TurnGuard::LIMIT_REACHED . '. Do not call another tool; answer with what you have.')
			);
		}

		$descriptors = $this->resolvedDescriptorsFor(agent: $agent);

		// An ungranted tool is a TOOL-level error (isError: true), never executed —
		// the model must see it and adapt. `$descriptors` is the resolved
		// (grant-filtered, default-denied) set, so a name absent from it is
		// ungranted by construction.
		if ($this->isDescriptorGranted(name: $name, descriptors: $descriptors) === false) {
			return $this->jsonRpcSuccess(id: $id, result: $this->toolError(text: "Tool '{$name}' is not available to this agent."));
		}

		// Register the resolved set so the invoker's grant/approval-gate check
		// (ToolSearchService::isGranted) sees it, then build the SAME governed
		// FacadeToolInvoker the http path builds.
		$this->toolSearchService->registerResolved(descriptors: $descriptors);

		$functionInfos = $this->toolLoop->buildFunctionInfos(
			functions: $descriptors,
			agent: $agent,
			organisation: $this->organisationOf(agent: $agent)
		);

		foreach ($functionInfos as $functionInfo) {
			if ($functionInfo->name !== $name) {
				continue;
			}

			// Record the step so the CHAT can show it. This request is the only
			// place that knows the tool ran: on this transport the model calls
			// Hermiq's MCP endpoint, so the turn's own StreamYieldChannel — which
			// the UI is listening to — is never touched. The run token binds this
			// request to its conversation, which is what lets the two be joined.
			$startedAt = microtime(true);
			$policy = $this->chatPolicy(conversationId: $conversationId);
			$shownArguments = $this->shownInChat(policy: $policy, value: $arguments);

			try {
				$resultJson = (string)$functionInfo->callWithArguments($arguments);
			} catch (Throwable $e) {
				$this->logger->error(
					message: '[McpRunController] Governed tool dispatch failed',
					context: ['tool' => $name, 'error' => $e->getMessage()]
				);

				$this->runStepBus->record(
					conversationId: $conversationId,
					toolName: $name,
					arguments: $shownArguments,
					result: 'The tool call could not be completed.',
					durationMs: (int)round((microtime(true) - $startedAt) * 1000),
					outcome: RunStepBus::OUTCOME_ERROR
				);

				return $this->jsonRpcSuccess(id: $id, result: $this->toolError(text: 'The tool call could not be completed.'));
			}

			$this->runStepBus->record(
				conversationId: $conversationId,
				toolName: $name,
				arguments: $shownArguments,
				result: $this->shownResult(policy: $policy, resultJson: $resultJson),
				durationMs: (int)round((microtime(true) - $startedAt) * 1000)
			);

			return $this->jsonRpcSuccess(id: $id, result: $this->toolResultFromInvoker(resultJson: $resultJson));
		}

		// Granted but no FunctionInfo built (e.g. a descriptor the loop skipped) —
		// treat as ungranted rather than executing an unbuilt path.
		return $this->jsonRpcSuccess(id: $id, result: $this->toolError(text: "Tool '{$name}' is not available to this agent."));
	}//end handleToolCall()

	/**
	 * The resolved (grant-filtered) descriptor set for the agent id.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return array<int, array<string, mixed>> The resolved descriptors.
	 */
	private function resolvedDescriptors(string $agentId): array {
		return $this->resolvedDescriptorsFor(agent: $this->loadAgent(agentId: $agentId));
	}//end resolvedDescriptors()

	/**
	 * The resolved (grant-filtered, default-denied) descriptor set for an agent
	 * object: `ToolGrantResolver::resolve($agent->tools, $catalog)` filtered back
	 * onto the catalog descriptors, preserving order.
	 *
	 * @param ObjectEntity|null $agent The agent object, or null when unresolved.
	 *
	 * @return array<int, array<string, mixed>> The resolved descriptors.
	 */
	private function resolvedDescriptorsFor(?ObjectEntity $agent): array {
		if ($agent === null) {
			return [];
		}

		$grants = $this->grantsOf(agent: $agent);
		$catalog = $this->toolRegistryFacade->listTools(toolWhitelist: []);
		$allowed = array_flip($this->resolvedToolIds(grants: $grants, catalog: $catalog));

		return array_values(
			array_filter(
				$catalog,
				static fn (mixed $descriptor): bool => self::isAllowedDescriptor(descriptor: $descriptor, allowed: $allowed)
			)
		);
	}//end resolvedDescriptorsFor()

	/**
	 * An agent's tool grants; a non-array value grants nothing.
	 *
	 * @param ObjectEntity $agent The agent.
	 *
	 * @return array<mixed> The grants.
	 */
	private function grantsOf(ObjectEntity $agent): array {
		$grants = ($agent->getObject()['tools'] ?? []);
		if (is_array($grants) === false) {
			return [];
		}

		return $grants;

	}//end grantsOf()

	/**
	 * The catalogue tool ids the grants resolve to. Hermiq-runner-git-capability: a
	 * repo-effecting tool resolves only from a grant that pins the repository and
	 * constrains the branch.
	 *
	 * @param array<mixed> $grants The agent's grants.
	 * @param array<int, array<string, mixed>> $catalog The tool catalogue.
	 *
	 * @return array<int, string> The resolved tool ids.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RepoEffectingGrants is a pure rule over the grant grammar, like ToolGrantResolver's static classifiers.
	 */
	private function resolvedToolIds(array $grants, array $catalog): array {
		return RepoEffectingGrants::filterIds(
			resolvedIds: $this->grantResolver->resolve(grants: $grants, catalog: $catalog),
			constraints: $this->grantResolver->argumentConstraints(grants: $grants)
		);

	}//end resolvedToolIds()

	/**
	 * Whether a catalogue descriptor, by its mcpId or else its name, is among the
	 * allowed tool ids.
	 *
	 * @param mixed $descriptor One catalogue descriptor.
	 * @param array<string, int> $allowed The allowed tool ids as keys.
	 *
	 * @return bool True when the descriptor is allowed.
	 */
	private static function isAllowedDescriptor(mixed $descriptor, array $allowed): bool {
		$descriptorId = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
		return is_string($descriptorId) === true && isset($allowed[$descriptorId]) === true;

	}//end isAllowedDescriptor()

	/**
	 * Whether an MCP tool name matches a granted descriptor (by `name` or `mcpId`).
	 *
	 * @param string $name The requested tool name.
	 * @param array<int, array<string, mixed>> $descriptors The resolved descriptors.
	 *
	 * @return bool
	 */
	private function isDescriptorGranted(string $name, array $descriptors): bool {
		foreach ($descriptors as $descriptor) {
			if (($descriptor['name'] ?? null) === $name || ($descriptor['mcpId'] ?? null) === $name) {
				return true;
			}
		}

		return false;
	}//end isDescriptorGranted()

	/**
	 * Load the run's agent under the (already impersonated) user's RBAC.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return ObjectEntity|null The agent, or null when absent/unreadable.
	 */
	private function loadAgent(string $agentId): ?ObjectEntity {
		if ($agentId === '') {
			return null;
		}

		try {
			$agent = $this->objectService->find(id: $agentId, register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA);
		} catch (Throwable $e) {
			// `ObjectService::find()` throws when the object is absent, and both
			// callers invoke this helper OUTSIDE their own try block — so the
			// throw would escape as a framework 500 with a stack trace. An agent
			// that cannot be loaded is, to a caller, unreadable, which is exactly
			// what this helper's null already means.
			$this->logger->warning(
				'Hermiq MCP agent lookup failed for ' . $agentId . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return null;
		}//end try

		if ($agent instanceof ObjectEntity) {
			return $agent;
		}

		return null;
	}//end loadAgent()

	/**
	 * A tool step's arguments or result as the chat may show it: through the
	 * policy's output filters, or as given when no policy is resolved.
	 *
	 * @param array<string,mixed>|null $policy The conversation's effective policy.
	 * @param mixed                    $value  The arguments or the result text.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	private function shownInChat(?array $policy, mixed $value): mixed {
		if ($policy === null || $this->guardrailService === null) {
			return $value;
		}

		if ($policy === self::WITHHOLD_ALL) {
			return $this->guardrailService->withheldPlaceholder();
		}

		return $this->guardrailService->filterOutputValue(policy: $policy, value: $value);
	}//end shownInChat()

	/**
	 * The tool's result JSON as the chat may show it. The JSON is filtered as its
	 * decoded value, so escaped characters such as `\/` do not hide a match.
	 *
	 * @param array<string,mixed>|null $policy     The conversation's effective policy.
	 * @param string                   $resultJson The result JSON the tool returned.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	private function shownResult(?array $policy, string $resultJson): string {
		$decoded = json_decode($resultJson, true);
		if (is_array($decoded) === false) {
			return (string)$this->shownInChat(policy: $policy, value: $resultJson);
		}

		$shown = $this->shownInChat(policy: $policy, value: $decoded);
		if ($shown === $decoded) {
			return $resultJson;
		}

		if (is_string($shown) === true) {
			return $shown;
		}

		return (string)json_encode($shown, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}//end shownResult()

	/**
	 * The policy that decides what the chat shows of a tool step: the
	 * conversation's, the same one that filters the answer. Null when there is no
	 * guardrail service; WITHHOLD_ALL when the conversation cannot be read.
	 *
	 * @param string $conversationId The run's conversation UUID.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#scenario-tool-details-follow-the-conversations-policy
	 */
	private function chatPolicy(string $conversationId): ?array {
		if ($this->guardrailService === null) {
			return null;
		}

		$organisation = $this->conversationOrganisation(conversationId: $conversationId);
		if ($organisation === null) {
			return self::WITHHOLD_ALL;
		}

		return $this->guardrailService->effectivePolicyFor(organisation: $organisation);
	}//end chatPolicy()

	/**
	 * The conversation's organisation, whose policy also filters the answer: ''
	 * when the run has no conversation, null when the conversation cannot be read.
	 *
	 * @param string $conversationId The run's conversation UUID.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#scenario-tool-details-follow-the-conversations-policy
	 */
	private function conversationOrganisation(string $conversationId): ?string {
		if ($conversationId === '') {
			return '';
		}

		try {
			$conversation = $this->objectService->find(
				id: $conversationId,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[McpRunController] Conversation lookup failed',
				context: ['conversationId' => $conversationId, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($conversation instanceof ObjectEntity) {
			return (string)($conversation->getOrganisation() ?? '');
		}

		return null;
	}//end conversationOrganisation()

	/**
	 * The agent's organisation (for the guardrail policy), or '' when unset.
	 * OpenRegister keeps it on the object entity, not in the object data.
	 *
	 * @param ObjectEntity|null $agent The agent object.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
	 */
	private function organisationOf(?ObjectEntity $agent): string {
		if ($agent === null) {
			return '';
		}

		return (string)($agent->getOrganisation() ?? '');
	}//end organisationOf()

	/**
	 * Wrap a `FacadeToolInvoker` result string as an MCP `tools/call` result. The
	 * invoker returns a JSON string; a governed refusal carries `isError: true`,
	 * which becomes the MCP result's `isError`.
	 *
	 * @param string $resultJson The invoker's JSON-encoded result.
	 *
	 * @return array<string, mixed> The MCP result envelope.
	 */
	private function toolResultFromInvoker(string $resultJson): array {
		$isError = false;
		$decoded = json_decode($resultJson, true);
		if (is_array($decoded) === true && ($decoded['isError'] ?? false) === true) {
			$isError = true;
		}

		return [
			'content' => [['type' => 'text', 'text' => $resultJson]],
			'isError' => $isError,
		];

	}//end toolResultFromInvoker()

	/**
	 * Build a tool-level error result (HTTP 200, `isError: true`) for the model.
	 *
	 * @param string $text The explanatory message the model sees.
	 *
	 * @return array<string, mixed> The MCP result envelope.
	 */
	private function toolError(string $text): array {
		return [
			'content' => [['type' => 'text', 'text' => $text]],
			'isError' => true,
		];

	}//end toolError()

	/**
	 * Run `$work` with the token's user set on the session, restoring the prior
	 * user in a `finally`. This is the only place the run's user is impersonated,
	 * so OR RBAC on the tool dispatch applies to that user.
	 *
	 * @param string $userId The token's user UID.
	 * @param callable $work The dispatch to run as that user.
	 *
	 * @return Response The dispatch response.
	 */
	private function withImpersonatedUser(string $userId, callable $work): Response {
		$user = null;
		if ($userId !== '') {
			$user = $this->userManager->get($userId);
		}

		if ($user === null) {
			// The token's user no longer exists — the run cannot be served.
			return new JSONResponse(['error' => 'run_not_active'], Http::STATUS_FORBIDDEN);
		}

		$prior = $this->userSession->getUser();
		$this->userSession->setUser($user);
		try {
			return $work();
		} finally {
			$this->userSession->setUser($prior);
		}

	}//end withImpersonatedUser()

	/**
	 * Extract the bearer token from the `Authorization` header. Never logged.
	 *
	 * @return string The token, or '' when absent/malformed.
	 */
	private function bearerToken(): string {
		$header = (string)$this->request->getHeader('Authorization');
		if (stripos($header, 'Bearer ') !== 0) {
			return '';
		}

		return trim(substr($header, 7));
	}//end bearerToken()

	/**
	 * An opaque, non-secret session id derived from the token (a one-way digest
	 * with a distinct label — it never reveals the token). Only used to satisfy
	 * the MCP `Mcp-Session-Id` handshake; the token remains the real auth.
	 *
	 * @return string A hex digest.
	 */
	private function bearerSessionId(): string {
		return hash('sha256', 'mcp-session:' . $this->bearerToken());
	}//end bearerSessionId()

	/**
	 * Read the raw POST body. Indirected (mirrors `WebhookTriggerController`) so
	 * tests can override it without stubbing `php://input`.
	 *
	 * @return string The raw request body, or '' when unreadable.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
	 */
	protected function readRawBody(): string {
		$body = file_get_contents('php://input');
		if ($body === false) {
			return '';
		}

		return $body;
	}//end readRawBody()

	/**
	 * Build a JSON-RPC 2.0 success response (HTTP 200).
	 *
	 * @param mixed $id The request id.
	 * @param mixed $result The result payload.
	 *
	 * @return JSONResponse
	 */
	private function jsonRpcSuccess(mixed $id, mixed $result): JSONResponse {
		return new JSONResponse(
			['jsonrpc' => '2.0', 'id' => $id, 'result' => $result],
			Http::STATUS_OK
		);

	}//end jsonRpcSuccess()

	/**
	 * Build a JSON-RPC 2.0 error response. Messages are static and generic per
	 * ADR-005 — never `$e->getMessage()`, never a token value.
	 *
	 * @param mixed $id The request id.
	 * @param int $code The JSON-RPC error code.
	 * @param string $message A static, generic message.
	 * @param int $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function jsonRpcError(mixed $id, int $code, string $message, int $status): JSONResponse {
		return new JSONResponse(
			['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]],
			$status
		);

	}//end jsonRpcError()
}//end class
