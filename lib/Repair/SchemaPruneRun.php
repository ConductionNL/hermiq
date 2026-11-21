<?php

/**
 * Hermiq schema prune run.
 *
 * The OpenRegister stores and output one PruneRetiredAgentFlowSchemas run works with,
 * resolved once when the run starts. Built by that repair step and never handed out of it.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCP\Migration\IOutput;

/**
 * One schema prune run.
 *
 * @spec openspec/specs/flow-authoring/spec.md#requirement-a-flow-is-stored-once-by-openregister-req-fa-002
 */
final class SchemaPruneRun {

	/**
	 * The run.
	 *
	 * @param SchemaMapper $schemaMapper The schema store.
	 * @param RegisterMapper $registerMapper The register store.
	 * @param SchemaDeletionService $deletionService OpenRegister's cascading schema delete.
	 * @param IOutput $output Repair output channel.
	 */
	public function __construct(
		public readonly SchemaMapper $schemaMapper,
		public readonly RegisterMapper $registerMapper,
		public readonly SchemaDeletionService $deletionService,
		public readonly IOutput $output,
	) {
	}//end __construct()
}//end class
