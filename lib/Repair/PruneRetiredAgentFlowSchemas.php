<?php

/**
 * Repair step that removes the retired `agentflow` / `agentflowrun` schemas
 * from OpenRegister instances that imported an older hermiq register.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes the retired agentflow object store's leftovers from the instance.
 *
 * Flows are authored in OpenRegister's native flow store (REQ-FA-002); the
 * `agentflow` / `agentflowrun` object store that predated it is retired — its
 * runner, resolver and frontend are gone, and this release drops the two
 * schemas from `hermiq_register.json`. Dropping them from the descriptor is
 * only half the retirement: OpenRegister's import UNIONS schema ids into the
 * register and never removes one, so on every instance that ever imported the
 * old descriptor both schemas keep their rows, their magic tables and their
 * place in the register's `schemas` array forever. This step is the other
 * half, mirroring `occ openregister:schemas:prune-retired` (the command this
 * borrows its mechanics from) so no operator has to remember to run it.
 *
 * IT DELETES ROWS, DELIBERATELY. Any surviving object is unreachable: no
 * hermiq code reads or writes the schemas any more, so what remains is demo
 * seed data and pre-pivot copies of flows that were long since re-seeded into
 * the native flow store. Keeping the rows would keep the schemas, and with
 * them the retired store's claim on the `agentflow` slug.
 *
 * App-scoped by construction: schemas are resolved with
 * `findByApplicationAndSlug(..., application: 'hermiq')`, so a same-slug
 * schema another app owns is out of reach.
 *
 * Idempotent: once pruned (or on a fresh install that never imported the old
 * descriptor) the lookup finds nothing and the step is a logged no-op. Never
 * raises — a repair step that aborts the upgrade over stale rows would trade
 * dead data for an instance that will not start.
 *
 * @spec openspec/specs/flow-authoring/spec.md#requirement-a-flow-is-stored-once-by-openregister-req-fa-002
 */
class PruneRetiredAgentFlowSchemas implements IRepairStep {

	/**
	 * The retired schema slugs, exactly as the old descriptor declared them.
	 *
	 * @var string[]
	 */
	public const RETIRED_SLUGS = [
		'agentflow',
		'agentflowrun',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for lazy OpenRegister resolution
	 *                                      (OpenRegister may be absent, or predate the deletion service).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step name, as shown by `occ upgrade`.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/flow-authoring/spec.md#requirement-a-flow-is-stored-once-by-openregister-req-fa-002
	 */
	public function getName(): string {
		return 'Prune the retired agentflow / agentflowrun schemas from OpenRegister';
	}//end getName()

	/**
	 * Resolve each retired slug within hermiq's ownership and cascade-delete it.
	 *
	 * Unlink-before-delete, in the prune command's order: a register left
	 * pointing at a deleted schema id makes every later slug resolution in
	 * that register scan a dangling reference.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-authoring/spec.md#requirement-a-flow-is-stored-once-by-openregister-req-fa-002
	 */
	public function run(IOutput $output): void {
		try {
			$schemaMapper = $this->container->get(SchemaMapper::class);
			$registerMapper = $this->container->get(RegisterMapper::class);
			$deletionService = $this->container->get(SchemaDeletionService::class);
		} catch (Throwable $e) {
			// OpenRegister absent, or too old to carry the deletion service.
			// Recoverable either way: the step re-runs on the next upgrade.
			$output->info('OpenRegister schema pruning unavailable — skipping the agentflow prune.');
			$this->logger->info('[hermiq] Agentflow schema prune skipped: ' . $e->getMessage());
			return;
		}

		foreach (self::RETIRED_SLUGS as $slug) {
			try {
				$schema = $schemaMapper->findByApplicationAndSlug(slug: $slug, application: Application::APP_ID);
				if ($schema === null) {
					// Already pruned, or never imported. This is the idempotent path.
					$output->info('Retired schema "' . $slug . '" is not present — nothing to prune.');
					continue;
				}

				$this->unlinkFromRegisters(registerMapper: $registerMapper, schema: $schema, output: $output);

				$result = $deletionService->cascadeDeleteSchema(schema: $schema);
				$output->info(
					'Pruned retired schema "' . $slug . '" (objects removed: '
					. (int)$result['deletedCount'] . ').'
				);
			} catch (Throwable $e) {
				// Reported, not raised — see the class docblock.
				$this->logger->warning(
					'[hermiq] Could not prune the retired schema "' . $slug . '": '
					. $e::class . ': ' . $e->getMessage(),
					['exception' => $e]
				);
				$output->warning('Could not prune retired schema "' . $slug . '": ' . $e->getMessage());
			}//end try
		}//end foreach

	}//end run()

	/**
	 * Drop the schema's id from every register that references it.
	 *
	 * Same coercion rule as `PruneRetiredSchemasCommand::unlinkSchemaId()`: the
	 * stored list holds ids as ints or strings depending on which import era
	 * wrote them, so `"74"` and `74` are the same reference and both go, while
	 * non-numeric entries are preserved untouched.
	 *
	 * @param RegisterMapper $registerMapper The register store.
	 * @param Schema $schema The schema being pruned.
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-authoring/spec.md#requirement-a-flow-is-stored-once-by-openregister-req-fa-002
	 */
	private function unlinkFromRegisters(RegisterMapper $registerMapper, Schema $schema, IOutput $output): void {
		$schemaId = (int)$schema->getId();

		foreach ($registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			$remaining = [];
			$changed = false;
			foreach ($register->getSchemas() as $ref) {
				if ((is_int($ref) === true || (is_string($ref) === true && is_numeric($ref) === true))
					&& (int)$ref === $schemaId
				) {
					$changed = true;
					continue;
				}

				$remaining[] = $ref;
			}

			if ($changed === false) {
				continue;
			}

			$register->setSchemas($remaining);
			$registerMapper->update($register);
			$output->info(
				'Unlinked retired schema id ' . $schemaId . ' from register "'
				. (string)$register->getSlug() . '".'
			);
		}//end foreach

	}//end unlinkFromRegisters()
}//end class
