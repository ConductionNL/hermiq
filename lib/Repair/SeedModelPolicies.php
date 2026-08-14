<?php

/**
 * Hermiq Seed Model Policies Repair Step.
 *
 * Idempotently seeds the two design.md `ModelPolicy` objects (tenant-model-policy) on
 * install/upgrade via `TenantModelPolicyService::upsertForOrganisation()` — the same
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
 * @spec openspec/changes/tenant-model-policy/tasks.md#task-9-seed-data
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the instance-default + sample org ModelPolicy objects (idempotent).
 *
 * @spec openspec/changes/tenant-model-policy/tasks.md#task-9-seed-data
 */
class SeedModelPolicies implements IRepairStep {
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
	 * @spec openspec/changes/tenant-model-policy/tasks.md#task-9-seed-data
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
	 * @spec openspec/changes/tenant-model-policy/tasks.md#task-9-seed-data
	 */
	public function run(IOutput $output): void {
		try {
			$policyService = $this->container->get(TenantModelPolicyService::class);
			$organisationMapper = $this->container->get(OrganisationMapper::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping model-policy seed.');
			$this->logger->warning('[hermiq] Model-policy seed skipped: ' . $e->getMessage());
			return;
		}

		$seeded = 0;

		$seeded += $this->seedIfMissing(
			policyService: $policyService,
			output: $output,
			organisation: '',
			payload: [
				'allowed' => [
					['provider' => 'openai', 'models' => []],
					['provider' => 'ollama', 'models' => []],
					['provider' => 'fireworks', 'models' => []],
					['provider' => 'nextcloud', 'models' => []],
				],
				'defaultModel' => null,
			]
		);

		$organisation = $this->firstOrganisation(organisationMapper: $organisationMapper);
		if ($organisation === '') {
			$output->info('No OpenRegister organisation exists yet — sample org model-policy seed deferred.');
		}

		if ($organisation !== '') {
			$seeded += $this->seedIfMissing(
				policyService: $policyService,
				output: $output,
				organisation: $organisation,
				payload: [
					'allowed' => [
						['provider' => 'ollama', 'models' => []],
					],
					'defaultModel' => [
						'provider' => 'ollama',
						'model' => 'qwen2.5',
					],
				]
			);
		}

		$output->info('Model-policy seed complete (' . $seeded . ' new).');

	}//end run()

	/**
	 * Create the policy when the organisation has none yet; never overwrite an
	 * existing one (admin edits must survive upgrades).
	 *
	 * @param TenantModelPolicyService $policyService The policy write-path.
	 * @param IOutput $output Repair output channel.
	 * @param string $organisation The organisation ('' = instance default).
	 * @param array<string,mixed> $payload The policy fields (allowed/defaultModel).
	 *
	 * @return int 1 when a new policy was created, 0 when skipped (exists or failed).
	 */
	private function seedIfMissing(
		TenantModelPolicyService $policyService,
		IOutput $output,
		string $organisation,
		array $payload,
	): int {
		try {
			if ($policyService->getForOrganisation(organisation: $organisation) !== null) {
				return 0;
			}

			$policyService->upsertForOrganisation(organisation: $organisation, payload: $payload);
			return 1;
		} catch (Throwable $e) {
			$output->warning('Could not seed a model policy: ' . $e->getMessage());
			$this->logger->error('[hermiq] Model-policy seed failed: ' . $e->getMessage());
			return 0;
		}

	}//end seedIfMissing()

	/**
	 * The first available OpenRegister organisation, or '' when none exists yet.
	 *
	 * @param OrganisationMapper $organisationMapper The organisation lookup.
	 *
	 * @return string
	 */
	private function firstOrganisation(OrganisationMapper $organisationMapper): string {
		try {
			$organisations = $organisationMapper->findAll(limit: 1);
		} catch (Throwable $e) {
			return '';
		}

		if ($organisations === []) {
			return '';
		}

		return (string)($organisations[0]->getUuid() ?? '');
	}//end firstOrganisation()
}//end class
