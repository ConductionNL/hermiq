<?php

/**
 * Test stub for OpenRegister SchemaMapper.
 *
 * Stands in for OCA\OpenRegister\Db\SchemaMapper when OpenRegister is not
 * installed (standalone CI). Mirrors only the `find()` signature Hermiq's
 * agent-object-leaf uses to resolve a schema and read its context allowlist. The
 * real mapper ships with OpenRegister (lib/Db/SchemaMapper.php).
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal SchemaMapper stub for standalone unit runs.
 */
class SchemaMapper
{

    /**
     * Resolve a schema by id/slug — mirrors the real mapper's signature.
     *
     * @param string|int          $id            The schema id or slug.
     * @param array<int,mixed>|null $_extend      Extend config (unused in the stub).
     * @param bool                $_rbac         Whether RBAC applies.
     * @param bool                $_multitenancy Whether multi-tenancy applies.
     *
     * @return Schema The resolved schema.
     */
    public function find(
        string | int $id,
        ?array $_extend=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): Schema {
        return new Schema();
    }//end find()
}//end class
