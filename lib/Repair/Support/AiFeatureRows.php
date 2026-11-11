<?php

/**
 * Hermiq AI-feature row lookup for the seed repair steps.
 *
 * Every repair step that seeds an `agentaifeature` row first asks whether a row
 * with its slug already exists, in any tenant and in system context (no RBAC, no
 * multitenancy), so a re-run never seeds a duplicate. The register filter is
 * applied by OpenRegister, but each returned row is re-checked for being an
 * entity whose own `slug` matches, because the filter is not trusted alone.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair\Support
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
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Finds an existing `agentaifeature` row by its slug, across every tenant.
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
 */
final class AiFeatureRows {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for AI-feature objects.
	 *
	 * @var string
	 */
	private const AIFEATURE_SCHEMA = 'agentaifeature';

	/**
	 * The existing AI-feature row with this slug, if any (system context, no RBAC).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param string        $slug          The feature slug.
	 *
	 * @return ObjectEntity|null The existing row, or null when there is none.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
	 */
	public static function find(ObjectService $objectService, string $slug): ?ObjectEntity {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::AIFEATURE_SCHEMA)
			->findAll(
				config: ['filters' => ['slug' => $slug], 'limit' => 200],
				_rbac: false,
				_multitenancy: false
			);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['slug'] ?? '') === $slug) {
				return $object;
			}
		}

		return null;
	}//end find()

	/**
	 * Whether an AI-feature row with this slug exists in any tenant.
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param string        $slug          The feature slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
	 */
	public static function exists(ObjectService $objectService, string $slug): bool {
		return self::find(objectService: $objectService, slug: $slug) !== null;
	}//end exists()
}//end class
