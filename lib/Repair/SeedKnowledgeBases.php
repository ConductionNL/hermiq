<?php

/**
 * Hermiq SeedKnowledgeBases repair step.
 *
 * Demo data for memory-knowledge-bases: the "Inkoopbeleid" knowledge base for
 * Gemeente Tilburg, with a team folder source and an integriq-synced
 * SharePoint folder, attached to the demo agent "Inkoopassistent" when that
 * agent exists. Runs only with Nextcloud's debug (dev-mode) flag on, and only
 * once: an existing knowledge base of that name is left alone.
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the demo knowledge base in dev mode.
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
 */
class SeedKnowledgeBases implements IRepairStep {

	use FindsSeedObjectByName;
	use \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;

	private const REGISTER_SLUG = 'hermiq';

	private const KNOWLEDGE_BASE_SCHEMA = 'agentknowledgebase';

	private const AGENT_SCHEMA = 'agent';

	public const KNOWLEDGE_BASE_NAME = 'Inkoopbeleid';

	public const AGENT_NAME = 'Inkoopassistent';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For OpenRegister's ObjectService, which may be absent.
	 * @param IConfig            $config    The dev-mode (debug) flag.
	 * @param LoggerInterface    $logger    Where a skipped or failed seed is noted.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	public function getName(): string {
		return 'Seed demo knowledge base (memory-knowledge-bases)';
	}//end getName()

	/**
	 * Seed the demo knowledge base when dev mode is on.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	public function run(IOutput $output): void {
		if ($this->config->getSystemValueBool('debug', false) === false) {
			$output->info('Dev mode is off — demo knowledge base not seeded.');
			return;
		}

		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping demo knowledge base seed.');
			$this->logger->warning('[hermiq] demo knowledge base seed skipped: '.$e->getMessage());
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->seed(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * Write the knowledge base once and attach it to the demo agent when present.
	 *
	 * @param ObjectService $objectService OpenRegister's objects.
	 * @param IOutput       $output        The repair output.
	 *
	 * @return void
	 */
	private function seed(ObjectService $objectService, IOutput $output): void {
		try {
			if ($this->findByName(objectService: $objectService, schema: self::KNOWLEDGE_BASE_SCHEMA, name: self::KNOWLEDGE_BASE_NAME) !== null) {
				$output->info(self::KNOWLEDGE_BASE_NAME.' knowledge base already present — skipped.');
				return;
			}

			$saved = $objectService->saveObject(
				object: self::knowledgeBase(),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::KNOWLEDGE_BASE_SCHEMA)
				),
				_rbac: false,
				_multitenancy: false
			);
			$output->info(self::KNOWLEDGE_BASE_NAME.' knowledge base seeded.');

			$this->attach(objectService: $objectService, knowledgeBaseUuid: (string)$saved->getUuid(), output: $output);
		} catch (Throwable $e) {
			$output->warning('Could not seed the demo knowledge base: '.$e->getMessage());
			$this->logger->error('[hermiq] demo knowledge base seed failed: '.$e->getMessage());
		}
	}//end seed()

	/**
	 * Add the knowledge base to the demo agent's knowledgeBaseRefs, when the agent exists.
	 *
	 * @param ObjectService $objectService     OpenRegister's objects.
	 * @param string        $knowledgeBaseUuid The seeded knowledge base.
	 * @param IOutput       $output            The repair output.
	 *
	 * @return void
	 */
	private function attach(ObjectService $objectService, string $knowledgeBaseUuid, IOutput $output): void {
		$agent = $this->findByName(objectService: $objectService, schema: self::AGENT_SCHEMA, name: self::AGENT_NAME);
		if ($agent === null || $knowledgeBaseUuid === '') {
			$output->info(self::AGENT_NAME.' agent not found — the knowledge base is not attached.');
			return;
		}

		$data = $agent->getObject();
		$refs = ($data['knowledgeBaseRefs'] ?? []);
		if (is_array($refs) === false) {
			$refs = [];
		}

		$refs = array_values($refs);
		if (in_array($knowledgeBaseUuid, $refs, true) === true) {
			return;
		}

		unset($data['@self'], $data['id']);
		$data['knowledgeBaseRefs'] = [...$refs, $knowledgeBaseUuid];
		$objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA),
				uuid: (string)$agent->getUuid()
			),
			_rbac: false,
			_multitenancy: false
		);
		$output->info(self::KNOWLEDGE_BASE_NAME.' attached to '.self::AGENT_NAME.'.');
	}//end attach()

	/**
	 * The demo knowledge base, as design.md's seed data names it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-outside-sources-come-in-through-integriq-req-kb-004
	 */
	public static function knowledgeBase(): array {
		return [
			'name' => self::KNOWLEDGE_BASE_NAME,
			'description' => 'Het inkoop- en aanbestedingsbeleid, de inkoopvoorwaarden en de modelcontracten.',
			'folderPath' => 'Team Inkoop/Kennisbank',
			'sources' => [
				['type' => 'folder', 'path' => 'Team Inkoop/Kennisbank'],
				[
					'type' => 'folder',
					'path' => 'Integraties/SharePoint/Beleidsdocumenten',
					'label' => 'Synced by integriq from SharePoint',
				],
			],
		];
	}//end knowledgeBase()
}//end class
