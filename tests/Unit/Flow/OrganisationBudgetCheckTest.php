<?php

/**
 * Tests hermiq's budget oversight check.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\OrganisationBudgetCheck;
use OCA\Hermiq\Flow\OrganisationKillSwitchCheck;
use OCA\Hermiq\Listener\FlowOversightRegistrationListener;
use OCA\Hermiq\Service\BudgetService;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowOversightRegistry;
use OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Veto semantics: scoped to hermiq hops, per organisation, read from the run row.
 *
 * @covers \OCA\Hermiq\Flow\OrganisationBudgetCheck
 * @uses   \OCA\Hermiq\Listener\FlowOversightRegistrationListener
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class OrganisationBudgetCheckTest extends TestCase {

	/**
	 * Mock BudgetService.
	 *
	 * @var BudgetService&MockObject
	 */
	private BudgetService $budgetService;

	/**
	 * Mock FlowRunMapper handed out by the container.
	 *
	 * @var FlowRunMapper&MockObject
	 */
	private FlowRunMapper $runMapper;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->budgetService = $this->createMock(BudgetService::class);
		$this->runMapper = $this->createMock(FlowRunMapper::class);
	}//end setUp()

	/**
	 * Build the check with the current mocks.
	 *
	 * @return OrganisationBudgetCheck
	 */
	private function check(): OrganisationBudgetCheck {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(FlowRunMapper::class)->willReturn($this->runMapper);

		return new OrganisationBudgetCheck(
			budgetService: $this->budgetService,
			container: $container,
		);
	}//end check()

	/**
	 * Declare a run attributed to the organisation.
	 *
	 * @param string $organisation The run's organisation.
	 *
	 * @return void
	 */
	private function runBelongsTo(string $organisation): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setOrganisation($organisation);
		$this->runMapper->method('findByUuid')->with('run-1')->willReturn($run);
	}//end runBelongsTo()

	/**
	 * The check names itself under hermiq's namespace, as a refusal records it.
	 *
	 * @return void
	 */
	public function testIdIsNamespacedByHermiq(): void {
		$this->assertSame('hermiq.budget', $this->check()->getId());
	}//end testIdIsNamespacedByHermiq()

	/**
	 * Other apps' hops are not hermiq's to veto, even with a spent budget.
	 *
	 * @return void
	 */
	public function testConsentsToNonHermiqHopsWithoutReadingABudget(): void {
		$this->runBelongsTo('org-1');
		$this->budgetService->expects($this->never())->method('isBlocked');

		$this->assertNull($this->check()->veto(context: ['nodeType' => 'openregister.object-write', 'runUuid' => 'run-1']));
		$this->assertNull($this->check()->veto(context: ['runUuid' => 'run-1']));
	}//end testConsentsToNonHermiqHopsWithoutReadingABudget()

	/**
	 * A hermiq hop of an organisation at its hard cap is vetoed with a reason naming the budget.
	 *
	 * @return void
	 */
	public function testVetoesAHopOfAnOrganisationAtItsHardCap(): void {
		$this->runBelongsTo('org-1');
		$this->budgetService->expects($this->once())
			->method('isBlocked')
			->with('org-1')
			->willReturn(true);

		$reason = $this->check()->veto(context: ['nodeType' => 'hermiq.agent-step', 'runUuid' => 'run-1']);

		$this->assertNotNull($reason);
		$this->assertStringContainsString('budget', $reason);
		$this->assertStringContainsString('hard cap', $reason);
	}//end testVetoesAHopOfAnOrganisationAtItsHardCap()

	/**
	 * A hermiq hop of an organisation under its cap runs.
	 *
	 * @return void
	 */
	public function testConsentsUnderTheCap(): void {
		$this->runBelongsTo('org-2');
		$this->budgetService->expects($this->once())
			->method('isBlocked')
			->with('org-2')
			->willReturn(false);

		$this->assertNull($this->check()->veto(context: ['nodeType' => 'hermiq.agent-step', 'runUuid' => 'run-1']));
	}//end testConsentsUnderTheCap()

	/**
	 * The organisation comes from the stored run, never from the context.
	 *
	 * @return void
	 */
	public function testTheContextCannotNameAnotherOrganisation(): void {
		$this->runBelongsTo('org-1');
		$this->budgetService->expects($this->once())
			->method('isBlocked')
			->with('org-1')
			->willReturn(true);

		$reason = $this->check()->veto(
			context: ['nodeType' => 'hermiq.agent-step', 'runUuid' => 'run-1', 'organisation' => 'org-free']
		);

		$this->assertNotNull($reason);
	}//end testTheContextCannotNameAnotherOrganisation()

	/**
	 * A run whose organisation cannot be established matches no budget and consents.
	 *
	 * @return void
	 */
	public function testAnUnattributableHopConsents(): void {
		$this->budgetService->expects($this->never())->method('isBlocked');

		// No run uuid at all.
		$this->assertNull($this->check()->veto(context: ['nodeType' => 'hermiq.agent-step']));

		// A run the mapper cannot find.
		$this->runMapper->method('findByUuid')->willThrowException(new RuntimeException('gone'));
		$this->assertNull($this->check()->veto(context: ['nodeType' => 'hermiq.agent-step', 'runUuid' => 'run-x']));
	}//end testAnUnattributableHopConsents()

	/**
	 * A run stored without an organisation is unattributable.
	 *
	 * @return void
	 */
	public function testAnOrganisationlessRunConsents(): void {
		$this->runBelongsTo('  ');
		$this->budgetService->expects($this->never())->method('isBlocked');

		$this->assertNull($this->check()->veto(context: ['nodeType' => 'hermiq.agent-step', 'runUuid' => 'run-1']));
	}//end testAnOrganisationlessRunConsents()

	/**
	 * The registration listener contributes both checks under their stable ids.
	 *
	 * @return void
	 */
	public function testListenerRegistersTheBudgetCheckBesideTheKillSwitch(): void {
		$registry = new FlowOversightRegistry($this->createMock(LoggerInterface::class));
		$event = new RegisterFlowOversightEvent(registry: $registry);

		$killSwitch = $this->createMock(OrganisationKillSwitchCheck::class);
		$killSwitch->method('getId')->willReturn('hermiq.tenant-killswitch');

		$listener = new FlowOversightRegistrationListener(killSwitchCheck: $killSwitch, budgetCheck: $this->check());
		$listener->handle(event: $event);

		$this->assertArrayHasKey('hermiq.budget', $registry->all());
		$this->assertArrayHasKey('hermiq.tenant-killswitch', $registry->all());
	}//end testListenerRegistersTheBudgetCheckBesideTheKillSwitch()
}//end class
