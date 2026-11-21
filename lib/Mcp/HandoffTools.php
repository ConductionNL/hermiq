<?php

/**
 * Hermiq HandoffTools.
 *
 * The services behind the tools that start work an agent does not wait for:
 * a task on a remote agent (`askRemoteAgent`, flows-hand-off-and-wait) and a
 * deep research run (`startResearch`, tools-deep-research-report). Either may
 * be absent, and its tool then answers that it is unavailable.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\Service\RemoteAgent\RemoteTasks;
use OCA\Hermiq\Service\Research\ResearchStarter;

/**
 * Holds the services of the work-starting tools.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
final class HandoffTools {
	/**
	 * Constructor.
	 *
	 * @param RemoteTasks|null     $remoteTasks     Backs `askRemoteAgent`.
	 * @param ResearchStarter|null $researchStarter Backs `startResearch`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?RemoteTasks $remoteTasks = null,
		public readonly ?ResearchStarter $researchStarter = null,
	) {
	}//end __construct()
}//end class
