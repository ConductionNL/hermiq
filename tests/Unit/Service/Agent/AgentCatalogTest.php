<?php

/**
 * Tests AgentCatalog: the agent list with owner, sharing and status.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AgentAvailabilityService;
use OCA\Hermiq\Service\Agent\AgentCatalog;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The catalog reads through the Agent read rule, so paging and totals count
 * only the agents the user may use; an organisation admin sees every agent.
 *
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003
 */
class AgentCatalogTest extends TestCase {

	/**
	 * Object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objects;

	/**
	 * The arguments of each paginated search.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $searches = [];

	/**
	 * Build the catalog over the given page of agents.
	 *
	 * @param array<int, ObjectEntity> $page       The rows the search returns.
	 * @param int                      $total      The total it reports.
	 * @param bool                     $orgAdmin   Whether alice administers org-1.
	 *
	 * @return AgentCatalog
	 */
	private function catalog(array $page, int $total, bool $orgAdmin = false): AgentCatalog {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query = [], bool $_rbac = true) use ($page, $total): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac];
				return ['results' => $page, 'total' => $total];
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => $gid === 'planning-desk');

		$availability = $this->createMock(AgentAvailabilityService::class);
		$availability->method('activeOrganisation')->willReturn('org-1');
		$availability->method('mayAdministerOrganisation')->willReturn($orgAdmin);

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => ['bob' => 'Bob de Vries', 'alice' => 'Alice'][$uid] ?? null);

		return new AgentCatalog(
			objectService: $this->objects,
			agentAccess: new AgentAccessService($this->objects, new NullLogger(), $groups),
			availability: $availability,
			userManager: $users,
		);

	}//end catalog()

	/**
	 * A stored agent.
	 *
	 * @param string               $uuid  Its id.
	 * @param array<string, mixed> $data  Its payload.
	 * @param string               $owner Its owner.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $uuid, array $data, string $owner): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid($uuid);
		$agent->setOwner($owner);
		$agent->setOrganisation('org-1');
		$agent->setObject($data);
		return $agent;

	}//end agent()

	/**
	 * A user's page reads through the read rule, and the total counts only what they may use.
	 *
	 * @return void
	 */
	public function testAUserPageReadsThroughTheRuleWithItsOwnTotal(): void {
		$catalog = $this->catalog(
			[
				$this->agent('a-1', ['name' => 'Permit reminder', 'isPrivate' => false, 'active' => false, 'prompt' => 'p'], 'bob'),
				$this->agent('a-2', ['name' => 'Planning desk helper', 'isPrivate' => true, 'groups' => ['planning-desk']], 'bob'),
			],
			12
		);

		$page = $catalog->page(uid: 'alice', limit: 10, offset: 10);

		$this->assertTrue($this->searches[0]['rbac'], 'A user reads through the Agent read rule.');
		$this->assertSame(10, $this->searches[0]['query']['_limit']);
		$this->assertSame(10, $this->searches[0]['query']['_offset']);
		$this->assertSame(12, $page['total']);
		$this->assertCount(2, $page['results']);

		$first = $page['results'][0];
		$this->assertSame('bob', $first['owner']);
		$this->assertSame('Bob de Vries', $first['ownerDisplayName']);
		$this->assertSame('organisation', $first['sharing']);
		$this->assertFalse($first['active']);
		$this->assertSame('people-and-groups', $page['results'][1]['sharing']);
		$this->assertTrue($page['results'][1]['active']);
		$this->assertArrayNotHasKey('visibleBecause', $first);

	}//end testAUserPageReadsThroughTheRuleWithItsOwnTotal()

	/**
	 * An organisation admin reads every agent; one they could not otherwise use
	 * carries the reason and no prompt or tools.
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-sees-every-agent-of-the-organisation-req-agshare-004
	 *
	 * @return void
	 */
	public function testAnOrganisationAdminSeesEveryAgentWithTheReason(): void {
		$catalog = $this->catalog(
			[
				$this->agent('a-3', ['name' => 'Salary helper', 'isPrivate' => true, 'prompt' => 'secret', 'tools' => ['x.y']], 'bob'),
				$this->agent('a-4', ['name' => 'Mine', 'isPrivate' => true, 'prompt' => 'mine'], 'alice'),
			],
			2,
			true
		);

		$page = $catalog->page(uid: 'alice', limit: 50, offset: 0);

		$this->assertFalse($this->searches[0]['rbac'], 'An organisation admin reads past the rule.');
		$hidden = $page['results'][0];
		$this->assertSame('organisation admin', $hidden['visibleBecause']);
		$this->assertSame('Salary helper', $hidden['name']);
		$this->assertSame('only-me', $hidden['sharing']);
		$this->assertArrayNotHasKey('prompt', $hidden);
		$this->assertArrayNotHasKey('tools', $hidden);
		$this->assertSame('mine', $page['results'][1]['prompt']);
		$this->assertArrayNotHasKey('visibleBecause', $page['results'][1]);

	}//end testAnOrganisationAdminSeesEveryAgentWithTheReason()

	/**
	 * One agent: the full row for someone who may use it, the reduced row for an
	 * organisation admin, nothing for anyone else.
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-sees-every-agent-of-the-organisation-req-agshare-004
	 *
	 * @return void
	 */
	public function testOneRowByWhoAsks(): void {
		$private = $this->agent('a-5', ['name' => 'Private', 'isPrivate' => true, 'prompt' => 'p'], 'bob');

		$this->assertNull($this->catalog([], 0)->row(agent: $private, uid: 'mallory'));
		$reduced = $this->catalog([], 0, true)->row(agent: $private, uid: 'alice');
		$this->assertSame('organisation admin', $reduced['visibleBecause']);
		$this->assertArrayNotHasKey('prompt', $reduced);
		$this->assertSame('p', $this->catalog([], 0)->row(agent: $private, uid: 'bob')['prompt']);

	}//end testOneRowByWhoAsks()

	/**
	 * The three sharing choices, read from the three stored fields.
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-agent-owner-decides-who-can-use-the-agent-req-agshare-001
	 *
	 * @return void
	 */
	public function testSharingIsReadFromTheThreeFields(): void {
		$catalog = $this->catalog([], 0);

		$this->assertSame('only-me', $catalog->sharing(data: ['isPrivate' => true]));
		$this->assertSame('only-me', $catalog->sharing(data: ['isPrivate' => true, 'invitedUsers' => [], 'groups' => []]));
		$this->assertSame('people-and-groups', $catalog->sharing(data: ['isPrivate' => true, 'invitedUsers' => ['carol']]));
		$this->assertSame('organisation', $catalog->sharing(data: ['isPrivate' => false, 'groups' => ['x']]));
		$this->assertSame('organisation', $catalog->sharing(data: []));

	}//end testSharingIsReadFromTheThreeFields()
}//end class
