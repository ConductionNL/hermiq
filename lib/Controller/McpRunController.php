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
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
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
use stdClass;
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
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
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
	 * The MCP protocol version this server defaults to when the client names none.
	 *
	 * @var string
	 */
	private const DEFAULT_PROTOCOL_VERSION = '2025-06-18';

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
	 * @param LoggerInterface $logger PSR-3 logger (never receives a token value).
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
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

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
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-request-without-a-valid-token-is-rejected-before-any-tool-work
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-token-cannot-reach-another-runs-tools
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function handle(): Response {
		// AUTH FIRST — the per-run token is the authorization. Reject before any
		// body is parsed or any tool is resolved.
		$binding = $this->runTokenService->verify(token: $this->bearerToken());
		if ($binding === null) {
			// Same run-token action as EgressAuthorizeController on purpose: an
			// attacker guessing run tokens can probe either endpoint, so both
			// must feed one counter (ADR-082).
			try {
				$this->throttler->registerAttempt(
					action: self::THROTTLE_ACTION,
					ip: $this->request->getRemoteAddress()
				);
			} catch (\Throwable $throttlerFailure) {
				unset($throttlerFailure);
			}

			return new JSONResponse(['error' => 'invalid_token'], Http::STATUS_UNAUTHORIZED);
		}

		$body = json_decode($this->readRawBody(), true);
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

		$method = (string)$body['method'];
		$id = ($body['id'] ?? null);
		$params = $body['params'] ?? [];
		if (is_array($params) === false) {
			$params = [];
		}

		// Notifications (no id) — acknowledge with 202, no body (e.g.
		// notifications/initialized).
		if ($id === null) {
			$response = new Response();
			$response->setStatus(Http::STATUS_ACCEPTED);
			return $response;
		}

		if ($method === 'initialize') {
			return $this->handleInitialize(id: $id, params: $params);
		}

		// Identity comes from the token ONLY — the request body cannot redirect
		// the run. Impersonate the token's user so OR RBAC applies to that user.
		return $this->withImpersonatedUser(
			userId: $binding['userId'],
			work: function () use ($id, $method, $params, $binding): Response {
				return $this->dispatch(id: $id, method: $method, params: $params, agentId: $binding['agentId']);
			}
		);

	}//end handle()

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
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
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
	 *
	 * @return Response The JSON-RPC response.
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-tool-call-is-governed-not-passed-through
	 */
	private function dispatch(mixed $id, string $method, array $params, string $agentId): Response {
		if ($method === 'tools/list') {
			return $this->jsonRpcSuccess(id: $id, result: ['tools' => $this->resolveMcpTools(agentId: $agentId)]);
		}

		if ($method === 'tools/call') {
			return $this->handleToolCall(id: $id, params: $params, agentId: $agentId);
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
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-only-granted-tools-are-listed
	 */
	private function resolveMcpTools(string $agentId): array {
		$tools = [];
		foreach ($this->resolvedDescriptors(agentId: $agentId) as $descriptor) {
			$tools[] = [
				'name' => (string)$descriptor['name'],
				'description' => (string)($descriptor['description'] ?? ''),
				'inputSchema' => $this->normaliseInputSchema(schema: ($descriptor['parameters'] ?? [])),
			];
		}

		return $tools;
	}//end resolveMcpTools()

	/**
	 * `tools/call`: dispatch through the SAME governed `FacadeToolInvoker` the
	 * `http` tool loop uses. A tool outside the agent's grants, a guardrail deny
	 * or a pending approval returns `result.isError: true` and executes nothing.
	 *
	 * @param mixed $id The JSON-RPC request id.
	 * @param array<string, mixed> $params The call params (`name`, `arguments`).
	 * @param string $agentId The run's agent UUID.
	 *
	 * @return Response The JSON-RPC response (200; tool-level errors carry isError).
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-governed-refusal-is-visible-to-the-model-not-a-transport-failure
	 */
	private function handleToolCall(mixed $id, array $params, string $agentId): Response {
		$name = (string)($params['name'] ?? '');
		if ($name === '') {
			return $this->jsonRpcError(id: $id, code: -32602, message: 'Invalid params', status: Http::STATUS_BAD_REQUEST);
		}

		$arguments = $params['arguments'] ?? [];
		if (is_array($arguments) === false) {
			$arguments = [];
		}

		// WHO IS CALLING, decided here rather than trusted from the model.
		//
		// Several tools need the calling agent's identity — memory, and the
		// tool-access request path, which cannot raise a request "from an agent"
		// without knowing which. They read it from `arguments['agentId']`, which
		// on this transport the MODEL would have to supply: it has no way to know
		// its own uuid, so it sent nothing and the request was refused with "an
		// access request must come from an agent".
		//
		// The token already binds this run to an agent (see the binding above),
		// so the identity is authoritative here and is stamped in. Overwriting
		// rather than defaulting is deliberate: a model-supplied agentId would
		// otherwise let one agent act as another.
		if ($agentId !== '') {
			$arguments['agentId'] = $agentId;
		}

		$agent = $this->loadAgent(agentId: $agentId);
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

			try {
				$resultJson = (string)$functionInfo->callWithArguments($arguments);
			} catch (Throwable $e) {
				$this->logger->error(
					message: '[McpRunController] Governed tool dispatch failed',
					context: ['tool' => $name, 'error' => $e->getMessage()]
				);

				return $this->jsonRpcSuccess(id: $id, result: $this->toolError(text: 'The tool call could not be completed.'));
			}

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

		$grants = ($agent->getObject()['tools'] ?? []);
		if (is_array($grants) === false) {
			$grants = [];
		}

		$catalog = $this->toolRegistryFacade->listTools(toolWhitelist: []);
		$resolvedIds = $this->grantResolver->resolve(grants: $grants, catalog: $catalog);
		$allowed = array_flip($resolvedIds);

		$out = [];
		foreach ($catalog as $descriptor) {
			$descriptorId = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
			if (is_string($descriptorId) === true && isset($allowed[$descriptorId]) === true) {
				$out[] = $descriptor;
			}
		}

		return $out;
	}//end resolvedDescriptorsFor()

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
	 * The agent's organisation (for the guardrail policy), or '' when unset.
	 *
	 * @param ObjectEntity|null $agent The agent object.
	 *
	 * @return string
	 */
	private function organisationOf(?ObjectEntity $agent): string {
		if ($agent === null) {
			return '';
		}

		return (string)($agent->getObject()['organisation'] ?? '');
	}//end organisationOf()

	/**
	 * Normalise a facade descriptor's JSON-schema into an MCP `inputSchema` whose
	 * `properties` is ALWAYS a JSON object — never `[]` (the exact shape the
	 * `claude` CLI's strict validator rejects). Recurses so nested object schemas
	 * with an empty `properties` are also objects.
	 *
	 * @param mixed $schema The descriptor's `parameters` fragment.
	 *
	 * @return array<string, mixed> The MCP-safe input schema.
	 */
	private function normaliseInputSchema(mixed $schema): array {
		if (is_array($schema) === false) {
			$schema = [];
		}

		if (isset($schema['type']) === false) {
			$schema['type'] = 'object';
		}

		// An object schema MUST carry a `properties` object — an argument-less tool
		// that omits it entirely (or carries `[]`) is exactly what the CLI's strict
		// validator rejects. Force a present, object-typed `properties`.
		if ($schema['type'] === 'object' && array_key_exists('properties', $schema) === false) {
			$schema['properties'] = new stdClass();
		}

		return $this->normaliseSchemaProperties(schema: $schema);
	}//end normaliseInputSchema()

	/**
	 * Force every `properties` map in a schema fragment to serialise as an object,
	 * recursing into each property's own schema.
	 *
	 * @param array<string, mixed> $schema The schema fragment.
	 *
	 * @return array<string, mixed> The normalised fragment.
	 */
	private function normaliseSchemaProperties(array $schema): array {
		if (array_key_exists('properties', $schema) === true) {
			$properties = $schema['properties'];
			// Empty (or non-array) properties MUST be an object, not `[]`.
			$schema['properties'] = new stdClass();
			if (is_array($properties) === true && $properties !== []) {
				$normalised = [];
				foreach ($properties as $propName => $propSchema) {
					$normalised[$propName] = $propSchema;
					if (is_array($propSchema) === true) {
						$normalised[$propName] = $this->normaliseSchemaProperties(schema: $propSchema);
					}
				}

				$schema['properties'] = $normalised;
			}
		}//end if

		if (isset($schema['items']) === true && is_array($schema['items']) === true) {
			$schema['items'] = $this->normaliseSchemaProperties(schema: $schema['items']);
		}

		return $schema;
	}//end normaliseSchemaProperties()

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
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
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
