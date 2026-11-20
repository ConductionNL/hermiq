<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Service\Handoff
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Handoff;

use OCA\Hermiq\Controller\HandoffResultController;
use OCA\Hermiq\Service\Handoff\FlowTurn;
use OCA\Hermiq\Service\Handoff\HandoffService;
use OCA\Hermiq\Service\Handoff\WebhookHandoffs;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A webhook workflow's result is accepted once, with the handoff's token, while it waits.
 *
 * @covers \OCA\Hermiq\Service\Handoff\WebhookHandoffs
 * @covers \OCA\Hermiq\Controller\HandoffResultController
 */
class WebhookHandoffsTest extends TestCase {

	/**
	 * The stored handoff, as HandoffService keeps it.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $stored = null;

	/**
	 * The results the handoff was closed with.
	 *
	 * @var array<int, mixed>
	 */
	private array $closed = [];

	/**
	 * The handoff record.
	 *
	 * @var HandoffService&MockObject
	 */
	private HandoffService&MockObject $handoffs;

	/**
	 * The service under test.
	 *
	 * @var WebhookHandoffs
	 */
	private WebhookHandoffs $webhooks;

	/**
	 * A handoff store that keeps what open() was given and closes once.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->handoffs = $this->createMock(HandoffService::class);
		$this->handoffs->method('open')->willReturnCallback(function (array $handoff): array {
			$this->stored = $handoff + ['status' => 'waiting'];
			return ['id' => 'h-1'] + $this->stored;
		});
		$this->handoffs->method('find')->willReturnCallback(fn (string $id): ?array => ($id === 'h-1') ? $this->stored : null);
		$this->handoffs->method('close')->willReturnCallback(function (string $id, string $status, mixed $result): bool {
			if (($this->stored['status'] ?? null) !== 'waiting') {
				return false;
			}

			$this->stored['status'] = $status;
			$this->closed[] = $result;
			return true;
		});

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')
			->with('hermiq.handoffResult.post', ['id' => 'h-1'])
			->willReturn('https://cloud.example.nl/apps/hermiq/api/handoffs/h-1/result');

		$this->webhooks = new WebhookHandoffs(handoffs: $this->handoffs, urlGenerator: $urls);
	}//end setUp()

	/**
	 * Open a handoff for the subsidy workflow.
	 *
	 * @return array{handoffId: string, hermiqResultUrl: string, hermiqResultToken: string}
	 */
	private function openOne(): array {
		return $this->webhooks->open(
			turn: new FlowTurn(agentId: 'agent-1', conversationId: 'conv-1', owner: 'anne'),
			label: 'Subsidieaanvraag beoordelen',
			target: 'subsidieaanvraag-beoordelen',
			deadlineHours: 24
		);
	}//end openOne()

	/**
	 * Opening answers the result address and a 32-byte token, and stores only the token's hash.
	 *
	 * @return void
	 */
	public function testOpeningStoresOnlyTheTokensHash(): void {
		$opened = $this->openOne();

		$this->assertSame('h-1', $opened['handoffId']);
		$this->assertSame('https://cloud.example.nl/apps/hermiq/api/handoffs/h-1/result', $opened['hermiqResultUrl']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $opened['hermiqResultToken']);
		$this->assertSame('webhook', $this->stored['kind']);
		$this->assertSame(24, $this->stored['deadlineHours']);
		$this->assertSame(hash('sha256', $opened['hermiqResultToken']), $this->stored['resultTokenHash']);
		$this->assertNotContains($opened['hermiqResultToken'], $this->stored);
		$this->assertNotSame($opened['hermiqResultToken'], $this->openOne()['hermiqResultToken'], 'every handoff gets its own token');
	}//end testOpeningStoresOnlyTheTokensHash()

	/**
	 * The right token closes the handoff once; a repeat, a wrong token, an unknown handoff or no token is refused.
	 *
	 * @return void
	 */
	public function testTheTokenIsSingleUse(): void {
		$token = $this->openOne()['hermiqResultToken'];

		$this->assertFalse($this->webhooks->acceptResult(handoffId: 'h-1', token: str_repeat('0', 64), result: ['x' => 1]));
		$this->assertFalse($this->webhooks->acceptResult(handoffId: 'h-1', token: '', result: ['x' => 1]));
		$this->assertFalse($this->webhooks->acceptResult(handoffId: 'other', token: $token, result: ['x' => 1]));
		$this->assertTrue($this->webhooks->acceptResult(handoffId: 'h-1', token: $token, result: ['toegekend' => true]));
		$this->assertFalse($this->webhooks->acceptResult(handoffId: 'h-1', token: $token, result: ['toegekend' => false]));
		$this->assertSame([['toegekend' => true]], $this->closed);
	}//end testTheTokenIsSingleUse()

	/**
	 * A handoff of another kind is never closed through the webhook address.
	 *
	 * @return void
	 */
	public function testAnotherKindIsRefused(): void {
		$token = $this->openOne()['hermiqResultToken'];
		$this->stored['kind'] = 'flow';

		$this->assertFalse($this->webhooks->acceptResult(handoffId: 'h-1', token: $token, result: []));
		$this->assertSame([], $this->closed);
	}//end testAnotherKindIsRefused()

	/**
	 * The route answers 202 once with the header token, then 404; a body token works too and is not stored.
	 *
	 * @return void
	 */
	public function testTheRouteAcceptsOnceAndThenAnswers404(): void {
		$token = $this->openOne()['hermiqResultToken'];

		$first = $this->controller(headers: ['X-Hermiq-Result-Token' => $token], body: '{"toegekend":true}')->post(id: 'h-1');
		$this->assertSame(202, $first->getStatus());

		$repeat = $this->controller(headers: ['X-Hermiq-Result-Token' => $token], body: '{"toegekend":true}')->post(id: 'h-1');
		$this->assertSame(404, $repeat->getStatus());
		$this->assertSame(['error' => 'not_found'], $repeat->getData());
		$this->assertSame([['toegekend' => true]], $this->closed);
	}//end testTheRouteAcceptsOnceAndThenAnswers404()

	/**
	 * A token in the body is accepted and left out of the stored result.
	 *
	 * @return void
	 */
	public function testABodyTokenIsAcceptedAndNotKept(): void {
		$token = $this->openOne()['hermiqResultToken'];

		$response = $this->controller(headers: [], body: json_encode(['hermiqResultToken' => $token, 'score' => 8]))->post(id: 'h-1');

		$this->assertSame(202, $response->getStatus());
		$this->assertSame([['score' => 8]], $this->closed);
	}//end testABodyTokenIsAcceptedAndNotKept()

	/**
	 * A wrong token gets the same 404, and a body over 1 MB is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAWrongTokenOrAnOversizedBodyIsRefused(): void {
		$this->openOne();

		$wrong = $this->controller(headers: ['X-Hermiq-Result-Token' => 'guess'], body: '{}')->post(id: 'h-1');
		$this->assertSame(404, $wrong->getStatus());

		$big = $this->controller(headers: ['Content-Length' => (string) (HandoffResultController::MAX_BODY_BYTES + 1)], body: '{}')->post(id: 'h-1');
		$this->assertSame(413, $big->getStatus());

		$lying = $this->controller(headers: [], body: str_repeat('a', HandoffResultController::MAX_BODY_BYTES + 1))->post(id: 'h-1');
		$this->assertSame(413, $lying->getStatus());
		$this->assertSame([], $this->closed);
	}//end testAWrongTokenOrAnOversizedBodyIsRefused()

	/**
	 * The controller over a request with these headers and this body.
	 *
	 * @param array<string, string> $headers The headers.
	 * @param string                $body    The raw body.
	 *
	 * @return HandoffResultController
	 */
	private function controller(array $headers, string $body): HandoffResultController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($headers[$name] ?? ''));

		return new class($request, $this->webhooks, $body) extends HandoffResultController {
			/**
			 * Constructor.
			 *
			 * @param IRequest        $request  The request.
			 * @param WebhookHandoffs $webhooks The webhook handoffs.
			 * @param string          $body     The raw body this request carries.
			 */
			public function __construct(IRequest $request, WebhookHandoffs $webhooks, private readonly string $body) {
				parent::__construct(request: $request, webhooks: $webhooks);
			}//end __construct()

			/**
			 * The raw body.
			 *
			 * @return string
			 */
			protected function readRawBody(): string {
				return $this->body;
			}//end readRawBody()
		};
	}//end controller()
}//end class
