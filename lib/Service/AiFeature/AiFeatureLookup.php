<?php

/**
 * Hermiq AiFeatureLookup.
 *
 * Finds a design-time `AiFeature` governance object by its slug: the ordinary read,
 * RBAC and tenancy scoped, and the gate read a delegate engine decides on, which
 * skips RBAC and keeps tenant scoping.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AiFeature
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
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Finds AiFeature objects by slug via OpenRegister.
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-3
 */
class AiFeatureLookup {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for AiFeature objects.
	 *
	 * @var string
	 */
	private const AIFEATURE_SCHEMA = 'agentaifeature';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object reads.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Find the AiFeature with the given slug in the caller's tenant, or null.
	 *
	 * @param string $slug The feature slug.
	 *
	 * @return ObjectEntity|null The AiFeature object, or null.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-3
	 */
	public function findBySlug(string $slug): ?ObjectEntity {
		if ($slug === '') {
			return null;
		}

		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::AIFEATURE_SCHEMA)
			->findAll(config: ['filters' => ['slug' => $slug], 'limit' => 200]);

		return $this->firstWithSlug(objects: $objects, slug: $slug);
	}//end findBySlug()

	/**
	 * Find the AiFeature a delegate engine gates on, whoever the caller is.
	 *
	 * Whether a feature is enabled is instance configuration a DPO decided, not
	 * something the caller must be allowed to read. A consuming app that serves a
	 * portal subject (portaliq's guardian messages) calls the engine with no
	 * Nextcloud session; under RBAC the row reads as absent and the gate answers
	 * "feature-not-enabled" even after the DPO enabled it. This read skips RBAC
	 * and keeps tenant scoping. The row itself is never returned to a client.
	 *
	 * @param string $slug The feature slug.
	 *
	 * @return ObjectEntity|null The AiFeature object, or null.
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-011-the-gate-answers-for-a-caller-without-a-nextcloud-session
	 */
	public function findBySlugForGate(string $slug): ?ObjectEntity {
		if ($slug === '') {
			return null;
		}

		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::AIFEATURE_SCHEMA)
			->findAll(config: ['filters' => ['slug' => $slug], 'limit' => 200], _rbac: false);

		return $this->firstWithSlug(objects: $objects, slug: $slug);
	}//end findBySlugForGate()

	/**
	 * The first object in `$objects` whose slug is exactly `$slug`.
	 *
	 * @param iterable<mixed> $objects The candidates.
	 * @param string $slug The slug to match.
	 *
	 * @return ObjectEntity|null The match, or null.
	 */
	private function firstWithSlug(iterable $objects, string $slug): ?ObjectEntity {
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['slug'] ?? '') === $slug) {
				return $object;
			}
		}

		return null;
	}//end firstWithSlug()
}//end class
