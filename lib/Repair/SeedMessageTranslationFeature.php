<?php

/**
 * Hermiq Seed MessageTranslation AiFeature Repair Step (message-translation-delegate).
 *
 * Idempotently seeds the `message-translation` `AiFeature` governance object (EU AI
 * Act, limited risk — the output must be disclosed as machine-translated, Art. 50;
 * it does not decide anything about a person) in `lifecycle: disabled`, mirroring
 * `lib/Repair/SeedCourseRecommendationFeature.php`'s pattern exactly: written through
 * OpenRegister's ObjectService single write-path (ADR-001, ADR-004), system-scoped
 * (`_rbac: false, _multitenancy: false`, `tenantId: ''`) so it is visible fleet-wide
 * for the DPO to acknowledge, and skipped on re-run once the slug exists. An admin/DPO
 * must explicitly enable it (`AiFeatureController::enable()`) before
 * `MessageTranslationEngine` calls any LLM provider (design.md "Security Considerations").
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
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
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
 * Seed the `message-translation` AiFeature via ObjectService (idempotent).
 *
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
 * @spec openspec/changes/ai-translation-provenance/specs/ai-feature-governance/spec.md#requirement-the-register-records-whether-a-features-outputs-are-labelled-as-ai-made
 */
class SeedMessageTranslationFeature implements IRepairStep {
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
	private const FEATURE_SLUG = 'message-translation';

	/**
	 * How this feature tells its readers that AI made the output
	 * (ai-translation-provenance). Recorded on the register entry so a DPO sees
	 * the Art. 50 measure next to the risk category.
	 *
	 * @var string
	 */
	private const OUTPUT_LABELLING = 'Every translation carries translatedByAi, the source and target language, '
		. 'a model label and a reference to the original. The reader sees a fixed disclosure sentence in their '
		. 'language with a link to the original text.';

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
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
	 */
	public function getName(): string {
		return 'Seed the message-translation AI feature (message-translation-delegate)';
	}//end getName()

	/**
	 * Seed the `message-translation` AiFeature when it does not yet exist.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping message-translation AI-feature seed.');
			$this->logger->warning('[hermiq] message-translation AiFeature seed skipped: ' . $e->getMessage());
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous'. A per-call `_rbac: false` is not
		// sufficient on its own — measured in a sibling app, a step flagging
		// every one of its own writes still failed eight times, because the
		// refusals arrive from writes further down the call chain.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->seedFeature(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * Seed the message-translation feature when it is not present yet, and
	 * back-fill the output-labelling fields on a row seeded before them.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/ai-feature-governance/spec.md#requirement-the-register-records-whether-a-features-outputs-are-labelled-as-ai-made
	 */
	private function seedFeature(object $objectService, IOutput $output): void {
		try {
			$existing = $this->findExisting(objectService: $objectService);
			if ($existing !== null) {
				$this->backfillLabelling(objectService: $objectService, existing: $existing, output: $output);
				return;
			}

			$objectService->saveObject(
				object: [
					'slug' => self::FEATURE_SLUG,
					'name' => 'Message translation',
					'description' => 'Translates a parent-facing message or news item into a target language, '
						. 'with a caller-supplied glossary for school-specific terms. Limited risk under the EU AI '
						. 'Act: the output must be disclosed as machine-translated (Art. 50); it does not decide '
						. 'anything about a person the way a course/career recommendation or a proctoring gate '
						. 'does.',
					'riskCategory' => 'limited',
					'lifecycle' => 'disabled',
					'tenantId' => '',
					'outputsLabelled' => true,
					'outputLabelling' => self::OUTPUT_LABELLING,
				],
				register: self::REGISTER_SLUG,
				schema: self::AIFEATURE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			$output->info('Seeded the message-translation AI feature (disabled, pending DPO acknowledgement).');
		} catch (Throwable $e) {
			$output->warning('Could not seed the message-translation AI feature: ' . $e->getMessage());
			$this->logger->error('[hermiq] message-translation AiFeature seed failed: ' . $e->getMessage());
		}//end try

	}//end seedFeature()

	/**
	 * Record the output labelling on a row seeded before the fields existed.
	 * The whole row is saved back under its own uuid with `lifecycle` as it
	 * was, so the lifecycle engine sees no transition and a DPO's enablement
	 * survives. A row that already says it is labelled is not touched.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param ObjectEntity $existing The existing message-translation row.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/ai-feature-governance/spec.md#requirement-the-register-records-whether-a-features-outputs-are-labelled-as-ai-made
	 */
	private function backfillLabelling(object $objectService, ObjectEntity $existing, IOutput $output): void {
		$data = $existing->getObject();
		if (($data['outputsLabelled'] ?? null) === true) {
			$output->info('message-translation AI feature already exists — skipping.');
			return;
		}

		unset($data['id'], $data['uuid'], $data['@self']);
		$data['outputsLabelled'] = true;
		if ((string)($data['outputLabelling'] ?? '') === '') {
			$data['outputLabelling'] = self::OUTPUT_LABELLING;
		}

		$objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::AIFEATURE_SCHEMA,
			uuid: (string)$existing->getUuid(),
			_rbac: false,
			_multitenancy: false
		);
		$output->info('message-translation AI feature already exists — back-filled output labelling.');

	}//end backfillLabelling()

	/**
	 * The existing `message-translation` AiFeature, if any (system context, no RBAC).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 *
	 * @return ObjectEntity|null The existing row, or null when there is none.
	 */
	private function findExisting(ObjectService $objectService): ?ObjectEntity {
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
				return $object;
			}
		}

		return null;
	}//end findExisting()
}//end class
