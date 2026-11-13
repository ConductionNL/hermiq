<?php

/**
 * Hermiq DomainTools.
 *
 * Runs the five domain tools (hermiq-domain-mcp-tools) through the read and
 * trigger paths their pages already use, under the same guards.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Mcp
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
 * @spec openspec/changes/hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Mcp;

use OCA\Hermiq\Kernel\Contracts\AgentCatalogInterface;
use OCA\Hermiq\Kernel\Contracts\ApprovalServiceInterface;
use OCA\Hermiq\Kernel\Contracts\LiteracyRequirementInterface;
use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillServiceInterface;
use OCA\Hermiq\Mcp\DomainToolDescriptors;
use OCA\Hermiq\Service\Schedule\OwnedSchedule;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin delegation only: every branch calls the service its HTTP counterpart
 * calls, with the same caller, and answers what that counterpart would show.
 * No branch reads more than its page does:
 *
 * - `listAgents` and `getAgentStatus` read through `AgentCatalogInterface`, the
 *   agent read rule the catalogue page and `AgentsController::show()` use; an
 *   agent the caller may not see is `not_found`, as the page's 404.
 * - `getAgentStatus` lists only the caller's OWN schedules for the agent.
 * - `listPendingApprovals` is `ApprovalController::index()`: the caller as reviewer.
 * - `runAgentNow` runs `OwnedSchedule`, the guard `RunNowController::run()` runs,
 *   then its course requirement, before `ScheduleServiceInterface::runNow()`.
 * - `searchSkills` filters `SkillServiceInterface::listSkills()`, the tenant read
 *   the skills page uses.
 *
 * `invoke()` never throws: a failure is the `{error: {code, message}}` envelope.
 *
 * @spec openspec/changes/hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
 */
class DomainTools {

	/**
	 * The most agents one call lists.
	 *
	 * @var int
	 */
	private const MAX_AGENTS = 50;

	/**
	 * Constructor.
	 *
	 * @param AgentCatalogInterface             $agents      The agent read rule of the catalogue page.
	 * @param ApprovalServiceInterface          $approvals   The approval inbox read.
	 * @param ScheduleServiceInterface          $schedules   Runs a schedule now.
	 * @param SkillServiceInterface             $skills      The tenant's skill catalogue.
	 * @param OwnedSchedule                     $owned       The owner guard of a run by hand.
	 * @param ObjectService                     $objects     Loads an agent and the caller's schedules for it.
	 * @param LoggerInterface                   $logger      Logs a failed call.
	 * @param LiteracyRequirementInterface|null $literacy    The course a run by hand may require.
	 */
	public function __construct(
		private readonly AgentCatalogInterface $agents,
		private readonly ApprovalServiceInterface $approvals,
		private readonly ScheduleServiceInterface $schedules,
		private readonly SkillServiceInterface $skills,
		private readonly OwnedSchedule $owned,
		private readonly ObjectService $objects,
		private readonly LoggerInterface $logger,
		private readonly ?LiteracyRequirementInterface $literacy = null,
	) {
	}//end __construct()

	/**
	 * Run one domain tool for the acting user.
	 *
	 * @param string               $uid       The acting user.
	 * @param string               $toolId    The namespaced tool id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		try {
			return match ($toolId) {
				DomainToolDescriptors::LIST_AGENTS => $this->listAgents(uid: $uid, arguments: $arguments),
				DomainToolDescriptors::AGENT_STATUS => $this->agentStatus(uid: $uid, agentId: self::text(arguments: $arguments, name: 'agentId')),
				DomainToolDescriptors::PENDING_APPROVALS => $this->pendingApprovals(uid: $uid),
				DomainToolDescriptors::RUN_AGENT_NOW => $this->runNow(uid: $uid, scheduleId: self::text(arguments: $arguments, name: 'scheduleId')),
				DomainToolDescriptors::SEARCH_SKILLS => $this->searchSkills(query: self::text(arguments: $arguments, name: 'query')),
				default => self::error(code: 'unknown_tool', message: "Unknown tool id '" . $toolId . "'."),
			};
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq domain tool failed: ' . $e->getMessage(), ['exception' => $e, 'tool' => $toolId]);
			return self::error(code: 'tool_failed', message: 'The tool call failed.');
		}
	}//end invoke()

	/**
	 * The agents the caller may see, one page.
	 *
	 * @param string               $uid       The caller.
	 * @param array<string, mixed> $arguments limit and offset.
	 *
	 * @return array<string, mixed>
	 */
	private function listAgents(string $uid, array $arguments): array {
		$limit = max(1, min(self::MAX_AGENTS, (int)($arguments['limit'] ?? 20)));
		$offset = max(0, (int)($arguments['offset'] ?? 0));

		return $this->agents->page(uid: $uid, limit: $limit, offset: $offset);
	}//end listAgents()

	/**
	 * One agent the caller may see, with the run status of the caller's own schedules for it.
	 *
	 * @param string $uid     The caller.
	 * @param string $agentId The agent uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function agentStatus(string $uid, string $agentId): array {
		if ($agentId === '') {
			return self::error(code: 'invalid_arguments', message: 'agentId is required.');
		}

		$row = null;
		$agent = $this->findOrNull(id: $agentId, schema: 'agent');
		if ($agent !== null) {
			$row = $this->agents->row(agent: $agent, uid: $uid);
		}

		if ($row === null) {
			return self::error(code: 'not_found', message: 'Agent not found.');
		}

		return ['agent' => $row, 'schedules' => $this->ownSchedulesOf(agentId: $agentId, uid: $uid)];
	}//end agentStatus()

	/**
	 * The caller's own schedules for an agent, with their run fields.
	 *
	 * @param string $agentId The agent uuid.
	 * @param string $uid     The caller.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ownSchedulesOf(string $agentId, string $uid): array {
		$found = $this->objects->setRegister('hermiq')->setSchema('schedule')
			->searchObjectsPaginated(query: ['agentId' => $agentId, '_limit' => 50]);

		$own = [];
		foreach (($found['results'] ?? []) as $schedule) {
			if (($schedule instanceof ObjectEntity) === false || (string)($schedule->getOwner() ?? '') !== $uid) {
				continue;
			}

			$data = $schedule->getObject();
			$own[] = [
				'scheduleId' => (string)$schedule->getUuid(),
				'enabled' => ($data['enabled'] ?? null),
				'lastStatus' => ($data['lastStatus'] ?? null),
				'lastError' => ($data['lastError'] ?? null),
				'nextRun' => ($data['nextRun'] ?? null),
			];
		}

		return $own;
	}//end ownSchedulesOf()

	/**
	 * The pending approvals the caller reviews.
	 *
	 * @param string $uid The caller.
	 *
	 * @return array<string, mixed>
	 */
	private function pendingApprovals(string $uid): array {
		$records = $this->approvals->listPendingForReviewer(uid: $uid);

		return ['results' => $records, 'total' => count($records)];
	}//end pendingApprovals()

	/**
	 * Run one of the caller's own schedules now.
	 *
	 * @param string $uid        The caller.
	 * @param string $scheduleId The schedule uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function runNow(string $uid, string $scheduleId): array {
		if ($scheduleId === '') {
			return self::error(code: 'invalid_arguments', message: 'scheduleId is required.');
		}

		$schedule = $this->owned->load(scheduleId: $scheduleId, uid: $uid);
		if ($schedule === null) {
			// As the route: a schedule of somebody else reads as one that does not exist.
			return self::error(code: 'not_found', message: 'Schedule not found.');
		}

		$refusal = $this->literacy?->refusal(uid: $uid);
		if ($refusal !== null) {
			return self::error(code: 'literacy_required', message: $refusal['message']);
		}

		$this->schedules->runNow(schedule: $schedule);
		$data = ($this->owned->load(scheduleId: $scheduleId, uid: $uid)?->getObject() ?? []);

		return [
			'scheduleId' => $scheduleId,
			'status' => ($data['lastStatus'] ?? null),
			'error' => ($data['lastError'] ?? null),
			'nextRun' => ($data['nextRun'] ?? null),
		];
	}//end runNow()

	/**
	 * The tenant's skills whose name or description holds every word of the query.
	 *
	 * @param string $query The words; empty lists every skill.
	 *
	 * @return array<string, mixed>
	 */
	private function searchSkills(string $query): array {
		$split = preg_split('/\s+/', mb_strtolower($query));
		if ($split === false) {
			$split = [];
		}

		$words = array_values(array_filter($split, static fn (string $word): bool => $word !== ''));
		$results = [];
		foreach ($this->skills->listSkills() as $skill) {
			$data = $skill->getObject();
			$haystack = mb_strtolower((string)($data['name'] ?? '') . ' ' . (string)($data['description'] ?? ''));
			foreach ($words as $word) {
				if (str_contains($haystack, $word) === false) {
					continue 2;
				}
			}

			$results[] = [
				'uuid' => (string)$skill->getUuid(),
				'name' => ($data['name'] ?? null),
				'description' => ($data['description'] ?? null),
			];
		}

		return ['results' => $results, 'total' => count($results)];
	}//end searchSkills()

	/**
	 * An object of the hermiq register, or null when it does not load.
	 *
	 * @param string $id     The uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectEntity|null
	 */
	private function findOrNull(string $id, string $schema): ?ObjectEntity {
		try {
			$found = $this->objects->find(id: $id, register: 'hermiq', schema: $schema);
		} catch (Throwable) {
			return null;
		}

		if (($found instanceof ObjectEntity) === false) {
			return null;
		}

		return $found;
	}//end findOrNull()

	/**
	 * A trimmed string argument, or ''.
	 *
	 * @param array<string, mixed> $arguments The arguments.
	 * @param string               $name      The argument.
	 *
	 * @return string
	 */
	private static function text(array $arguments, string $name): string {
		$value = ($arguments[$name] ?? '');
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()

	/**
	 * An error envelope.
	 *
	 * @param string $code    The machine code.
	 * @param string $message The message.
	 *
	 * @return array{error: array{code: string, message: string}}
	 */
	private static function error(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end error()
}//end class
