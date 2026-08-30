<?php

/**
 * Unit tests for WebhookTriggerController (agent-webhook-trigger).
 *
 * Exercises the public, secret-authenticated trigger endpoint without a live
 * Nextcloud/OpenRegister:
 *   - a correct secret is accepted (202 + correlationId) and enqueues
 *     WebhookAgentRunJob with the expected context;
 *   - a wrong secret / disabled webhook / unknown agent id ALL produce the
 *     byte-identical 401 response body (enumeration safety);
 *   - an oversized body is rejected (413) both via an honest Content-Length
 *     header (before ANY read) and via the actual byte count when
 *     Content-Length is absent/understated — no job is enqueued either way;
 *   - a non-JSON body is rejected (400) only AFTER the secret has verified.
 *
 * `readRawBody()` is overridden via an anonymous subclass (mirrors pipelinq's
 * `BlastWebhookControllerTest`) since `php://input` cannot be repopulated
 * per-test in a CLI PHPUnit run.
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
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-3-webhooktriggercontroller-public-secret-authenticated-enumeration-safe
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\WebhookTriggerController;
use OCA\Hermiq\BackgroundJob\WebhookAgentRunJob;
use OCA\Hermiq\Service\WebhookSecretService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-webhook-trigger WebhookTriggerController.
 *
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-3-webhooktriggercontroller-public-secret-authenticated-enumeration-safe
 */
class WebhookTriggerControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * Mock WebhookSecretService.
	 *
	 * @var WebhookSecretService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WebhookSecretService $webhookSecretService;

	/**
	 * Mock IJobList.
	 *
	 * @var IJobList&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IJobList $jobList;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->webhookSecretService = $this->createMock(WebhookSecretService::class);
		$this->jobList = $this->createMock(IJobList::class);

	}//end setUp()

	/**
	 * Build the controller with a fixed raw body (readRawBody() overridden —
	 * `php://input` cannot be repopulated per-test).
	 *
	 * @param string $rawBody The stubbed raw request body.
	 *
	 * @return WebhookTriggerController
	 */
	private function controller(string $rawBody = ''): WebhookTriggerController {
		return new class($this->request, $this->webhookSecretService, $this->jobList, $this->createMock(LoggerInterface::class), $rawBody) extends WebhookTriggerController {
			/**
			 * The stubbed raw body.
			 *
			 * @var string
			 */
			private string $stubBody;

			/**
			 * Constructor.
			 *
			 * @param IRequest $request The request object.
			 * @param WebhookSecretService $webhookSecretService The secret verifier.
			 * @param IJobList $jobList Enqueues the job.
			 * @param LoggerInterface $logger PSR-3 logger.
			 * @param string $stubBody The stubbed raw body.
			 */
			public function __construct(
				IRequest $request,
				WebhookSecretService $webhookSecretService,
				IJobList $jobList,
				LoggerInterface $logger,
				string $stubBody,
			) {
				parent::__construct($request, $webhookSecretService, $jobList, $logger);
				$this->stubBody = $stubBody;
			}//end __construct()

			/**
			 * Return the stubbed raw body instead of reading php://input.
			 *
			 * @return string
			 */
			protected function readRawBody(): string {
				return $this->stubBody;
			}//end readRawBody()
		};

	}//end controller()

	/**
	 * Build a headers map for $this->request->method('getHeader')->willReturnMap().
	 *
	 * @param string $secret The X-Hermiq-Webhook-Secret header value.
	 * @param string $contentLength The Content-Length header value.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function headers(string $secret = '', string $contentLength = ''): array {
		return [
			['X-Hermiq-Webhook-Secret', $secret],
			['Content-Length', $contentLength],
		];

	}//end headers()

	/**
	 * Build a matched, enabled AgentWebhook ObjectEntity.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return ObjectEntity
	 */
	private function webhook(array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('webhook-1');
		$entity->setObject(array_merge(['enabled' => true], $data));
		return $entity;
	}//end webhook()

	/**
	 * A correct secret is accepted: 202 + a correlationId, and
	 * WebhookAgentRunJob is enqueued with the expected context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
	 */
	public function testValidSecretAcceptedAndEnqueuesJob(): void {
		$this->request->method('getHeader')->willReturnMap($this->headers(secret: 'hwh_correct', contentLength: '20'));
		$this->webhookSecretService->method('verifyAndLoad')
			->with('agent-1', 'hwh_correct')
			->willReturn($this->webhook(['requiresApproval' => false, 'reviewer' => '', 'reviewerType' => 'user']));

		$enqueuedArgs = null;
		$this->jobList->expects($this->once())
			->method('add')
			->with(WebhookAgentRunJob::class, $this->callback(function ($arg) use (&$enqueuedArgs) {
				$enqueuedArgs = $arg;
				return true;
			}));
		$this->webhookSecretService->expects($this->once())->method('markUsed');

		$response = $this->controller(rawBody: '{"event":"ping"}')->trigger(id: 'agent-1');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame('accepted', $response->getData()['status']);
		$this->assertNotEmpty($response->getData()['correlationId']);
		$this->assertSame('agent-1', $enqueuedArgs['agentId']);
		$this->assertSame(['event' => 'ping'], $enqueuedArgs['payload']);

	}//end testValidSecretAcceptedAndEnqueuesJob()

	/**
	 * An empty body is accepted (payload becomes an empty array) — the webhook
	 * caller may fire the trigger with no body at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
	 */
	public function testEmptyBodyIsAcceptedAsEmptyPayload(): void {
		$this->request->method('getHeader')->willReturnMap($this->headers(secret: 'hwh_correct'));
		$this->webhookSecretService->method('verifyAndLoad')->willReturn($this->webhook());

		$response = $this->controller(rawBody: '')->trigger(id: 'agent-1');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

	}//end testEmptyBodyIsAcceptedAsEmptyPayload()

	/**
	 * Wrong secret / disabled webhook / unknown agent id all produce the
	 * BYTE-IDENTICAL 401 response body (enumeration safety, TC-6).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
	 */
	public function testEveryAuthFailureModeProducesIdenticalResponse(): void {
		$this->jobList->expects($this->never())->method('add');

		// Wrong secret.
		$wrongSecretRequest = $this->createMock(IRequest::class);
		$wrongSecretRequest->method('getHeader')->willReturnMap($this->headers(secret: 'hwh_wrong'));
		$wrongSecretService = $this->createMock(WebhookSecretService::class);
		$wrongSecretService->method('verifyAndLoad')->willReturn(null);
		$controller1 = new WebhookTriggerController(
			$wrongSecretRequest,
			$wrongSecretService,
			$this->jobList,
			$this->createMock(LoggerInterface::class)
		);
		$response1 = $controller1->trigger(id: 'agent-1');

		// Unknown agent id.
		$unknownAgentRequest = $this->createMock(IRequest::class);
		$unknownAgentRequest->method('getHeader')->willReturnMap($this->headers(secret: 'anything'));
		$unknownAgentService = $this->createMock(WebhookSecretService::class);
		$unknownAgentService->method('verifyAndLoad')->willReturn(null);
		$controller2 = new WebhookTriggerController(
			$unknownAgentRequest,
			$unknownAgentService,
			$this->jobList,
			$this->createMock(LoggerInterface::class)
		);
		$response2 = $controller2->trigger(id: 'nonexistent-agent');

		// Missing secret header entirely (disabled-webhook-equivalent case:
		// verifyAndLoad still returns null via the enumeration-safe fallback).
		$missingSecretRequest = $this->createMock(IRequest::class);
		$missingSecretRequest->method('getHeader')->willReturnMap($this->headers(secret: ''));
		$missingSecretService = $this->createMock(WebhookSecretService::class);
		$missingSecretService->method('verifyAndLoad')->willReturn(null);
		$controller3 = new WebhookTriggerController(
			$missingSecretRequest,
			$missingSecretService,
			$this->jobList,
			$this->createMock(LoggerInterface::class)
		);
		$response3 = $controller3->trigger(id: 'agent-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response1->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response2->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response3->getStatus());
		$this->assertSame($response1->getData(), $response2->getData());
		$this->assertSame($response2->getData(), $response3->getData());
		$this->assertSame(['error' => 'unauthorized'], $response1->getData());

	}//end testEveryAuthFailureModeProducesIdenticalResponse()

	/**
	 * An honest Content-Length over the 64 KiB cap is rejected BEFORE the body
	 * is ever read (no secret verification, no job enqueued).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-payload-is-size-capped-before-it-is-processed
	 */
	public function testOversizedContentLengthRejectedBeforeReadingBody(): void {
		$this->request->method('getHeader')->willReturnMap($this->headers(contentLength: (string)(65536 + 1)));
		$this->webhookSecretService->expects($this->never())->method('verifyAndLoad');
		$this->jobList->expects($this->never())->method('add');

		$response = $this->controller()->trigger(id: 'agent-1');

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		$this->assertSame(['error' => 'payload_too_large'], $response->getData());

	}//end testOversizedContentLengthRejectedBeforeReadingBody()

	/**
	 * An oversized ACTUAL body is rejected even when Content-Length is absent/
	 * understated — no job is enqueued.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-payload-is-size-capped-before-it-is-processed
	 */
	public function testOversizedActualBodyRejectedWithoutHonestContentLength(): void {
		$this->request->method('getHeader')->willReturnMap($this->headers(secret: 'hwh_correct', contentLength: ''));
		$this->webhookSecretService->method('verifyAndLoad')->willReturn($this->webhook());
		$this->jobList->expects($this->never())->method('add');

		$oversized = str_repeat('a', 65536 + 1);
		$response = $this->controller(rawBody: $oversized)->trigger(id: 'agent-1');

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());

	}//end testOversizedActualBodyRejectedWithoutHonestContentLength()

	/**
	 * A non-JSON body is rejected (400) — but ONLY after the secret has
	 * verified, so an invalid-JSON response can never leak agent existence.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
	 */
	public function testNonJsonBodyRejectedOnlyAfterSecretVerifies(): void {
		$this->request->method('getHeader')->willReturnMap($this->headers(secret: 'hwh_correct'));
		$this->webhookSecretService->method('verifyAndLoad')->willReturn($this->webhook());
		$this->jobList->expects($this->never())->method('add');

		$response = $this->controller(rawBody: 'not json at all {{{')->trigger(id: 'agent-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid_json'], $response->getData());

	}//end testNonJsonBodyRejectedOnlyAfterSecretVerifies()
}//end class
