<?php

/**
 * Hermiq RefreshAgentTalkBots repair step.
 *
 * Re-registers the Talk bot of every Talk-enabled agent on install and upgrade.
 * An agent's bot is otherwise installed only when the agent is saved, so a bot
 * whose registration spreed lost, or one that predates a change to the bot's
 * name, url or features, would stay stale until somebody edits the agent.
 * spreed's install is an upsert on (url, secret), so refreshing an agent whose
 * bot is current changes nothing.
 *
 * A no-op, never a failure, when Talk or OpenRegister is absent.
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
 * @spec openspec/changes/instant-talk/tasks.md#4-multi-agent-rooms
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Kernel\Contracts\TalkBotInstallerInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes the per-agent Talk bots on install and upgrade.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/tasks.md#4-multi-agent-rooms
 */
class RefreshAgentTalkBots implements IRepairStep {

	/**
	 * OpenRegister's object service, resolved lazily: OpenRegister may be absent.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The most agents one run reads. A larger fleet is refreshed on the next
	 * upgrade or when the agent is saved, and the step says so.
	 *
	 * @var int
	 */
	public const MAX_AGENTS = 500;

	/**
	 * Constructor.
	 *
	 * @param TalkBotInstallerInterface $installer Installs each agent's bot through spreed.
	 * @param ContainerInterface        $container Resolves OpenRegister's object service.
	 * @param LoggerInterface           $logger    PSR-3 logger.
	 */
	public function __construct(
		private readonly TalkBotInstallerInterface $installer,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The repair step's human-readable name.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#4-multi-agent-rooms
	 */
	public function getName(): string {
		return 'Refresh the Talk bots of Talk-enabled Hermiq agents';
	}//end getName()

	/**
	 * Run the step.
	 *
	 * Never throws: Talk is an optional runtime dependency, so a failure here
	 * must not be able to break an install or upgrade.
	 *
	 * @param IOutput $output Migration output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#4-multi-agent-rooms
	 */
	public function run(IOutput $output): void {
		try {
			$agents = $this->talkEnabledAgents();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[RefreshAgentTalkBots] Could not read the Talk-enabled agents',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			$output->warning('Could not refresh the agent Talk bots: ' . $e->getMessage());
			return;
		}

		if ($agents === []) {
			$output->info('No Talk-enabled agents — no agent Talk bot to refresh.');
			return;
		}

		$refreshed = 0;
		foreach ($agents as $agentId => $name) {
			if ($this->refresh(agentId: $agentId, agentName: $name) === true) {
				$refreshed++;
			}
		}

		if ($refreshed === 0) {
			$output->info('Talk is not available — skipping the agent Talk bot refresh.');
			return;
		}

		$output->info(sprintf('Refreshed the Talk bot of %d of %d Talk-enabled agents.', $refreshed, count($agents)));
		if (count($agents) >= self::MAX_AGENTS) {
			$output->warning(sprintf('Only the first %d agents were read; the rest refresh when they are saved.', self::MAX_AGENTS));
		}

	}//end run()

	/**
	 * Refresh one agent's bot; a failure is logged and counts as not refreshed.
	 *
	 * @param string $agentId   The agent uuid.
	 * @param string $agentName The agent's display name.
	 *
	 * @return bool True when the bot was installed or renamed.
	 */
	private function refresh(string $agentId, string $agentName): bool {
		try {
			return $this->installer->installForAgent(agentId: $agentId, agentName: $agentName);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[RefreshAgentTalkBots] Could not refresh an agent Talk bot',
				context: ['file' => __FILE__, 'line' => __LINE__, 'agentId' => $agentId, 'error' => $e->getMessage()]
			);
			return false;
		}

	}//end refresh()

	/**
	 * The Talk-enabled, not trashed agents, uuid to name.
	 *
	 * RBAC and multitenancy off: this is a system step refreshing every
	 * tenant's bots, and it reads only the uuid, name and opt-in.
	 *
	 * @return array<string, string> The agents.
	 */
	private function talkEnabledAgents(): array {
		if ($this->container->has(self::OBJECT_SERVICE) === false) {
			return [];
		}

		$rows = $this->container->get(self::OBJECT_SERVICE)->findAll(
			config: [
				'filters' => ['register' => 'hermiq', 'schema' => 'agent', 'talkEnabled' => true],
				'limit' => self::MAX_AGENTS,
			],
			_rbac: false,
			_multitenancy: false
		);

		$agents = [];
		foreach ($rows as $row) {
			if (($row instanceof ObjectEntity) === false) {
				continue;
			}

			$data = (array) $row->getObject();
			$uuid = (string) $row->getUuid();
			$deleted = $row->getDeleted();
			if ($uuid === '' || ($data['talkEnabled'] ?? false) !== true || ($deleted !== null && $deleted !== [])) {
				continue;
			}

			$agents[$uuid] = (string) ($data['name'] ?? '');
		}

		return $agents;

	}//end talkEnabledAgents()
}//end class
