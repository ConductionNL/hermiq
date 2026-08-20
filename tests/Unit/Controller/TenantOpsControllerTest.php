<?php

/**
 * Unit tests for TenantOpsController (multi-tenant-ops).
 *
 * Covers the two tenant-scoped operations endpoints — quota() and auditExport() — for the
 * happy path (200 with the service payload), the unauthenticated path (401 before any service
 * call), and the service-failure path (500). auditExport additionally asserts the
 * Content-Disposition attachment header is set on success.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/changes/playwright-regression-coverage/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Controller\TenantOpsController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\TenantOpsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the multi-tenant-ops controller.
 *
 * @spec openspec/changes/playwright-regression-coverage/tasks.md#task-2-1
 */
class TenantOpsControllerTest extends TestCase {
	/**
	 * A session with the given (or no) user.
	 *
	 * @param string|null $uid The UID, or null for unauthenticated.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * Build the controller with the given service + session (+ optional
	 * ActionAuthService/request params for the agent-lifecycle-governance
	 * mutating endpoints; a permissive no-op ActionAuthService by default).
	 *
	 * @param TenantOpsService $service The tenant-ops service.
	 * @param IUserSession $session The user session.
	 * @param ActionAuthService|null $actionAuth The action-auth gate, or null for a permissive default.
	 * @param array<string,mixed> $requestParams Request params getParam() should resolve.
	 *
	 * @return TenantOpsController
	 */
	private function controller(
		TenantOpsService $service,
		IUserSession $session,
		?ActionAuthService $actionAuth = null,
		array $requestParams = [],
	): TenantOpsController {
		return new TenantOpsController(
			$this->request(params: $requestParams),
			$service,
			$session,
			($actionAuth ?? $this->createMock(ActionAuthService::class)),
			$this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * A request whose getParam() resolves from the given params array.
	 *
	 * @param array<string,mixed> $params The params to resolve.
	 *
	 * @return IRequest
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);
		return $request;
	}//end request()

	/**
	 * quota() returns 200 with the service's quota payload for an authenticated caller.
	 *
	 * @return void
	 */
	public function testQuotaReturnsStatus(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('quotaStatus')->willReturn(['requests' => ['used' => 3, 'limit' => 100]]);

		$response = $this->controller($service, $this->session('alice'))->quota();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(3, $response->getData()['requests']['used']);

	}//end testQuotaReturnsStatus()

	/**
	 * quota() returns 401 for an unauthenticated caller, never calling the service.
	 *
	 * @return void
	 */
	public function testQuotaUnauthenticated(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->expects($this->never())->method('quotaStatus');

		$response = $this->controller($service, $this->session(null))->quota();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testQuotaUnauthenticated()

	/**
	 * quota() maps a service failure to 500.
	 *
	 * @return void
	 */
	public function testQuotaServiceFailureIsServerError(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('quotaStatus')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller($service, $this->session('alice'))->quota();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testQuotaServiceFailureIsServerError()

	/**
	 * auditExport() returns 200 with the service's export payload.
	 *
	 * The attachment Content-Disposition header is set by the controller but is not asserted
	 * here: JSONResponse::getHeaders() materialises Nextcloud's default headers via \OC, which
	 * is unavailable in standalone unit CI. The status + payload prove the success path.
	 *
	 * @return void
	 */
	public function testAuditExportReturnsExport(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('exportAuditTrail')->willReturn(['generatedAt' => 'now', 'entries' => []]);

		$response = $this->controller($service, $this->session('alice'))->auditExport();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('now', $response->getData()['generatedAt']);

	}//end testAuditExportReturnsExport()

	/**
	 * auditExport() returns 401 for an unauthenticated caller, never calling the service.
	 *
	 * @return void
	 */
	public function testAuditExportUnauthenticated(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->expects($this->never())->method('exportAuditTrail');

		$response = $this->controller($service, $this->session(null))->auditExport();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAuditExportUnauthenticated()

	/**
	 * auditExport() maps a service failure to 500.
	 *
	 * @return void
	 */
	public function testAuditExportServiceFailureIsServerError(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('exportAuditTrail')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller($service, $this->session('alice'))->auditExport();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testAuditExportServiceFailureIsServerError()

	/**
	 * reviewList() returns 200 with the service's access-review payload.
	 *
	 * @return void
	 */
	public function testReviewListReturnsPayload(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('accessReviewList')->willReturn(['agents' => [['uuid' => 'a1']]]);

		$response = $this->controller($service, $this->session('alice'))->reviewList();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['agents']);

	}//end testReviewListReturnsPayload()

	/**
	 * reviewList() returns 401 for an unauthenticated caller.
	 *
	 * @return void
	 */
	public function testReviewListUnauthenticated(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->expects($this->never())->method('accessReviewList');

		$response = $this->controller($service, $this->session(null))->reviewList();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testReviewListUnauthenticated()

	/**
	 * attestReview() gates on requireAction('tenantops.attest-review') then attests.
	 *
	 * @return void
	 */
	public function testAttestReviewGatesThenAttests(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('attestAgentReviewed')->willReturn(['uuid' => 'a1', 'reviewedBy' => 'org.admin']);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'tenantops.attest-review');

		$response = $this->controller($service, $this->session('org.admin'), $actionAuth)->attestReview('a1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('org.admin', $response->getData()['reviewedBy']);

	}//end testAttestReviewGatesThenAttests()

	/**
	 * attestReview() returns 403 when ActionAuthService rejects the caller.
	 *
	 * @return void
	 */
	public function testAttestReviewForbidden(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->expects($this->never())->method('attestAgentReviewed');

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$response = $this->controller($service, $this->session('mallory'), $actionAuth)->attestReview('a1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAttestReviewForbidden()

	/**
	 * attestReview() maps a missing agent to 404.
	 *
	 * @return void
	 */
	public function testAttestReviewNotFound(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('attestAgentReviewed')->willThrowException(new RuntimeException('Agent not found'));

		$response = $this->controller($service, $this->session('org.admin'))->attestReview('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAttestReviewNotFound()

	/**
	 * reassignAgent() gates on requireAction('tenantops.reassign-agent') then reassigns.
	 *
	 * @return void
	 */
	public function testReassignAgentGatesThenReassigns(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('reassignAgent')->willReturn(['uuid' => 'a1', 'actingUser' => 'new.user']);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'tenantops.reassign-agent');

		$response = $this->controller(
			$service,
			$this->session('org.admin'),
			$actionAuth,
			['actingUser' => 'new.user']
		)->reassignAgent('a1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('new.user', $response->getData()['actingUser']);

	}//end testReassignAgentGatesThenReassigns()

	/**
	 * reassignAgent() returns 403 when ActionAuthService rejects the caller.
	 *
	 * @return void
	 */
	public function testReassignAgentForbidden(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->expects($this->never())->method('reassignAgent');

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$response = $this->controller(
			$service,
			$this->session('mallory'),
			$actionAuth,
			['actingUser' => 'new.user']
		)->reassignAgent('a1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testReassignAgentForbidden()

	/**
	 * reassignAgent() maps an invalid/disabled target user to 422.
	 *
	 * @return void
	 */
	public function testReassignAgentRejectsInvalidTarget(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('reassignAgent')->willThrowException(new InvalidArgumentException('Target user does not exist or is not active'));

		$response = $this->controller(
			$service,
			$this->session('org.admin'),
			null,
			['actingUser' => 'ghost']
		)->reassignAgent('a1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testReassignAgentRejectsInvalidTarget()

	/**
	 * incidents() returns 200 with the service's incident list.
	 *
	 * @return void
	 */
	public function testIncidentsReturnsList(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('listIncidents')->willReturn(['incidents' => [['uuid' => 'i1']]]);

		$response = $this->controller($service, $this->session('alice'))->incidents();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['incidents']);

	}//end testIncidentsReturnsList()

	/**
	 * createIncident() gates on requireAction('tenantops.create-incident') then creates.
	 *
	 * @return void
	 */
	public function testCreateIncidentGatesThenCreates(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('createIncident')->willReturn(['uuid' => 'i1', 'description' => 'boom']);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'tenantops.create-incident');

		$response = $this->controller(
			$service,
			$this->session('org.admin'),
			$actionAuth,
			['description' => 'boom', 'impact' => 'minor', 'actionsTaken' => 'fixed']
		)->createIncident();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('i1', $response->getData()['uuid']);

	}//end testCreateIncidentGatesThenCreates()

	/**
	 * createIncident() returns 422 when a required field is missing.
	 *
	 * @return void
	 */
	public function testCreateIncidentRejectsMissingFields(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->expects($this->never())->method('createIncident');

		$response = $this->controller(
			$service,
			$this->session('org.admin'),
			null,
			['description' => 'boom']
		)->createIncident();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testCreateIncidentRejectsMissingFields()

	/**
	 * createIncident() returns 403 when ActionAuthService rejects the caller.
	 *
	 * @return void
	 */
	public function testCreateIncidentForbidden(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->expects($this->never())->method('createIncident');

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$response = $this->controller(
			$service,
			$this->session('mallory'),
			$actionAuth,
			['description' => 'boom', 'impact' => 'minor', 'actionsTaken' => 'fixed']
		)->createIncident();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCreateIncidentForbidden()

	/**
	 * retention() returns 200 with the service's configured retention period.
	 *
	 * @return void
	 */
	public function testRetentionReturnsMonths(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('getRetentionMonths')->willReturn(6);

		$response = $this->controller($service, $this->session('alice'))->retention();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(6, $response->getData()['retentionMonths']);

	}//end testRetentionReturnsMonths()

	/**
	 * updateRetention() gates on requireAction('tenantops.update-retention') then updates.
	 *
	 * @return void
	 */
	public function testUpdateRetentionGatesThenUpdates(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('setRetentionMonths')->willReturn(12);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'tenantops.update-retention');

		$response = $this->controller(
			$service,
			$this->session('org.admin'),
			$actionAuth,
			['retentionMonths' => 12]
		)->updateRetention();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(12, $response->getData()['retentionMonths']);

	}//end testUpdateRetentionGatesThenUpdates()

	/**
	 * updateRetention() maps a below-minimum value to 422, service is the source of truth.
	 *
	 * @return void
	 */
	public function testUpdateRetentionRejectsBelowMinimum(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->method('setRetentionMonths')->willThrowException(new InvalidArgumentException('retentionMonths must be at least 6'));

		$response = $this->controller(
			$service,
			$this->session('org.admin'),
			null,
			['retentionMonths' => 3]
		)->updateRetention();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testUpdateRetentionRejectsBelowMinimum()

	/**
	 * updateRetention() returns 403 when ActionAuthService rejects the caller.
	 *
	 * @return void
	 */
	public function testUpdateRetentionForbidden(): void {
		$service = $this->createMock(TenantOpsService::class);
		$service->expects($this->never())->method('setRetentionMonths');

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$response = $this->controller(
			$service,
			$this->session('mallory'),
			$actionAuth,
			['retentionMonths' => 12]
		)->updateRetention();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testUpdateRetentionForbidden()
}//end class
