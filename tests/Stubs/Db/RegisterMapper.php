<?php

/**
 * Minimal OpenRegister RegisterMapper stub for standalone unit runs.
 *
 * Registered at TEST TIME only by tests/bootstrap.php — see the note there on
 * why these mappings must not live in composer.json `autoload-dev`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal RegisterMapper stub.
 */
class RegisterMapper {

	/**
	 * Resolve a register by id or slug.
	 *
	 * @param string|int $id The register id or slug.
	 *
	 * @return Register The resolved register.
	 */
	public function find(string|int $id): Register {
		return new Register();
	}//end find()

	/**
	 * List registers — mirrors the real mapper's signature
	 * (openregister lib/Db/RegisterMapper.php), used by the agentflow
	 * retirement prune to unlink a deleted schema id.
	 *
	 * @param int|null $limit Maximum rows.
	 * @param int|null $offset First row.
	 * @param array<string,mixed>|null $filters Column filters.
	 * @param array<int,mixed>|null $searchConditions Search conditions.
	 * @param array<string,mixed>|null $searchParams Search parameters.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multi-tenancy applies.
	 *
	 * @return Register[] The registers.
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		return [];
	}//end findAll()

	/**
	 * Persist a changed register — the real mapper inherits
	 * `QBMapper::update(Entity)`; the stub narrows to the one entity hermiq
	 * ever passes.
	 *
	 * @param Register $register The register to update.
	 *
	 * @return Register The updated register.
	 */
	public function update(Register $register): Register {
		return $register;
	}//end update()
}//end class
