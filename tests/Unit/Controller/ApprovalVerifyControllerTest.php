<?php

/**
 * The verify endpoint integriq calls (approval-verification-contract).
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
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Controller\ApprovalVerifyController;
use OCA\Hermiq\Service\Approval\ApprovalVerdictService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests the verify endpoint's answers and its attributes.
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
 */
class ApprovalVerifyControllerTest extends TestCase {

	/**
	 * The controller over a request carrying $params and a verdict service.
	 *
	 * @param array<string, mixed>   $params  The request params.
	 * @param ApprovalVerdictService $service The verdict service.
	 *
	 * @return ApprovalVerifyController
	 */
	private function controller(array $params, ApprovalVerdictService $service): ApprovalVerifyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new ApprovalVerifyController(
			request: $request,
			verdicts: $service,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * The five contract fields reach the service, and its answer is the body.
	 *
	 * @return void
	 */
	public function testAnswersTheSignedVerdict(): void {
		$request = ['approvalId' => 'a1', 'toolId' => 't', 'binding' => 'b', 'actingAgent' => 'g1', 'nonce' => 'n', '_route' => 'x'];
		$answer  = ['verdict' => ['approved' => true, 'reason' => 'approved'], 'signature' => 'sig'];
		$service = $this->createMock(ApprovalVerdictService::class);
		$service->expects($this->once())->method('verify')
			->with(['approvalId' => 'a1', 'toolId' => 't', 'binding' => 'b', 'actingAgent' => 'g1', 'nonce' => 'n'])
			->willReturn($answer);

		$response = $this->controller(params: $request, service: $service)->verify();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($answer, $response->getData());
		$this->assertFalse($response->isThrottled());

	}//end testAnswersTheSignedVerdict()

	/**
	 * A verdict about an approval the caller could not name is throttled.
	 *
	 * @return void
	 */
	public function testAnUnknownApprovalIsThrottled(): void {
		foreach (['unknown', 'binding-mismatch'] as $reason) {
			$service = $this->createMock(ApprovalVerdictService::class);
			$service->method('verify')->willReturn(['verdict' => ['approved' => false, 'reason' => $reason], 'signature' => 'sig']);

			$response = $this->controller(params: [], service: $service)->verify();

			$this->assertSame(Http::STATUS_OK, $response->getStatus());
			$this->assertTrue($response->isThrottled(), $reason);
		}

	}//end testAnUnknownApprovalIsThrottled()

	/**
	 * An incomplete request is a 400 with no verdict.
	 *
	 * @return void
	 */
	public function testAnIncompleteRequestIsABadRequest(): void {
		$service = $this->createMock(ApprovalVerdictService::class);
		$service->method('verify')->willThrowException(new InvalidArgumentException('nonce is missing'));

		$response = $this->controller(params: [], service: $service)->verify();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayNotHasKey('verdict', $response->getData());
		$this->assertTrue($response->isThrottled());

	}//end testAnIncompleteRequestIsABadRequest()

	/**
	 * integriq calls from the server without a session: public, no CSRF token,
	 * and brute-force protected.
	 *
	 * @return void
	 */
	public function testTheRouteIsPublicAndThrottled(): void {
		$method = new ReflectionMethod(ApprovalVerifyController::class, 'verify');

		$this->assertCount(1, $method->getAttributes(PublicPage::class));
		$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class));
		$throttle = $method->getAttributes(BruteForceProtection::class);
		$this->assertCount(1, $throttle);
		$this->assertSame('hermiq_approval_verify', $throttle[0]->getArguments()['action'] ?? null);

	}//end testTheRouteIsPublicAndThrottled()
}//end class
