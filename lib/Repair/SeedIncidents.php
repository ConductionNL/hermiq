<?php

/**
 * Hermiq Seed Incidents Repair Step.
 *
 * Idempotently seeds the 3 design.md incident records (agent-lifecycle-governance) on
 * install/upgrade, via `ObjectService::saveObject()` — the same single write-path
 * (ADR-001/ADR-004) `TenantOpsService::createIncident()` uses. Re-running is safe: an
 * incident matching an existing description is skipped.
 *
 * Incident objects are tenant-scoped (`ObjectEntity.organisation`), so each row's
 * `linkedAgentId` (and organisation) is resolved from an existing, named `agent`
 * object; mirrors `SeedBudgets`'s graceful-degradation contract — a row whose named
 * agent does not exist yet is deferred (logged), never fabricated with a bogus
 * `linkedAgentId`.
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
 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-1-declarative-schema-changes-incident-agent-tenantcontrol
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
 * Seed the 3 design.md Incident objects (idempotent, graceful-degradation).
 *
 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-1-declarative-schema-changes-incident-agent-tenantcontrol
 */
class SeedIncidents implements IRepairStep {
	use \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for Incident objects.
	 *
	 * @var string
	 */
	private const INCIDENT_SCHEMA = 'incident';

	/**
	 * OpenRegister schema slug for Agent objects.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

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
	 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-1-declarative-schema-changes-incident-agent-tenantcontrol
	 */
	public function getName(): string {
		return 'Seed agent-lifecycle-governance incident records';
	}//end getName()

	/**
	 * Seed each design.md incident row whose named agent already exists and that
	 * does not yet exist (matched by description).
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-1-declarative-schema-changes-incident-agent-tenantcontrol
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping incident seed.');
			$this->logger->warning('[hermiq] Incident seed skipped: ' . $e->getMessage());
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous'. Without it this seed writes nothing
		// and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$seeded = 0;

				foreach ($this->seedRows() as $row) {
					$seeded += $this->seedIfMissing(objectService: $objectService, output: $output, row: $row);
				}

				$output->info('Incident seed complete (' . $seeded . ' new).');
			}
		);

	}//end run()

	/**
	 * The design.md seed rows for the 3 incident records.
	 *
	 * @return array<int, array{agentName: string, description: string, impact: string, actionsTaken: string, createdBy: string}>
	 */
	private function seedRows(): array {
		return [
			[
				'agentName' => 'Daily Briefing',
				'description' => 'Agent posted a duplicate reply to the same Talk thread three times.',
				'impact' => 'Minor — no data leak, user confusion only.',
				'actionsTaken' => 'Paused the schedule, added a dedup guard, re-enabled.',
				'createdBy' => 'org.admin',
			],
			[
				'agentName' => 'Heavy Tool Runner',
				'description' => 'A shared API credential expired mid-run, causing three consecutive failed runs.',
				'impact' => 'Moderate — daily briefing missed for one team for a day.',
				'actionsTaken' => 'Rotated the credential, notified the schedule owner, re-enabled.',
				'createdBy' => 'org.admin',
			],
			[
				'agentName' => 'Daily Briefing',
				'description' => 'A misconfigured cron schedule fired every minute instead of daily for six hours.',
				'impact' => 'Moderate — elevated LLM token spend, no data exposure.',
				'actionsTaken' => 'Engaged the kill-switch, fixed the cron expression, disengaged.',
				'createdBy' => 'org.admin',
			],
		];

	}//end seedRows()

	/**
	 * Create the incident when no matching one exists yet (matched by
	 * description) and its named agent already exists.
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param IOutput $output Repair output channel.
	 * @param array<string,string> $row The seed row (see seedRows() for its shape).
	 *
	 * @return int 1 when a new incident was created, 0 when skipped (already exists,
	 *             prerequisite missing, or failed).
	 */
	private function seedIfMissing(ObjectService $objectService, IOutput $output, array $row): int {
		try {
			if ($this->alreadySeeded(objectService: $objectService, description: $row['description']) === true) {
				return 0;
			}

			$agent = $this->findAgentByName(objectService: $objectService, name: $row['agentName']);
			if ($agent === null) {
				$output->info(sprintf('No agent named "%s" exists yet — its incident seed is deferred.', $row['agentName']));
				return 0;
			}

			$objectService->saveObject(
				object: [
					'description' => $row['description'],
					'impact' => $row['impact'],
					'actionsTaken' => $row['actionsTaken'],
					'linkedAgentId' => (string)$agent->getUuid(),
					'linkedRunIds' => [],
					'createdAt' => gmdate('c'),
					'createdBy' => $row['createdBy'],
				],
				register: self::REGISTER_SLUG,
				schema: self::INCIDENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			return 1;
		} catch (Throwable $e) {
			$output->warning('Could not seed an incident: ' . $e->getMessage());
			$this->logger->error('[hermiq] Incident seed failed: ' . $e->getMessage());
			return 0;
		}//end try

	}//end seedIfMissing()

	/**
	 * Whether an incident matching this description already exists (system-wide
	 * read; a repair step runs with no user session).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param string $description The candidate incident's description.
	 *
	 * @return bool
	 */
	private function alreadySeeded(ObjectService $objectService, string $description): bool {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::INCIDENT_SCHEMA)
			->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['description'] ?? '') === $description) {
				return true;
			}
		}

		return false;
	}//end alreadySeeded()

	/**
	 * Find an agent object by its exact display name (system-wide read).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param string $name The agent name to match.
	 *
	 * @return ObjectEntity|null The matching agent, or null when none exists.
	 */
	private function findAgentByName(ObjectService $objectService, string $name): ?ObjectEntity {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::AGENT_SCHEMA)
			->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['name'] ?? '') === $name) {
				return $object;
			}
		}

		return null;
	}//end findAgentByName()
}//end class
