<?php

/**
 * Unit tests for ReportSubscriptionController: the session user is the subscriber, and refusals keep their status.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Controller\ReportSubscriptionController;
use OCA\Hermiq\Service\ReportSubscriptions;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The three routes behind the Reports page's card actions.
 */
class ReportSubscriptionControllerTest extends TestCase {

	/**
	 * A controller for `$uid` (null: nobody logged in) over `$subscriptions`.
	 *
	 * @param ReportSubscriptions $subscriptions The service.
	 * @param string|null         $uid           The session user.
	 * @param array<string, mixed> $params       Request parameters.
	 *
	 * @return ReportSubscriptionController
	 */
	private function controller(ReportSubscriptions $subscriptions, ?string $uid, array $params = []): ReportSubscriptionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new ReportSubscriptionController(request: $request, subscriptions: $subscriptions, userSession: $session);
	}//end controller()

	/**
	 * Subscribing passes the session user, the route's report and the posted frequency.
	 *
	 * @return void
	 */
	public function testSubscribeIsForTheSessionUser(): void {
		$subscriptions = $this->createMock(ReportSubscriptions::class);
		$subscriptions->expects($this->once())->method('subscribe')
			->with('anna', 'AiOversight', 'monthly')
			->willReturn(['id' => 'sub-1', 'report' => 'AiOversight', 'frequency' => 'monthly', 'status' => 'active']);

		$response = $this->controller(subscriptions: $subscriptions, uid: 'anna', params: ['frequency' => 'monthly', 'person' => 'kees'])
			->subscribe(report: 'AiOversight');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('active', $response->getData()['status']);
	}//end testSubscribeIsForTheSessionUser()

	/**
	 * Without a session every route answers 401 and nothing is asked of the service.
	 *
	 * @return void
	 */
	public function testNobodyLoggedInIsRefused(): void {
		$subscriptions = $this->createMock(ReportSubscriptions::class);
		$subscriptions->expects($this->never())->method($this->anything());
		$controller = $this->controller(subscriptions: $subscriptions, uid: null);

		$this->assertSame(401, $controller->index()->getStatus());
		$this->assertSame(401, $controller->subscribe(report: 'AiOversight')->getStatus());
		$this->assertSame(401, $controller->cancel(report: 'AiOversight')->getStatus());
	}//end testNobodyLoggedInIsRefused()

	/**
	 * A refused argument is a 400 with its reason; any other failure a 500 without detail.
	 *
	 * @return void
	 */
	public function testRefusalsKeepTheirStatus(): void {
		$subscriptions = $this->createMock(ReportSubscriptions::class);
		$subscriptions->method('subscribe')->willThrowException(new InvalidArgumentException('A report is sent weekly or monthly.'));
		$subscriptions->method('cancel')->willThrowException(new RuntimeException('SQLSTATE[HY000] gone'));
		$controller = $this->controller(subscriptions: $subscriptions, uid: 'anna', params: ['frequency' => 'daily']);

		$bad = $controller->subscribe(report: 'AiOversight');
		$this->assertSame(400, $bad->getStatus());
		$this->assertSame('A report is sent weekly or monthly.', $bad->getData()['error']);

		$broken = $controller->cancel(report: 'AiOversight');
		$this->assertSame(500, $broken->getStatus());
		$this->assertStringNotContainsString('SQLSTATE', $broken->getData()['error']);
	}//end testRefusalsKeepTheirStatus()

	/**
	 * The list and the cancel answer what the service says, for the session user.
	 *
	 * @return void
	 */
	public function testIndexAndCancel(): void {
		$subscriptions = $this->createMock(ReportSubscriptions::class);
		$subscriptions->method('mine')->with('anna')->willReturn([['id' => 'sub-1', 'report' => 'AiOversight', 'frequency' => 'weekly', 'status' => 'active']]);
		$subscriptions->method('cancel')->with('anna', 'AiOversight')->willReturn(true);
		$controller = $this->controller(subscriptions: $subscriptions, uid: 'anna');

		$this->assertSame('AiOversight', $controller->index()->getData()[0]['report']);
		$this->assertSame(['cancelled' => true], $controller->cancel(report: 'AiOversight')->getData());
	}//end testIndexAndCancel()
}//end class
