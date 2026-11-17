<?php

/**
 * Unit tests for OrganisationOpsController's failure answers.
 *
 * OrganisationOpsControllerTest covers the happy paths, the action gate and the
 * validation answers. This file pins what the agent-lifecycle-governance
 * endpoints answer when the tenant-ops service throws unexpectedly (500 with a
 * fixed error body, and the failure logged), when the reassign target is
 * missing (422) or the agent is unknown (404), and when an unauthenticated
 * caller reads the incident list or the retention setting (401, no service call).
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
 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-5-tenantopscontroller-access-review-reassign-endpoints
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use Error;
use LogicException;
use OCA\Hermiq\Controller\OrganisationOpsController;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\OrganisationOpsServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure answers of OrganisationOpsController.
 *
 * @covers \OCA\Hermiq\Controller\OrganisationOpsController
 * @uses   \OCA\Hermiq\Controller\ActionGate
 *
 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-5-tenantopscontroller-access-review-reassign-endpoints
 */
final class OrganisationOpsControllerUncoveredPathsTest extends TestCase {

	/**
	 * @var OrganisationOpsServiceInterface&MockObject
	 */
	private OrganisationOpsServiceInterface $service;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(OrganisationOpsServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller for the given caller and request params.
	 *
	 * @param string|null $uid The signed-in UID, or null for unauthenticated.
	 * @param array<string,mixed> $params Request params getParam() resolves.
	 *
	 * @return OrganisationOpsController
	 */
	private function controller(?string $uid, array $params = []): OrganisationOpsController {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

		return new OrganisationOpsController(
			$request,
			$this->service,
			$session,
			$this->createMock(ActionAuthServiceInterface::class),
			$this->logger
		);
	}//end controller()

	/**
	 * Expect exactly one error log line with the given message.
	 *
	 * @param string $message The full expected log message.
	 *
	 * @return void
	 */
	private function expectErrorLogged(string $message): void {
		$this->logger->expects(self::once())
			->method('error')
			->with($message, self::callback(static fn (array $ctx): bool => isset($ctx['exception'])));
	}//end expectErrorLogged()

	/**
	 * reviewList() answers 500 and logs when the service throws.
	 *
	 * @return void
	 */
	public function testReviewListServiceFailureIsServerError(): void {
		$this->service->method('accessReviewList')->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged('Hermiq access-review list failed: db down');

		$response = $this->controller('alice')->reviewList();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not load access review'], $response->getData());
	}//end testReviewListServiceFailureIsServerError()

	/**
	 * attestReview() answers 500 and logs on a non-RuntimeException failure.
	 *
	 * @return void
	 */
	public function testAttestReviewUnexpectedFailureIsServerError(): void {
		$this->service->expects(self::once())
			->method('attestAgentReviewed')
			->with('agent-1', 'alice')
			->willThrowException(new LogicException('boom'));
		$this->expectErrorLogged('Hermiq review attestation failed: boom');

		$response = $this->controller('alice')->attestReview('agent-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Attestation failed'], $response->getData());
	}//end testAttestReviewUnexpectedFailureIsServerError()

	/**
	 * reassignAgent() answers 422 without calling the service when actingUser is missing.
	 *
	 * @return void
	 */
	public function testReassignAgentRequiresActingUser(): void {
		$this->service->expects(self::never())->method('reassignAgent');

		$response = $this->controller('alice', ['actingUser' => ''])->reassignAgent('agent-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(['error' => 'actingUser is required'], $response->getData());
	}//end testReassignAgentRequiresActingUser()

	/**
	 * reassignAgent() answers 404 with the service message for an unknown agent.
	 *
	 * @return void
	 */
	public function testReassignAgentUnknownAgentIsNotFound(): void {
		$this->service->expects(self::once())
			->method('reassignAgent')
			->with('agent-404', 'bob')
			->willThrowException(new RuntimeException('Agent not found'));

		$response = $this->controller('alice', ['actingUser' => 'bob'])->reassignAgent('agent-404');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Agent not found'], $response->getData());
	}//end testReassignAgentUnknownAgentIsNotFound()

	/**
	 * reassignAgent() answers 500 and logs on an unexpected failure.
	 *
	 * @return void
	 */
	public function testReassignAgentUnexpectedFailureIsServerError(): void {
		$this->service->method('reassignAgent')->willThrowException(new Error('fatal'));
		$this->expectErrorLogged('Hermiq agent reassignment failed: fatal');

		$response = $this->controller('alice', ['actingUser' => 'bob'])->reassignAgent('agent-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Reassignment failed'], $response->getData());
	}//end testReassignAgentUnexpectedFailureIsServerError()

	/**
	 * incidents() answers 401 for an unauthenticated caller, never calling the service.
	 *
	 * @return void
	 */
	public function testIncidentsUnauthenticated(): void {
		$this->service->expects(self::never())->method('listIncidents');

		$response = $this->controller(null)->incidents();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Unauthenticated'], $response->getData());
	}//end testIncidentsUnauthenticated()

	/**
	 * incidents() answers 500 and logs when the service throws.
	 *
	 * @return void
	 */
	public function testIncidentsServiceFailureIsServerError(): void {
		$this->service->method('listIncidents')->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged('Hermiq incident list failed: db down');

		$response = $this->controller('alice')->incidents();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not load incidents'], $response->getData());
	}//end testIncidentsServiceFailureIsServerError()

	/**
	 * retention() answers 401 for an unauthenticated caller, never calling the service.
	 *
	 * @return void
	 */
	public function testRetentionUnauthenticated(): void {
		$this->service->expects(self::never())->method('getRetentionMonths');

		$response = $this->controller(null)->retention();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Unauthenticated'], $response->getData());
	}//end testRetentionUnauthenticated()

	/**
	 * retention() answers 500 and logs when the service throws.
	 *
	 * @return void
	 */
	public function testRetentionServiceFailureIsServerError(): void {
		$this->service->method('getRetentionMonths')->willThrowException(new RuntimeException('config gone'));
		$this->expectErrorLogged('Hermiq retention read failed: config gone');

		$response = $this->controller('alice')->retention();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not load retention'], $response->getData());
	}//end testRetentionServiceFailureIsServerError()

	/**
	 * updateRetention() answers 500 and logs on a non-validation failure.
	 *
	 * @return void
	 */
	public function testUpdateRetentionUnexpectedFailureIsServerError(): void {
		$this->service->expects(self::once())
			->method('setRetentionMonths')
			->with(12)
			->willThrowException(new RuntimeException('write failed'));
		$this->expectErrorLogged('Hermiq retention update failed: write failed');

		$response = $this->controller('alice', ['retentionMonths' => '12'])->updateRetention();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not update retention'], $response->getData());
	}//end testUpdateRetentionUnexpectedFailureIsServerError()
}//end class
