<?php

/**
 * Hermiq FindsSeedObjectByName trait.
 *
 * The exact-name lookup the seed repair steps use to stay idempotent: the
 * first object of a schema in the hermiq register whose name equals the given
 * one, read in system context.
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Exact-name lookup for seed repair steps; the using class defines REGISTER_SLUG.
 */
trait FindsSeedObjectByName {

	/**
	 * Find an object by exact name in the given schema (system context, no RBAC),
	 * or null when absent.
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param string        $schema        The schema slug to search.
	 * @param string        $name          The exact object name.
	 *
	 * @return ObjectEntity|null The matching object, or null.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/tasks.md
	 */
	private function findByName(ObjectService $objectService, string $schema, string $name): ?ObjectEntity {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema($schema)
			->findAll(
				config: ['filters' => ['name' => $name], 'limit' => 50],
				_rbac: false,
				_multitenancy: false
			);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['name'] ?? '') === $name) {
				return $object;
			}
		}

		return null;
	}//end findByName()
}//end trait
