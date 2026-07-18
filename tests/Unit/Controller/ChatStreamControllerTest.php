<?php

/**
 * Unit tests for ChatStreamController (agent-engine-port, SSE envelope).
 *
 * Strategy (ported from OR's ChatStreamControllerTest): the controller emits
 * SSE frames via `echo` and terminates with `exit;` — both impossible to drive
 * under PHPUnit directly. We subclass it (TestableChatStreamController) and
 * override the protected `emitSseEvent` / `emitAndExit` / `readRequestBody`
 * helpers to capture frames in-memory, inject a JSON body, and throw a
 * sentinel exception instead of exiting. The `terminated` latch makes the
 * capture faithful to production: nothing after the first emitAndExit() frame
 * reaches the wire (production has exited), so the captured array IS the
 * envelope — letting the tests assert the ADR-034 invariants directly:
 * exactly one terminal `final` XOR `error`, error path emits `error` not
 * `final`, zero `token` frames on early exits, `tool_call` before
 * `tool_result` before `final`.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatStreamController;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sentinel exception thrown by TestableChatStreamController in place of
 * the production `exit;` so PHPUnit can continue.
 */
class ChatStreamControllerStopSignal extends RuntimeException
{
}//end class

/**
 * Subclass that captures SSE frames instead of writing them to stdout,
 * injects the request body, and throws a sentinel instead of calling exit;.
 * The `terminated` latch ignores frames after the first terminal — exactly
 * what production's exit; guarantees on the wire.
 */
class TestableChatStreamController extends ChatStreamController
{

    /**
     * Captured SSE frames in emit order.
     *
     * @var array<int, array{type: string, payload: array}>
     */
    public array $capturedEvents = [];

    /**
     * Injected JSON request body.
     *
     * @var string
     */
    public string $requestBody = '';

    /**
     * Whether a terminal frame has been emitted (production exited).
     *
     * @var bool
     */
    private bool $terminated = false;

    /**
     * Capture the frame unless production would already have exited.
     *
     * @param string $eventType Event type.
     * @param array  $payload   Frame payload.
     *
     * @return void
     */
    protected function emitSseEvent(string $eventType, array $payload): void
    {
        if ($this->terminated === true) {
            return;
        }

        $this->capturedEvents[] = ['type' => $eventType, 'payload' => $payload];

    }//end emitSseEvent()

    /**
     * Capture the terminal frame once, then raise the stop signal.
     *
     * @param string $eventType Event type.
     * @param array  $payload   Frame payload.
     *
     * @return never
     */
    protected function emitAndExit(string $eventType, array $payload): never
    {
        if ($this->terminated === false) {
            $this->capturedEvents[] = ['type' => $eventType, 'payload' => $payload];
            $this->terminated       = true;
        }

        throw new ChatStreamControllerStopSignal("emitAndExit({$eventType})");

    }//end emitAndExit()

    /**
     * Inject the request body (php://input is not seedable under PHPUnit).
     *
     * @return string The injected body.
     */
    protected function readRequestBody(): string
    {
        return $this->requestBody;

    }//end readRequestBody()

    /**
     * Skip — closing PHPUnit's output buffer would trip its risky detector.
     *
     * @return void
     */
    protected function clearOutputBuffers(): void
    {
    }//end clearOutputBuffers()

    /**
     * Skip — header() under PHPUnit warns "headers already sent".
     *
     * @return void
     */
    protected function emitSseHeaders(): void
    {
    }//end emitSseHeaders()
}//end class

/**
 * Tests for the agent-engine-port ChatStreamController SSE envelope.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
 */
class ChatStreamControllerTest extends TestCase
{

    /**
     * Mock engine facade.
     *
     * @var Engine&MockObject
     */
    private Engine $engine;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Wire fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->engine        = $this->createMock(Engine::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->userSession = $this->createMock(IUserSession::class);

    }//end setUp()

    /**
     * Authenticate the session as the given user.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function authenticate(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end authenticate()

    /**
     * Build the testable controller wired to the current mocks.
     *
     * @param string $body The JSON request body to inject.
     *
     * @return TestableChatStreamController
     */
    private function makeController(string $body='', string $companionAgentUuid=''): TestableChatStreamController
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') use ($companionAgentUuid): string {
                if ($key === 'companion_agent_uuid') {
                    return $companionAgentUuid;
                }

                return $default;
            }
        );

        $controller = new TestableChatStreamController(
            $this->createMock(IRequest::class),
            $this->engine,
            $this->objectService,
            $this->userSession,
            $this->createMock(IDBConnection::class),
            $this->createMock(LoggerInterface::class),
            $appConfig
        );
        $controller->requestBody = $body;
        return $controller;

    }//end makeController()

    /**
     * The configured companion agent is preferred over the register-order
     * fallback when the current user may access it.
     *
     * @return void
     */
    public function testConfiguredCompanionAgentIsPreferred(): void
    {
        $this->authenticate('alice');

        $configured = $this->createMock(ObjectEntity::class);
        $configured->method('getUuid')->willReturn('configured-agent-uuid');
        // Non-private agent → org-accessible → canUserAccessAgent() true.
        $configured->method('getObject')->willReturn(['isPrivate' => false]);

        // find() resolves the configured agent. (ObjectService::find has more
        // parameters than the three we pass by name, so we assert the call
        // happened rather than pin an exact positional signature.)
        $this->objectService->expects($this->once())
            ->method('find')
            ->willReturn($configured);
        // The register-order scan MUST NOT run when the configured agent resolves.
        $this->objectService->expects($this->never())->method('findAll');

        $controller = $this->makeController(companionAgentUuid: 'configured-agent-uuid');

        $method = new \ReflectionMethod($controller, 'pickFallbackAgentForUser');
        $method->setAccessible(true);
        $this->assertSame('configured-agent-uuid', $method->invoke($controller, 'alice'));

    }//end testConfiguredCompanionAgentIsPreferred()

    /**
     * When no companion agent is configured, the controller falls back to the
     * first agent in register order the user can access (pre-existing behaviour,
     * unchanged).
     *
     * @return void
     */
    public function testFallsBackToFirstAccessibleAgentWhenUnconfigured(): void
    {
        $this->authenticate('alice');

        $first = $this->createMock(ObjectEntity::class);
        $first->method('getUuid')->willReturn('first-accessible-uuid');
        $first->method('getObject')->willReturn(['isPrivate' => false]);

        // No configured agent → find() is never called for a companion uuid;
        // the register-order scan runs and returns the first accessible agent.
        $this->objectService->expects($this->never())->method('find');
        $this->objectService->expects($this->once())->method('findAll')->willReturn([$first]);

        $controller = $this->makeController();

        $method = new \ReflectionMethod($controller, 'pickFallbackAgentForUser');
        $method->setAccessible(true);
        $this->assertSame('first-accessible-uuid', $method->invoke($controller, 'alice'));

    }//end testFallsBackToFirstAccessibleAgentWhenUnconfigured()

    /**
     * Drive stream() and absorb the sentinel stop signal.
     *
     * @param TestableChatStreamController $controller The controller under test.
     *
     * @return void
     */
    private function runStream(TestableChatStreamController $controller): void
    {
        try {
            $controller->stream();
        } catch (ChatStreamControllerStopSignal) {
            // Expected — production code calls exit; here we capture.
        }

    }//end runStream()

    /**
     * Frames of one type, in order.
     *
     * @param TestableChatStreamController $controller The controller under test.
     * @param string                       $type       The frame type to filter.
     *
     * @return array<int, array{type: string, payload: array}>
     */
    private function frames(TestableChatStreamController $controller, string $type): array
    {
        return array_values(
            array_filter(
                $controller->capturedEvents,
                static fn (array $e): bool => $e['type'] === $type
            )
        );

    }//end frames()

    /**
     * An unauthenticated call emits a single terminal `error` (code
     * unauthenticated) — zero `final`, zero `token` — and never reaches the
     * engine.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    public function testUnauthenticatedEmitsSingleTerminalError(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->engine->expects($this->never())->method('processMessage');

        $controller = $this->makeController();
        $this->runStream($controller);

        $this->assertCount(1, $controller->capturedEvents);
        $this->assertSame('error', $controller->capturedEvents[0]['type']);
        $this->assertSame('unauthenticated', $controller->capturedEvents[0]['payload']['code']);
        $this->assertCount(0, $this->frames($controller, 'final'));
        $this->assertCount(0, $this->frames($controller, 'token'));

    }//end testUnauthenticatedEmitsSingleTerminalError()

    /**
     * A missing message emits a single terminal `error` (code missing_message)
     * and never invokes the engine.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    public function testMissingMessageEmitsSingleTerminalError(): void
    {
        $this->authenticate('alice');
        $this->engine->expects($this->never())->method('processMessage');

        $controller = $this->makeController('{}');
        $this->runStream($controller);

        $this->assertCount(1, $controller->capturedEvents);
        $this->assertSame('error', $controller->capturedEvents[0]['type']);
        $this->assertSame('missing_message', $controller->capturedEvents[0]['payload']['code']);
        $this->assertCount(0, $this->frames($controller, 'final'));

    }//end testMissingMessageEmitsSingleTerminalError()

    /**
     * Gate-7 IDOR guard: streaming into another user's conversation emits the
     * `forbidden` error terminal and never invokes the engine.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    public function testForeignConversationEmitsForbidden(): void
    {
        $this->authenticate('alice');
        $this->objectService->method('find')->willReturnCallback(
            function (): ObjectEntity {
                $conversation = new ObjectEntity();
                $conversation->setUuid('conv-bob');
                $conversation->setObject(['userId' => 'bob', 'agentId' => 'agent-1']);
                return $conversation;
            }
        );
        $this->engine->expects($this->never())->method('processMessage');

        $controller = $this->makeController('{"message":"hi","conversationUuid":"conv-bob"}');
        $this->runStream($controller);

        $errors = $this->frames($controller, 'error');
        $this->assertCount(1, $errors);
        $this->assertSame('forbidden', $errors[0]['payload']['code']);
        $this->assertCount(0, $this->frames($controller, 'final'));

    }//end testForeignConversationEmitsForbidden()

    /**
     * A successful synchronous turn (non-streaming provider degradation)
     * emits zero `token` frames plus exactly one terminal `final` carrying
     * the full text — and zero `error` frames.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    public function testSuccessfulTurnEmitsExactlyOneFinal(): void
    {
        $this->authenticate('alice');
        $this->objectService->method('find')->willReturnCallback(
            function (): ObjectEntity {
                $conversation = new ObjectEntity();
                $conversation->setUuid('conv-1');
                $conversation->setObject(['userId' => 'alice', 'agentId' => 'agent-1']);
                return $conversation;
            }
        );
        $this->engine->method('processMessage')->willReturn(
            [
                'message'   => 'hello from the engine',
                'messageId' => 'msg-42',
                'sources'   => [],
                'timings'   => [],
                'usage'     => [],
            ]
        );

        $controller = $this->makeController('{"message":"hi","conversationUuid":"conv-1"}');
        $this->runStream($controller);

        $finals = $this->frames($controller, 'final');
        $this->assertCount(1, $finals, 'Exactly one final frame per successful request.');
        $this->assertCount(0, $this->frames($controller, 'error'), 'A successful turn must emit zero error frames.');
        $this->assertCount(0, $this->frames($controller, 'token'), 'Synchronous degradation emits zero token frames.');
        $this->assertSame('hello from the engine', $finals[0]['payload']['fullText']);
        $this->assertSame('msg-42', $finals[0]['payload']['messageId']);
        $this->assertSame('conv-1', $finals[0]['payload']['conversationUuid']);

        // The final frame is the LAST frame on the wire.
        $last = end($controller->capturedEvents);
        $this->assertSame('final', $last['type']);

    }//end testSuccessfulTurnEmitsExactlyOneFinal()

    /**
     * hermiq-chat-attachments: an `attachments` array in the JSON body reaches
     * `Engine::processMessage()` as the named `attachments` argument, and the
     * turn still completes/streams normally.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-both-chat-endpoints-accept-an-attachment-reference-in-their-json-body
     */
    public function testAttachmentsInTheJsonBodyReachTheEngine(): void
    {
        $this->authenticate('alice');
        $this->objectService->method('find')->willReturnCallback(
            function (): ObjectEntity {
                $conversation = new ObjectEntity();
                $conversation->setUuid('conv-1');
                $conversation->setObject(['userId' => 'alice', 'agentId' => 'agent-1']);
                return $conversation;
            }
        );

        // `attachments` is Engine::processMessage()'s LAST parameter (appended
        // after $channel/$trace/$dryRun to keep every pre-existing positional
        // test double of this method unaffected) — a variadic capture stays
        // correct regardless of how many named args a given call site
        // supplies; `end($args)` is always the final resolved parameter.
        $seenAttachments = null;
        $this->engine->method('processMessage')->willReturnCallback(
            function (...$args) use (&$seenAttachments): array {
                $seenAttachments = end($args);
                return ['message' => 'ok', 'messageId' => 'msg-1', 'sources' => [], 'timings' => [], 'usage' => []];
            }
        );

        $body = json_encode(
            [
                'message'          => 'What does the report say?',
                'conversationUuid' => 'conv-1',
                'attachments'      => [['path' => 'Hermiq/Attachments/report.txt', 'name' => 'report.txt']],
            ]
        );
        $controller = $this->makeController((string) $body);
        $this->runStream($controller);

        $this->assertSame(
            [['path' => 'Hermiq/Attachments/report.txt', 'name' => 'report.txt']],
            $seenAttachments
        );
        $this->assertCount(1, $this->frames($controller, 'final'));

    }//end testAttachmentsInTheJsonBodyReachTheEngine()

    /**
     * Omitting `attachments` from the JSON body defaults to `[]` — byte-for-byte
     * unchanged behaviour for every request predating this change.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-both-chat-endpoints-accept-an-attachment-reference-in-their-json-body
     */
    public function testOmittedAttachmentsDefaultToEmptyArray(): void
    {
        $this->authenticate('alice');
        $this->objectService->method('find')->willReturnCallback(
            function (): ObjectEntity {
                $conversation = new ObjectEntity();
                $conversation->setUuid('conv-1');
                $conversation->setObject(['userId' => 'alice', 'agentId' => 'agent-1']);
                return $conversation;
            }
        );

        $seenAttachments = 'unset';
        $this->engine->method('processMessage')->willReturnCallback(
            function (...$args) use (&$seenAttachments): array {
                $seenAttachments = end($args);
                return ['message' => 'ok', 'messageId' => 'msg-1', 'sources' => [], 'timings' => [], 'usage' => []];
            }
        );

        $controller = $this->makeController('{"message":"hi","conversationUuid":"conv-1"}');
        $this->runStream($controller);

        $this->assertSame([], $seenAttachments);

    }//end testOmittedAttachmentsDefaultToEmptyArray()

    /**
     * A failed turn (engine throws) emits exactly one terminal `error`
     * (stream_failed) and zero `final` frames — and the wire message is the
     * generic public string, never the exception text.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    public function testFailedTurnEmitsSingleTerminalErrorNotFinal(): void
    {
        $this->authenticate('alice');
        $this->objectService->method('find')->willReturnCallback(
            function (): ObjectEntity {
                $conversation = new ObjectEntity();
                $conversation->setUuid('conv-1');
                $conversation->setObject(['userId' => 'alice', 'agentId' => 'agent-1']);
                return $conversation;
            }
        );
        $this->engine->method('processMessage')->willThrowException(
            new RuntimeException('LLM exploded: apiKey=sk-SECRET')
        );

        $controller = $this->makeController('{"message":"hi","conversationUuid":"conv-1"}');
        $this->runStream($controller);

        $errors = $this->frames($controller, 'error');
        $this->assertCount(1, $errors, 'Exactly one error frame per failed request.');
        $this->assertCount(0, $this->frames($controller, 'final'), 'A failed turn must emit zero final frames.');
        $this->assertSame('stream_failed', $errors[0]['payload']['code']);
        $this->assertSame('An internal error occurred.', $errors[0]['payload']['message']);
        $this->assertStringNotContainsString('sk-SECRET', json_encode($controller->capturedEvents));

    }//end testFailedTurnEmitsSingleTerminalErrorNotFinal()

    /**
     * Channel-driven tool activity: every `tool_call` is followed by its
     * `tool_result` before the single `final` frame (ADR-034 ordering
     * invariant).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
     */
    public function testToolCallThenToolResultPrecedeFinal(): void
    {
        $this->authenticate('alice');
        $this->objectService->method('find')->willReturnCallback(
            function (): ObjectEntity {
                $conversation = new ObjectEntity();
                $conversation->setUuid('conv-1');
                $conversation->setObject(['userId' => 'alice', 'agentId' => 'agent-1']);
                return $conversation;
            }
        );

        // The engine drives the channel mid-turn, then returns the result.
        $this->engine->method('processMessage')->willReturnCallback(
            function (
                string $conversationId,
                string $userId,
                string $userMessage,
                array $selectedViews=[],
                array $selectedTools=[],
                array $ragSettings=[],
                array $context=[],
                ?StreamYieldChannel $channel=null
            ): array {
                $channel?->emitToolCall(['toolId' => 'decidesk.listMeetings', 'arguments' => []]);
                $channel?->emitToolResult(['toolId' => 'decidesk.listMeetings', 'result' => ['ok' => true]]);
                return [
                    'message'   => 'used a tool',
                    'messageId' => 'msg-7',
                    'sources'   => [],
                    'timings'   => [],
                    'usage'     => [],
                ];
            }
        );

        $controller = $this->makeController('{"message":"hi","conversationUuid":"conv-1"}');
        $this->runStream($controller);

        $types = array_column($controller->capturedEvents, 'type');

        $this->assertCount(1, $this->frames($controller, 'final'));
        $this->assertCount(0, $this->frames($controller, 'error'));

        $callPos   = array_search('tool_call', $types, true);
        $resultPos = array_search('tool_result', $types, true);
        $finalPos  = array_search('final', $types, true);
        $this->assertNotFalse($callPos, 'The tool_call frame must be forwarded.');
        $this->assertNotFalse($resultPos, 'The tool_result frame must be forwarded.');
        $this->assertGreaterThan($callPos, $resultPos, 'tool_result must follow its tool_call.');
        $this->assertGreaterThan($resultPos, $finalPos, 'final must follow the tool_result.');

    }//end testToolCallThenToolResultPrecedeFinal()
}//end class
