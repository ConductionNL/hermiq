<?php

/**
 * Hermiq SeedAgentBuilder repair step.
 *
 * Seeds the `agent-builder` skill and the "Agent builder" agent
 * (agents-plain-language-builder). A person describes the agent they want in
 * chat; the builder answers with a draft in a `hermiq-agent-draft` block, and
 * the chat page opens that draft in the full agent form. The builder holds no
 * tools at all: it cannot create, change or delete an agent, and the agent
 * exists only when the person saves the form.
 *
 * Idempotent by name: an existing skill or agent is left as it is, so an
 * admin's edit or switch-off survives every upgrade.
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-described-agent-becomes-a-draft-in-chat-req-agbuild-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Service\SeedFreshnessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the agent-builder skill and the Agent builder agent.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-described-agent-becomes-a-draft-in-chat-req-agbuild-001
 */
class SeedAgentBuilder implements IRepairStep {
	use \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * The skill schema slug.
	 */
	private const SKILL_SCHEMA = 'agentskill';

	/**
	 * The agent schema slug.
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * The seeded skill's name.
	 */
	public const SKILL_NAME = 'agent-builder';

	/**
	 * The seeded agent's name.
	 */
	public const AGENT_NAME = 'Agent builder';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface   $container OpenRegister's services, resolved lazily.
	 * @param LoggerInterface      $logger    PSR-3 logger.
	 * @param SeedFreshnessService $freshness Stamps the seeded skill fresh.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly SeedFreshnessService $freshness,
	) {
	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Seed the Agent builder agent and its skill (agents-plain-language-builder)';
	}//end getName()

	/**
	 * Seed what is missing; never fails the upgrade.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-described-agent-becomes-a-draft-in-chat-req-agbuild-001
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available, skipping the Agent builder seed.');
			$this->logger->warning('[hermiq] Agent builder seed skipped: ' . $e->getMessage());
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
	 * The seeded agent: shared with the organisation, the skill installed, no tools.
	 *
	 * Public so a test can assert the posture without a live OpenRegister.
	 *
	 * @param string $skillUuid The agent-builder skill.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-described-agent-becomes-a-draft-in-chat-req-agbuild-001
	 */
	public function agentObject(string $skillUuid): array {
		return [
			'name' => self::AGENT_NAME,
			'description' => 'Describe the agent you want and get a draft you can open in the agent form.',
			'icon' => 'RobotOutline',
			'active' => true,
			'isPrivate' => false,
			// No tools: the builder writes text, never an agent. The draft is
			// checked and saved by the person, through the normal agent form.
			'tools' => [],
			'delegationAllowlist' => [],
			'maxToolCalls' => 5,
			'skillInstalls' => [$skillUuid],
			'prompt' => 'You help a person design an AI agent in this Nextcloud. Follow the agent-builder '
				. 'skill: ask what is missing, then answer with a short explanation and one hermiq-agent-draft '
				. 'block. Never say the agent exists. The person opens the draft and saves it themselves.',
			'enableRag' => false,
			'searchObjects' => false,
			'searchFiles' => false,
		];
	}//end agentObject()

	/**
	 * Seed the skill, then the agent, then record the install on the skill.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 */
	private function seed(object $objectService, IOutput $output): void {
		try {
			$skill = $this->findByName(objectService: $objectService, schema: self::SKILL_SCHEMA, name: self::SKILL_NAME);
			if ($skill === null) {
				$skill = $objectService->saveObject(
					object: $this->freshness->stampFresh(seed: $this->skillObject()),
					register: self::REGISTER_SLUG,
					schema: self::SKILL_SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
			}

			$agent = $this->findByName(objectService: $objectService, schema: self::AGENT_SCHEMA, name: self::AGENT_NAME);
			if ($agent !== null) {
				$output->info('Agent builder already present, left as it is.');
				return;
			}

			$skillUuid = (string)$skill->getUuid();
			$agent = $objectService->saveObject(
				object: $this->agentObject(skillUuid: $skillUuid),
				register: self::REGISTER_SLUG,
				schema: self::AGENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);

			$skillData = $skill->getObject();
			$skillData['installedOn'] = array_values(array_unique(array_merge((array)($skillData['installedOn'] ?? []), [(string)$agent->getUuid()])));
			$objectService->saveObject(
				object: $skillData,
				register: self::REGISTER_SLUG,
				schema: self::SKILL_SCHEMA,
				uuid: $skillUuid,
				_rbac: false,
				_multitenancy: false
			);

			$output->info('Agent builder seeded.');
		} catch (Throwable $e) {
			$output->warning('Could not seed the Agent builder: ' . $e->getMessage());
			$this->logger->error('[hermiq] Agent builder seed failed: ' . $e->getMessage());
		}//end try
	}//end seed()

	/**
	 * The agent-builder skill.
	 *
	 * @return array<string, mixed>
	 */
	private function skillObject(): array {
		return [
			'name' => self::SKILL_NAME,
			'description' => 'Turn a plain description of an agent into a draft the person reviews.',
			'frontmatter' => "name: agent-builder\ndescription: Turn a plain description of an agent into a draft the person reviews.\nversion: 0.1.0",
			'body' => $this->skillBody(),
			'files' => [],
			'state' => 'active',
			'source' => 'local',
			'createdBy' => '',
			'installedOn' => [],
		];
	}//end skillObject()

	/**
	 * The skill's instructions: the draft format and three worked examples.
	 *
	 * @return string
	 */
	private function skillBody(): string {
		return <<<'MARKDOWN'
        # Agent builder

        You turn a person's description of an agent into a draft they open in the agent form.
        You never create the agent. The person checks the draft and saves it.

        ## How to work

        1. Read the description. Ask one question at a time about what is missing: what the agent
           reads, what it produces, who uses it, and when it runs.
        2. Answer with two or three sentences that explain the agent, then one draft block.
        3. Name only tools the person needs. Leave tools out when you are unsure; the form shows
           the catalogue.
        4. Never write "I created the agent". Say "Open the draft to check it and save it."

        ## The draft block

        ```hermiq-agent-draft
        {"name": "Objections digest",
         "description": "Summarises new objections every Monday for the legal team.",
         "prompt": "You summarise the objections filed last week. Group them by case and name the deadline of each.",
         "provider": "", "model": "",
         "tools": ["openregister.searchObjects"],
         "sharing": {"mode": "groups", "groups": ["legal"]},
         "schedule": {"kind": "cron", "cronExpr": "0 8 * * 1", "prompt": "Write this week's objections digest."},
         "startFields": []}
        ```

        - `sharing.mode` is `only-me`, `groups` or `organisation`.
        - `schedule` is optional: `{"kind": "once"|"interval"|"cron", "cronExpr", "intervalMinutes", "prompt"}`.
        - Leave `provider` and `model` empty unless the person names one.
        - `startFields` lists questions the person answers before a chat: `{key, label, type, options, required}`.

        ## More examples

        - A permit intake helper: no schedule, shared with the permits group, asks for the case number
          as a required start field (`{"key": "case_no", "label": "Case number", "type": "text", "required": true}`).
        - A meeting minutes drafter: only for the person, no tools, instructions that turn notes into
          decisions, actions and owners.
        MARKDOWN;
	}//end skillBody()

	/**
	 * Find a seeded object by name (system context, no RBAC).
	 *
	 * @param ObjectService $objectService The object service.
	 * @param string        $schema        The schema slug.
	 * @param string        $name          The name.
	 *
	 * @return ObjectEntity|null
	 */
	private function findByName(ObjectService $objectService, string $schema, string $name): ?ObjectEntity {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema($schema)
			->findAll(
				config: ['filters' => ['name' => $name], 'limit' => 50],
				_rbac: false,
				_multitenancy: false
			);

		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity && (string)($object->getObject()['name'] ?? '') === $name) {
				return $object;
			}
		}

		return null;
	}//end findByName()
}//end class
