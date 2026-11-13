<?php

/**
 * Make every existing agent Memory the agent's shared memory.
 *
 * The change memory-project-scopes gives `Memory` a `scope` (`shared` or `project`), and
 * `MemoryService::getMemory()` finds the shared memory by `scope: shared`.
 * A Memory written before that carries no scope, so it would not be found and
 * a second, empty shared memory would be created beside it. This step sets
 * `scope: shared` on every Memory without one, so the existing memory becomes
 * the shared layer without moving an entry (design D1).
 *
 * Idempotent: a rerun finds nothing to change. It counts the unscoped objects
 * after the pass and fails loudly when any remain, rather than letting a second
 * shared memory appear unnoticed (design, Risks).
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Sets `scope: shared` on every Memory that has none.
 *
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
 */
class ScopeExistingMemories implements IRepairStep {
	use \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;

	public const REGISTER_SLUG = 'hermiq';

	public const MEMORY_SCHEMA = 'memory';

	/**
	 * How many Memory objects one read returns.
	 *
	 * @var int
	 */
	public const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Serves OpenRegister's ObjectService when it is installed.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
	 */
	public function getName(): string {
		return 'Make every existing agent memory the shared memory (memory-project-scopes)';
	}//end getName()

	/**
	 * Run the step under a system identity, an upgrade having no session.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When Memory objects without a scope remain after the pass.
	 *
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping the memory scope backfill.');
			$this->logger->warning('[hermiq] Memory scope backfill skipped: ' . $e->getMessage());
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->runInner(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * Scope every unscoped Memory, then check that none is left.
	 *
	 * @param ObjectService $objectService The object service.
	 * @param IOutput       $output        The output.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When Memory objects without a scope remain after the pass.
	 */
	private function runInner(ObjectService $objectService, IOutput $output): void {
		$before = $this->unscoped(objectService: $objectService);
		foreach ($before as $memory) {
			$objectService->patchObject(
				objectId: (string)$memory->getUuid(),
				data: ['scope' => 'shared'],
				register: self::REGISTER_SLUG,
				schema: self::MEMORY_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		}

		$left = count($this->unscoped(objectService: $objectService));
		if ($left > 0) {
			throw new RuntimeException(
				'Memory scope backfill left ' . $left . ' agent memory object(s) without a scope; '
				. 'a second shared memory would be created beside each. Rerun the upgrade.'
			);
		}

		$output->info('Memory scope backfill: ' . count($before) . ' agent memory object(s) made the shared memory.');
	}//end runInner()

	/**
	 * Every Memory, across tenants, that carries no scope.
	 *
	 * @param ObjectService $objectService The object service.
	 *
	 * @return array<int,ObjectEntity>
	 */
	private function unscoped(ObjectService $objectService): array {
		$unscoped = [];
		$offset   = 0;
		do {
			$page = $objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::MEMORY_SCHEMA)
				->findAll(
					config: ['limit' => self::PAGE, 'offset' => $offset],
					_rbac: false,
					_multitenancy: false
				);

			foreach ($page as $memory) {
				$scope = '';
				if ($memory instanceof ObjectEntity) {
					$scope = trim((string)($memory->getObject()['scope'] ?? ''));
				}

				if ($memory instanceof ObjectEntity && $scope === '' && trim((string)$memory->getUuid()) !== '') {
					$unscoped[] = $memory;
				}
			}

			$offset += self::PAGE;
		} while (count($page) === self::PAGE);

		return $unscoped;
	}//end unscoped()
}//end class
