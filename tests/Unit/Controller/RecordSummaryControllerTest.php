<?php

/**
 * Unit tests for RecordSummaryController (agents-bound-to-their-app, task 5).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\RecordSummaryController;
use OCA\Hermiq\Service\Assistant\RecordSummaryService;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The two summary endpoints pass the record reference through as the caller and keep each refusal's status.
 */
final class RecordSummaryControllerTest extends TestCase {

	private const PARAMS = ['register' => 'subsidies', 'schema' => 'application', 'objectId' => 'obj-1'];

	/**
	 * Build the controller.
	 *
	 * @param RecordSummaryService $service The service double.
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return RecordSummaryController
	 */
	private function controller(RecordSummaryService $service, bool $signedIn = true): RecordSummaryController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => (self::PARAMS[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);

		return new RecordSummaryController(request: $request, summaries: $service, userSession: $session);
	}//end controller()

	public function testSummariseAsksForTheRecordAsTheCaller(): void {
		$service = $this->createMock(RecordSummaryService::class);
		$service->expects(self::once())->method('summarise')->with('alice', 'subsidies', 'application', 'obj-1')
			->willReturn(['summary' => 'Short.', 'cached' => false]);

		$response = $this->controller(service: $service)->summarise();

		self::assertSame(200, $response->getStatus());
		self::assertSame('Short.', $response->getData()['summary']);
	}//end testSummariseAsksForTheRecordAsTheCaller()

	public function testShowAsksForTheStatusAsTheCaller(): void {
		$service = $this->createMock(RecordSummaryService::class);
		$service->expects(self::once())->method('status')->with('alice', 'subsidies', 'application', 'obj-1')
			->willReturn(['enabled' => true, 'agent' => null, 'summary' => null]);

		$response = $this->controller(service: $service)->show();

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['enabled']);
	}//end testShowAsksForTheStatusAsTheCaller()

	public function testRefusalsKeepTheirStatus(): void {
		foreach ([400, 403, 404, 409] as $code) {
			$service = $this->createMock(RecordSummaryService::class);
			$service->method('summarise')->willThrowException(new RuntimeException('no', $code));
			$service->method('status')->willThrowException(new RuntimeException('no', $code));

			self::assertSame($code, $this->controller(service: $service)->summarise()->getStatus());
			self::assertSame($code, $this->controller(service: $service)->show()->getStatus());
		}

		$service = $this->createMock(RecordSummaryService::class);
		$service->method('summarise')->willThrowException(new RuntimeException('odd', 0));
		self::assertSame(500, $this->controller(service: $service)->summarise()->getStatus());
	}//end testRefusalsKeepTheirStatus()

	public function testAGuardrailRefusalIs422WithItsCode(): void {
		$service = $this->createMock(RecordSummaryService::class);
		$service->method('summarise')->willThrowException(new GuardrailBlockedException(reason: 'pii'));

		$response = $this->controller(service: $service)->summarise();

		self::assertSame(422, $response->getStatus());
		self::assertSame('guardrail_blocked', $response->getData()['errorCode']);
	}//end testAGuardrailRefusalIs422WithItsCode()

	public function testNoUserIs401AndNothingIsAsked(): void {
		$service = $this->createMock(RecordSummaryService::class);
		$service->expects(self::never())->method('summarise');
		$service->expects(self::never())->method('status');

		self::assertSame(401, $this->controller(service: $service, signedIn: false)->summarise()->getStatus());
		self::assertSame(401, $this->controller(service: $service, signedIn: false)->show()->getStatus());
	}//end testNoUserIs401AndNothingIsAsked()
}//end class
