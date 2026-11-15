<?php

/**
 * Hermiq OrganisationPolicyGate.
 *
 * Who may read and administer an organisation-scoped governance policy, shared
 * by GuardrailPolicyController and OrganisationModelPolicyController, which
 * guard the same kind of per-organisation object (`ObjectEntity.organisation`,
 * read with `_rbac: false, _multitenancy: false`) with the same rules:
 *
 * - an instance admin (`IGroupManager::isAdmin`) may administer any
 *   organisation's policy and the organisation-less instance default;
 * - the owner of an OpenRegister organisation (`Organisation::getOwner`) may
 *   administer that organisation's policy, never the instance default;
 * - nobody owns an organisation that cannot be found, or no organisation.
 *
 * Built by each controller from its own injected collaborators.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read and administer rights on organisation-scoped policies.
 *
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
 */
final class OrganisationPolicyGate {

	/**
	 * Build the gate.
	 *
	 * @param IGroupManager $groupManager Instance-admin check.
	 * @param OrganisationMapper $organisationMapper OpenRegister organisation lookup.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly OrganisationMapper $organisationMapper,
	) {
	}//end __construct()

	/**
	 * Whether the user may administer the policy for the given organisation
	 * scope. An empty `$organisation` (the instance-wide default) is admitted
	 * ONLY for an instance admin — there is no "owner" of no organisation. A
	 * non-empty organisation is admitted for an instance admin OR the owner of
	 * that OpenRegister organisation.
	 *
	 * @param string $organisation The organisation identifier, or '' for the instance default.
	 * @param IUser $user The requesting user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
	 */
	public function mayAdminister(string $organisation, IUser $user): bool {
		$isAdmin = $this->groupManager->isAdmin($user->getUID());

		if ($organisation === '') {
			return $isAdmin;
		}

		if ($isAdmin === true) {
			return true;
		}

		return $this->ownsOrganisation(organisation: $organisation, uid: $user->getUID());
	}//end mayAdminister()

	/**
	 * Whether the given user owns the given OpenRegister organisation.
	 *
	 * @param string $organisation The organisation identifier.
	 * @param string $uid The user id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
	 */
	public function ownsOrganisation(string $organisation, string $uid): bool {
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
	 * The policies the user may see: every policy for an instance admin (who may
	 * administer any organisation), otherwise only those of organisations the
	 * user owns. The admin check runs before the policies are listed.
	 *
	 * @param IUser $user The requesting user.
	 * @param callable(): iterable<ObjectEntity> $listAll Lists every stored policy.
	 *
	 * @return array<int, ObjectEntity> The visible policies, in listed order.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
	 */
	public function visiblePolicies(IUser $user, callable $listAll): array {
		$isAdmin = $this->groupManager->isAdmin($user->getUID());

		$visible = [];
		foreach ($listAll() as $policy) {
			$organisation = (string)($policy->getOrganisation() ?? '');
			if ($isAdmin === true || $this->ownsOrganisation(organisation: $organisation, uid: $user->getUID()) === true) {
				$visible[] = $policy;
			}
		}

		return $visible;
	}//end visiblePolicies()

	/**
	 * Resolve the calling user's active organisation (identity from session — no
	 * request parameter required). Falls back to '' (the instance-wide default
	 * scope) when the user has no active/default organisation, so the read never
	 * errors for a user outside any organisation; a failed lookup is logged.
	 *
	 * @param string $uid The requesting user's id.
	 * @param LoggerInterface $logger Where a failed lookup is logged.
	 *
	 * @return string The organisation identifier, or '' when none resolves.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
	 */
	public function activeOrganisation(string $uid, LoggerInterface $logger): string {
		try {
			// OpenRegister's OrganisationMapper declares
			// getActiveOrganisationWithFallback() on both `main` and
			// `development`, so the method_exists() probe this replaces could
			// never be false.
			return (string)($this->organisationMapper->getActiveOrganisationWithFallback($uid) ?? '');
		} catch (Throwable $e) {
			$logger->warning('Hermiq could not resolve active organisation: ' . $e->getMessage(), ['exception' => $e]);
		}

		return '';
	}//end activeOrganisation()
}//end class
