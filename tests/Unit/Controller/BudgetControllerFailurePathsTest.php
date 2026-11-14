<?php

/**
 * Unit tests for BudgetController's failure paths.
 *
 * BudgetControllerTest pins who may read and change a budget. What this file
 * pins is what each endpoint answers when the budget service fails behind an
 * allowed caller: a validation failure is a 400 with its message, a vanished
 * budget on update is a 404, anything else is a 500 that is logged and says
 * nothing about the cause. It also pins that the status and estimate reads are
 * closed to an anonymous caller.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Controller\BudgetController;
use OCA\Hermiq\Controller\OrganisationAdministrators;
use OCA\Hermiq\Controller\OrganisationPolicyGate;
use OCA\Hermiq\Service\BudgetService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The budget endpoints when the service behind them fails.
 */
#[CoversClass(BudgetController::class)]
#[UsesClass(OrganisationAdministrators::class)]
#[UsesClass(OrganisationPolicyGate::class)]
class BudgetControllerFailurePathsTest extends TestCase {

	/**
	 * The controller for an instance administrator, or for nobody.
	 *
	 * @param BudgetService        $service The budget service.
	 * @param array<string, mixed> $options `anonymous`, `logger`, `objects`, `params`.
	 *
	 * @return BudgetController The controller.
	 */
	private function controller(BudgetService $service, array $options=[]): BudgetController {
		$session = $this->createMock(IUserSession::class);
		if (($options['anonymous'] ?? false) === true) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('admin');
			$session->method('getUser')->willReturn($user);
		}

		$params = $options['params'] ?? ['organisation' => 'org-a', 'tokenLimit' => 5000];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		return new BudgetController(
			$request,
			$service,
			$session,
			$groupManager,
			$this->createMock(OrganisationMapper::class),
			$options['objects'] ?? $this->createMock(ObjectService::class),
			$options['logger'] ?? $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A logger that expects exactly this one error message.
	 *
	 * @param string $message The message.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function loggerExpecting(string $message): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($message);
		return $logger;
	}//end loggerExpecting()

	/**
	 * An existing budget of one organisation.
	 *
	 * @return ObjectEntity The budget.
	 */
	private function existing(): ObjectEntity {
		$budget = new ObjectEntity();
		$budget->setUuid('b1');
		$budget->setOrganisation('org-a');
		return $budget;
	}//end existing()

	/**
	 * A failing list is a logged 500.
	 *
	 * @return void
	 */
	public function testAFailingListIsALogged500(): void {
		$service = $this->createMock(BudgetService::class);
		$service->method('listForCaller')->willThrowException(new RuntimeException('db down'));

		$response = $this->controller($service, ['logger' => $this->loggerExpecting('Hermiq budget list failed: db down')])->index('org-a');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load budgets'], $response->getData());
	}//end testAFailingListIsALogged500()

	/**
	 * A status without an agent asks for the organisation's own scope.
	 *
	 * @return void
	 */
	public function testAStatusWithoutAnAgentAsksForTheOrganisationScope(): void {
		$service = $this->createMock(BudgetService::class);
		$service->expects($this->once())->method('statusForScope')->with('org-a', null)->willReturn(['used' => 10]);

		$this->assertSame(['used' => 10], $this->controller($service)->status('org-a')->getData());
	}//end testAStatusWithoutAnAgentAsksForTheOrganisationScope()

	/**
	 * A failing status is a logged 500.
	 *
	 * @return void
	 */
	public function testAFailingStatusIsALogged500(): void {
		$service = $this->createMock(BudgetService::class);
		$service->method('statusForScope')->willThrowException(new RuntimeException('timeout'));

		$response = $this->controller($service, ['logger' => $this->loggerExpecting('Hermiq budget status failed: timeout')])->status('org-a', 'agent-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load budget status'], $response->getData());
	}//end testAFailingStatusIsALogged500()

	/**
	 * Nobody signed in gets no estimate, and a failing estimate is a logged 500.
	 *
	 * @return void
	 */
	public function testTheEstimateIsClosedAndFailsAsALogged500(): void {
		$closed = $this->createMock(BudgetService::class);
		$closed->expects($this->never())->method('estimateNextRun');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($closed, ['anonymous' => true])->estimate('agent-1')->getStatus());

		$service = $this->createMock(BudgetService::class);
		$service->method('estimateNextRun')->willThrowException(new RuntimeException('no pricing'));
		$response = $this->controller($service, ['logger' => $this->loggerExpecting('Hermiq budget estimate failed: no pricing')])->estimate('agent-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load the cost estimate'], $response->getData());
	}//end testTheEstimateIsClosedAndFailsAsALogged500()

	/**
	 * A create that fails for another reason than validation is a logged 500.
	 *
	 * @return void
	 */
	public function testAFailingCreateIsALogged500(): void {
		$service = $this->createMock(BudgetService::class);
		$service->expects($this->once())->method('create')
			->with(['organisation' => 'org-a', 'tokenLimit' => 5000], 'org-a')
			->willThrowException(new RuntimeException('register missing'));

		$response = $this->controller($service, ['logger' => $this->loggerExpecting('Hermiq budget create failed: register missing')])->create();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not create budget'], $response->getData());
	}//end testAFailingCreateIsALogged500()

	/**
	 * Nobody signed in updates or deletes nothing, and the budget is not even looked up.
	 *
	 * @return void
	 */
	public function testNobodySignedInChangesNothing(): void {
		$service = $this->createMock(BudgetService::class);
		$service->expects($this->never())->method('findById');
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('deleteObject');

		$controller = $this->controller($service, ['anonymous' => true, 'objects' => $objects]);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->update('b1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->destroy('b1')->getStatus());
	}//end testNobodySignedInChangesNothing()

	/**
	 * An invalid update is a 400 with the validation message.
	 *
	 * @return void
	 */
	public function testAnInvalidUpdateIsA400WithItsMessage(): void {
		$service = $this->createMock(BudgetService::class);
		$service->method('findById')->willReturn($this->existing());
		$service->expects($this->once())->method('update')
			->with('b1', ['organisation' => 'org-a', 'tokenLimit' => 5000])
			->willThrowException(new InvalidArgumentException('tokenLimit must be positive'));

		$response = $this->controller($service)->update('b1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'tokenLimit must be positive'], $response->getData());
	}//end testAnInvalidUpdateIsA400WithItsMessage()

	/**
	 * A budget that vanishes during the update is a 404 with the service's message.
	 *
	 * @return void
	 */
	public function testABudgetThatVanishesDuringTheUpdateIsA404(): void {
		$service = $this->createMock(BudgetService::class);
		$service->method('findById')->willReturn($this->existing());
		$service->method('update')->willThrowException(new RuntimeException('Budget b1 not found'));

		$response = $this->controller($service)->update('b1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Budget b1 not found'], $response->getData());
	}//end testABudgetThatVanishesDuringTheUpdateIsA404()

	/**
	 * Any other update failure is a logged 500.
	 *
	 * @return void
	 */
	public function testAnyOtherUpdateFailureIsALogged500(): void {
		$service = $this->createMock(BudgetService::class);
		$service->method('findById')->willReturn($this->existing());
		$service->method('update')->willThrowException(new \Error('type mismatch'));

		$response = $this->controller($service, ['logger' => $this->loggerExpecting('Hermiq budget update failed: type mismatch')])->update('b1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not update budget'], $response->getData());
	}//end testAnyOtherUpdateFailureIsALogged500()

	/**
	 * A delete removes the budget object without RBAC or tenancy filters, as the gate already ran.
	 *
	 * @return void
	 */
	public function testADeleteRemovesTheBudgetObject(): void {
		$service = $this->createMock(BudgetService::class);
		$service->method('findById')->willReturn($this->existing());
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())->method('deleteObject')
			->with('b1', BudgetService::REGISTER_SLUG, BudgetService::BUDGET_SCHEMA, false, false)
			->willReturn(true);

		$response = $this->controller($service, ['objects' => $objects])->destroy('b1');

		$this->assertSame(['deleted' => true], $response->getData());
	}//end testADeleteRemovesTheBudgetObject()

	/**
	 * A failing delete is a logged 500.
	 *
	 * @return void
	 */
	public function testAFailingDeleteIsALogged500(): void {
		$service = $this->createMock(BudgetService::class);
		$service->method('findById')->willReturn($this->existing());
		$objects = $this->createMock(ObjectService::class);
		$objects->method('deleteObject')->willThrowException(new RuntimeException('locked'));

		$response = $this->controller($service, ['objects' => $objects, 'logger' => $this->loggerExpecting('Hermiq budget delete failed: locked')])->destroy('b1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not delete budget'], $response->getData());
	}//end testAFailingDeleteIsALogged500()
}//end class
