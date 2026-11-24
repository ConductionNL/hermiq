<?php

/**
 * Hermiq BundledFlowSeed.
 *
 * What one run of SeedBundledFlows works with: the flow store, the organisation the
 * rows go to, the names already present and the preflight (null when OpenRegister
 * has none).
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Db\Flow\FlowSelection;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;

/**
 * One bundled flow seed run.
 *
 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
 */
final class BundledFlowSeed {

	/**
	 * How many existing flows the by-name check reads.
	 *
	 * @var integer
	 */
	private const EXISTING_LIMIT = 1000;

	/**
	 * Names of the flows already in the store, whichever app or organisation holds them,
	 * so a hand-imported flow is never seeded a second time.
	 *
	 * @var array<string, true>
	 */
	public readonly array $existing;

	/**
	 * The run over the store as it is now.
	 *
	 * @param FlowMapper             $mapper       The flow store.
	 * @param string                 $organisation The default organisation's UUID.
	 * @param FlowNodePreflight|null $preflight    The preflight, or null when unavailable.
	 *
	 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
	 */
	public function __construct(
		public readonly FlowMapper $mapper,
		public readonly string $organisation,
		public readonly ?FlowNodePreflight $preflight,
	) {
		$names = [];
		foreach ($mapper->findAllFlows(selection: new FlowSelection(), query: new ListQuery(limit: self::EXISTING_LIMIT, offset: 0)) as $flow) {
			$names[trim((string)$flow->getName())] = true;
		}

		$this->existing = $names;
	}//end __construct()
}//end class
