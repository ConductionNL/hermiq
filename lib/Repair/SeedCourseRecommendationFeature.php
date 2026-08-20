<?php

/**
 * Hermiq Seed CourseRecommendation AiFeature Repair Step (ai-course-recommendations).
 *
 * Idempotently seeds the `course-recommendations` `AiFeature` governance object (EU AI
 * Act Annex III §3, high-risk — an education recommender that can materially influence
 * a learner's course/career path) in `lifecycle: disabled`, mirroring
 * `lib/Repair/SeedAiFeatures.php`'s pattern exactly: written through OpenRegister's
 * ObjectService single write-path (ADR-001, ADR-004), system-scoped
 * (`_rbac: false, _multitenancy: false`, `tenantId: ''`) so it is visible fleet-wide
 * for the DPO to acknowledge, and skipped on re-run once the slug exists. An admin/DPO
 * must explicitly acknowledge (`AiFeatureController::acknowledge()`) and enable
 * (`AiFeatureController::enable()`) it before `CourseRecommendationEngine` computes or
 * serves anything (design.md "EU AI Act posture" §1).
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
 * @spec openspec/changes/ai-course-recommendations/tasks.md#5-seed-the-aifeature-governance-data-not-schema
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
 * Seed the `course-recommendations` AiFeature via ObjectService (idempotent).
 *
 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-5-1
 */
class SeedCourseRecommendationFeature implements IRepairStep {

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
	 * The feature slug this step seeds.
	 *
	 * @var string
	 */
	private const FEATURE_SLUG = 'course-recommendations';

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
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-5-1
	 */
	public function getName(): string {
		return 'Seed the course-recommendations AI feature (ai-course-recommendations)';
	}//end getName()

	/**
	 * Seed the `course-recommendations` AiFeature when it does not yet exist.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-5-1
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping course-recommendations AI-feature seed.');
			$this->logger->warning('[hermiq] course-recommendations AiFeature seed skipped: ' . $e->getMessage());
			return;
		}

		try {
			if ($this->slugExists(objectService: $objectService) === true) {
				$output->info('course-recommendations AI feature already exists — skipping.');
				return;
			}

			$objectService->saveObject(
				object: [
					'slug' => self::FEATURE_SLUG,
					'name' => 'Course recommendations',
					'description' => 'A next-best-course recommendation engine that reads a learner\'s '
						. 'enrolment, completion, activity and goal data (from Scholiq) to suggest what to study '
						. 'next. High-risk under EU AI Act Annex III §3 (education and vocational training) '
						. 'because it can materially influence a learner\'s course/career path. Advisory only — '
						. 'ranking is a deterministic weighted-signal score; no automated enrolment.',
					'riskCategory' => 'high',
					'lifecycle' => 'disabled',
					'tenantId' => '',
				],
				register: self::REGISTER_SLUG,
				schema: self::AIFEATURE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			$output->info('Seeded the course-recommendations AI feature (disabled, pending DPO acknowledgement).');
		} catch (Throwable $e) {
			$output->warning('Could not seed the course-recommendations AI feature: ' . $e->getMessage());
			$this->logger->error('[hermiq] course-recommendations AiFeature seed failed: ' . $e->getMessage());
		}//end try

	}//end run()

	/**
	 * Whether the `course-recommendations` AiFeature already exists (system context, no RBAC).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 *
	 * @return bool True when the feature already exists.
	 */
	private function slugExists(ObjectService $objectService): bool {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::AIFEATURE_SCHEMA)
			->findAll(
				config: ['filters' => ['slug' => self::FEATURE_SLUG], 'limit' => 200],
				_rbac: false,
				_multitenancy: false
			);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['slug'] ?? '') === self::FEATURE_SLUG) {
				return true;
			}
		}

		return false;
	}//end slugExists()
}//end class
