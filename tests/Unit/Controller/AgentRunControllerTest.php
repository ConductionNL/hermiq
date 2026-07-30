<?php

/**
 * Unit tests for AgentRunController::runOnObject (agent-object-leaf).
 *
 * Exercises the scoped run-on-object endpoint's contract without a live
 * Nextcloud/OpenRegister:
 *   - 400 when a required body field (register/schema/objectId) is missing;
 *   - 404 when the object is not readable in the caller's RBAC scope (fail-closed,
 *     object-permission-scoped — NOT admin-gated);
 *   - 404 when the named agent cannot be resolved;
 *   - 202 + a correlation id on success, dispatching the GOVERNED
 *     AgentRunRequestedEvent (mode async, flowName run-on-object) rather than
 *     calling any run service directly;
 *   - the approval-downgrade guard: a request-body approval field MUST NOT
 *     override the agent policy's requiresApproval.
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
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-scoped-run-on-object-endpoint
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentRunController;
use OCA\Hermiq\Service\Agent\AgentContextBuilder;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for AgentRunController.
 *
 * @spec openspec/changes/hermiq-agent-leaf/tasks.md#1-scoped-run-on-object-endpoint
 */
class AgentRunControllerTest extends TestCase
{

    /**
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * @var AgentMapper&MockObject
     */
    private AgentMapper $agentMapper;

    /**
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper $schemaMapper;

    /**
     * @var IEventDispatcher&MockObject
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * The last event dispatched via dispatchTyped(), captured for assertions.
     *
     * @var Event|null
     */
    private ?Event $dispatched = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request         = $this->createMock(IRequest::class);
        $this->objectService   = $this->createMock(ObjectService::class);
        $this->agentMapper     = $this->createMock(AgentMapper::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);

        $this->dispatched = null;
        $this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
            function (Event $event): void {
                $this->dispatched = $event;
            }
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);
    }//end setUp()

    /**
     * Build the controller wired to the current mocks.
     *
     * @return AgentRunController
     */
    private function controller(): AgentRunController
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

        return new AgentRunController(
            $this->request,
            $this->objectService,
            $this->agentMapper,
            $this->schemaMapper,
            new AgentContextBuilder(),
            $this->eventDispatcher,
            $this->userSession,
            $l10n,
            $this->createMock(LoggerInterface::class)
        );
    }//end controller()

    /**
     * Stub request params via getParam(key, default).
     *
     * @param array<string,mixed> $params The parameter map.
     *
     * @return void
     */
    private function stubParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default=null) use ($params): mixed {
                return ($params[$key] ?? $default);
            }
        );
    }//end stubParams()

    /**
     * A readable ObjectEntity with a uuid.
     *
     * @return ObjectEntity
     */
    private function readableObject(): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('object-uuid-1');
        $object->setObject(['title' => 'Permit', 'bsn' => '123456789']);
        return $object;
    }//end readableObject()

    /**
     * Missing required body fields yield 400 and dispatch nothing.
     *
     * @return void
     */
    public function testMissingRequiredFieldReturns400(): void
    {
        $this->stubParams(['register' => 'reg', 'schema' => 'sch']);
        // objectId absent.

        $response = $this->controller()->runOnObject('agent-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertNull($this->dispatched);
    }//end testMissingRequiredFieldReturns400()

    /**
     * An object the caller cannot read yields 404 (fail-closed) and dispatches nothing.
     *
     * @return void
     */
    public function testUnreadableObjectReturns404(): void
    {
        $this->stubParams(['register' => 'reg', 'schema' => 'sch', 'objectId' => 'obj-1']);
        $this->objectService->method('find')->willReturn(null);

        $response = $this->controller()->runOnObject('agent-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertNull($this->dispatched);
    }//end testUnreadableObjectReturns404()

    /**
     * A permission exception during resolution is treated as 404 (never leaks existence).
     *
     * @return void
     */
    public function testObjectResolutionThrowReturns404(): void
    {
        $this->stubParams(['register' => 'reg', 'schema' => 'sch', 'objectId' => 'obj-1']);
        $this->objectService->method('find')->willThrowException(new RuntimeException('forbidden'));

        $response = $this->controller()->runOnObject('agent-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertNull($this->dispatched);
    }//end testObjectResolutionThrowReturns404()

    /**
     * An unknown agent yields 404 and dispatches nothing.
     *
     * @return void
     */
    public function testUnknownAgentReturns404(): void
    {
        $this->stubParams(['register' => 'reg', 'schema' => 'sch', 'objectId' => 'obj-1']);
        $this->objectService->method('find')->willReturn($this->readableObject());
        $this->agentMapper->method('findByUuid')->willThrowException(new RuntimeException('not found'));

        $response = $this->controller()->runOnObject('missing-agent');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertNull($this->dispatched);
    }//end testUnknownAgentReturns404()

    /**
     * The happy path returns 202 with a correlation id and dispatches the governed event.
     *
     * @return void
     */
    public function testHappyPathDispatchesGovernedEvent(): void
    {
        $this->stubParams(['register' => 'reg', 'schema' => 'sch', 'objectId' => 'obj-1']);
        $this->objectService->method('find')->willReturn($this->readableObject());

        $agent = new Agent();
        $agent->setUuid('agent-1');
        $this->agentMapper->method('findByUuid')->willReturn($agent);

        // Mock the DECLARED getConfiguration() accessor instead of round-tripping
        // through setConfiguration(): the real OpenRegister Schema validates set
        // keys against its ANNOTATION_VOCABULARY and current OR releases silently
        // DROP `x-openregister-agent-context` there (upstream gap, reported to
        // openregister) — the controller contract under test only needs the
        // configuration READ to contain the allowlist.
        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')->willReturn(['x-openregister-agent-context' => ['title']]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $response = $this->controller()->runOnObject('agent-1');

        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

        $data = $response->getData();
        $this->assertSame('accepted', $data['status']);
        $this->assertSame('async', $data['mode']);
        $this->assertNotEmpty($data['correlationId']);

        $this->assertInstanceOf(AgentRunRequestedEvent::class, $this->dispatched);
        /** @var AgentRunRequestedEvent $event */
        $event = $this->dispatched;
        $this->assertSame('async', $event->getMode());
        $this->assertSame('run-on-object', $event->getFlowName());
        $this->assertSame('object-uuid-1', $event->getSubjectUuid());
        $this->assertSame('agent-1', $event->getAgent());
        // The prompt is grounded on ONLY the allowlisted field (title), never bsn.
        $this->assertStringContainsString('Permit', $event->getPrompt());
        $this->assertStringNotContainsString('123456789', $event->getPrompt());
    }//end testHappyPathDispatchesGovernedEvent()

    /**
     * The approval requirement comes from the AGENT policy; a request-body value cannot downgrade it.
     *
     * @return void
     */
    public function testApprovalCannotBeDowngradedByBody(): void
    {
        $this->stubParams(
            [
                'register' => 'reg',
                'schema'   => 'sch',
                'objectId' => 'obj-1',
                // A malicious caller trying to bypass the approval gate.
                'requiresApproval' => false,
            ]
        );
        $this->objectService->method('find')->willReturn($this->readableObject());

        $agent = new Agent();
        $agent->setUuid('agent-1');
        // Agent policy REQUIRES approval.
        $agent->setConfiguration(['requiresApproval' => true]);
        $this->agentMapper->method('findByUuid')->willReturn($agent);
        $this->schemaMapper->method('find')->willReturn(new Schema());

        $this->controller()->runOnObject('agent-1');

        $this->assertInstanceOf(AgentRunRequestedEvent::class, $this->dispatched);
        /** @var AgentRunRequestedEvent $event */
        $event = $this->dispatched;
        $this->assertTrue($event->isRequiresApproval(), 'agent policy approval must win over the request body');
    }//end testApprovalCannotBeDowngradedByBody()
}//end class
