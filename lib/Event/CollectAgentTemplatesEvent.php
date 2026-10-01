<?php

/**
 * Hermiq CollectAgentTemplatesEvent.
 *
 * Dispatched by hermiq to ask every installed app for the agent templates it
 * offers for itself. A listener in the offering app calls `offer()` with its own
 * app id and a package in the AgentTemplateSerializer format (the JSON the
 * Store's "Export" produces). hermiq imports each offer quarantined and scanned;
 * an organisation admin reviews it before anyone can use it.
 *
 * A collect event carrying data, not a command (hydra ADR-066). An offering app
 * stays installable without hermiq by listening through the class name:
 *
 *     $context->registerEventListener(
 *         'OCA\Hermiq\Event\CollectAgentTemplatesEvent',
 *         OfferHelpAgentListener::class
 *     );
 *
 * @category Event
 * @package  OCA\Hermiq\Event
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
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Collects the agent templates installed apps offer for themselves.
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */
class CollectAgentTemplatesEvent extends Event {

	/**
	 * The offers made so far, in order.
	 *
	 * @var array<int, array{appId: string, package: string}>
	 */
	private array $offers = [];

	/**
	 * Offer one agent template for the calling app.
	 *
	 * Nothing is checked here, so a listener never fails on its own offer: hermiq
	 * checks every offer when it imports them and counts the ones it refuses.
	 *
	 * @param string $appId The offering app's id (the template's `offeredBy`).
	 * @param string $package The template package as JSON (AgentTemplateSerializer format).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
	 */
	public function offer(string $appId, string $package): void {
		$this->offers[] = ['appId' => $appId, 'package' => $package];
	}//end offer()

	/**
	 * The offers made so far.
	 *
	 * @return array<int, array{appId: string, package: string}>
	 *
	 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
	 */
	public function getOffers(): array {
		return $this->offers;
	}//end getOffers()
}//end class
