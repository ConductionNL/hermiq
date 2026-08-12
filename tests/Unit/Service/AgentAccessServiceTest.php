<?php

/**
 * Unit tests for AgentAccessService — hermiq's one per-agent authorization predicate.
 *
 * The service exists because the predicate previously lived as a private method
 * inside controllers, which is why thirteen `#[NoAdminRequired]` routes shipped
 * without it (hermiq#187). These tests pin the two halves separately: READ access
 * (owner, invitee, or anyone in the organisation for a non-private agent) and
 * MODIFY access (owner only), plus the lookup contract — an absent or unreadable
 * agent, and a throwing `ObjectService::find()`, must all resolve to null rather
 * than escaping as a framework 500 on a `#[NoAdminRequired]` route.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AgentAccessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see AgentAccessService}.
 *
 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
 */
class AgentAccessServiceTest extends TestCase {

	/**
	 * An Agent ObjectEntity.
	 *
	 * @param string $owner The owning uid.
	 * @param bool|null $isPrivate The privacy flag (null = unset).
	 * @param array<int, string> $invitedUsers Explicitly invited uids.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $owner, ?bool $isPrivate = true, array $invitedUsers = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setOwner($owner);
		$entity->setObject(['isPrivate' => $isPrivate, 'invitedUsers' => $invitedUsers]);
		return $entity;
	}//end agent()

	/**
	 * The service over an ObjectService that resolves to $agent (or throws).
	 *
	 * @param ObjectEntity|null $agent The agent the lookup resolves to.
	 * @param bool $throws Whether the lookup throws instead.
	 *
	 * @return AgentAccessService
	 */
	private function service(?ObjectEntity $agent, bool $throws = false): AgentAccessService {
		$objectService = $this->createMock(ObjectService::class);
		if ($throws === true) {
			$objectService->method('find')->willThrowException(new RuntimeException('not found'));
		} else {
			$objectService->method('find')->willReturn($agent);
		}

		return new AgentAccessService($objectService, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * The owner may read and modify their own private agent.
	 *
	 * @return void
	 */
	public function testOwnerMayReadAndModify(): void {
		$service = $this->service($this->agent('alice'));

		$this->assertNotNull($service->loadAccessibleAgent('agent-1', 'alice'));
		$this->assertNotNull($service->loadModifiableAgent('agent-1', 'alice'));

	}//end testOwnerMayReadAndModify()

	/**
	 * A stranger may neither read nor modify a PRIVATE agent.
	 *
	 * @return void
	 */
	public function testStrangerMayNotReachAPrivateAgent(): void {
		$service = $this->service($this->agent('alice'));

		$this->assertNull($service->loadAccessibleAgent('agent-1', 'mallory'));
		$this->assertNull($service->loadModifiableAgent('agent-1', 'mallory'));

	}//end testStrangerMayNotReachAPrivateAgent()

	/**
	 * An explicitly invited user may READ a private agent but NOT modify it.
	 *
	 * @return void
	 */
	public function testInvitedUserMayReadButNotModify(): void {
		$service = $this->service($this->agent('alice', true, ['bob']));

		$this->assertNotNull($service->loadAccessibleAgent('agent-1', 'bob'));
		$this->assertNull($service->loadModifiableAgent('agent-1', 'bob'));

	}//end testInvitedUserMayReadButNotModify()

	/**
	 * A NON-private agent is readable across the organisation but still only
	 * modifiable by its owner — this is the split the memory write routes rely
	 * on, and getting it wrong in either direction is a finding.
	 *
	 * @return void
	 */
	public function testSharedAgentIsOrgReadableAndOwnerWritable(): void {
		$service = $this->service($this->agent('alice', false));

		$this->assertNotNull($service->loadAccessibleAgent('agent-1', 'bob'));
		$this->assertNull($service->loadModifiableAgent('agent-1', 'bob'));
		$this->assertNotNull($service->loadModifiableAgent('agent-1', 'alice'));

	}//end testSharedAgentIsOrgReadableAndOwnerWritable()

	/**
	 * An agent with `isPrivate` UNSET behaves as non-private (OR's own default in
	 * `AgentMapper::canUserAccessAgent()`), so legacy agents are not locked out.
	 *
	 * @return void
	 */
	public function testUnsetPrivacyIsTreatedAsShared(): void {
		$service = $this->service($this->agent('alice', null));

		$this->assertNotNull($service->loadAccessibleAgent('agent-1', 'bob'));

	}//end testUnsetPrivacyIsTreatedAsShared()

	/**
	 * An empty uid never passes either check — an unauthenticated caller must not
	 * be credited with access to a shared agent.
	 *
	 * @return void
	 */
	public function testEmptyUidNeverPasses(): void {
		$service = $this->service($this->agent('', false));

		$this->assertNull($service->loadAccessibleAgent('agent-1', ''));
		$this->assertNull($service->loadModifiableAgent('agent-1', ''));

	}//end testEmptyUidNeverPasses()

	/**
	 * An absent agent, an empty id, and a THROWING lookup all resolve to null —
	 * `ObjectService::find()` documents `@throws Exception If the object is not
	 * found`, and the guard runs outside its caller's try block (gate-49).
	 *
	 * @return void
	 */
	public function testMissingOrThrowingLookupResolvesToNull(): void {
		$this->assertNull($this->service(null)->loadAccessibleAgent('agent-1', 'alice'));
		$this->assertNull($this->service($this->agent('alice'))->loadAccessibleAgent('  ', 'alice'));
		$this->assertNull($this->service(null, true)->loadAccessibleAgent('agent-1', 'alice'));
		$this->assertNull($this->service(null, true)->loadModifiableAgent('agent-1', 'alice'));

	}//end testMissingOrThrowingLookupResolvesToNull()
}//end class
