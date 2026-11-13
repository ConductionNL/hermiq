<?php

/**
 * Hermiq NamedObjectRows.
 *
 * Seeding and installing by name is idempotent only when the "does it already
 * exist?" read is exact: OpenRegister's `name` filter is applied by the
 * register, but each row it answers is re-checked here for being an entity
 * whose own `name` matches character for character, because the filter is not
 * trusted alone and a loosely typed `findAll()` can answer non-entity rows.
 *
 * Shared by the agent-template and example-skill seed repair steps and the
 * skill bundle installer, which each run their own query first.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The rows a query answered, and the first entity among them with an exact `name`.
 * Built by each caller over its own query's result.
 *
 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
 */
final class NamedObjectRows {

	/**
	 * Constructor.
	 *
	 * @param array<int|string, mixed> $objects The rows a `findAll()` answered.
	 */
	public function __construct(
		private readonly array $objects,
	) {
	}//end __construct()

	/**
	 * The first entity whose own `name` equals the given name.
	 *
	 * @param string $name The exact name to match.
	 *
	 * @return ObjectEntity|null The first matching entity, or null when none matches.
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
	 */
	public function first(string $name): ?ObjectEntity {
		foreach ($this->objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['name'] ?? '') === $name) {
				return $object;
			}
		}

		return null;
	}//end first()
}//end class
