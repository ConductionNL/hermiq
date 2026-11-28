<?php

/**
 * Hermiq ReportToolessAgents
 *
 * Lists every agent whose `tools` is unset or empty, and changes none of them.
 *
 * An agent with no configured tools gets no tools (OpenRegister's
 * `ToolGrantResolver::resolve()`): there is no override that hands it the
 * discovered catalog any more. On the development instance that made 99 of
 * 111 agents text-only. This step makes that visible on every upgrade, and to
 * an operator who runs `occ maintenance:repair`, so it reads as a decision to
 * make per agent rather than as agents going quiet.
 *
 * It deliberately grants nothing. Back-filling the tools these agents used to
 * receive implicitly would write ~101,000 tokens per turn into each of them as
 * an explicit grant nobody chose, which is the behaviour the change removes.
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
 * @spec openspec/changes/tool-scope-security-default/specs/agent-tool-governance/spec.md#requirement-affected-agents-must-be-reportable
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
 * Reports the agents that run without tools; read only.
 *
 * @spec openspec/changes/tool-scope-security-default/specs/agent-tool-governance/spec.md#requirement-affected-agents-must-be-reportable
 */
class ReportToolessAgents implements IRepairStep {

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
	 * The most agents read in one run.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for lazy ObjectService
	 *                                      resolution (OpenRegister may not be
	 *                                      installed yet).
	 * @param LoggerInterface    $logger    PSR-3 logger.
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
	 * @spec openspec/changes/tool-scope-security-default/specs/agent-tool-governance/spec.md#requirement-affected-agents-must-be-reportable
	 */
	public function getName(): string {
		return 'Report agents with no tools configured (tool-scope-security-default)';
	}//end getName()

	/**
	 * List each agent with unset or empty `tools` by name and id; write nothing.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tool-scope-security-default/specs/agent-tool-governance/spec.md#requirement-affected-agents-must-be-reportable
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
			$agents        = $objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::AGENT_SCHEMA)
				->findAll(config: ['limit' => self::LIMIT], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available, or the agents could not be read: the report of agents without tools is skipped.');
			$this->logger->warning('[hermiq] Report of agents without tools skipped: ' . $e->getMessage());
			return;
		}

		$toolless = self::toolless(agents: (array)$agents);
		$output->info(count($toolless) . ' agent(s) have no tools configured and run text-only. Grant tools on the agent to give it any.');
		foreach ($toolless as $uuid => $name) {
			$output->info('No tools: "' . $name . '" (' . $uuid . ')');
		}

		if ($toolless !== []) {
			$this->logger->info('[hermiq] Agents without tools: {agents}', ['agents' => $toolless]);
		}
	}//end run()

	/**
	 * The agents whose `tools` is unset, null or an empty list, by uuid.
	 *
	 * A non-empty `tools` is configured, even when its grants resolve to
	 * nothing: that case is an error the tool loop reports on its own.
	 *
	 * @param array<int, mixed> $agents The agent objects.
	 *
	 * @return array<string, string> Name by uuid.
	 *
	 * @spec openspec/changes/tool-scope-security-default/specs/agent-tool-governance/spec.md#requirement-affected-agents-must-be-reportable
	 */
	public static function toolless(array $agents): array {
		$toolless = [];
		foreach ($agents as $agent) {
			if (($agent instanceof ObjectEntity) === false) {
				continue;
			}

			$data  = (array)$agent->getObject();
			$tools = ($data['tools'] ?? null);
			if ($tools === null || $tools === [] || $tools === '') {
				$toolless[(string)$agent->getUuid()] = (string)($data['name'] ?? '');
			}
		}

		return $toolless;
	}//end toolless()
}//end class
