<?php

/**
 * Hermiq Seed LessonAuthoring AiFeature Repair Step (lesson-authoring-ai-delegate).
 *
 * Idempotently seeds the `lesson-authoring` `AiFeature` governance object in
 * `lifecycle: disabled`, at EU AI Act limited risk: the four actions draft lesson
 * content for a teacher and decide nothing about a pupil. Mirrors
 * `SeedMessageTranslationFeature` and `SeedCourseRecommendationFeature`: written
 * through OpenRegister's ObjectService single write-path (ADR-001, ADR-004),
 * system-scoped (`_rbac: false, _multitenancy: false`, `tenantId: ''`) so any DPO
 * can acknowledge it, and skipped on re-run once the slug exists. An admin must
 * enable it after the DPO acknowledgement before `LessonAuthoringEngine` calls
 * any provider.
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
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-001-lesson-authoring-is-gated-by-a-disabled-by-default-aifeature
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
 * Seed the `lesson-authoring` AiFeature via ObjectService (idempotent).
 *
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-001-lesson-authoring-is-gated-by-a-disabled-by-default-aifeature
 */
class SeedLessonAuthoringFeature implements IRepairStep {
	use \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;

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
	private const FEATURE_SLUG = 'lesson-authoring';

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
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-001-lesson-authoring-is-gated-by-a-disabled-by-default-aifeature
	 */
	public function getName(): string {
		return 'Seed the lesson-authoring AI feature (lesson-authoring-ai-delegate)';
	}//end getName()

	/**
	 * Seed the `lesson-authoring` AiFeature when it does not yet exist.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-001-lesson-authoring-is-gated-by-a-disabled-by-default-aifeature
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available, skipping the lesson-authoring AI feature seed.');
			$this->logger->warning('[hermiq] lesson-authoring AiFeature seed skipped: ' . $e->getMessage());
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous' further down the call chain even when
		// the write itself passes `_rbac: false`.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->seedFeature(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * Seed the lesson-authoring feature when it is not present yet.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 */
	private function seedFeature(object $objectService, IOutput $output): void {
		try {
			if ($this->slugExists(objectService: $objectService) === true) {
				$output->info('lesson-authoring AI feature already exists, skipping.');
				return;
			}

			$objectService->saveObject(
				object: $this->featurePayload(),
				register: self::REGISTER_SLUG,
				schema: self::AIFEATURE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			$output->info('Seeded the lesson-authoring AI feature (disabled, pending DPO acknowledgement).');
		} catch (Throwable $e) {
			$output->warning('Could not seed the lesson-authoring AI feature: ' . $e->getMessage());
			$this->logger->error('[hermiq] lesson-authoring AiFeature seed failed: ' . $e->getMessage());
		}//end try

	}//end seedFeature()

	/**
	 * The governance row this step seeds.
	 *
	 * `requiresRedaction` is left unset on purpose: it means "refuses a document
	 * filinq has not redacted", and this delegate reads no filinq document.
	 *
	 * @return array<string, string> The AiFeature payload.
	 */
	private function featurePayload(): array {
		return [
			'slug' => self::FEATURE_SLUG,
			'name' => 'Lesson authoring assistance',
			'description' => 'Drafts a lesson outline from learning goals, suggests questions, rewrites a text at a '
				. 'lower reading level and suggests which given goals a lesson covers. Input is lesson text and goal '
				. 'titles only, never pupil data. Every output is a draft a teacher accepts. Limited risk under the EU '
				. 'AI Act: it drafts content and decides nothing about a pupil.',
			'riskCategory' => 'limited',
			'lifecycle' => 'disabled',
			'tenantId' => '',
			'doel' => 'Help teachers draft lesson material faster, while the teacher stays the author.',
			'dataBronnen' => 'Lesson text and learning goal titles the teacher supplies. No pupil data.',
			'humanIntervention' => 'Every output is a draft. The teacher accepts, edits or discards it before '
				. 'anything is saved or published.',
		];
	}//end featurePayload()

	/**
	 * Whether the `lesson-authoring` AiFeature already exists (system context, no RBAC).
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
