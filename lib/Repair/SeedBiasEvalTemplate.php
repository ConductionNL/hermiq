<?php

/**
 * Hermiq Seed Bias Eval Template Repair Step.
 *
 * Seeds the read-only bias test set templates (reuse-scheduled-bias-evals,
 * REQ-BIASEVAL-002) from `lib/Settings/eval-templates/bias-public-services.json`:
 * one EvalDataset per language, each holding twenty `counterfactualPair` cases
 * over name, gender, age, origin and disability. A template carries
 * `template: true` and its `templateKey`; `EvalTemplateGuardListener` refuses an
 * edit of it, and "Use for this agent" (`EvalTemplateCopier`) copies it into a
 * dataset the agent owner edits.
 *
 * Idempotent by name, in system context: a re-run never duplicates a template.
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCP\Migration\IRepairStep;
use OCP\Migration\IOutput;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Seed the bias test set templates via ObjectService (idempotent, by name).
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */
class SeedBiasEvalTemplate implements IRepairStep {

	use FindsSeedObjectByName;
	use \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;

	/**
	 * The template key every bias dataset carries, the template and its copies alike.
	 *
	 * @var string
	 */
	public const TEMPLATE_KEY = 'bias-public-services';

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for EvalDataset objects.
	 *
	 * @var string
	 */
	private const DATASET_SCHEMA = 'evaldataset';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for lazy ObjectService resolution
	 *                                      (OpenRegister may not be installed yet).
	 * @param LoggerInterface    $logger    PSR-3 logger.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
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
	 * @spec exclude Trivial IRepairStep display-name accessor; no behavioural spec.
	 */
	public function getName(): string {
		return 'Seed the bias test set templates';
	}//end getName()

	/**
	 * Seed every template that is not present yet (matched by name).
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping the bias test set templates.');
			$this->logger->warning('[hermiq] bias test set template seed skipped: ' . $e->getMessage());
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous'.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->seedTemplates(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * The templates shipped with the app, each ready to store: name, description,
	 * language, cases, `template: true` and the template key.
	 *
	 * @return array<int, array<string, mixed>> The template datasets.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public static function templates(): array {
		$path    = __DIR__ . '/../Settings/eval-templates/bias-public-services.json';
		$decoded = json_decode((string)file_get_contents($path), true);
		if (is_array($decoded) === false || is_array($decoded['templates'] ?? null) === false) {
			return [];
		}

		$templates = [];
		foreach ($decoded['templates'] as $template) {
			if (is_array($template) === false || is_string($template['name'] ?? null) === false) {
				continue;
			}

			$templates[] = [
				'name'        => $template['name'],
				'description' => (string)($template['description'] ?? ''),
				'language'    => (string)($template['language'] ?? ''),
				'cases'       => array_values((array)($template['cases'] ?? [])),
				'template'    => true,
				'templateKey' => self::TEMPLATE_KEY,
			];
		}

		return $templates;
	}//end templates()

	/**
	 * Seed the templates that are missing.
	 *
	 * @param ObjectService $objectService OpenRegister's ObjectService.
	 * @param IOutput       $output        Progress reporting.
	 *
	 * @return void
	 */
	private function seedTemplates(ObjectService $objectService, IOutput $output): void {
		foreach (self::templates() as $template) {
			$name = (string)$template['name'];
			try {
				if ($this->findByName(objectService: $objectService, schema: self::DATASET_SCHEMA, name: $name) !== null) {
					$output->info($name . ' already present — skipped.');
					continue;
				}

				$objectService->saveObject(
					object: $template,
					options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::DATASET_SCHEMA)),
					_rbac: false,
					_multitenancy: false
				);
				$output->info($name . ' seeded.');
			} catch (Throwable $e) {
				$output->warning('Could not seed "' . $name . '": ' . $e->getMessage());
				$this->logger->error('[hermiq] bias test set template seed failed for ' . $name . ': ' . $e->getMessage());
			}//end try
		}//end foreach
	}//end seedTemplates()
}//end class
