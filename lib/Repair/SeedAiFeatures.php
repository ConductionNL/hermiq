<?php

/**
 * Hermiq Seed AiFeatures Repair Step.
 *
 * Idempotently seeds a small set of realistic `AiFeature` governance objects (EU AI Act
 * registration inventory) on install/upgrade. Each object is written through
 * OpenRegister's ObjectService single write-path (ADR-001, ADR-004) — never a bespoke
 * insert — so tenancy and the hash-chained AuditTrail are inherited. Re-running is safe:
 * a feature whose `slug` already exists is skipped. Runs after InitializeSettings (which
 * imports the `aifeature` schema); OpenRegister is resolved lazily so the step no-ops
 * gracefully when OpenRegister is not installed.
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
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#5-seed-data-adr-001-adr-003-single-write-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the realistic AiFeature governance objects via ObjectService (idempotent).
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
 */
class SeedAiFeatures implements IRepairStep {

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
	 * @param ContainerInterface $container Server container for lazy ObjectService resolution.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
	 */
	public function getName(): string {
		return 'Seed AI-feature governance register (ai-feature-governance-register)';
	}//end getName()

	/**
	 * Seed the AiFeature objects that do not yet exist (by slug).
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping AI-feature seed.');
			$this->logger->warning('[hermiq] AiFeature seed skipped: ' . $e->getMessage());
			return;
		}

		$seeded = 0;
		foreach ($this->seedFeatures() as $feature) {
			try {
				if ($this->slugExists(objectService: $objectService, slug: $feature['slug']) === true) {
					continue;
				}

				$objectService->saveObject(
					object: $feature,
					register: self::REGISTER_SLUG,
					schema: self::AIFEATURE_SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
				$seeded++;
			} catch (Throwable $e) {
				$output->warning('Could not seed AI feature "' . $feature['slug'] . '": ' . $e->getMessage());
				$this->logger->error('[hermiq] AiFeature seed failed for ' . $feature['slug'] . ': ' . $e->getMessage());
			}//end try
		}//end foreach

		$output->info('AI-feature seed complete (' . $seeded . ' new).');

	}//end run()

	/**
	 * The realistic AiFeature seed payloads.
	 *
	 * @return array<int, array<string, mixed>> The seed objects.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
	 */
	private function seedFeatures(): array {
		return [
			[
				'slug' => 'autonomous-agent-run',
				'name' => 'Autonomous agent run',
				'description' => 'An agent runs autonomously on a schedule and may act without a per-run human prompt.',
				'riskCategory' => 'high',
				'lifecycle' => 'disabled',
				'tenantId' => '',
			],
			[
				'slug' => 'skill-code-execution',
				'name' => 'Skill code execution',
				'description' => 'An installed skill executes code as part of an agent run.',
				'riskCategory' => 'high',
				'lifecycle' => 'disabled',
				'tenantId' => '',
			],
			[
				'slug' => 'chat-companion',
				'name' => 'Chat companion',
				'description' => 'A conversational assistant that answers user questions with limited autonomy.',
				'riskCategory' => 'limited',
				'lifecycle' => 'disabled',
				'tenantId' => '',
			],
		];

	}//end seedFeatures()

	/**
	 * Whether an AiFeature with the given slug already exists (system context, no RBAC).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param string $slug The feature slug.
	 *
	 * @return bool True when a matching object exists.
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
	 */
	private function slugExists(ObjectService $objectService, string $slug): bool {
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
				return true;
			}
		}

		return false;
	}//end slugExists()
}//end class
