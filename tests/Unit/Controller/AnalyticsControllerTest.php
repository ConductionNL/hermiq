<?php

/**
 * Unit tests for AnalyticsController (run-analytics).
 *
 * Covers both read endpoints: `index` (the dashboard metrics) and `runs` (the
 * cross-agent run list). For each — 401 unauthenticated, 200 happy path, 500 on
 * service failure — plus the parameter handling `runs` owns: an empty `agentId` or
 * `status` means "no filter" rather than a filter on the empty string, and `limit`
 * is clamped rather than trusted.
 *
 * The clamp is the one worth a test of its own. `limit` arrives from a query string,
 * and an unbounded one turns a paged list endpoint into a way to pull every audit row
 * the caller can see in a single request.
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
 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AnalyticsController;
use OCA\Hermiq\Service\AnalyticsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the run-analytics AnalyticsController.
 *
 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
 */
class AnalyticsControllerTest extends TestCase {

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
	 * Build the controller with a given service and session.
	 *
	 * @param AnalyticsService $service The analytics service (usually a mock).
	 * @param string|null      $uid     The caller's UID, or null for unauthenticated.
	 *
	 * @return AnalyticsController
	 */
	private function controller(AnalyticsService $service, ?string $uid): AnalyticsController {
		return new AnalyticsController(
			$this->createMock(IRequest::class),
			$service,
			$this->session($uid),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * An unauthenticated caller gets 401 from the metrics endpoint.
	 *
	 * @return void
	 */
	public function testIndexRefusesAnUnauthenticatedCaller(): void {
		$service = $this->createMock(AnalyticsService::class);
		$service->expects($this->never())->method('computeAnalytics');

		$response = $this->controller($service, null)->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testIndexRefusesAnUnauthenticatedCaller()

	/**
	 * An unauthenticated caller gets 401 from the run list, and the service is never
	 * reached — the guard is before the read, not inside it.
	 *
	 * @return void
	 */
	public function testRunsRefusesAnUnauthenticatedCaller(): void {
		$service = $this->createMock(AnalyticsService::class);
		$service->expects($this->never())->method('listRuns');

		$response = $this->controller($service, null)->runs();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testRunsRefusesAnUnauthenticatedCaller()

	/**
	 * The metrics endpoint returns the service's payload unchanged.
	 *
	 * @return void
	 */
	public function testIndexReturnsTheMetrics(): void {
		$payload = ['totalRuns' => 3, 'successRate' => 66.7];

		$service = $this->createMock(AnalyticsService::class);
		$service->method('computeAnalytics')->willReturn($payload);

		$response = $this->controller($service, 'admin')->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($payload, $response->getData());
	}//end testIndexReturnsTheMetrics()

	/**
	 * The run list returns the service's page unchanged.
	 *
	 * @return void
	 */
	public function testRunsReturnsThePage(): void {
		$payload = [
			'results' => [['id' => 'run-1', 'status' => 'ok']],
			'total' => 1,
			'limit' => 50,
			'offset' => 0,
		];

		$service = $this->createMock(AnalyticsService::class);
		$service->method('listRuns')->willReturn($payload);

		$response = $this->controller($service, 'admin')->runs();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($payload, $response->getData());
	}//end testRunsReturnsThePage()

	/**
	 * An empty agentId or status means NO filter, not a filter on the empty string.
	 *
	 * Passing '' straight through would match no run at all and render an empty list
	 * that looks exactly like "this agent has never run".
	 *
	 * @return void
	 */
	public function testRunsTreatsEmptyFiltersAsAbsent(): void {
		$service = $this->createMock(AnalyticsService::class);
		$service->expects($this->once())
			->method('listRuns')
			->with(null, null, 50, 0)
			->willReturn(['results' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

		$response = $this->controller($service, 'admin')->runs(agentId: '', status: '');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testRunsTreatsEmptyFiltersAsAbsent()

	/**
	 * A supplied agentId and status reach the service as given.
	 *
	 * @return void
	 */
	public function testRunsPassesSuppliedFiltersThrough(): void {
		$service = $this->createMock(AnalyticsService::class);
		$service->expects($this->once())
			->method('listRuns')
			->with('agent-uuid', 'error', 50, 0)
			->willReturn(['results' => [], 'total' => 0, 'limit' => 50, 'offset' => 0]);

		$this->controller($service, 'admin')->runs(agentId: 'agent-uuid', status: 'error');
	}//end testRunsPassesSuppliedFiltersThrough()

	/**
	 * `limit` is clamped to a maximum, and a nonsensical `limit`/`offset` cannot
	 * reach the service.
	 *
	 * @return void
	 */
	public function testRunsClampsPaging(): void {
		$service = $this->createMock(AnalyticsService::class);
		$service->expects($this->once())
			->method('listRuns')
			->with(null, null, 200, 0)
			->willReturn(['results' => [], 'total' => 0, 'limit' => 200, 'offset' => 0]);

		$this->controller($service, 'admin')->runs(limit: 100000, offset: -5);
	}//end testRunsClampsPaging()

	/**
	 * A service failure is a 500 with a message, not an escaped exception.
	 *
	 * `runs` is a `#[NoAdminRequired]` route, so an uncaught throw would surface a
	 * framework stack trace to any authenticated caller.
	 *
	 * @return void
	 */
	public function testRunsReportsAServiceFailureAsFiveHundred(): void {
		$service = $this->createMock(AnalyticsService::class);
		$service->method('listRuns')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller($service, 'admin')->runs();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testRunsReportsAServiceFailureAsFiveHundred()

	/**
	 * The same for the metrics endpoint.
	 *
	 * @return void
	 */
	public function testIndexReportsAServiceFailureAsFiveHundred(): void {
		$service = $this->createMock(AnalyticsService::class);
		$service->method('computeAnalytics')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller($service, 'admin')->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testIndexReportsAServiceFailureAsFiveHundred()
}//end class
