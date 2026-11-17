<?php

/**
 * Hermiq agent-data migration run.
 *
 * The progress of one MigrateAgentData pass: the object write-path and output it
 * reports to, the table and row being migrated, the source id → uuid maps built
 * across the pass, the dangling references nulled so far, and the conversations
 * reported as unmigratable. Built by MigrateAgentData and never handed out of it.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;

/**
 * One agent-data migration pass.
 *
 * @spec openspec/changes/agent-data-migration/specs/agent-data-migration/spec.md#integer-foreign-keys-are-resolved-to-uuid-references-during-migration
 */
final class AgentDataMigrationRun {

	/**
	 * The target schema slug of the table being migrated.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The source row being migrated.
	 *
	 * @var array<string, mixed>
	 */
	public array $row = [];

	/**
	 * The uuid of the row being migrated.
	 *
	 * @var string
	 */
	public string $uuid = '';

	/**
	 * Source id → uuid per schema slug, for every row read (including
	 * already-migrated ones), so a child table resolves its foreign keys.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $uuids = [];

	/**
	 * Dangling foreign keys nulled so far (reported at the end).
	 *
	 * @var int
	 */
	public int $danglingCount = 0;

	/**
	 * Conversation uuids reported as unmigratable, in this or an earlier run (warn-once).
	 *
	 * @var array<int, string>
	 */
	public array $reportedUnmigratable = [];

	/**
	 * Start the pass.
	 *
	 * @param ObjectService $objectService The OpenRegister object read/write-path.
	 * @param IOutput       $output        Repair output channel.
	 */
	public function __construct(
		public readonly ObjectService $objectService,
		public readonly IOutput $output,
	) {
	}//end __construct()
}//end class
