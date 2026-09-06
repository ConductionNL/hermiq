<?php
/**
 * Unit tests for the PruneRetiredAgentFlowSchemas repair step.
 *
 * The step exists because dropping a schema from `hermiq_register.json` does
 * not remove it from an instance: OpenRegister's import unions schema ids and
 * never prunes one, so the retired `agentflow` / `agentflowrun` schemas keep
 * their rows, their tables and their place in the register forever.
 *
 * A repair step must fail loudly in tests and quietly in production: it runs
 * during `occ upgrade`, so an exception here aborts an upgrade. These tests
 * therefore pin WHICH slugs are resolved (app-scoped, so another app's
 * same-slug schema is out of reach), that a second run after a prune is a
 * no-op rather than a second delete, and that a failed delete is reported
 * instead of raised.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\PruneRetiredAgentFlowSchemas;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Repair\PruneRetiredAgentFlowSchemas
 *
 * @spec openspec/specs/flow-authoring/spec.md#requirement-a-flow-is-stored-once-by-openregister-req-fa-002
 */
final class PruneRetiredAgentFlowSchemasTest extends TestCase {

	/**
	 * Build the step around a container serving the given collaborators.
	 *
	 * @param SchemaMapper $schemaMapper The schema store.
	 * @param RegisterMapper $registerMapper The register store.
	 * @param SchemaDeletionService $deletionService The cascade teardown.
	 *
	 * @return PruneRetiredAgentFlowSchemas The step under test.
	 */
	private function step(
		SchemaMapper $schemaMapper,
		RegisterMapper $registerMapper,
		SchemaDeletionService $deletionService,
	): PruneRetiredAgentFlowSchemas {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($schemaMapper, $registerMapper, $deletionService) {
				return match ($id) {
					SchemaMapper::class => $schemaMapper,
					RegisterMapper::class => $registerMapper,
					SchemaDeletionService::class => $deletionService,
					default => throw new RuntimeException('not available in this test: ' . $id),
				};
			}
		);

		return new PruneRetiredAgentFlowSchemas(
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end step()

	/**
	 * A schema entity with the given id and slug (the PSR-4 test stub, which
	 * carries real state — see tests/bootstrap.php).
	 *
	 * @param int $id The schema id.
	 * @param string $slug The schema slug.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);

		return $schema;
	}//end schema()

	/**
	 * Both retired slugs are resolved APP-SCOPED and cascade-deleted.
	 *
	 * Asserts the ARGUMENTS, not a call count: an app filter other than
	 * `hermiq` would reach a same-slug schema another app owns, which is the
	 * failure the scoping exists to prevent.
	 *
	 * @return void
	 */
	public function testPrunesBothRetiredSlugsAppScoped(): void {
		$flowSchema = $this->schema(id: 74, slug: 'agentflow');
		$runSchema = $this->schema(id: 75, slug: 'agentflowrun');

		$lookups = [];
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findByApplicationAndSlug')->willReturnCallback(
			static function (string $slug, string $application) use ($flowSchema, $runSchema, &$lookups): ?Schema {
				$lookups[] = [$slug, $application];
				return match ($slug) {
					'agentflow' => $flowSchema,
					'agentflowrun' => $runSchema,
					default => null,
				};
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([]);

		$deleted = [];
		$deletionService = $this->createMock(SchemaDeletionService::class);
		$deletionService->expects($this->exactly(2))->method('cascadeDeleteSchema')->willReturnCallback(
			static function (Schema $schema) use (&$deleted): array {
				$deleted[] = $schema->getSlug();
				return ['deletedCount' => 3, 'deletedUuids' => [], 'tableDropped' => true];
			}
		);

		$this->step($schemaMapper, $registerMapper, $deletionService)
			->run($this->createMock(IOutput::class));

		$this->assertSame(
			[['agentflow', 'hermiq'], ['agentflowrun', 'hermiq']],
			$lookups,
			'Both retired slugs must be resolved, and only within hermiq\'s ownership.'
		);
		$this->assertSame(['agentflow', 'agentflowrun'], $deleted);
	}//end testPrunesBothRetiredSlugsAppScoped()

	/**
	 * The schema id is unlinked from a referencing register BEFORE the delete,
	 * in every stored form — the list holds ids as ints or strings depending
	 * on which import era wrote them — while non-numeric entries survive.
	 *
	 * @return void
	 */
	public function testUnlinksEveryStoredFormOfTheSchemaId(): void {
		$flowSchema = $this->schema(id: 74, slug: 'agentflow');

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findByApplicationAndSlug')->willReturnCallback(
			static fn (string $slug, string $application): ?Schema => ($slug === 'agentflow') ? $flowSchema : null
		);

		$register = new Register();
		$register->setId(1);
		$register->setSlug('hermiq');
		$register->setSchemas([12, '74', 74, 'not-a-number', 15]);

		$updated = [];
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);
		$registerMapper->expects($this->once())->method('update')->willReturnCallback(
			static function (Register $entity) use (&$updated): Register {
				$updated = $entity->getSchemas();
				return $entity;
			}
		);

		$deletionService = $this->createMock(SchemaDeletionService::class);
		$deletionService->method('cascadeDeleteSchema')
			->willReturn(['deletedCount' => 0, 'deletedUuids' => [], 'tableDropped' => true]);

		$this->step($schemaMapper, $registerMapper, $deletionService)
			->run($this->createMock(IOutput::class));

		$this->assertSame(
			[12, 'not-a-number', 15],
			$updated,
			'Both the int and the string form of the id must go; unrelated entries must survive.'
		);
	}//end testUnlinksEveryStoredFormOfTheSchemaId()

	/**
	 * 🔑 Idempotency, proven rather than claimed: after a prune the lookup
	 * finds nothing, so a second run performs ZERO deletes and ZERO register
	 * writes. A step that deleted again on re-run would be reaching a schema
	 * some later change legitimately re-created under the same slug.
	 *
	 * @return void
	 */
	public function testSecondRunAfterPruneIsANoOp(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findByApplicationAndSlug')->willReturn(null);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->expects($this->never())->method('findAll');
		$registerMapper->expects($this->never())->method('update');

		$deletionService = $this->createMock(SchemaDeletionService::class);
		$deletionService->expects($this->never())->method('cascadeDeleteSchema');

		$step = $this->step($schemaMapper, $registerMapper, $deletionService);
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->addToAssertionCount(1);
	}//end testSecondRunAfterPruneIsANoOp()

	/**
	 * A container that cannot serve OpenRegister's deletion service produces a
	 * SKIP, not an aborted upgrade — and nothing is deleted.
	 *
	 * @return void
	 */
	public function testSkipsWhenOpenRegisterIsUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		$step = new PruneRetiredAgentFlowSchemas(
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);
		$step->run($output);
	}//end testSkipsWhenOpenRegisterIsUnavailable()

	/**
	 * A delete that throws is reported and the step continues with the next
	 * slug: an upgrade must not abort over stale rows, and one broken slug
	 * must not shield the other from its prune.
	 *
	 * @return void
	 */
	public function testAFailedDeleteIsReportedAndTheNextSlugStillPrunes(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findByApplicationAndSlug')->willReturnCallback(
			fn (string $slug, string $application): ?Schema => $this->schema(
				id: ($slug === 'agentflow') ? 74 : 75,
				slug: $slug
			)
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([]);

		$deleted = [];
		$deletionService = $this->createMock(SchemaDeletionService::class);
		$deletionService->method('cascadeDeleteSchema')->willReturnCallback(
			static function (Schema $schema) use (&$deleted): array {
				if ($schema->getSlug() === 'agentflow') {
					throw new RuntimeException('phase 1 rollback');
				}

				$deleted[] = $schema->getSlug();
				return ['deletedCount' => 0, 'deletedUuids' => [], 'tableDropped' => true];
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step($schemaMapper, $registerMapper, $deletionService)->run($output);

		$this->assertSame(['agentflowrun'], $deleted, 'The second slug must still be pruned.');
	}//end testAFailedDeleteIsReportedAndTheNextSlugStillPrunes()
}//end class
