<?php

/**
 * Hermiq SeedBundledFlows repair step.
 *
 * Seeds the flow definitions shipped in `lib/Settings/flows` (hydra's pipeline flows,
 * vendored by `scripts/sync-bundled-flows.sh`) into OpenRegister's native flow store,
 * so an instance arrives with them instead of waiting for someone to import them by hand.
 *
 * Settled by SeedHydraTriageFlow and not redesigned here:
 * - DISABLED and OWNERLESS. A trigger fires with no acting user, so a flow's owner is
 *   the person who enables it; a repair step running as no one cannot be that person.
 *   Every one of these flows writes to the forge, so arriving enabled would mean
 *   running on a credential nobody chose. Definitions that say `enabled: true` are
 *   seeded disabled all the same.
 * - IDEMPOTENT BY NAME, across every app: a flow of that name, seeded before or
 *   imported by hand, is never touched again, so an operator's edits survive.
 * - ON THE DEFAULT ORGANISATION, or not at all: an org-less row is invisible to
 *   every tenant and blocks its own re-seed (hermiq#140).
 *
 * New here:
 * - PREFLIGHT per flow through OpenRegister's FlowNodePreflight: a definition naming
 *   a step this instance cannot run (openconnector absent, say) is reported and left
 *   unseeded, a warning rather than a failure, because a missing optional app is a
 *   legitimate install.
 * - A REAL FAILURE IS NOT SWALLOWED. Every flow is attempted, then the step throws
 *   naming the ones that failed, so the install reports what went wrong instead of a
 *   clean result with flows missing. The per-flow outcome is also kept in app config
 *   (`occ config:app:get hermiq bundled_flow_seed`) where a short CI log tail cannot
 *   lose it.
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
 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Seed the bundled flow definitions, disabled and ownerless, once each by name.
 *
 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
 */
class SeedBundledFlows implements IRepairStep {

	/**
	 * App-config key holding the per-flow outcome as JSON (`{name: outcome}`).
	 *
	 * @var string
	 */
	public const OUTCOME_KEY = 'bundled_flow_seed';

	/**
	 * The application the seeded flows belong to (the hydra console), as SeedHydraTriageFlow tags its flow.
	 *
	 * @var string
	 */
	public const APPLICATION_SLUG = 'hydra-console';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface          $container   Server container (OpenRegister may not be installed yet).
	 * @param LoggerInterface             $logger      PSR-3 logger.
	 * @param IAppConfig|null             $appConfig   Where the per-flow outcome is recorded.
	 * @param BundledFlowDefinitions|null $definitions The definitions; the shipped ones by default.
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ?IAppConfig $appConfig=null,
		private readonly ?BundledFlowDefinitions $definitions=null,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
	 */
	public function getName(): string {
		return 'Seed the bundled hydra flows (disabled, ownerless)';
	}//end getName()

	/**
	 * Seed every bundled definition not yet present, then fail loudly if any write failed.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When one or more flows could not be written.
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
	 */
	public function run(IOutput $output): void {
		try {
			$mapper = $this->container->get(FlowMapper::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping the bundled flow seed.');
			$this->record(outcomes: ['*' => 'unavailable: ' . $e->getMessage()]);
			return;
		}

		$organisation = (new DefaultOrganisationLookup(container: $this->container, logger: $this->logger))->uuid();
		if ($organisation === null) {
			$output->warning('No default organisation is resolvable — skipping the bundled flow seed rather than writing rows no tenant can see.');
			$this->record(outcomes: ['*' => 'unavailable: no default organisation']);
			return;
		}

		$seed = new BundledFlowSeed(mapper: $mapper, organisation: $organisation, preflight: $this->preflight());

		$outcomes = [];
		$failures = [];
		foreach (($this->definitions ?? new BundledFlowDefinitions())->all() as $file => $definition) {
			[$name, $outcome] = $this->seedOne(seed: $seed, file: $file, definition: $definition);
			$outcomes[$name] = $outcome;
			$this->report(output: $output, name: $name, outcome: $outcome);
			if (str_starts_with($outcome, 'failed') === true) {
				$failures[] = $name . ' (' . $outcome . ')';
			}
		}

		$this->record(outcomes: $outcomes);
		if ($failures !== []) {
			throw new RuntimeException('Could not seed ' . count($failures) . ' bundled flow(s): ' . implode('; ', $failures));
		}
	}//end run()

	/**
	 * Seed one definition; its name and how it ended.
	 *
	 * @param BundledFlowSeed                                              $seed       The seed run.
	 * @param string                                                       $file       The definition's file name.
	 * @param array{data: array<string, mixed>|null, error: string|null} $definition The definition or its read error.
	 *
	 * @return array{0: string, 1: string} The flow name (the file name when unreadable) and the outcome.
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
	 */
	private function seedOne(BundledFlowSeed $seed, string $file, array $definition): array {
		$data = $definition['data'];
		if ($data === null) {
			return [$file, 'failed: ' . (string)$definition['error']];
		}

		$name = trim((string)$data['name']);
		if (isset($seed->existing[$name]) === true) {
			return [$name, 'present'];
		}

		$refusal = $this->refusal(seed: $seed, name: $name, data: $data);
		if ($refusal !== null) {
			return [$name, 'refused: ' . $refusal];
		}

		try {
			$flow = (new SeededFlowRow())->from(data: $data, name: $name, applicationSlug: self::APPLICATION_SLUG);
			$flow->setOrganisation($seed->organisation);
			$seed->mapper->insert($flow);
		} catch (Throwable $e) {
			$this->logger->error(
				'[hermiq] Bundled flow seed failed for "' . $name . '": ' . $e::class . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return [$name, 'failed: ' . $e::class . ': ' . $e->getMessage()];
		}

		return [$name, 'seeded'];
	}//end seedOne()

	/**
	 * Why the instance cannot run this definition, or null when it can (or cannot be asked).
	 *
	 * @param BundledFlowSeed      $seed The seed run.
	 * @param string               $name The flow's name.
	 * @param array<string, mixed> $data The definition.
	 *
	 * @return string|null The refusal.
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
	 */
	private function refusal(BundledFlowSeed $seed, string $name, array $data): ?string {
		if ($seed->preflight === null) {
			return null;
		}

		$report = $seed->preflight->inspect(flow: $data);
		if (($report['blocking'] ?? []) === []) {
			return null;
		}

		return $seed->preflight->describe(flow: $name, blocking: $report['blocking']);
	}//end refusal()

	/**
	 * OpenRegister's preflight, or null on a version that does not have it.
	 *
	 * @return FlowNodePreflight|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
	 */
	private function preflight(): ?FlowNodePreflight {
		try {
			return $this->container->get(FlowNodePreflight::class);
		} catch (Throwable $e) {
			$this->logger->info('[hermiq] Flow preflight unavailable, seeding without it: ' . $e->getMessage());
			return null;
		}
	}//end preflight()

	/**
	 * Tell the operator how one flow ended.
	 *
	 * @param IOutput $output  Repair output channel.
	 * @param string  $name    The flow.
	 * @param string  $outcome How it ended.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
	 */
	private function report(IOutput $output, string $name, string $outcome): void {
		if ($outcome === 'seeded') {
			$output->info('Flow "' . $name . '" seeded (disabled — enabling it supplies its owner).');
			return;
		}

		if ($outcome === 'present') {
			return;
		}

		$output->warning('Flow "' . $name . '" not seeded: ' . $outcome);
	}//end report()

	/**
	 * Keep the per-flow outcome in app config. Never throws.
	 *
	 * @param array<string, string> $outcomes How each flow ended, by name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
	 */
	private function record(array $outcomes): void {
		if ($this->appConfig === null) {
			return;
		}

		try {
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::OUTCOME_KEY, (string)json_encode($outcomes, JSON_UNESCAPED_SLASHES));
		} catch (Throwable $e) {
			$this->logger->warning('[hermiq] Could not record the bundled flow seed outcome: ' . $e->getMessage());
		}
	}//end record()

}//end class
