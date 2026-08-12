<?php

/**
 * Hermiq Seed Agent Templates Repair Step.
 *
 * Idempotently seeds 5 starter `AgentTemplate` objects (agent-template-gallery) on
 * install/upgrade, grounded in the user-research journeys cited in proposal.md: Morning
 * briefing, Inbox triage, Website/monitor watcher, Weekly report, and Meeting-notes
 * summariser. Each is written through OpenRegister's ObjectService single write-path
 * (ADR-001/ADR-004), system-context (`_rbac: false, _multitenancy: false`) exactly like
 * `SeedComplianceControls`/`SeedAiFeatures`, matched by its seeded `name` so a re-run never
 * duplicates a template or overwrites an admin's edit — mirrors `SeedModelPolicies`'
 * "never overwrite an existing object" contract.
 *
 * Seeded templates deliberately leave `suggestedProvider`/`suggestedModel` empty: a
 * hard-coded provider guess would either be wrong for a given install or need constant
 * upkeep, and `AgentTemplateService::instantiate()` already resolves an empty suggestion
 * against the caller's own `TenantModelPolicy` default — the safe, portable choice.
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
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-6-seedagenttemplates-repair-step
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
 * Seed the 5 starter AgentTemplate objects via ObjectService (idempotent, by name).
 *
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-6-seedagenttemplates-repair-step
 */
class SeedAgentTemplates implements IRepairStep {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for AgentTemplate objects.
	 *
	 * @var string
	 */
	private const TEMPLATE_SCHEMA = 'agenttemplate';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for lazy ObjectService resolution
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
	 * @spec openspec/changes/agent-template-gallery/tasks.md#task-6-seedagenttemplates-repair-step
	 */
	public function getName(): string {
		return 'Seed starter agent templates (agent-template-gallery)';
	}//end getName()

	/**
	 * Seed each starter template that does not already exist (matched by name); an
	 * existing seeded template — including one an admin has since edited — is left
	 * untouched.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-template-gallery/tasks.md#task-6-seedagenttemplates-repair-step
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping agent-template seed.');
			$this->logger->warning('[hermiq] Agent-template seed skipped: ' . $e->getMessage());
			return;
		}

		$seeded = 0;
		foreach ($this->starterTemplates() as $template) {
			try {
				if ($this->nameExists(objectService: $objectService, name: $template['name']) === true) {
					continue;
				}

				// Explicit — never rely on the JSON-schema `default` being applied by
				// whatever OpenRegister/ObjectService version is running; a seed step
				// must be correct on its own.
				$template['state'] = 'active';
				$template['source'] = 'local';
				$template['quarantineReason'] = null;
				$template['scanReport'] = null;
				$template['createdBy'] = '';

				$objectService->saveObject(
					object: $template,
					register: self::REGISTER_SLUG,
					schema: self::TEMPLATE_SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
				$seeded++;
			} catch (Throwable $e) {
				$output->warning('Could not seed agent template "' . $template['name'] . '": ' . $e->getMessage());
				$this->logger->error('[hermiq] Agent-template seed failed for ' . $template['name'] . ': ' . $e->getMessage());
			}//end try
		}//end foreach

		$output->info('Agent-template seed complete (' . $seeded . ' new).');

	}//end run()

	/**
	 * The 5 starter templates (proposal.md's user-research journeys).
	 *
	 * @return array<int, array<string, mixed>> The seed objects.
	 */
	private function starterTemplates(): array {
		return [
			[
				'name' => 'Morning briefing',
				'description' => 'A daily briefing summarising your calendar, unread mail, and open '
					. 'tasks for the day ahead, delivered to Nextcloud Talk each morning.',
				'category' => 'productivity',
				'systemPrompt' => 'You are a morning briefing assistant. Summarise the user\'s '
					. 'calendar events for today, any unread mail that looks time-sensitive, and any open '
					. 'tasks that are due soon. Keep the briefing short, scannable, and organised under '
					. 'clear headings (Calendar, Mail, Tasks). Never fabricate an event, message, or task '
					. 'that was not actually found.',
				'suggestedProvider' => '',
				'suggestedModel' => '',
				'tools' => [],
				'skillRefs' => [],
				'suggestedSchedule' => ['kind' => 'cron', 'cronExpr' => '0 7 * * *', 'deliver' => 'talk'],
				'version' => '0.1.0',
			],
			[
				'name' => 'Inbox triage',
				'description' => 'Reviews unread mail on a fixed interval, flags anything that looks '
					. 'urgent or needs a reply, and files the rest into a short digest.',
				'category' => 'productivity',
				'systemPrompt' => 'You are an inbox triage assistant. Review the unread messages you '
					. 'are given, classify each as urgent / needs-reply / informational, and produce a short '
					. 'digest grouped by that classification. Flag anything that mentions a deadline or a '
					. 'direct question. Never draft or send a reply yourself — triage only.',
				'suggestedProvider' => '',
				'suggestedModel' => '',
				'tools' => [],
				'skillRefs' => [],
				'suggestedSchedule' => ['kind' => 'interval', 'intervalMinutes' => 60, 'deliver' => 'notification'],
				'version' => '0.1.0',
			],
			[
				'name' => 'Website/monitor watcher',
				'description' => 'Periodically fetches a page or endpoint and reports what changed '
					. 'since the last check — a lightweight change-monitor for a page you care about.',
				'category' => 'monitoring',
				'systemPrompt' => 'You are a change-monitoring assistant. Fetch the configured URL, '
					. 'compare it against what you last observed, and report a concise summary of what '
					. 'changed (new content, removed content, or "no change"). Never summarise content that '
					. 'was not actually present in the fetched page.',
				'suggestedProvider' => '',
				'suggestedModel' => '',
				'tools' => ['openconnector.fetch'],
				'skillRefs' => [],
				'suggestedSchedule' => ['kind' => 'interval', 'intervalMinutes' => 30, 'deliver' => 'talk'],
				'version' => '0.1.0',
			],
			[
				'name' => 'Weekly report',
				'description' => 'Compiles a weekly summary of what changed across your OpenRegister '
					. 'data — new/updated records, notable counts — delivered every Monday morning.',
				'category' => 'reporting',
				'systemPrompt' => 'You are a weekly reporting assistant. Summarise what changed in the '
					. 'organisation\'s data over the past 7 days: notable new or updated records, counts by '
					. 'category, and anything that stands out. Present the report under clear headings. '
					. 'Ground every figure in data you actually retrieved — never estimate a count.',
				'suggestedProvider' => '',
				'suggestedModel' => '',
				'tools' => ['openregister.searchObjects'],
				'skillRefs' => [],
				'suggestedSchedule' => ['kind' => 'cron', 'cronExpr' => '0 9 * * 1', 'deliver' => 'talk'],
				'version' => '0.1.0',
			],
			[
				'name' => 'Meeting-notes summariser',
				'description' => 'Turns a meeting transcript or set of raw notes into a short summary '
					. 'with clear decisions and action items — run on demand after a meeting.',
				'category' => 'productivity',
				'systemPrompt' => 'You are a meeting-notes assistant. Given a transcript or raw notes, '
					. 'produce: (1) a short summary of what was discussed, (2) a list of decisions made, and '
					. '(3) a list of action items with an owner where one is stated. Never invent a decision '
					. 'or action item that was not actually discussed.',
				'suggestedProvider' => '',
				'suggestedModel' => '',
				'tools' => [],
				'skillRefs' => [],
				'suggestedSchedule' => [],
				'version' => '0.1.0',
			],
		];

	}//end starterTemplates()

	/**
	 * Whether an AgentTemplate with the given name already exists (system context, no RBAC).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param string $name The seeded template's name.
	 *
	 * @return bool True when a matching object exists.
	 */
	private function nameExists(ObjectService $objectService, string $name): bool {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::TEMPLATE_SCHEMA)
			->findAll(
				config: ['filters' => ['name' => $name], 'limit' => 50],
				_rbac: false,
				_multitenancy: false
			);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['name'] ?? '') === $name) {
				return true;
			}
		}

		return false;
	}//end nameExists()
}//end class
