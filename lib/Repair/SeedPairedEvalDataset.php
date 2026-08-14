<?php

/**
 * Hermiq Seed Paired Eval Dataset Repair Step.
 *
 * Idempotently seeds ONE EvalDataset (`woo-triage-paired-eval`, skill-evals) linked to
 * the `woo-request-triage` skill seeded by skill-maturity-model, so the paired
 * with-skill vs without-skill baseline flow is demonstrable on a fresh install:
 * three municipality-context cases (a `contains "termijn"` triage prompt, a
 * `notContains "klacht"` routing prompt, and a rubric case at threshold 0.7). The
 * linked skill's uuid is resolved BY NAME at seed time — never hard-coded (nil UUIDs
 * appear only in docs/examples). It links exactly ONE skill (design.md Decision 2's
 * clean-attribution recommendation). NO EvalRun and NO l5 evidence are seeded —
 * evidence only ever comes from real executed runs (ADR-060).
 *
 * Written through OpenRegister's ObjectService single write-path in system context
 * (`_rbac: false, _multitenancy: false`), matched by name so a re-run never duplicates
 * the seed or overwrites an admin's edit — mirroring `SeedMaturityExampleSkills`.
 * Skipped with a log line when the `woo-request-triage` skill is absent.
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
 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evaldataset-links-skills-via-skillrefs-per-the-relation-dialect
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
 * Seed the paired-eval demo dataset via ObjectService (idempotent, by name).
 *
 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evaldataset-links-skills-via-skillrefs-per-the-relation-dialect
 */
class SeedPairedEvalDataset implements IRepairStep {

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
	 * Schema slug for Skill objects (namespaced to avoid a cross-app slug collision).
	 *
	 * @var string
	 */
	private const SKILL_SCHEMA = 'agentskill';

	/**
	 * The seeded dataset's name (idempotency key).
	 *
	 * @var string
	 */
	private const DATASET_NAME = 'woo-triage-paired-eval';

	/**
	 * The linked skill's name, resolved to its uuid at seed time.
	 *
	 * @var string
	 */
	private const LINKED_SKILL_NAME = 'woo-request-triage';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for lazy ObjectService resolution
	 *                                      (OpenRegister may not be installed yet).
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
	 * @spec exclude Trivial IRepairStep display-name accessor; no behavioural spec.
	 */
	public function getName(): string {
		return 'Seed paired eval dataset (skill-evals)';
	}//end getName()

	/**
	 * Seed the dataset when absent (matched by name); an existing seed — including
	 * one an admin has since edited — is left untouched. Skipped with a log line
	 * when the `woo-request-triage` skill cannot be resolved by name.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evaldataset-links-skills-via-skillrefs-per-the-relation-dialect
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping paired eval dataset seed.');
			$this->logger->warning('[hermiq] paired eval dataset seed skipped: ' . $e->getMessage());
			return;
		}

		try {
			if ($this->findByName(objectService: $objectService, schema: self::DATASET_SCHEMA, name: self::DATASET_NAME) !== null) {
				$output->info(self::DATASET_NAME . ' seed already present — skipped.');
				return;
			}

			$skill = $this->findByName(objectService: $objectService, schema: self::SKILL_SCHEMA, name: self::LINKED_SKILL_NAME);
			if ($skill === null) {
				$output->info(self::LINKED_SKILL_NAME . ' skill not found — paired eval dataset seed skipped.');
				$this->logger->info('[hermiq] paired eval dataset seed skipped: ' . self::LINKED_SKILL_NAME . ' skill absent.');
				return;
			}

			$objectService->saveObject(
				object: $this->seedDataset(linkedSkillUuid: (string)$skill->getUuid()),
				register: self::REGISTER_SLUG,
				schema: self::DATASET_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			$output->info(self::DATASET_NAME . ' seed complete.');
		} catch (Throwable $e) {
			$output->warning('Could not seed ' . self::DATASET_NAME . ' dataset: ' . $e->getMessage());
			$this->logger->error('[hermiq] ' . self::DATASET_NAME . ' seed failed: ' . $e->getMessage());
		}//end try

	}//end run()

	/**
	 * The seed EvalDataset payload — three municipality-context cases, ONE linked
	 * skill (clean attribution), no run and no evidence (ADR-060). Public + static
	 * so tests can assert the seed's shape against the schema.
	 *
	 * @param string $linkedSkillUuid The `woo-request-triage` skill's uuid, resolved at seed time.
	 *
	 * @return array<string, mixed> The EvalDataset object payload.
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evaldataset-links-skills-via-skillrefs-per-the-relation-dialect
	 */
	public static function seedDataset(string $linkedSkillUuid): array {
		$description = 'Paired baseline eval for the woo-request-triage skill — measures the skill\'s '
			. 'marginal contribution on realistic WOO intake prompts.';

		$triagePrompt = 'Er is een Woo-verzoek binnengekomen over de kapvergunningen in het Vondelpark; '
			. 'wat is de eerste triagestap?';

		$routingPrompt = 'Een inwoner vraagt om alle documenten over de aanbesteding van het nieuwe '
			. 'zwembad. Routeer dit verzoek.';

		$rubricPrompt = 'Triageer dit Woo-verzoek: alle e-mails van de afdeling Vergunningen over de '
			. 'dakopbouw aan de Voorbeeldstraat 1.';

		$rubric = 'Score 1 when the answer names routing, deadline (4 weken), and an exemption '
			. 'pre-check per the woo-request-triage procedure; 0 otherwise.';

		return [
			'name' => self::DATASET_NAME,
			'description' => $description,
			'skillRefs' => [$linkedSkillUuid],
			'cases' => [
				[
					'prompt' => $triagePrompt,
					'expectationType' => 'contains',
					'expectedSubstring' => 'termijn',
				],
				[
					'prompt' => $routingPrompt,
					'expectationType' => 'notContains',
					'expectedSubstring' => 'klacht',
				],
				[
					'prompt' => $rubricPrompt,
					'expectationType' => 'rubric',
					'rubric' => $rubric,
					'rubricPassThreshold' => 0.7,
				],
			],
		];

	}//end seedDataset()

	/**
	 * Find an object by exact name in the given schema (system context, no RBAC),
	 * or null when absent.
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param string $schema The schema slug to search.
	 * @param string $name The exact object name.
	 *
	 * @return ObjectEntity|null The matching object, or null.
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
}//end class
