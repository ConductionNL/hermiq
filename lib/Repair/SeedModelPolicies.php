<?php

/**
 * Hermiq Seed Model Policies Repair Step.
 *
 * Idempotently seeds the two design.md `ModelPolicy` objects (tenant-model-policy) on
 * install/upgrade via `OrganisationModelPolicyService::upsertForOrganisation()` — the same
 * single write-path (OR `ObjectService`, ADR-001/ADR-004) the controller uses:
 *   - the organisation-less INSTANCE DEFAULT policy (all four drivers, unrestricted
 *     models), so a fresh install is permissive-but-explicit instead of fail-closed;
 *   - one sample organisation-scoped policy (ollama-only, defaultModel
 *     {provider: ollama, model: qwen2.5}) that
 *     demonstrates the sovereignty use case without manual setup.
 * The sample org policy needs an OpenRegister organisation to pin itself to; on a
 * genuinely fresh install (no organisation yet) it is deferred, never fabricated —
 * mirroring `SeedBudgets`' graceful-degradation contract.
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
 * @spec openspec/changes/archive/2026-07-12-tenant-model-policy/tasks.md#task-9-seed-data
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Kernel\Contracts\OrganisationModelPolicyServiceInterface;
use OCA\Hermiq\Repair\Support\FirstOrganisation;
use OCA\Hermiq\Repair\Support\OrganisationSeed;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the instance-default + sample org ModelPolicy objects (idempotent).
 *
 * @spec openspec/changes/archive/2026-07-12-tenant-model-policy/tasks.md#task-9-seed-data
 */
class SeedModelPolicies implements IRepairStep {
	/**
	 * The instance-default policy: every provider allowed, no default model.
	 *
	 * @var array{allowed: array<int, array{provider: string, models: array<int, string>}>, defaultModel: null}
	 */
	private const INSTANCE_DEFAULT_POLICY = [
		'allowed' => [
			['provider' => 'openai', 'models' => []],
			['provider' => 'ollama', 'models' => []],
			['provider' => 'fireworks', 'models' => []],
			['provider' => 'nextcloud', 'models' => []],
		],
		'defaultModel' => null,
	];

	/**
	 * The sample organisation policy: Ollama only, defaulting to qwen2.5.
	 *
	 * @var array{allowed: array<int, array{provider: string, models: array<int, string>}>, defaultModel: array{provider: string, model: string}}
	 */
	private const SAMPLE_ORGANISATION_POLICY = [
		'allowed' => [
			['provider' => 'ollama', 'models' => []],
		],
		'defaultModel' => [
			'provider' => 'ollama',
			'model' => 'qwen2.5',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for lazy service resolution
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
	 * @spec openspec/changes/archive/2026-07-12-tenant-model-policy/tasks.md#task-9-seed-data
	 */
	public function getName(): string {
		return 'Seed model policies (tenant-model-policy)';
	}//end getName()

	/**
	 * Seed the instance default (always) and the sample organisation policy (when an
	 * organisation exists). `upsertForOrganisation()` is idempotent per organisation,
	 * but an EXISTING policy is never overwritten — admin edits survive upgrades.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-12-tenant-model-policy/tasks.md#task-9-seed-data
	 */
	public function run(IOutput $output): void {
		$run = $this->startRun(output: $output);
		if ($run === null) {
			return;
		}

		$seeded = $this->seedIfMissing(
			run: $run,
			seed: new OrganisationSeed(organisation: '', payload: self::INSTANCE_DEFAULT_POLICY)
		);
		$seeded += $this->seedSampleOrganisationPolicy(run: $run);

		$output->info('Model-policy seed complete (' . $seeded . ' new).');

	}//end run()

	/**
	 * Resolve the services the seed needs, or warn and answer null when OpenRegister
	 * is not available.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return ModelPolicySeedRun|null
	 */
	private function startRun(IOutput $output): ?ModelPolicySeedRun {
		$run = new ModelPolicySeedRun(output: $output);
		try {
			$run->policyService = $this->container->get(OrganisationModelPolicyServiceInterface::class);
			$run->organisationMapper = $this->container->get(OrganisationMapper::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping model-policy seed.');
			$this->logger->warning('[hermiq] Model-policy seed skipped: ' . $e->getMessage());
			return null;
		}

		return $run;
	}//end startRun()

	/**
	 * Seed the sample policy on the first organisation, or defer it when no
	 * organisation exists yet.
	 *
	 * @param ModelPolicySeedRun $run The seed run.
	 *
	 * @return int 1 when a new policy was created, otherwise 0.
	 */
	private function seedSampleOrganisationPolicy(ModelPolicySeedRun $run): int {
		$organisation = (new FirstOrganisation(organisationMapper: $run->organisationMapper))->uuid();
		if ($organisation === '') {
			$run->output->info('No OpenRegister organisation exists yet — sample org model-policy seed deferred.');
			return 0;
		}

		return $this->seedIfMissing(
			run: $run,
			seed: new OrganisationSeed(organisation: $organisation, payload: self::SAMPLE_ORGANISATION_POLICY)
		);
	}//end seedSampleOrganisationPolicy()

	/**
	 * Create the policy when the organisation has none yet; never overwrite an
	 * existing one (admin edits must survive upgrades).
	 *
	 * @param ModelPolicySeedRun $run The seed run.
	 * @param OrganisationSeed $seed The organisation ('' = instance default) and the policy fields.
	 *
	 * @return int 1 when a new policy was created, 0 when skipped (exists or failed).
	 */
	private function seedIfMissing(ModelPolicySeedRun $run, OrganisationSeed $seed): int {
		try {
			if ($run->policyService->getForOrganisation(organisation: $seed->organisation) !== null) {
				return 0;
			}

			$run->policyService->upsertForOrganisation(organisation: $seed->organisation, payload: $seed->payload);
			return 1;
		} catch (Throwable $e) {
			$run->output->warning('Could not seed a model policy: ' . $e->getMessage());
			$this->logger->error('[hermiq] Model-policy seed failed: ' . $e->getMessage());
			return 0;
		}

	}//end seedIfMissing()
}//end class
