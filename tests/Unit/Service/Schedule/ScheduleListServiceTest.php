<?php

/**
 * The rows of the Schedules page (scheduling-overview-and-start-choice, REQ-SCHED-001).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Schedule;

use OCA\Hermiq\Controller\OrganisationAdministrators;
use OCA\Hermiq\Controller\OrganisationPolicyGate;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Service\Schedule\ScheduleListService;
use OCA\Hermiq\Service\ScheduleWhenParser;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ScheduleListService::class)]
#[UsesClass(OrganisationAdministrators::class)]
#[UsesClass(OrganisationPolicyGate::class)]
#[UsesClass(ScheduleWhenParser::class)]
final class ScheduleListServiceTest extends TestCase {

	private IGroupManager&MockObject $groups;

	private OrganisationMapper&MockObject $organisations;

	/** @var array<int, array<string, mixed>> */
	private array $queries = [];

	/**
	 * An object of a schema.
	 *
	 * @param string               $uuid  The uuid.
	 * @param string               $owner The owner.
	 * @param array<string, mixed> $data  The data.
	 * @param string               $org   The organisation.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $owner, array $data, string $org = 'org-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setOwner($owner);
		$object->setOrganisation($org);
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * The service over these agents and schedules, with access to the given agents.
	 *
	 * @param array<int, ObjectEntity> $agents     The agents.
	 * @param array<int, ObjectEntity> $schedules  The schedules.
	 * @param array<int, string>       $usable     The agent uuids the person may use.
	 * @param array<string, string>    $orgOwners  The owner of each known organisation.
	 *
	 * @return ScheduleListService
	 */
	private function service(array $agents, array $schedules, array $usable = [], array $orgOwners = []): ScheduleListService {
		$objects = $this->createMock(ObjectService::class);
		$schema  = '';
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string $slug) use ($objects, &$schema) {
				$schema = $slug;
				return $objects;
			}
		);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use (&$schema, $agents, $schedules): array {
				$this->queries[] = ['schema' => $schema] + $query;
				$all = $agents;
				if ($schema === 'schedule') {
					$all = $schedules;
				}

				return ['results' => array_slice($all, (int)$query['_offset'], (int)$query['_limit']), 'total' => count($all)];
			}
		);

		$access = $this->createMock(AgentAccessServiceInterface::class);
		$access->method('canUserAccessAgent')->willReturnCallback(
			static fn (ObjectEntity $agent, string $userId): bool => in_array((string)$agent->getUuid(), $usable, true)
		);

		$this->groups        = $this->createMock(IGroupManager::class);
		$this->organisations = $this->createMock(OrganisationMapper::class);
		$this->organisations->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($orgOwners): Organisation {
				if (isset($orgOwners[$uuid]) === false) {
					throw new RuntimeException('not found');
				}

				$organisation = new Organisation();
				$organisation->setOwner($orgOwners[$uuid]);
				return $organisation;
			}
		);

		return new ScheduleListService(
			objectService: $objects,
			access: $access,
			groupManager: $this->groups,
			organisationMapper: $this->organisations,
			when: new ScheduleWhenParser()
		);
	}//end service()

	/**
	 * A person.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	public function testAPersonSeesOwnSchedulesAndThoseOfAgentsTheyMayUseButNotAPrivateAgentsSchedule(): void {
		$agents    = [
			$this->object('shared', 'lead', ['name' => 'Digest']),
			$this->object('private', 'boss', ['name' => 'Secret']),
		];
		$schedules = [
			$this->object('s-own', 'clerk', ['name' => 'Mine', 'agentId' => 'private', 'kind' => 'cron', 'cronExpr' => '0 8 * * 1-5']),
			$this->object('s-shared', 'lead', ['name' => 'Team digest', 'agentId' => 'shared', 'kind' => 'interval', 'intervalMinutes' => 60]),
			$this->object('s-private', 'boss', ['name' => 'Board', 'agentId' => 'private', 'kind' => 'cron', 'cronExpr' => '0 9 * * 1']),
		];

		$rows = $this->service(agents: $agents, schedules: $schedules, usable: ['shared'])->list(user: $this->user('clerk'), filters: [], lang: 'en');

		self::assertSame(['s-own', 's-shared'], array_column($rows, 'id'));
		self::assertSame(['Secret', 'Digest'], array_column($rows, 'agentName'));
		self::assertSame('Every weekday at 08:00', $rows[0]['when']);
	}//end testAPersonSeesOwnSchedulesAndThoseOfAgentsTheyMayUseButNotAPrivateAgentsSchedule()

	public function testAnInstanceAdminSeesEveryScheduleOfTheOrganisation(): void {
		$service = $this->service(
			agents: [$this->object('private', 'boss', ['name' => 'Secret'])],
			schedules: [$this->object('s-private', 'boss', ['name' => 'Board', 'agentId' => 'private'])]
		);
		$this->groups->method('isAdmin')->with('admin')->willReturn(true);

		$rows = $service->list(user: $this->user('admin'), filters: [], lang: 'en');

		self::assertSame(['s-private'], array_column($rows, 'id'));
	}//end testAnInstanceAdminSeesEveryScheduleOfTheOrganisation()

	public function testTheOrganisationOwnerSeesItsSchedulesButNotAnotherOrganisations(): void {
		$service = $this->service(
			agents: [],
			schedules: [
				$this->object('s-1', 'boss', ['name' => 'Ours', 'agentId' => 'gone'], 'org-1'),
				$this->object('s-2', 'other', ['name' => 'Theirs', 'agentId' => 'gone'], 'org-2'),
			],
			orgOwners: ['org-1' => 'owner', 'org-2' => 'someone-else']
		);
		$this->groups->method('isAdmin')->willReturn(false);

		$rows = $service->list(user: $this->user('owner'), filters: [], lang: 'en');

		self::assertSame(['s-1'], array_column($rows, 'id'));
		self::assertSame('', $rows[0]['agentName']);
	}//end testTheOrganisationOwnerSeesItsSchedulesButNotAnotherOrganisations()

	public function testFiltersOnAgentEnabledAndAFailedLastResult(): void {
		$schedules = [
			$this->object('a-ok', 'clerk', ['name' => 'A ok', 'agentId' => 'a', 'lastStatus' => 'ok', 'enabled' => true]),
			$this->object('a-err', 'clerk', ['name' => 'A err', 'agentId' => 'a', 'lastStatus' => 'error', 'lastError' => 'Provider down', 'enabled' => true]),
			$this->object('a-off', 'clerk', ['name' => 'A off', 'agentId' => 'a', 'lastStatus' => 'dead_letter', 'enabled' => false]),
			$this->object('b-err', 'clerk', ['name' => 'B err', 'agentId' => 'b', 'lastStatus' => 'error']),
		];
		$service = $this->service(agents: [], schedules: $schedules);
		$clerk   = $this->user('clerk');

		self::assertSame(['a-err', 'a-off', 'b-err'], array_column($service->list(user: $clerk, filters: ['failed' => true], lang: 'en'), 'id'));
		self::assertSame(['a-err', 'a-off'], array_column($service->list(user: $clerk, filters: ['agentId' => 'a', 'failed' => true], lang: 'en'), 'id'));
		self::assertSame(['a-off'], array_column($service->list(user: $clerk, filters: ['enabled' => false], lang: 'en'), 'id'));
		self::assertSame(['a-ok'], array_column($service->list(user: $clerk, filters: ['failed' => false], lang: 'en'), 'id'));

		$row = $service->list(user: $clerk, filters: ['agentId' => 'b'], lang: 'en')[0];
		self::assertSame(['error', null, true, true], [$row['lastStatus'], $row['lastError'], $row['failed'], $row['enabled']]);
	}//end testFiltersOnAgentEnabledAndAFailedLastResult()

	public function testRowsAreOrderedByNextRunWithUnscheduledLastAndReadInDutch(): void {
		$schedules = [
			$this->object('none', 'clerk', ['name' => 'A', 'agentId' => 'x', 'kind' => 'cron', 'cronExpr' => '0 9 * * 1']),
			$this->object('late', 'clerk', ['name' => 'B', 'agentId' => 'x', 'nextRun' => '2026-11-20T08:00:00+00:00', 'kind' => 'interval', 'intervalMinutes' => 15]),
			$this->object('soon', 'clerk', ['name' => 'C', 'agentId' => 'x', 'nextRun' => '2026-11-14T08:00:00+00:00', 'kind' => 'cron', 'cronExpr' => 'not a rule']),
		];

		$rows = $this->service(agents: [], schedules: $schedules)->list(user: $this->user('clerk'), filters: [], lang: 'nl');

		self::assertSame(['soon', 'late', 'none'], array_column($rows, 'id'));
		self::assertSame('Elke maandag om 09:00', $rows[2]['when']);
		self::assertSame(['kind' => 'cron', 'cronExpr' => '0 9 * * 1'], array_intersect_key($rows[2], ['kind' => 1, 'cronExpr' => 1]));
	}//end testRowsAreOrderedByNextRunWithUnscheduledLastAndReadInDutch()

	public function testEveryPageOfSchedulesIsRead(): void {
		$schedules = [];
		for ($i = 0; $i < 501; $i++) {
			$schedules[] = $this->object('s-' . $i, 'clerk', ['name' => sprintf('S %03d', $i), 'agentId' => 'x']);
		}

		$rows = $this->service(agents: [], schedules: $schedules)->list(user: $this->user('clerk'), filters: [], lang: 'en');

		self::assertCount(501, $rows);
		$scheduleQueries = array_values(array_filter($this->queries, static fn (array $q): bool => $q['schema'] === 'schedule'));
		self::assertSame([0, 500], array_column($scheduleQueries, '_offset'));
	}//end testEveryPageOfSchedulesIsRead()
}//end class
