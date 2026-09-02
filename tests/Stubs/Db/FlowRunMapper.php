<?php

/**
 * Test stub for OpenRegister's FlowRunMapper.
 *
 * Stands in for OCA\OpenRegister\Db\FlowRunMapper when OpenRegister is not
 * installed (standalone CI). Only `findByUuid()` is declared — the one entry
 * point hermiq's oversight check uses — with the real signature: an unknown
 * uuid THROWS DoesNotExistException rather than answering an empty run.
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
 * Minimal FlowRunMapper stub for standalone static analysis.
 */
class FlowRunMapper {
	/**
	 * Find a run by its public uuid.
	 *
	 * @param string $uuid The run uuid.
	 *
	 * @return FlowRun The run.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException Always, on the stub.
	 */
	public function findByUuid(string $uuid): FlowRun {
		throw new \OCP\AppFramework\Db\DoesNotExistException('stub: no run ' . $uuid);
	}//end findByUuid()
}//end class
