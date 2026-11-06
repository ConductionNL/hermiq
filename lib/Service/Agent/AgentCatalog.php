<?php

/**
 * Hermiq: the agent list with owner, sharing and status (agents-sharing-and-catalog-columns).
 *
 * A user's page reads through the Agent schema's read rule, so OpenRegister
 * filters inside the query and paging and the total count only the agents the
 * user may use; AgentAccessService's predicate is applied again to every row.
 * An organisation admin (an instance admin, or the owner of their active
 * organisation) reads every agent of the organisation; a row they could not
 * otherwise use says why they see it and carries no prompt and no tools.
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
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use OCA\Hermiq\Service\AgentAccessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;

/**
 * List and show agents by who asks.
 *
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003
 */
class AgentCatalog {

	/**
	 * Why an organisation admin sees an agent they could not otherwise use.
	 */
	public const ADMIN_REASON = 'organisation admin';

	/**
	 * The fields an organisation admin sees of an agent they could not otherwise use.
	 */
	private const REDUCED_FIELDS = ['name', 'description', 'icon', 'active', 'availabilityReason', 'isPrivate'];

	/**
	 * Register slug.
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Agent schema slug.
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Constructor.
	 *
	 * @param ObjectService            $objectService OpenRegister objects.
	 * @param AgentAccessService       $agentAccess   The one per-agent read predicate.
	 * @param AgentAvailabilityService $availability  The organisation admin check.
	 * @param IUserManager             $userManager   Owner display names.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentAccessService $agentAccess,
		private readonly AgentAvailabilityService $availability,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * One page of the agents the user may see.
	 *
	 * @param string  $uid    The user.
	 * @param integer $limit  Page size.
	 * @param integer $offset Rows to skip.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-sees-every-agent-of-the-organisation-req-agshare-004
	 */
	public function page(string $uid, int $limit, int $offset): array {
		$asAdmin = $this->administers(uid: $uid);
		$found = $this->objectService->setRegister(self::REGISTER_SLUG)->setSchema(self::AGENT_SCHEMA)
			->searchObjectsPaginated(
				query: ['_limit' => $limit, '_offset' => $offset],
				_rbac: ($asAdmin === false)
			);

		$rows = [];
		foreach (($found['results'] ?? []) as $agent) {
			if (($agent instanceof ObjectEntity) === false) {
				continue;
			}

			$row = $this->shape(agent: $agent, uid: $uid, asAdmin: $asAdmin);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		return [
			'results' => $rows,
			'total' => (int)($found['total'] ?? count($rows)),
			'limit' => $limit,
			'offset' => $offset,
		];

	}//end page()

	/**
	 * One agent as the user may see it: the full row, the reduced row for an
	 * organisation admin, or null (a 404 to the caller).
	 *
	 * @param ObjectEntity $agent The agent.
	 * @param string       $uid   The user.
	 *
	 * @return array<string, mixed>|null The row.
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-sees-every-agent-of-the-organisation-req-agshare-004
	 */
	public function row(ObjectEntity $agent, string $uid): ?array {
		if ($this->agentAccess->canUserAccessAgent(agent: $agent, userId: $uid) === true) {
			return $this->shape(agent: $agent, uid: $uid, asAdmin: false);
		}

		if ($this->availability->mayAdministerOrganisation(organisation: (string)($agent->getOrganisation() ?? ''), uid: $uid) === false) {
			return null;
		}

		return $this->shape(agent: $agent, uid: $uid, asAdmin: true);

	}//end row()

	/**
	 * Who can use the agent, read from the three stored fields.
	 *
	 * @param array<string, mixed> $data The agent payload.
	 *
	 * @return string `only-me`, `people-and-groups` or `organisation`.
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-agent-owner-decides-who-can-use-the-agent-req-agshare-001
	 */
	public function sharing(array $data): string {
		if (($data['isPrivate'] ?? null) !== true) {
			return 'organisation';
		}

		$people = ($data['invitedUsers'] ?? []);
		$groups = ($data['groups'] ?? []);
		if ((is_array($people) === true && $people !== []) || (is_array($groups) === true && $groups !== [])) {
			return 'people-and-groups';
		}

		return 'only-me';

	}//end sharing()

	/**
	 * Whether the user administers their active organisation.
	 *
	 * @param string $uid The user.
	 *
	 * @return bool True when they do.
	 */
	private function administers(string $uid): bool {
		$organisation = $this->availability->activeOrganisation(uid: $uid);
		if ($organisation === '') {
			return false;
		}

		return $this->availability->mayAdministerOrganisation(organisation: $organisation, uid: $uid);

	}//end administers()

	/**
	 * The row for one agent, or null when the user may not see it.
	 *
	 * @param ObjectEntity $agent   The agent.
	 * @param string       $uid     The user.
	 * @param bool         $asAdmin Whether the user reads as organisation admin.
	 *
	 * @return array<string, mixed>|null The row.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Two readers, one shape.
	 */
	private function shape(ObjectEntity $agent, string $uid, bool $asAdmin): ?array {
		$data = $agent->getObject();
		$usable = $this->agentAccess->canUserAccessAgent(agent: $agent, userId: $uid);
		if ($usable === false && $asAdmin === false) {
			return null;
		}

		$owner = (string)($agent->getOwner() ?? '');
		$row = $data;
		if ($usable === false) {
			$row = array_intersect_key($data, array_flip(self::REDUCED_FIELDS));
			$row['visibleBecause'] = self::ADMIN_REASON;
		}

		return array_merge(
			$row,
			[
				'id' => $agent->getUuid(),
				'uuid' => $agent->getUuid(),
				'owner' => $owner,
				'ownerDisplayName' => ($this->userManager->getDisplayName($owner) ?? $owner),
				'organisation' => $agent->getOrganisation(),
				'sharing' => $this->sharing(data: $data),
				'active' => (($data['active'] ?? true) !== false),
				'created' => $agent->getCreated()?->format('c'),
				'updated' => $agent->getUpdated()?->format('c'),
			]
		);

	}//end shape()

}//end class
