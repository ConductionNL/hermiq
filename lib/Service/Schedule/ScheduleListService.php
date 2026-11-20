<?php

/**
 * Hermiq ScheduleListService.
 *
 * The rows of the Schedules page (scheduling-overview-and-start-choice D1):
 * every schedule a person may see, with its agent, a readable "when", its
 * next run, its last result and whether it is on. A person sees a schedule
 * they own, a schedule of an agent they may use (AgentAccessService), and,
 * as an administrator of its organisation, every schedule of that
 * organisation. The schedule schema's own read rule is `authenticated`, so the
 * object API cannot draw this line; this service does, per row.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
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
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use OCA\Hermiq\Controller\OrganisationAdministrators;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Service\ScheduleWhenParser;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use Throwable;

/**
 * The schedules one person may see, as list rows.
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
 */
class ScheduleListService {

	/**
	 * The register the schedules and agents live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'hermiq';

	/**
	 * Objects read per page.
	 *
	 * @var integer
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The last results that count as failed.
	 *
	 * @var array<int, string>
	 */
	public const FAILED_STATUSES = ['error', 'retry_pending', 'dead_letter', 'paused_circuit_breaker'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService               $objectService      Reads the schedule and agent objects.
	 * @param AgentAccessServiceInterface $access             Whether the person may use an agent.
	 * @param IGroupManager               $groupManager       Instance administrators.
	 * @param OrganisationMapper          $organisationMapper Organisation owners.
	 * @param ScheduleWhenParser          $when               The readable sentence of a rule.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentAccessServiceInterface $access,
		private readonly IGroupManager $groupManager,
		private readonly OrganisationMapper $organisationMapper,
		private readonly ScheduleWhenParser $when,
	) {
	}//end __construct()

	/**
	 * The rows the person may see, filtered, ordered by next run (none last), then name.
	 *
	 * @param IUser                $user    The person.
	 * @param array<string, mixed> $filters `agentId` (string), `enabled` and `failed` (bool or null).
	 * @param string               $lang    The readback language.
	 *
	 * @return list<array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
	 */
	public function list(IUser $user, array $filters, string $lang): array {
		$agents = [];
		foreach ($this->all(schema: 'agent') as $agent) {
			$agents[(string)$agent->getUuid()] = $agent;
		}

		$administrators = new OrganisationAdministrators(groupManager: $this->groupManager, organisationMapper: $this->organisationMapper);
		$adminOf        = [];
		$rows           = [];
		foreach ($this->all(schema: 'schedule') as $schedule) {
			$data  = $schedule->getObject();
			$agent = ($agents[(string)($data['agentId'] ?? '')] ?? null);
			if ($this->maySee(schedule: $schedule, agent: $agent, user: $user, administrators: $administrators, adminOf: $adminOf) === false) {
				continue;
			}

			$row = $this->row(schedule: $schedule, agent: $agent, lang: $lang);
			if ($this->matches(row: $row, filters: $filters) === true) {
				$rows[] = $row;
			}
		}

		usort($rows, static fn (array $a, array $b): int => self::sortKey(row: $a) <=> self::sortKey(row: $b));

		return $rows;
	}//end list()

	/**
	 * The order of a row: with a next run first, by next run, then by name.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array{0: bool, 1: string, 2: string}
	 */
	private static function sortKey(array $row): array {
		$next = (string)($row['nextRun'] ?? '');
		return [$next === '', $next, (string)$row['name']];
	}//end sortKey()

	/**
	 * Whether the person may see the schedule: they own it, may use its agent, or
	 * administer its organisation.
	 *
	 * @param ObjectEntity               $schedule       The schedule.
	 * @param ObjectEntity|null          $agent          Its agent, null when it no longer exists.
	 * @param IUser                      $user           The person.
	 * @param OrganisationAdministrators $administrators Who administers an organisation.
	 * @param array<string, bool>        $adminOf        Organisations already decided, filled in place.
	 *
	 * @return bool
	 */
	private function maySee(
		ObjectEntity $schedule,
		?ObjectEntity $agent,
		IUser $user,
		OrganisationAdministrators $administrators,
		array &$adminOf,
	): bool {
		$uid = $user->getUID();
		if ((string)($schedule->getOwner() ?? '') === $uid) {
			return true;
		}

		if ($agent !== null && $this->access->canUserAccessAgent(agent: $agent, userId: $uid) === true) {
			return true;
		}

		$organisation = (string)($schedule->getOrganisation() ?? '');
		if (isset($adminOf[$organisation]) === false) {
			$adminOf[$organisation] = $administrators->mayAdminister(organisation: $organisation, user: $user);
		}

		return $adminOf[$organisation];
	}//end maySee()

	/**
	 * One list row.
	 *
	 * @param ObjectEntity      $schedule The schedule.
	 * @param ObjectEntity|null $agent    Its agent.
	 * @param string            $lang     The readback language.
	 *
	 * @return array<string, mixed>
	 */
	private function row(ObjectEntity $schedule, ?ObjectEntity $agent, string $lang): array {
		$data = $schedule->getObject();
		$rule = array_intersect_key($data, array_flip(['kind', 'cronExpr', 'intervalMinutes', 'runAt']));

		return [
			'id' => (string)$schedule->getUuid(),
			'name' => (string)($data['name'] ?? ''),
			'agentId' => (string)($data['agentId'] ?? ''),
			'agentName' => (string)($agent?->getObject()['name'] ?? ''),
			'owner' => (string)($schedule->getOwner() ?? ''),
			'when' => $this->readback(rule: $rule, lang: $lang),
			'nextRun' => ($data['nextRun'] ?? null),
			'lastStatus' => ($data['lastStatus'] ?? null),
			'lastError' => ($data['lastError'] ?? null),
			'failed' => in_array((string)($data['lastStatus'] ?? ''), self::FAILED_STATUSES, true),
			'enabled' => (($data['enabled'] ?? true) !== false),
		] + $rule;
	}//end row()

	/**
	 * The readable "when" of a rule; an unreadable rule reads as nothing.
	 *
	 * @param array<string, mixed> $rule The schedule's kind and rule fields.
	 * @param string               $lang The language.
	 *
	 * @return string
	 */
	private function readback(array $rule, string $lang): string {
		try {
			return $this->when->readback(rule: $rule, lang: $lang);
		} catch (Throwable $e) {
			return '';
		}
	}//end readback()

	/**
	 * Whether a row passes the filters.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool
	 */
	private function matches(array $row, array $filters): bool {
		$agentId = (string)($filters['agentId'] ?? '');
		if ($agentId !== '' && $row['agentId'] !== $agentId) {
			return false;
		}

		if (isset($filters['enabled']) === true && $row['enabled'] !== $filters['enabled']) {
			return false;
		}

		return isset($filters['failed']) === false || $row['failed'] === $filters['failed'];
	}//end matches()

	/**
	 * Every object of a hermiq schema, page by page, in the caller's scope.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<ObjectEntity>
	 */
	private function all(string $schema): array {
		$scoped  = $this->objectService->setRegister(self::REGISTER)->setSchema($schema);
		$objects = [];
		$offset  = 0;
		do {
			$result = $scoped->searchObjectsPaginated(query: ['_limit' => self::PAGE_SIZE, '_offset' => $offset]);
			$page   = ($result['results'] ?? []);
			foreach ($page as $object) {
				if ($object instanceof ObjectEntity) {
					$objects[] = $object;
				}
			}

			$offset += self::PAGE_SIZE;
		} while ($page !== [] && $offset < (int)($result['total'] ?? 0));

		return $objects;
	}//end all()
}//end class
