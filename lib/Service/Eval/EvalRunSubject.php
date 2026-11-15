<?php

/**
 * Hermiq EvalRunSubject
 *
 * The dataset, agent and organisation an eval run belongs to.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Eval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Eval;

/**
 * The dataset, agent and organisation an eval run belongs to.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class EvalRunSubject {

	/**
	 * Constructor.
	 *
	 * @param string $datasetId The dataset UUID.
	 * @param string $agentId The agent UUID.
	 * @param string $organisation The agent's organisation.
	 * @param string|null $agentVersionId Inert field.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $datasetId,
		public readonly string $agentId,
		public readonly string $organisation,
		public readonly ?string $agentVersionId,
	) {
	}//end __construct()
}//end class
