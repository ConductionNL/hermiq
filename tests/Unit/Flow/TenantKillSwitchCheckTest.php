<?php

/**
 * Tests hermiq's contributed oversight check.
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
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\TenantKillSwitchCheck;
use OCA\Hermiq\Listener\FlowOversightRegistrationListener;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowOversightRegistry;
use OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Veto semantics: scoped to hermiq hops, fail-closed on attribution.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
 */
class TenantKillSwitchCheckTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

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
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->runMapper = $this->createMock(FlowRunMapper::class);
	}//end setUp()

	/**
	 * Build the check with the current mocks.
	 *
	 * @return TenantKillSwitchCheck
	 */
	private function check(): TenantKillSwitchCheck {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->runMapper);

		return new TenantKillSwitchCheck(
			objectService: $this->objectService,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end check()

	/**
	 * Declare one engaged TenantControl for the organisation.
	 *
	 * @param string $organisation The engaged organisation.
	 *
	 * @return void
	 */
	private function engage(string $organisation): void {
		$control = new ObjectEntity();
		$control->setOrganisation($organisation);
		$control->setObject(['engaged' => true]);
		$this->objectService->method('findAll')->willReturn([$control]);
	}//end engage()

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
		$this->runMapper->method('findByUuid')->willReturn($run);
	}//end runBelongsTo()

	/**
	 * Other apps' hops are not hermiq's to veto, engaged or not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	public function testConsentsToNonHermiqHops(): void {
		$this->engage('org-1');

		$this->assertNull(
			$this->check()->veto(context: ['nodeType' => 'openregister.object-write', 'runUuid' => 'run-1'])
		);

	}//end testConsentsToNonHermiqHops()

	/**
	 * No engaged switch means consent on the cheap path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	public function testConsentsWhenNothingIsEngaged(): void {
		$this->objectService->method('findAll')->willReturn([]);

		$this->assertNull(
			$this->check()->veto(context: ['nodeType' => 'hermiq.agent-step', 'runUuid' => 'run-1'])
		);

	}//end testConsentsWhenNothingIsEngaged()

	/**
	 * An engaged organisation's hermiq hop is vetoed with a reason naming the
	 * kill switch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	public function testVetoesAnEngagedOrganisationsHop(): void {
		$this->engage('org-1');
		$this->runBelongsTo('org-1');

		$reason = $this->check()->veto(context: ['nodeType' => 'hermiq.agent-step', 'runUuid' => 'run-1']);

		$this->assertNotNull($reason);
		$this->assertStringContainsString('kill switch', $reason);

	}//end testVetoesAnEngagedOrganisationsHop()

	/**
	 * Another organisation's hop keeps running: the veto is per tenant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	public function testConsentsToAnotherOrganisationsHop(): void {
		$this->engage('org-1');
		$this->runBelongsTo('org-2');

		$this->assertNull(
			$this->check()->veto(context: ['nodeType' => 'hermiq.agent-step', 'runUuid' => 'run-1'])
		);

	}//end testConsentsToAnotherOrganisationsHop()

	/**
	 * While any switch is engaged, an unattributable hermiq hop is vetoed: a
	 * kill switch that cannot tell whose run it sees must stop it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	public function testVetoesAnUnattributableHopWhileEngaged(): void {
		$this->engage('org-1');

		$reason = $this->check()->veto(context: ['nodeType' => 'hermiq.agent-step']);

		$this->assertNotNull($reason);
		$this->assertStringContainsString('could not be established', $reason);

	}//end testVetoesAnUnattributableHopWhileEngaged()

	/**
	 * A TenantControl read failure consents (logged): the dispatcher's own
	 * documented fail-open-on-read choice, so a transient outage never halts
	 * every tenant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	public function testReadFailureConsents(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db gone'));

		$this->assertNull(
			$this->check()->veto(context: ['nodeType' => 'hermiq.agent-step', 'runUuid' => 'run-1'])
		);

	}//end testReadFailureConsents()

	/**
	 * The registration listener contributes the check under its stable id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	public function testListenerRegistersTheCheck(): void {
		$registry = new FlowOversightRegistry();
		$event = new RegisterFlowOversightEvent(registry: $registry);

		$listener = new FlowOversightRegistrationListener(killSwitchCheck: $this->check());
		$listener->handle(event: $event);

		$this->assertArrayHasKey('hermiq.tenant-killswitch', $registry->all());

	}//end testListenerRegistersTheCheck()
}//end class
