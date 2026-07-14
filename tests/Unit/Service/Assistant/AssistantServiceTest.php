<?php

/**
 * Unit tests for AssistantService (case-assistant-surface).
 *
 * Exercises turn orchestration (new/existing session, ownership guard),
 * validation (400s), guardrail blocking, and — most importantly — pins the
 * "zero tool execution" guarantee directly against `ToolLoop::
 * listAgentFunctions()` so a future change to its whitelist semantics fails
 * loudly here rather than silently re-opening the surface (design.md
 * Decision 1).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-assistant-surface/tasks.md#task-3-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Assistant;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Assistant\AssistantService;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for AssistantService.
 *
 * @spec openspec/changes/case-assistant-surface/tasks.md#task-3-1
 */
class AssistantServiceTest extends TestCase
{
    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * Mock MessageHistoryHandler.
     *
     * @var MessageHistoryHandler&MockObject
     */
    private MessageHistoryHandler $historyHandler;

    /**
     * Mock ResponseGenerationHandler.
     *
     * @var ResponseGenerationHandler&MockObject
     */
    private ResponseGenerationHandler $responseHandler;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Wire fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->historyHandler  = $this->createMock(MessageHistoryHandler::class);
        $this->responseHandler = $this->createMock(ResponseGenerationHandler::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the service wired to the current mocks.
     *
     * @param GuardrailPolicyService|null $guardrailPolicyService Optional guardrail service.
     *
     * @return AssistantService
     */
    private function service(?GuardrailPolicyService $guardrailPolicyService=null): AssistantService
    {
        return new AssistantService(
            $this->objectService,
            $this->historyHandler,
            $this->responseHandler,
            $this->logger,
            $guardrailPolicyService
        );
    }//end service()

    /**
     * Build an ObjectEntity fixture.
     *
     * @param string              $uuid    The object UUID.
     * @param array<string,mixed> $payload The object payload.
     *
     * @return ObjectEntity
     */
    private function entity(string $uuid, array $payload): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($payload);
        return $entity;
    }//end entity()

    /**
     * An empty message is rejected before any collaborator is touched.
     *
     * @return void
     */
    public function testEmptyMessageIsRejected(): void
    {
        $this->objectService->expects($this->never())->method('find');

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(400);

        $this->service()->converse(userId: 'alice', sessionId: null, message: '  ', context: ['app' => 'procest']);
    }//end testEmptyMessageIsRejected()

    /**
     * A message over the length cap is rejected.
     *
     * @return void
     */
    public function testOversizedMessageIsRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(400);

        $this->service()->converse(
            userId: 'alice',
            sessionId: null,
            message: str_repeat('a', 8001),
            context: ['app' => 'procest']
        );
    }//end testOversizedMessageIsRejected()

    /**
     * Missing context.app is rejected.
     *
     * @return void
     */
    public function testMissingContextAppIsRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(400);

        $this->service()->converse(userId: 'alice', sessionId: null, message: 'hello', context: []);
    }//end testMissingContextAppIsRejected()

    /**
     * Oversized contextData is rejected.
     *
     * @return void
     */
    public function testOversizedContextDataIsRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(400);

        $this->service()->converse(
            userId: 'alice',
            sessionId: null,
            message: 'hello',
            context: ['app' => 'procest', 'contextData' => str_repeat('a', 20001)]
        );
    }//end testOversizedContextDataIsRejected()

    /**
     * Requesting an unknown sessionId returns 404 and never calls the LLM.
     *
     * @return void
     */
    public function testUnknownSessionReturns404(): void
    {
        $this->objectService->method('find')->willReturn(null);
        $this->responseHandler->expects($this->never())->method('generateResponse');

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(404);

        $this->service()->converse(
            userId: 'alice',
            sessionId: 'missing-uuid',
            message: 'hello',
            context: ['app' => 'procest']
        );
    }//end testUnknownSessionReturns404()

    /**
     * A sessionId owned by another user returns 403 and never calls the LLM.
     *
     * @return void
     */
    public function testForeignSessionReturns403(): void
    {
        $conversation = $this->entity('conv-1', ['userId' => 'bob', 'agentId' => 'agent-1']);
        $this->objectService->method('find')->willReturn($conversation);
        $this->responseHandler->expects($this->never())->method('generateResponse');

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(403);

        $this->service()->converse(
            userId: 'alice',
            sessionId: 'conv-1',
            message: 'hello',
            context: ['app' => 'procest']
        );
    }//end testForeignSessionReturns403()

    /**
     * A blocked input never reaches the LLM or persists an assistant message.
     *
     * @return void
     */
    public function testGuardrailBlockedInputNeverCallsLlm(): void
    {
        $conversation = $this->entity('conv-1', ['userId' => 'alice', 'agentId' => 'agent-1']);
        $this->objectService->method('find')->willReturn($conversation);

        $guardrail = $this->createMock(GuardrailPolicyService::class);
        $guardrail->method('effectivePolicyFor')->willReturn([]);
        $guardrail->method('filterInput')->willReturn([
            'text'    => 'hello',
            'blocked' => true,
            'reason'  => 'prompt_injection',
        ]);

        $this->responseHandler->expects($this->never())->method('generateResponse');
        $this->historyHandler->expects($this->never())->method('storeMessage');

        $this->expectException(GuardrailBlockedException::class);

        $this->service($guardrail)->converse(
            userId: 'alice',
            sessionId: 'conv-1',
            message: 'hello',
            context: ['app' => 'procest']
        );
    }//end testGuardrailBlockedInputNeverCallsLlm()

    /**
     * Happy path against an existing session: stores both turns, calls the
     * response handler with no tools, and returns the expected envelope.
     *
     * @return void
     */
    public function testHappyPathReturnsEnvelope(): void
    {
        $conversation = $this->entity('conv-1', ['userId' => 'alice', 'agentId' => 'agent-1']);
        $agent        = $this->entity('agent-1', ['name' => 'Case Assistant (procest)', 'tools' => ['__none__']]);

        $this->objectService->method('find')->willReturnCallback(
            static function (string $id) use ($conversation, $agent): ?ObjectEntity {
                return match ($id) {
                    'conv-1'  => $conversation,
                    'agent-1' => $agent,
                    default   => null,
                };
            }
        );

        $this->historyHandler->method('buildMessageHistory')->willReturn([]);
        $this->historyHandler->expects($this->exactly(2))->method('storeMessage');

        $this->responseHandler->method('generateResponse')->with(
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->equalTo([])
        )->willReturn('The case is currently in review.');
        $this->responseHandler->lastUsage = ['promptTokens' => 10, 'completionTokens' => 5];

        $result = $this->service()->converse(
            userId: 'alice',
            sessionId: 'conv-1',
            message: 'What is the status of this case?',
            context: ['app' => 'procest', 'objectType' => 'case', 'contextData' => ['status' => 'in review']]
        );

        $this->assertSame('conv-1', $result['sessionId']);
        $this->assertSame('The case is currently in review.', $result['reply']);
        $this->assertSame(['promptTokens' => 10, 'completionTokens' => 5], $result['usage']);
    }//end testHappyPathReturnsEnvelope()

    /**
     * ToolLoop-pinned guarantee: an agent provisioned with the
     * case-assistant-surface's `['__none__']` sentinel resolves to ZERO
     * functions, regardless of what the caller passes as `selectedTools` —
     * this is the mechanism `AssistantService::findOrCreateAgent()` relies on
     * to guarantee no tool execution is possible (design.md Decision 1).
     *
     * @return void
     */
    public function testNoneSentinelAgentResolvesZeroToolsRegardlessOfSelection(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        // The facade is only ever asked for the __none__ sentinel (plus its
        // legacy-expanded 'openregister.__none__' form) — a concrete,
        // non-empty whitelist that matches no real tool id, so it always
        // resolves to []. It must NEVER be asked for the full catalog (an
        // EMPTY whitelist) — that only happens on the "empty = allow all"
        // fail-open path this sentinel exists to avoid; if that path is ever
        // hit, return a non-empty result so the assertions below fail loudly.
        $facade->method('listTools')->willReturnCallback(
            static function (array $toolWhitelist): array {
                $onlySentinel = array_filter(
                    $toolWhitelist,
                    static fn (string $id): bool => in_array($id, ['__none__', 'openregister.__none__'], true) === false
                );

                if ($toolWhitelist !== [] && $onlySentinel === []) {
                    return [];
                }

                return [['name' => 'unexpected.tool']];
            }
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturn(30);

        $loop = new ToolLoop(
            $facade,
            new NullLogger(),
            new ToolGrantResolver(),
            new ToolSearchService(),
            $this->createMock(ApprovalService::class),
            $appConfig
        );

        $agent = $this->entity('agent-1', ['name' => 'Case Assistant (procest)', 'tools' => ['__none__']]);

        $this->assertSame([], $loop->listAgentFunctions(agent: $agent, selectedTools: []));
        $this->assertSame(
            [],
            $loop->listAgentFunctions(agent: $agent, selectedTools: ['some.other.tool']),
            'A caller-supplied selectedTools MUST NOT resurrect any function on a __none__-locked agent.'
        );
    }//end testNoneSentinelAgentResolvesZeroToolsRegardlessOfSelection()
}//end class
