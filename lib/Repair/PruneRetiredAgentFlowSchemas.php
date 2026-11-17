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

use OCA\Hermiq\Kernel\AppIdentity;
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
		$run = $this->startRun(output: $output);
		if ($run === null) {
			return;
		}

		foreach (self::RETIRED_SLUGS as $slug) {
			try {
				$this->prune(run: $run, slug: $slug);
			} catch (Throwable $e) {
				// Reported, not raised — see the class docblock.
				$this->logger->warning(
					'[hermiq] Could not prune the retired schema "' . $slug . '": '
					. $e::class . ': ' . $e->getMessage(),
					['exception' => $e]
				);
				$output->warning('Could not prune retired schema "' . $slug . '": ' . $e->getMessage());
			}
		}

	}//end run()

	/**
	 * Resolve the OpenRegister stores, or report and answer null when OpenRegister is
	 * absent or too old to carry the deletion service.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return SchemaPruneRun|null
	 */
	private function startRun(IOutput $output): ?SchemaPruneRun {
		try {
			return new SchemaPruneRun(
				schemaMapper: $this->container->get(SchemaMapper::class),
				registerMapper: $this->container->get(RegisterMapper::class),
				deletionService: $this->container->get(SchemaDeletionService::class),
				output: $output
			);
		} catch (Throwable $e) {
			// OpenRegister absent, or too old to carry the deletion service.
			// Recoverable either way: the step re-runs on the next upgrade.
			$output->info('OpenRegister schema pruning unavailable — skipping the agentflow prune.');
			$this->logger->info('[hermiq] Agentflow schema prune skipped: ' . $e->getMessage());
			return null;
		}
	}//end startRun()

	/**
	 * Prune one retired schema: unlink it from every register, then cascade-delete it.
	 *
	 * @param SchemaPruneRun $run The run.
	 * @param string $slug The retired schema's slug.
	 *
	 * @return void
	 */
	private function prune(SchemaPruneRun $run, string $slug): void {
		$schema = $run->schemaMapper->findByApplicationAndSlug(slug: $slug, application: AppIdentity::APP_ID);
		if ($schema === null) {
			// Already pruned, or never imported. This is the idempotent path.
			$run->output->info('Retired schema "' . $slug . '" is not present — nothing to prune.');
			return;
		}

		$this->unlinkFromRegisters(run: $run, schema: $schema);

		$result = $run->deletionService->cascadeDeleteSchema(schema: $schema);
		$run->output->info(
			'Pruned retired schema "' . $slug . '" (objects removed: '
			. (int)$result['deletedCount'] . ').'
		);
	}//end prune()

	/**
	 * Drop the schema's id from every register that references it.
	 *
	 * Same coercion rule as `PruneRetiredSchemasCommand::unlinkSchemaId()`: the
	 * stored list holds ids as ints or strings depending on which import era
	 * wrote them, so `"74"` and `74` are the same reference and both go, while
	 * non-numeric entries are preserved untouched.
	 *
	 * @param SchemaPruneRun $run The run.
	 * @param Schema $schema The schema being pruned.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-authoring/spec.md#requirement-a-flow-is-stored-once-by-openregister-req-fa-002
	 */
	private function unlinkFromRegisters(SchemaPruneRun $run, Schema $schema): void {
		$schemaId = (int)$schema->getId();

		foreach ($run->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			$remaining = $this->refsWithout(refs: $register->getSchemas(), schemaId: $schemaId);
			if (count($remaining) === count($register->getSchemas())) {
				continue;
			}

			$register->setSchemas($remaining);
			$run->registerMapper->update($register);
			$run->output->info(
				'Unlinked retired schema id ' . $schemaId . ' from register "'
				. (string)$register->getSlug() . '".'
			);
		}

	}//end unlinkFromRegisters()

	/**
	 * A register's schema references without every stored form of one schema id.
	 *
	 * @param array $refs The register's schema references.
	 * @param int $schemaId The schema id to drop.
	 *
	 * @return array The remaining references, in order.
	 */
	private function refsWithout(array $refs, int $schemaId): array {
		$remaining = [];
		foreach ($refs as $ref) {
			if ((is_int($ref) === true || (is_string($ref) === true && is_numeric($ref) === true))
				&& (int)$ref === $schemaId
			) {
				continue;
			}

			$remaining[] = $ref;
		}

		return $remaining;
	}//end refsWithout()
}//end class
