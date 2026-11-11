<?php

/**
 * Golden tests for OrganisationOpsController's gated routes.
 *
 * Pins the allowed and refused outcomes of every action-gated route (an anonymous
 * caller 401 before any action check, a refused caller 403 with the refusal message
 * and no service call, an allowed caller checked against the route's own action),
 * and the exact incident the create route hands the service.
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
 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-7-tenantopscontroller-incidents-retention-endpoints
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\OrganisationOpsController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\OrganisationOpsService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the gated organisation-ops routes.
 *
 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-7-tenantopscontroller-incidents-retention-endpoints
 */
class OrganisationOpsControllerAccessGoldenTest extends TestCase {

	/**
	 * The action names asked.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Every gated route with its action and the arguments it takes.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array}>
	 */
	public static function routes(): array {
		return [
			'attest' => ['attestReview', 'tenantops.attest-review', ['a-1']],
			'reassign' => ['reassignAgent', 'tenantops.reassign-agent', ['a-1']],
			'incident' => ['createIncident', 'tenantops.create-incident', []],
			'retention' => ['updateRetention', 'tenantops.update-retention', []],
		];
	}//end routes()

	/**
	 * A controller over the given caller, decision, params and service.
	 *
	 * @param string|null $uid The caller, or null.
	 * @param bool $allowed Whether the action check passes.
	 * @param array<string, mixed> $params The request params.
	 * @param OrganisationOpsService|null $service The service.
	 *
	 * @return OrganisationOpsController
	 */
	private function controller(?string $uid, bool $allowed, array $params = [], ?OrganisationOpsService $service = null): OrganisationOpsController {
		$this->asked = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('requireAction')->willReturnCallback(
			function ($caller, string $action) use ($allowed): void {
				$this->asked[] = $action;
				if ($allowed === false) {
					throw new OCSForbiddenException('not yours');
				}
			}
		);

		return new OrganisationOpsController($request, ($service ?? $this->createMock(OrganisationOpsService::class)), $session, $auth, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * A service no refused call may reach.
	 *
	 * @return OrganisationOpsService
	 */
	private function untouched(): OrganisationOpsService {
		$service = $this->createMock(OrganisationOpsService::class);
		foreach (['attestAgentReviewed', 'reassignAgent', 'createIncident', 'setRetentionMonths'] as $method) {
			$service->expects($this->never())->method($method);
		}

		return $service;
	}//end untouched()

	/**
	 * Anonymous: 401, no action check, no service call.
	 *
	 * @param string $route The route.
	 * @param string $action Its action.
	 * @param array $args Its arguments.
	 *
	 * @return void
	 *
	 * @dataProvider routes
	 */
	public function testAnonymousIsUnauthenticated(string $route, string $action, array $args): void {
		$response = $this->controller(null, true, [], $this->untouched())->{$route}(...$args);
		$this->assertSame([401, ['error' => 'Unauthenticated']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([], $this->asked);
	}//end testAnonymousIsUnauthenticated()

	/**
	 * Refused: 403 with the message, the route's own action asked, no service call.
	 *
	 * @param string $route The route.
	 * @param string $action Its action.
	 * @param array $args Its arguments.
	 *
	 * @return void
	 *
	 * @dataProvider routes
	 */
	public function testARefusedCallerIsForbidden(string $route, string $action, array $args): void {
		$response = $this->controller('mallory', false, ['description' => 'd', 'impact' => 'i', 'actionsTaken' => 'a', 'actingUser' => 'u'], $this->untouched())->{$route}(...$args);
		$this->assertSame([403, ['error' => 'not yours']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([$action], $this->asked);
	}//end testARefusedCallerIsForbidden()

	/**
	 * The exact incident the create route hands the service, and its failure.
	 *
	 * @return void
	 */
	public function testTheIncidentHandedToTheService(): void {
		$calls = [];
		$service = $this->createMock(OrganisationOpsService::class);
		$service->method('createIncident')->willReturnCallback(
			function (...$args) use (&$calls): array {
				$calls[] = $args;
				return ['uuid' => 'inc-1'];
			}
		);

		$response = $this->controller('alice', true, ['description' => 'd', 'impact' => 'i', 'actionsTaken' => 'a', 'linkedAgentId' => 42, 'linkedRunIds' => 'x'], $service)->createIncident();
		$this->assertSame([201, ['uuid' => 'inc-1']], [$response->getStatus(), $response->getData()]);
		$this->controller('alice', true, ['description' => 'd', 'impact' => 'i', 'actionsTaken' => 'a', 'linkedRunIds' => ['r-1']], $service)->createIncident();
		$this->assertSame([['d', 'i', 'a', '42', [], 'alice'], ['d', 'i', 'a', null, ['r-1'], 'alice']], $calls);

		$failing = $this->createMock(OrganisationOpsService::class);
		$failing->method('createIncident')->willThrowException(new RuntimeException('db'));
		$response = $this->controller('alice', true, ['description' => 'd', 'impact' => 'i', 'actionsTaken' => 'a'], $failing)->createIncident();
		$this->assertSame([500, ['error' => 'Could not create the incident']], [$response->getStatus(), $response->getData()]);

		$response = $this->controller('alice', true, ['description' => 'd', 'impact' => 'i'], $failing)->createIncident();
		$this->assertSame([422, ['error' => 'description, impact, and actionsTaken are required']], [$response->getStatus(), $response->getData()]);
	}//end testTheIncidentHandedToTheService()
}//end class
