<?php

/**
 * Publishes mirror flows so OpenRegister's version pin will run them.
 *
 * OpenRegister versions flow definitions, and its `FlowRunVersionPin` refuses
 * every scheduled dispatch of a flow with no published version. A mirror the
 * bridge only inserts is therefore a clock that never ticks. This class owns
 * the lifecycle side of that fact: publish a head, draft it before a
 * redefinition, and answer whether the pin would refuse a flow right now.
 * Everything resolves lazily through the container and is guarded on the
 * version service existing, because an OpenRegister without flow versioning
 * has no pin and nothing to publish.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use Psr\Container\ContainerInterface;

/**
 * The lifecycle seam between the schedule bridge and flow versioning.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */
class ScheduleFlowPublisher {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Lazy FlowVersionService resolution:
	 *                                      an OpenRegister without flow
	 *                                      versioning must keep booting, so
	 *                                      the version classes are never hard
	 *                                      constructor dependencies.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * Publish the flow's head so the engine's version pin accepts it.
	 *
	 * A head that is not a draft (a deprecated mirror, or a null lifecycle
	 * column from before versioning) is drafted first, because the version
	 * service refuses to publish anything else. A failure propagates: the
	 * caller decides whether that unmirrors the flow (create) or leaves a
	 * draft head for the next pass to heal (refresh). Swallowing it here
	 * would recreate the defect this class exists to close, a mirror the pin
	 * refuses on every tick.
	 *
	 * @param Flow $flow The mirror flow whose head must be published.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function publishHead(Flow $flow): void {
		if (class_exists(FlowVersionService::class) === false) {
			return;
		}

		$versions = $this->container->get(FlowVersionService::class);

		$state = $flow->getLifecycleStatus();
		if ($state === FlowVersion::STATUS_PUBLISHED) {
			return;
		}

		if ($state !== FlowVersion::STATUS_DRAFT) {
			$versions->createDraft(flow: $flow);
		}

		// No interactive user publishes a mirror: the bridge does, as the
		// standing consequence of the owner's schedule. `publishedBy` null is
		// the honest record of that.
		$versions->publish(flow: $flow, publishedBy: null);

	}//end publishHead()

	/**
	 * Draft the head of a published flow before its definition changes.
	 *
	 * The engine runs the pinned published version, not the flow row, so a
	 * redefinition must land as draft, update, publish. Drafting first flips
	 * the head off `published`, which makes a crash between the update and
	 * the republish visible as an unpublished head the next sync pass heals;
	 * the old published version keeps serving in between.
	 *
	 * @param Flow $flow The mirror flow about to be redefined.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function draftForRedefinition(Flow $flow): void {
		if (class_exists(FlowVersionService::class) === false) {
			return;
		}

		if ($flow->getLifecycleStatus() !== FlowVersion::STATUS_PUBLISHED) {
			return;
		}

		$this->container->get(FlowVersionService::class)->createDraft(flow: $flow);

	}//end draftForRedefinition()

	/**
	 * Whether the engine's version pin would refuse this flow right now.
	 *
	 * @param Flow $flow The mirror flow.
	 *
	 * @return boolean True when versioning exists and the head is unpublished.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function lacksPublishedVersion(Flow $flow): bool {
		return (class_exists(FlowVersionService::class) === true
			&& $flow->getLifecycleStatus() !== FlowVersion::STATUS_PUBLISHED);

	}//end lacksPublishedVersion()
}//end class
