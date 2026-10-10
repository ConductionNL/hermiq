<?php

/**
 * Hermiq: switch one agent off and on (agents-switch-off-and-stop).
 *
 * The agent owner, an instance admin or the owner of the agent's organisation
 * may switch an agent. The switch writes `active` with who, when and why onto
 * the agent and one `agent.availability` entry on OpenRegister's audit trail.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Switch an agent, read its state, count its schedules.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */
class AgentAvailabilityService {

	/**
	 * The audit action of a switch.
	 */
	public const AUDIT_ACTION = 'agent.availability';

	/**
	 * Register slug.
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Agent schema slug.
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Schedule schema slug.
	 */
	private const SCHEDULE_SCHEMA = 'schedule';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister objects.
	 * @param AgentAccessService $agentAccess Who may read and modify an agent.
	 * @param IGroupManager $groupManager Instance admin check.
	 * @param OrganisationMapper $organisationMapper Organisation owner check.
	 * @param AuditTrailMapper $auditTrailMapper The audit trail.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentAccessService $agentAccess,
		private readonly IGroupManager $groupManager,
		private readonly OrganisationMapper $organisationMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Switch an agent off or on.
	 *
	 * @param string $agentId The agent.
	 * @param bool   $active  On (true) or off (false).
	 * @param string $reason  Why; required when switching off.
	 * @param string $actorUid Who switches.
	 *
	 * @return ObjectEntity The stored agent.
	 *
	 * @throws RuntimeException 404 unknown agent, 403 not allowed, 400 no reason.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The switch IS a boolean.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
	 */
	public function switchAgent(string $agentId, bool $active, string $reason, string $actorUid): ObjectEntity {
		$agent = $this->findAgent(agentId: $agentId);
		if ($agent === null) {
			throw new RuntimeException('Agent not found', 404);
		}

		if ($this->mayModify(agent: $agent, uid: $actorUid) === false) {
			throw new RuntimeException('Only the agent owner or an admin of its organisation may switch it.', 403);
		}

		$reason = trim($reason);
		if ($active === false && $reason === '') {
			throw new RuntimeException('Give a reason to switch the agent off.', 400);
		}

		$changedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$data = $agent->getObject();
		$data['active'] = $active;
		$data['availabilityChangedBy'] = $actorUid;
		$data['availabilityChangedAt'] = $changedAt;
		$data['availabilityReason'] = null;
		if ($reason !== '') {
			$data['availabilityReason'] = $reason;
		}

		$stored = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::AGENT_SCHEMA,
			uuid: (string)$agent->getUuid(),
			_rbac: false,
			_multitenancy: false
		);

		$this->audit(
			agent: $stored,
			context: [
				'agentId' => $agentId,
				'active' => $active,
				'changedBy' => $actorUid,
				'changedAt' => $changedAt,
				'reason' => $data['availabilityReason'],
			]
		);

		return $stored;

	}//end switchAgent()

	/**
	 * Whether the stored agent is on right now; an unknown agent reads as on
	 * (the run path already refused a missing agent).
	 *
	 * @param string $agentId The agent.
	 *
	 * @return bool True when on.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
	 */
	public function isOn(string $agentId): bool {
		return (new AgentAvailability())->isOn(agent: $this->findAgent(agentId: $agentId));

	}//end isOn()

	/**
	 * The agent when the user may read it, else null.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid     The user.
	 *
	 * @return ObjectEntity|null The agent.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
	 */
	public function readableAgent(string $agentId, string $uid): ?ObjectEntity {
		$agent = $this->findAgent(agentId: $agentId);
		if ($agent === null) {
			return null;
		}

		if ($this->agentAccess->canUserAccessAgent(agent: $agent, userId: $uid) === false
			&& $this->mayModify(agent: $agent, uid: $uid) === false
		) {
			return null;
		}

		return $agent;

	}//end readableAgent()

	/**
	 * Whether a user may switch the agent: its owner, an instance admin, or the
	 * owner of the agent's organisation.
	 *
	 * @param ObjectEntity $agent The agent.
	 * @param string       $uid   The user.
	 *
	 * @return bool True when allowed.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
	 */
	public function mayModify(ObjectEntity $agent, string $uid): bool {
		if ($uid === '') {
			return false;
		}

		if ($this->agentAccess->canUserModifyAgent(agent: $agent, userId: $uid) === true) {
			return true;
		}

		return $this->mayAdministerOrganisation(organisation: (string)($agent->getOrganisation() ?? ''), uid: $uid);

	}//end mayModify()

	/**
	 * Whether a user administers an organisation: an instance admin, or its owner.
	 *
	 * @param string $organisation The organisation uuid.
	 * @param string $uid          The user.
	 *
	 * @return bool True when they administer it.
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-sees-every-agent-of-the-organisation-req-agshare-004
	 */
	public function mayAdministerOrganisation(string $organisation, string $uid): bool {
		if ($uid === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		return $this->ownsOrganisation(organisation: $organisation, uid: $uid);

	}//end mayAdministerOrganisation()

	/**
	 * The user's active organisation, or an empty string.
	 *
	 * @param string $uid The user.
	 *
	 * @return string The organisation uuid.
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-sees-every-agent-of-the-organisation-req-agshare-004
	 */
	public function activeOrganisation(string $uid): string {
		try {
			return (string)($this->organisationMapper->getActiveOrganisationWithFallback($uid) ?? '');
		} catch (Throwable $e) {
			return '';
		}

	}//end activeOrganisation()

	/**
	 * How many schedules the agent has, which the delete confirmation names.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
	 */
	public function scheduleCount(string $agentId): int {
		try {
			$result = $this->objectService->setRegister(self::REGISTER_SLUG)->setSchema(self::SCHEDULE_SCHEMA)
				->searchObjectsPaginated(
					query: ['agentId' => $agentId, '_limit' => 1],
					_rbac: false,
					_multitenancy: false
				);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq could not count the schedules of an agent: ' . $e->getMessage(), ['exception' => $e]);
			return 0;
		}

		return (int)($result['total'] ?? 0);

	}//end scheduleCount()

	/**
	 * Whether the user owns the organisation.
	 *
	 * @param string $organisation The organisation uuid.
	 * @param string $uid          The user.
	 *
	 * @return bool True when they own it.
	 */
	private function ownsOrganisation(string $organisation, string $uid): bool {
		if ($organisation === '') {
			return false;
		}

		try {
			$org = $this->organisationMapper->findByUuid($organisation);
		} catch (Throwable $e) {
			return false;
		}

		return (string)($org->getOwner() ?? '') === $uid;

	}//end ownsOrganisation()

	/**
	 * Load an agent without RBAC; the caller decides who may see it.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return ObjectEntity|null The agent.
	 */
	private function findAgent(string $agentId): ?ObjectEntity {
		if ($agentId === '') {
			return null;
		}

		try {
			return $this->objectService->find(
				id: $agentId,
				register: self::REGISTER_SLUG,
				schema: self::AGENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

	}//end findAgent()

	/**
	 * Write the switch to the audit trail; a failure is logged, never fatal.
	 *
	 * @param ObjectEntity         $agent   The stored agent.
	 * @param array<string, mixed> $context What changed.
	 *
	 * @return void
	 */
	private function audit(ObjectEntity $agent, array $context): void {
		try {
			$this->auditTrailMapper->createAuditTrailEntry(
				object: $agent,
				action: self::AUDIT_ACTION,
				context: $context
			);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq could not record an agent switch: ' . $e->getMessage(), ['exception' => $e]);
		}

	}//end audit()

}//end class
