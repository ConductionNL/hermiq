<?php

/**
 * Hermiq Backfill Agent Application Slug Repair Step.
 *
 * The hermiq-agent-application-slug change added the OPTIONAL `applicationSlug`
 * property to the `agent` schema in `lib/Settings/hermiq_register.json` — every Agent
 * object written before that change necessarily has it empty, since the column did not
 * exist yet. This is the one-time, idempotent backfill for the four Agent objects that
 * belong to the `hydra-console` OpenBuild application: seeded by `SeedHydraTriageAgent` (one),
 * plus three hand-created via the UI with no seed script of their own ("Hydra
 * Applier — Axel Pliér", "Hydra Builder — Al Gorithm", "Hydra Author — Ada Wright") —
 * which is exactly why this is a narrow, name-matched repair step rather than a
 * per-agent seed: three of the four have no natural re-seed trigger to hang a
 * seed-time write on.
 *
 * Idempotent by NAME, matching an object only when `applicationSlug` is currently
 * empty; a value already present — an operator's own retag, or one a prior run of
 * this same backfill already wrote — is never overwritten. Mirrors
 * `SeedHydraTriageFlow::backfillApplicationSlug()` (hydra-flow-application-slug-backfill)
 * exactly, one register-object layer down.
 *
 * 🔴 THE WRITE MUST BE A PATCH, NOT A SAVE. `ObjectService::saveObject()` is
 * PUT-semantic — a property absent from the payload is written away, not left alone
 * (see `ObjectService::updateObject()`'s docblock: "Replace semantics are deliberate
 * ... existing callers ... rely on an omitted property being cleared"). Writing this
 * backfill with `saveObject(['applicationSlug' => ...])` would have nulled every
 * other field on all four agents — name, prompt, tools, delegationAllowlist,
 * everything. `ObjectService::patchObject()` is the fleet's supported PATCH-semantic
 * path: it reads the stored object, merges only the keys the payload names, and
 * saves the merged result — which is what makes a one-field backfill safe.
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
 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#requirement-the-four-hydra-console-agents-are-backfilled-with-their-application-slug
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
 * Backfill `applicationSlug` on the four known hydra-console Agent objects
 * (idempotent, by name; never overwrites a non-empty value).
 *
 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#requirement-the-four-hydra-console-agents-are-backfilled-with-their-application-slug
 */
class BackfillAgentApplicationSlug implements IRepairStep {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for Agent objects.
	 *
	 * @var string
	 */
	public const AGENT_SCHEMA = 'agent';

	/**
	 * hydra-console's real OpenBuild application slug — see
	 * `SeedHydraTriageFlow::APPLICATION_SLUG` for the two independent live/source
	 * confirmations this value rests on.
	 *
	 * @var string
	 */
	public const APPLICATION_SLUG = 'hydra-console';

	/**
	 * The exact names of the four Agent objects known to belong to `hydra-console`,
	 * as of hermiq-agent-application-slug: one seeded (`SeedHydraTriageAgent`),
	 * three hand-created via the UI with no seed script of their own.
	 *
	 * @var array<int, string>
	 */
	public const AGENT_NAMES = [
		'Hydra Triage',
		'Hydra Applier — Axel Pliér',
		'Hydra Builder — Al Gorithm',
		'Hydra Author — Ada Wright',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for lazy ObjectService
	 *                                      resolution (OpenRegister may not be
	 *                                      installed yet).
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
	 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#requirement-the-four-hydra-console-agents-are-backfilled-with-their-application-slug
	 */
	public function getName(): string {
		return 'Backfill applicationSlug on the hydra-console agents (hermiq-agent-application-slug)';
	}//end getName()

	/**
	 * Backfill `applicationSlug` on every named agent whose value is currently
	 * empty; an agent that does not exist yet is skipped (nothing to backfill —
	 * its own seed, when it has one, is responsible for setting the field going
	 * forward), and an agent that already carries a value is left untouched.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#scenario-backfilling-twice-writes-once
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping the agent applicationSlug backfill.');
			$this->logger->warning('[hermiq] Agent applicationSlug backfill skipped: ' . $e->getMessage());
			return;
		}

		$backfilled = 0;
		$skipped = 0;
		foreach (self::AGENT_NAMES as $name) {
			foreach ($this->findByName(objectService: $objectService, name: $name) as $agent) {
				if ($this->backfillOne(objectService: $objectService, agent: $agent) === true) {
					$backfilled++;
					continue;
				}

				$skipped++;
			}
		}

		$output->info(
			'Agent applicationSlug backfill: ' . $backfilled . ' agent(s) backfilled, '
			. $skipped . ' already present or absent.'
		);

	}//end run()

	/**
	 * Backfill one agent's `applicationSlug` when it is currently empty.
	 *
	 * Writes through `ObjectService::patchObject()` — never `saveObject()` — so
	 * only `applicationSlug` changes; every other field (`name`, `prompt`,
	 * `tools`, `delegationAllowlist`, …) is left exactly as it was.
	 *
	 * @param ObjectService $objectService The OpenRegister object write-path.
	 * @param ObjectEntity $agent The agent to (maybe) backfill.
	 *
	 * @return bool True when a write was issued.
	 *
	 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#requirement-the-four-hydra-console-agents-are-backfilled-with-their-application-slug
	 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#requirement-a-previously-set-application-slug-is-never-overwritten
	 */
	private function backfillOne(ObjectService $objectService, ObjectEntity $agent): bool {
		$data = $agent->getObject();
		$current = trim((string)($data['applicationSlug'] ?? ''));
		if ($current !== '') {
			return false;
		}

		$uuid = trim((string)$agent->getUuid());
		if ($uuid === '') {
			return false;
		}

		try {
			$objectService->patchObject(
				objectId: $uuid,
				data: ['applicationSlug' => self::APPLICATION_SLUG],
				register: self::REGISTER_SLUG,
				schema: self::AGENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $e) {
			// Non-fatal: the agent itself is unchanged and fully functional
			// without this field. A failed backfill simply retries on the next
			// repair run.
			$this->logger->warning(
				'[hermiq] Could not backfill applicationSlug onto agent "' . (string)($data['name'] ?? $uuid)
				. '": ' . $e->getMessage()
			);
			return false;
		}

	}//end backfillOne()

	/**
	 * Every Agent object with this exact name (system context, no RBAC).
	 *
	 * @param ObjectService $objectService The OpenRegister object read-path.
	 * @param string $name The exact agent name to match.
	 *
	 * @return array<int, ObjectEntity> The matching agents.
	 */
	private function findByName(ObjectService $objectService, string $name): array {
		try {
			$objects = $objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::AGENT_SCHEMA)
				->findAll(
					config: ['filters' => ['name' => $name], 'limit' => 50],
					_rbac: false,
					_multitenancy: false
				);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[hermiq] Could not look up agent "' . $name . '" for the applicationSlug backfill: '
				. $e->getMessage()
			);
			return [];
		}

		$matches = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['name'] ?? '') === $name) {
				$matches[] = $object;
			}
		}

		return $matches;
	}//end findByName()
}//end class
