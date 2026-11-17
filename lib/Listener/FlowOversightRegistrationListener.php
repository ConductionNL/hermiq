<?php

/**
 * Contributes hermiq's kill switch and budget checks to the engine's oversight registry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Flow\OrganisationBudgetCheck;
use OCA\Hermiq\Flow\OrganisationKillSwitchCheck;
use OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers the tenant kill switch and the budget hard cap when the oversight
 * registry discovers.
 *
 * Mirrors `HermiqFlowNodeListener`: the registry dispatches
 * `RegisterFlowOversightEvent` before its first use, and every check
 * contributed here is asked before every hop of every flow run.
 *
 * @template-implements IEventListener<RegisterFlowOversightEvent>
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
 */
class FlowOversightRegistrationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param OrganisationKillSwitchCheck $killSwitchCheck The per-organisation stop control.
	 * @param OrganisationBudgetCheck     $budgetCheck     The per-organisation budget hard cap.
	 */
	public function __construct(
		private readonly OrganisationKillSwitchCheck $killSwitchCheck,
		private readonly OrganisationBudgetCheck $budgetCheck,
	) {

	}//end __construct()

	/**
	 * Contribute hermiq's oversight checks.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-the-tenant-kill-switch-reaches-every-hermiq-flow-hop
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterFlowOversightEvent) === false) {
			return;
		}

		$event->registerCheck(check: $this->killSwitchCheck);
		$event->registerCheck(check: $this->budgetCheck);

	}//end handle()
}//end class
