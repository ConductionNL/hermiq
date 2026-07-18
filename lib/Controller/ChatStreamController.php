<?php

/**
 * Hermiq ChatStreamController.
 *
 * SSE streaming endpoint for the AI chat companion widget, ported bit-for-bit
 * from OpenRegister's ChatStreamController (agent-engine-port task 4.2). Wraps
 * the in-app Engine::processMessage pipeline (RAG + tool loop + LLM) and emits
 * a Server-Sent Events stream conformant to the six-event envelope defined in
 * hydra ADR-034 Decision 6: `token` / `tool_call` / `tool_result` / `heartbeat`
 * (15s interleave) / `final` / `error` — exactly one terminal `final` OR
 * `error` per request; every `tool_call` is followed by a `tool_result` before
 * `final`; non-streaming providers degrade to zero `token` events plus one
 * `final` carrying the full text.
 *
 * Persistence adaptation: conversation resolution/creation runs against the
 * `conversation` object in the `hermiq` OpenRegister register via ObjectService
 * (UUID ids), replacing OR's ConversationMapper/AgentMapper.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Implements POST /api/chat/stream — Server-Sent Events streaming of an AI
 * response. Emits the contractual envelope; when the active provider supports
 * LLPhant streaming the Engine drives the StreamYieldChannel, otherwise the
 * synchronous fallback (zero `token` events + one `final`) applies — both
 * shapes are part of the contract, so clients never branch.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) SSE controller composes Engine +
 * ObjectService + IUserSession + IDBConnection + LoggerInterface; each handles a
 * separate concern of the streaming pipeline (auth, DB commit safety, conversation
 * routing, message processing) and cannot be removed without losing functionality.
 */
class ChatStreamController extends Controller
{
    use SanitizesForSaveTrait;

    /**
     * Forbidden error sentinel used by resolveConversation() when the
     * caller does not own the requested conversation.
     *
     * @var string
     */
    private const ERROR_FORBIDDEN = 'cn_chat_stream_forbidden';

    /**
     * Generic public-facing error message. We deliberately never leak
     * exception messages over the SSE wire — they can contain DB
     * connection strings, API key fragments or internal paths. The
     * full $e->getMessage() is recorded in the logger instead.
     *
     * @var string
     */
    private const PUBLIC_ERROR_MESSAGE = 'An internal error occurred.';

    /**
     * OpenRegister register slug that holds Hermiq agent-engine objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for agent objects.
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * App-config key holding the UUID of the agent the companion widget uses by
     * default (the widget itself sends no agentUuid — there is no agent picker
     * in v1). Set with `occ config:app:set hermiq companion_agent_uuid <uuid>`;
     * empty/unset falls back to the first agent the user can access.
     *
     * @var string
     */
    private const COMPANION_AGENT_CONFIG_KEY = 'companion_agent_uuid';

    /**
     * Per-user config key holding the UUID of the user's chosen default agent.
     *
     * Written by the personal-settings picker through `PreferencesController`, which
     * stores every preference under the `pref_` prefix, so the stored key is
     * `pref_default-agent`. Read here as the HIGHEST-precedence tier: a user's own
     * choice beats the instance-wide `companion_agent_uuid`, which beats the
     * access-scoped scan. The stored value is a preference, never an authorization —
     * `canUserAccessAgent()` re-checks it on every read, so a UUID the user can no
     * longer reach simply falls through to the next tier.
     *
     * @var string
     */
    private const DEFAULT_AGENT_PREF_KEY = 'pref_default-agent';

    /**
     * Schema slug for conversation objects.
     *
     * @var string
     */
    private const CONVERSATION_SCHEMA = 'conversation';

    /**
     * Wall-clock timestamp (microtime float) of the most recently emitted
     * SSE frame of any type. Used by the heartbeat-interleave pre-check
     * inside the channel callbacks: when `now() - $lastEventAt >= 15.0`
     * we emit a `heartbeat` frame before forwarding the next event.
     *
     * @var float
     */
    private float $lastEventAt = 0.0;

    /**
     * Constructor.
     *
     * @param IRequest        $request       The request object.
     * @param Engine          $engine        In-app agent engine facade (ported ChatService).
     * @param ObjectService   $objectService OpenRegister object read/write (single write-path).
     * @param IUserSession    $userSession   Resolves the requesting user.
     * @param IDBConnection   $db            Database connection, used to finalise any open
     *                                       transaction before the SSE handler bypasses the
     *                                       framework via exit; (connection-leak mitigation).
     * @param LoggerInterface $logger        PSR-3 logger.
     * @param IAppConfig      $appConfig     Reads the instance-wide `companion_agent_uuid`.
     * @param IConfig         $config        Reads the per-user `pref_default-agent` preference.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    public function __construct(
        IRequest $request,
        private readonly Engine $engine,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $appConfig,
        private readonly IConfig $config,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * SSE streaming chat endpoint.
     *
     * Emits text/event-stream with the contractual envelope. The method always
     * terminates with exit; — it bypasses the NC response pipeline because the
     * SSE framing must reach the wire before that pipeline would buffer it.
     * The declared `: Response` return type is a formality required by the
     * framework; cleanup before exit; is funnelled through emitAndExit() so DB
     * transactions never leak under PHP-FPM.
     *
     * CSRF protection is REQUIRED (no @NoCSRFRequired), exactly as in OR: the
     * endpoint is an authenticated POST that creates/updates Conversation +
     * Message objects, and the client invokes it via `fetch()` (POST with a
     * JSON body, requesttoken header attached), not EventSource.
     *
     * @return Response Never returned — emitAndExit() always terminates.
     *
     * @NoAdminRequired
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  stream() is the single SSE dispatch path: auth
     * check, body parse, agent/conversation resolution, heartbeat, channel wiring, and error handling
     * are sequential guards that cannot be split into sub-methods without leaking the exit-based flow
     * control across multiple layers.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple independent early-exit paths (unauthenticated,
     * missing message, missing agent, forbidden conversation, stream failure) must all terminate via
     * emitAndExit(); collapsing them would hide the distinct SSE error codes.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) The method coordinates the full SSE lifecycle in
     * one readable sequence; extracting sub-stages would split the exit-based control flow across
     * multiple methods and make the error-path analysis harder.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    public function stream(): Response
    {
        // Hard requirement: clear every output buffer layer (PHP, NC framework,
        // any plugin) so the first echo is flushed immediately. Extracted to
        // its own method so unit tests can override and skip — closing
        // PHPUnit's output buffer trips its "risky" detector.
        $this->clearOutputBuffers();

        $this->emitSseHeaders();

        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                $this->emitAndExit(
                    eventType: 'error',
                    payload: ['code' => 'unauthenticated', 'message' => 'Authentication required']
                );
            }

            $userId  = $user->getUID();
            $rawBody = $this->readRequestBody();
            if ($rawBody === '') {
                $rawBody = '[]';
            }

            $body = (json_decode($rawBody, associative: true) ?? []);

            $userMessage = trim((string) ($body['message'] ?? ''));
            if ($userMessage === '') {
                $this->emitAndExit(
                    eventType: 'error',
                    payload: ['code' => 'missing_message', 'message' => 'message content is required']
                );
            }

            // Resolve agent + conversation.
            $agentUuid        = (string) ($body['agentUuid'] ?? '');
            $conversationUuid = (string) ($body['conversationUuid'] ?? '');
            $context          = ($body['context'] ?? null);

            // Widget UX: when the widget opens a fresh chat it doesn't know which
            // agent to use (no agent picker in v1). Fall back to an agent the
            // CURRENT USER can access (owner / non-private / invited), never the
            // first agent in the register — that would cross tenant/user boundaries
            // in a multi-user deployment.
            if ($conversationUuid === '' && $agentUuid === '') {
                $agentUuid = $this->pickFallbackAgentForUser(userId: $userId);
            }

            if ($conversationUuid === '' && $agentUuid === '') {
                $this->emitAndExit(
                    eventType: 'error',
                    payload: [
                        'code'    => 'missing_agent',
                        'message' => 'No agent available; configure one in Hermiq settings.',
                    ]
                );
            }

            try {
                $conversation = $this->resolveConversation(
                    conversationUuid: $conversationUuid,
                    agentUuid: $agentUuid,
                    userId: $userId
                );
            } catch (RuntimeException $e) {
                if ($e->getMessage() === self::ERROR_FORBIDDEN) {
                    $this->emitAndExit(
                        eventType: 'error',
                        payload: ['code' => 'forbidden', 'message' => 'Forbidden']
                    );
                }

                throw $e;
            }

            // The CnAiContext snapshot is persisted on the user-authored Message
            // object Engine::processMessage will create. Reject anything other
            // than an associative array so a bad client payload doesn't break
            // the JSON encoding.
            $contextArr = [];
            if (is_array($context) === true) {
                $contextArr = $context;
            }

            // Emit a heartbeat right after headers so the client knows we're alive
            // (the synchronous LLM call may take >15s on cold-load).
            $this->emitSseEvent(eventType: 'heartbeat', payload: ['ts' => gmdate('c')]);
            $this->lastEventAt = $this->now();

            // Build the streaming channel and register four callbacks. Each
            // callback funnels through `forwardWithHeartbeat()` which checks
            // wall-clock since the last emit and interleaves a `heartbeat`
            // frame when more than 15s have elapsed (ADR-034 design D3). When
            // the active provider supports LLPhant streaming the handler invokes
            // the channel; otherwise the channel sits idle and the existing
            // initial heartbeat is the only one — that's the synchronous-
            // fallback degradation explicitly allowed by the SSE contract.
            $channel = new StreamYieldChannel();
            $channel->onToken(
                callback: fn (string $delta) => $this->forwardWithHeartbeat(
                    eventType: 'token',
                    payload: ['delta' => $delta]
                )
            );
            $channel->onToolCall(
                callback: fn (array $payload) => $this->forwardWithHeartbeat(
                    eventType: 'tool_call',
                    payload: $payload
                )
            );
            $channel->onToolResult(
                callback: fn (array $payload) => $this->forwardWithHeartbeat(
                    eventType: 'tool_result',
                    payload: $payload
                )
            );
            $channel->onHeartbeat(
                callback: fn () => $this->forwardWithHeartbeat(
                    eventType: 'heartbeat',
                    payload: ['ts' => gmdate('c')]
                )
            );

            $result = $this->engine->processMessage(
                conversationId: (string) $conversation->getUuid(),
                userId: $userId,
                userMessage: $userMessage,
                selectedViews: [],
                selectedTools: [],
                ragSettings: [],
                context: $contextArr,
                channel: $channel
            );

            // Emit final event with the full assistant text. The `response`/
            // `message` fallbacks are kept from the OR ground truth so a future
            // engine return-shape change can never silently break the wire
            // contract (hence the phpstan ignores on the shape-narrowed reads).
            $finalPayload = [
                // @phpstan-ignore-next-line -- deliberate defensive fallback, see above.
                'messageId'        => (string) ($result['messageId'] ?? ''),
                'conversationUuid' => (string) $conversation->getUuid(),
                // @phpstan-ignore-next-line -- deliberate defensive fallback, see above.
                'fullText'         => (string) ($result['response'] ?? $result['message'] ?? ''),
                'context'          => $context,
            ];
            $this->emitAndExit(eventType: 'final', payload: $finalPayload);
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[ChatStreamController] Stream failed',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e,
                    'error'     => $e->getMessage(),
                ]
            );
            $this->emitAndExit(
                eventType: 'error',
                payload: [
                    'code'    => 'stream_failed',
                    'message' => self::PUBLIC_ERROR_MESSAGE,
                ]
            );
        }//end try

        // Unreachable — emitAndExit() never returns. Present so the declared
        // `: Response` return type (required by the framework) is satisfied.
        // The SSE framing is already written to the wire by the time we get
        // anywhere near this line.
        // @phpstan-ignore-next-line deadCode.unreachable — deliberate, see above.
        return new Response();
    }//end stream()

    /**
     * Emit a single SSE frame, release any open DB transaction, then exit.
     *
     * Centralising every termination point through this helper ensures that
     * the connection-leak risk of bypassing NC's response pipeline ("exit;
     * under PHP-FPM") is mitigated: any in-flight transaction is finalised
     * before the process is torn down. The error path rolls back; only the
     * successful `final` path commits — this is what makes `final`/`error`
     * mutually exclusive terminals (exactly one per request).
     *
     * @param string               $eventType Event type. When 'error', any
     *                                        open transaction is rolled back
     *                                        instead of committed — partial
     *                                        writes from a failed
     *                                        Engine::processMessage() call
     *                                        must not be persisted.
     * @param array<string, mixed> $payload   JSON-encodable payload.
     *
     * @return never
     *
     * @SuppressWarnings(PHPMD.ExitExpression) SSE streaming contract requires process termination after
     * the final frame; exit cannot be replaced by a return because the calling flow in stream() must
     * stop unconditionally.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    protected function emitAndExit(string $eventType, array $payload): never
    {
        $this->emitSseEvent(eventType: $eventType, payload: $payload);
        $this->safeShutdown(rollback: $eventType === 'error');
        exit;
    }//end emitAndExit()

    /**
     * Finalise any open DB transaction so the connection can be released
     * cleanly when PHP shuts the process down after exit;.
     *
     * On the error path callers pass $rollback=true so partial writes left
     * behind by a failed service call are discarded. The success path
     * (the 'final' SSE frame) commits. Best-effort: swallow any exception so
     * cleanup never masks the user-visible SSE frame we just emitted.
     *
     * @param bool $rollback True to roll back, false to commit.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    private function safeShutdown(bool $rollback): void
    {
        try {
            if ($this->db->inTransaction() === true) {
                if ($rollback === true) {
                    $this->db->rollBack();
                    return;
                }

                $this->db->commit();
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[ChatStreamController] Shutdown finalisation failed',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'rollback' => $rollback,
                    'error'    => $e->getMessage(),
                ]
            );
        }
    }//end safeShutdown()

    /**
     * Emit a single SSE frame and flush.
     *
     * @param string               $eventType Event type (token / tool_call /
     *                                        tool_result / heartbeat / final /
     *                                        error).
     * @param array<string, mixed> $payload   JSON-encodable payload.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    protected function emitSseEvent(string $eventType, array $payload): void
    {
        echo 'event: '.$eventType."\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";
        flush();
    }//end emitSseEvent()

    /**
     * Current wall-clock seconds as a float. Production returns
     * `microtime(true)`; tests override to drive a fake clock.
     *
     * @return float Seconds since the Unix epoch.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    protected function now(): float
    {
        return microtime(true);
    }//end now()

    /**
     * Read the raw request body. Production reads `php://input`; tests
     * override to inject a JSON body (the stream is not seedable under
     * PHPUnit).
     *
     * @return string The raw request body ('' when unreadable).
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    protected function readRequestBody(): string
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) {
            return '';
        }

        return $rawBody;
    }//end readRequestBody()

    /**
     * Emit a payload as an SSE frame, interleaving a `heartbeat` frame
     * first whenever ≥15s have elapsed since the last emit. Both the
     * heartbeat (when emitted) and the incoming frame update
     * `$this->lastEventAt` so back-to-back frames within the same yield
     * cycle do not retrigger the interleave.
     *
     * The 15-second threshold is fixed per ADR-034 design D3. Pre-emit
     * interleaving (rather than post-emit scheduling) keeps the contract
     * exactly: "heartbeat appears immediately before the next outgoing
     * frame when the wall-clock interval since the last event exceeds
     * 15.0s".
     *
     * @param string               $eventType Frame type to emit after the
     *                                        optional heartbeat.
     * @param array<string, mixed> $payload   Frame payload.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    protected function forwardWithHeartbeat(string $eventType, array $payload): void
    {
        $now = $this->now();
        if (($now - $this->lastEventAt) >= 15.0) {
            $this->emitSseEvent(eventType: 'heartbeat', payload: ['ts' => gmdate('c')]);
            $this->lastEventAt = $now;
        }

        $this->emitSseEvent(eventType: $eventType, payload: $payload);
        $this->lastEventAt = $this->now();
    }//end forwardWithHeartbeat()

    /**
     * Clear every output buffer layer (PHP, NC framework, any plugin) so
     * the first SSE echo is flushed immediately. Extracted from stream()
     * so unit tests can override and skip — closing PHPUnit's output
     * buffer trips its "risky" detector.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    protected function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }//end clearOutputBuffers()

    /**
     * Emit the three SSE response headers. Extracted so tests can skip
     * (header() emits a "headers already sent" warning under PHPUnit
     * because the test runner has already written output).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    protected function emitSseHeaders(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
    }//end emitSseHeaders()

    /**
     * Resolve which agent the companion widget starts a conversation with.
     *
     * Three tiers, highest precedence first, each gated by the SAME access check so a
     * stored UUID is never treated as evidence of access:
     *   1. the user's own default agent (`pref_default-agent`, set in personal settings);
     *   2. the instance-wide companion agent (`companion_agent_uuid` app-config);
     *   3. the first agent the user can access (bounded scan) — non-private OR
     *      owned-by-user OR invited, the semantics OR's AgentMapper::canUserAccessAgent
     *      applies.
     * Falls back to '' when no accessible agent exists. NEVER returns "the first agent
     * regardless of owner": that caused cross-user data exposure in multi-user
     * deployments. An inaccessible or missing agent at any tier falls through to the
     * next rather than erroring the chat.
     *
     * @param string $userId Nextcloud user id.
     *
     * @return string The accessible agent uuid, or '' when none is found.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     * @spec openspec/changes/default-companion-agent/specs/default-companion-agent/spec.md#requirement-the-companion-agent-resolves-by-per-user-then-app-config-then-first-accessible-precedence
     */
    private function pickFallbackAgentForUser(string $userId): string
    {
        // Highest precedence: the user's OWN chosen default agent. A per-user choice
        // must win over the instance-wide companion agent — otherwise setting a personal
        // default would do nothing whenever an admin default is also configured. The
        // stored value is a preference, not an authorization: canUserAccessAgent()
        // re-checks it here, so a default the user can no longer access (revoked,
        // deleted) falls through to the tiers below rather than erroring the chat.
        $preferredUuid = trim(
            $this->config->getUserValue($userId, Application::APP_ID, self::DEFAULT_AGENT_PREF_KEY, '')
        );
        if ($preferredUuid !== '') {
            try {
                $preferredAgent = $this->objectService->find(
                    id: $preferredUuid,
                    register: self::REGISTER_SLUG,
                    schema: self::AGENT_SCHEMA
                );
                if (($preferredAgent instanceof ObjectEntity) === true
                    && $this->canUserAccessAgent(agent: $preferredAgent, userId: $userId) === true
                ) {
                    return (string) $preferredAgent->getUuid();
                }

                $this->logger->info(
                    message: '[ChatStreamController] User default agent is missing or inaccessible; falling through',
                    context: [
                        'file'      => __FILE__,
                        'line'      => __LINE__,
                        'agentUuid' => $preferredUuid,
                        'userId'    => $userId,
                    ]
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    message: '[ChatStreamController] User default agent lookup failed; falling through',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'error' => $e->getMessage(),
                    ]
                );
            }//end try
        }//end if

        // Next: the admin-configured companion agent when one is set AND the
        // current user may access it. This makes the widget deterministic —
        // otherwise the agent is whichever the register happens to return first,
        // which on a busy instance is arbitrary and can be a misconfigured test
        // agent (e.g. one whose model is not available on the active provider).
        $configuredUuid = trim($this->appConfig->getValueString(self::REGISTER_SLUG, self::COMPANION_AGENT_CONFIG_KEY, ''));
        if ($configuredUuid !== '') {
            try {
                $configuredAgent = $this->objectService->find(
                    id: $configuredUuid,
                    register: self::REGISTER_SLUG,
                    schema: self::AGENT_SCHEMA
                );
                if (($configuredAgent instanceof ObjectEntity) === true
                    && $this->canUserAccessAgent(agent: $configuredAgent, userId: $userId) === true
                ) {
                    return (string) $configuredAgent->getUuid();
                }

                // Configured but inaccessible/missing: log and fall through to
                // the access-scoped scan rather than failing the whole chat.
                $this->logger->warning(
                    message: '[ChatStreamController] Configured companion agent is missing or inaccessible; using access-scoped fallback',
                    context: [
                        'file'      => __FILE__,
                        'line'      => __LINE__,
                        'agentUuid' => $configuredUuid,
                        'userId'    => $userId,
                    ]
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    message: '[ChatStreamController] Configured companion agent lookup failed; using access-scoped fallback',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'error' => $e->getMessage(),
                    ]
                );
            }//end try
        }//end if

        try {
            // Cheap cap — we only need the first match. Twenty rows is
            // enough headroom for any realistic instance.
            $agents = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(self::AGENT_SCHEMA)
                ->findAll(config: ['limit' => 20]);
            foreach ($agents as $agent) {
                if (($agent instanceof ObjectEntity) === false) {
                    continue;
                }

                if ($this->canUserAccessAgent(agent: $agent, userId: $userId) === true) {
                    return (string) $agent->getUuid();
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[ChatStreamController] Agent fallback lookup failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
        }//end try

        return '';
    }//end pickFallbackAgentForUser()

    /**
     * Whether the user may use an agent: non-private agents are open to the
     * organisation (multitenancy already scoped the read), private agents
     * only to their owner or explicitly invited users — mirrors OR's
     * AgentMapper::canUserAccessAgent() against the hermiq `agent` payload.
     *
     * @param ObjectEntity $agent  Agent object.
     * @param string       $userId Nextcloud user id.
     *
     * @return bool True when the user may access the agent.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    private function canUserAccessAgent(ObjectEntity $agent, string $userId): bool
    {
        $data      = $agent->getObject();
        $isPrivate = ($data['isPrivate'] ?? null);

        // Non-private agents are accessible to all users in the organisation.
        if ($isPrivate === false || $isPrivate === null) {
            return true;
        }

        // Owner always has access.
        if ($agent->getOwner() === $userId) {
            return true;
        }

        // Check if user is invited.
        $invitedUsers = ($data['invitedUsers'] ?? []);
        if (is_array($invitedUsers) === true && in_array($userId, $invitedUsers, true) === true) {
            return true;
        }

        return false;
    }//end canUserAccessAgent()

    /**
     * Resolve (load or create) the conversation referenced by the request.
     *
     * Mirrors ChatController::resolveConversation but kept local so this
     * controller does not couple to the chat controller's private helpers.
     * When a conversationUuid is supplied, ownership is verified against the
     * calling user — preventing the IDOR where any authed user could
     * read/append to another user's thread by guessing the uuid (gate-7).
     *
     * @param string $conversationUuid Conversation UUID or '' to create new.
     * @param string $agentUuid        Agent UUID (required when creating new).
     * @param string $userId           Current user id.
     *
     * @return ObjectEntity Resolved conversation.
     *
     * @throws RuntimeException When the caller does not own the conversation
     *                          (message === self::ERROR_FORBIDDEN; translated
     *                          into the `forbidden` SSE error by stream()),
     *                          or when the conversation/agent does not exist
     *                          (translated into `stream_failed` by the outer
     *                          catch, matching OR's DoesNotExist behavior).
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    private function resolveConversation(string $conversationUuid, string $agentUuid, string $userId): ObjectEntity
    {
        if ($conversationUuid !== '') {
            $conversation = $this->objectService->find(
                id: $conversationUuid,
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA
            );
            if ($conversation === null) {
                throw new RuntimeException('Conversation not found: '.$conversationUuid);
            }

            // IDOR guard: find() does not scope by user, so we MUST verify the
            // conversation belongs to the caller. Without this check any authed
            // user could supply any conversationUuid and hijack another user's
            // thread.
            if (($conversation->getObject()['userId'] ?? null) !== $userId) {
                throw new RuntimeException(self::ERROR_FORBIDDEN);
            }

            return $conversation;
        }

        // Need an agent to create a new conversation.
        $agent = $this->objectService->find(
            id: $agentUuid,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA
        );
        if ($agent === null) {
            throw new RuntimeException('Agent not found: '.$agentUuid);
        }

        return $this->objectService->saveObject(
            object: $this->sanitizeForSave(
                data: [
                    'userId'  => $userId,
                    'agentId' => (string) $agent->getUuid(),
                    'title'   => 'New conversation',
                ]
            ),
            register: self::REGISTER_SLUG,
            schema: self::CONVERSATION_SCHEMA
        );
    }//end resolveConversation()
}//end class
