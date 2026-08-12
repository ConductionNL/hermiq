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
}//end class
