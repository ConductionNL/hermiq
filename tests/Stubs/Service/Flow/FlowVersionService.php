<?php

/**
 * Test stub for OpenRegister's FlowVersionService.
 *
 * Stands in for OCA\OpenRegister\Service\Flow\FlowVersionService when
 * OpenRegister is not installed (standalone CI). Only the two entry points the
 * ScheduleFlowBridge calls are declared, with the real signatures: `publish()`
 * moves the flow's head to the published lifecycle (deprecating nothing here,
 * there is no store) and `createDraft()` moves it to draft.
 *
 * The stub MODELS THE PIN'S CONTRACT rather than being a hollow shell: the
 * real `publish()` sets the flow's `lifecycleStatus` to published, and
 * OpenRegister's `FlowRunVersionPin` refuses every scheduled dispatch of a
 * flow that is not. A stub whose publish did nothing would let a bridge that
 * never publishes pass its tests, which is exactly the defect the tests exist
 * to catch (an engine mirror the pin refused on every tick until a manual
 * publish). The tests therefore assert the flow's lifecycle, and this stub is
 * the only thing that can put it there.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowVersion;

/**
 * Minimal FlowVersionService stub for standalone static analysis.
 */
class FlowVersionService {

	/**
	 * Publish the flow's head, as the real service does.
	 *
	 * @param Flow $flow The flow whose head is being published.
	 * @param string|null $publishedBy The acting user.
	 *
	 * @return FlowVersion The now-published version.
	 */
	public function publish(Flow $flow, ?string $publishedBy = null): FlowVersion {
		$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);

		$version = new FlowVersion();
		$version->setFlowUuid((string)$flow->getUuid());
		$version->setStatus(FlowVersion::STATUS_PUBLISHED);

		return $version;
	}//end publish()

	/**
	 * Create a draft head, as the real service does.
	 *
	 * @param Flow $flow The flow to draft from.
	 *
	 * @return FlowVersion The new draft version row.
	 */
	public function createDraft(Flow $flow): FlowVersion {
		$flow->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		$version = new FlowVersion();
		$version->setFlowUuid((string)$flow->getUuid());
		$version->setStatus(FlowVersion::STATUS_DRAFT);

		return $version;
	}//end createDraft()
}//end class
