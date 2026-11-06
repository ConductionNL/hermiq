<?php

/**
 * Hermiq SeedRecordSummary repair step (agents-bound-to-their-app, task 5).
 *
 * Seeds the `record-summary` AI feature (limited risk, disabled until a DPO
 * enables it, as every other feature) and ships the "Record summary" prompt
 * into the assistant prompt library with usage scope `record-summary`. An
 * existing feature is left alone, so the DPO's switch survives an upgrade, and
 * the library itself refuses to restore a prompt an administrator edited.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Hermiq\Service\Assistant\AssistantPromptLibrary;
use OCA\Hermiq\Service\Assistant\RecordSummaryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the record-summary AI feature and its shipped prompt.
 *
 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */
class SeedRecordSummary implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * Register slug of hermiq's objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug of AI features.
	 *
	 * @var string
	 */
	private const AIFEATURE_SCHEMA = 'agentaifeature';

	/**
	 * How the output is labelled (EU AI Act art. 50), as the register records it.
	 *
	 * @var string
	 */
	private const OUTPUT_LABELLING = 'Every summary is shown beside its record with the line "Written by AI on <date>. '
		. 'Check it before you rely on it." It is never written into the record.';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's services lazily (absent during a broken install).
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	public function getName(): string {
		return 'Seed the record-summary AI feature and its prompt (agents-bound-to-their-app)';
	}//end getName()

	/**
	 * Seed the feature and the prompt, under a system identity.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
			$library = $this->container->get(AssistantPromptLibrary::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available, skipping the record-summary seed.');
			$this->logger->warning('[hermiq] record-summary seed skipped: ' . $e->getMessage());
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $library, $output): void {
				$this->seedFeature(objectService: $objectService, output: $output);
				$this->seedPrompt(library: $library, output: $output);
			}
		);
	}//end run()

	/**
	 * Seed the AI feature unless one with its slug exists.
	 *
	 * @param ObjectService $objectService The object service.
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	private function seedFeature(ObjectService $objectService, IOutput $output): void {
		try {
			if ($this->featureExists(objectService: $objectService) === true) {
				return;
			}

			$objectService->saveObject(
				object: [
					'slug' => RecordSummaryService::FEATURE_SLUG,
					'name' => 'Record summary',
					'description' => 'Writes a short summary of a record on its page, with the assistant of the '
						. 'record\'s app. Limited risk under the EU AI Act: the text is labelled as written by AI '
						. '(art. 50) and decides nothing about the record or a person.',
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
			$output->info('Seeded the record-summary AI feature (disabled, pending DPO acknowledgement).');
		} catch (Throwable $e) {
			$output->warning('Could not seed the record-summary AI feature: ' . $e->getMessage());
			$this->logger->error('[hermiq] record-summary AiFeature seed failed: ' . $e->getMessage());
		}//end try
	}//end seedFeature()

	/**
	 * Ship the record-summary prompt into the library.
	 *
	 * @param AssistantPromptLibrary $library The prompt library.
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	private function seedPrompt(AssistantPromptLibrary $library, IOutput $output): void {
		try {
			$result = $library->seed(
				appId: 'hermiq',
				prompts: [
					[
						'label' => 'Record summary',
						'prompt' => RecordSummaryService::DEFAULT_PROMPT,
						'usageScope' => RecordSummaryService::PROMPT_SCOPE,
						'order' => 0,
						'enabled' => true,
					],
				]
			);
			$output->info('Record-summary prompt: ' . $result['installed'] . ' installed, ' . $result['skipped'] . ' kept as administered.');
		} catch (Throwable $e) {
			$output->warning('Could not seed the record-summary prompt: ' . $e->getMessage());
			$this->logger->error('[hermiq] record-summary prompt seed failed: ' . $e->getMessage());
		}
	}//end seedPrompt()

	/**
	 * Whether the feature exists in any tenant.
	 *
	 * @param ObjectService $objectService The object service.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	private function featureExists(ObjectService $objectService): bool {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::AIFEATURE_SCHEMA)
			->findAll(
				config: ['filters' => ['slug' => RecordSummaryService::FEATURE_SLUG], 'limit' => 200],
				_rbac: false,
				_multitenancy: false
			);

		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity && (string)($object->getObject()['slug'] ?? '') === RecordSummaryService::FEATURE_SLUG) {
				return true;
			}
		}

		return false;
	}//end featureExists()
}//end class
