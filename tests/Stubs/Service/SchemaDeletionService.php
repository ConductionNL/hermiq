<?php

/**
 * Test stub for OpenRegister SchemaDeletionService.
 *
 * Stands in for OCA\OpenRegister\Service\SchemaDeletionService when OpenRegister
 * is not installed (standalone CI). Mirrors only `cascadeDeleteSchema()`, which
 * the agentflow retirement prune calls to tear down a retired schema. The real
 * service ships with OpenRegister (lib/Service/SchemaDeletionService.php).
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;

/**
 * Minimal SchemaDeletionService stub for standalone unit runs.
 */
class SchemaDeletionService {

	/**
	 * Cascade-delete a schema: its objects, its magic tables, and the schema
	 * itself — mirrors the real service's signature and result shape.
	 *
	 * @param Schema $schema The schema to tear down.
	 *
	 * @return array{deletedCount: int, deletedUuids: array<int, string>, tableDropped: bool} The cascade result.
	 */
	public function cascadeDeleteSchema(Schema $schema): array {
		return [
			'deletedCount' => 0,
			'deletedUuids' => [],
			'tableDropped' => false,
		];
	}//end cascadeDeleteSchema()
}//end class
