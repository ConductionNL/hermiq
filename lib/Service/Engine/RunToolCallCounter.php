<?php

/**
 * Hermiq: count the tool calls of one run on the CLI runner's MCP path.
 *
 * On the `cli` transport the model calls Hermiq's MCP endpoint once per tool
 * call, so no single PHP request sees the whole turn. The count lives in the
 * distributed cache under the run id from the verified run token
 * (agents-switch-off-and-stop). Without a distributed cache that can count
 * (IMemcache) or a run id there is nothing to count, and the call is admitted.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * The tool call count of a run, across MCP requests.
 *
 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
 */
class RunToolCallCounter {

	/**
	 * How long a run's count is kept, in seconds; a turn is far shorter.
	 */
	private const TTL = 3600;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Nextcloud's cache factory.
	 */
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()

	/**
	 * Count one tool call of the run and say whether it is within the cap.
	 *
	 * @param string  $runId The run, from the verified run token.
	 * @param integer $cap   Tool calls the turn may make.
	 *
	 * @return bool True to run the call.
	 *
	 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
	 */
	public function admit(string $runId, int $cap): bool {
		if ($runId === '' || $this->cacheFactory->isAvailable() === false) {
			return true;
		}

		$cache = $this->cacheFactory->createDistributed('hermiq-run-tool-calls');
		if (($cache instanceof IMemcache) === false) {
			return true;
		}

		$key = 'run-' . $runId;
		$cache->add($key, 0, self::TTL);
		$count = $cache->inc($key);
		if ($count === false) {
			return true;
		}

		return $count <= $cap;
	}//end admit()

}//end class
