<?php

/**
 * Hermiq CredentialScopeResolver.
 *
 * Given a broker provider identifier, the acting user, and an organisation,
 * picks the best-scoped OpenRegister credential-broker credential id: the
 * acting user's own personal credential for that provider (if it allows
 * `hermiq`), else the organisation's organisation-scope credential for that
 * provider (if it allows `hermiq`), else `null` (meaning: fall back to the
 * instance-wide configured credential — unchanged behaviour).
 *
 * Reads OpenRegister's own `credential-broker`/`brokeredcredential` register —
 * the SAME register/schema {@see \OCA\OpenRegister\Service\Credential\CredentialBrokerService}
 * itself reads from — directly via {@see ObjectService}, mirroring
 * `TenantModelPolicyService`/`ScheduleWebhookSecretService`'s existing
 * precedent in this codebase for reading a small, admin/user-curated
 * OpenRegister collection. `owner` is read via `ObjectEntity::getOwner()`
 * (the system field the broker's own `assertPersonalOwner()` guard checks
 * against); `provider`/`scope`/`organisation`/`allowedApps` are read from the
 * object's own data (`getObject()`) — the same split
 * `CredentialBrokerService` itself uses (its `organisation`/`scope`/
 * `allowedApps`/`provider` guards all read `$data[...]`, only the owner guard
 * reads `$credential->getOwner()`).
 *
 * This is a mere CANDIDATE selector, not a new trust boundary: every id this
 * class returns is re-validated in full by `CredentialBrokerService::request()`'s
 * four guards (owner/membership, allowedApps, provider allow-rules, host-lock)
 * before any secret is ever touched — see design.md "Security Considerations".
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Credential
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
 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-resolver-selections-never-bypass-the-brokers-own-guards
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Resolves the best-scoped broker credential id for a (provider, user, organisation)
 * tuple: personal → organisation → null (instance fallback).
 *
 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
 */
class CredentialScopeResolver {

	/**
	 * OpenRegister register slug holding brokered-credential metadata objects.
	 * The SAME register `CredentialBrokerService::REGISTER` reads from — this
	 * class is a second, read-only consumer of that one collection.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'credential-broker';

	/**
	 * OpenRegister schema slug for brokered-credential metadata objects.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'brokeredcredential';

	/**
	 * The app id a credential's `allowedApps[]` must contain for hermiq to be
	 * permitted to select it. Matches `BrokerHttpClient::APP_ID`.
	 *
	 * @var string
	 */
	private const APP_ID = 'hermiq';

	/**
	 * The personal (owner-scoped) credential scope — the default when the
	 * credential's own `scope` field is absent.
	 *
	 * @var string
	 */
	private const SCOPE_PERSONAL = 'personal';

	/**
	 * The organisation (membership-scoped) credential scope.
	 *
	 * @var string
	 */
	private const SCOPE_ORGANISATION = 'organisation';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read (single read-path;
	 *                                     this class never writes a credential).
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the best-scoped broker credential id for the given provider.
	 *
	 * @param string $provider The provider identifier (e.g. "openai", "fireworks").
	 * @param string|null $actingUserId The acting user's uid, or null when there is no
	 *                                  identity to check a personal credential against
	 *                                  (the personal branch is then skipped entirely).
	 * @param string|null $organisation The agent's organisation, or null/'' to skip the
	 *                                  organisation branch (matches the
	 *                                  `createChatDriver()`/`enforceModelPolicy()` opt-in
	 *                                  shape — an organisation-less call never resolves
	 *                                  an organisation-scope credential).
	 * @param array<string, string>|null $pinned The agent's own credential per provider
	 *                                  (`credentialIds`). A pin for `$provider` goes first
	 *                                  and is never bypassed: when it cannot be used the
	 *                                  resolution stops rather than trying another.
	 *
	 * @return string|null The resolved credential uuid, or null when neither a personal
	 *                     nor an organisation match exists (fall back to instance).
	 *
	 * @throws PinnedCredentialRefusedException When the pin for `$provider` cannot be used.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The personal-scope predicate closure
	 *   must keep `$_data`: `firstMatch()`'s callable contract is (ObjectEntity, array)
	 *   even when a predicate only inspects the entity.
	 *
	 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
	 */
	public function resolve(string $provider, ?string $actingUserId, ?string $organisation, ?array $pinned = null): ?string {
		$candidates = $this->loadCandidates();

		$pin = $pinned[$provider] ?? null;
		if (is_string($pin) === true && trim($pin) !== '') {
			return $this->usablePin(
				candidates: $candidates,
				pin: trim($pin),
				provider: $provider,
				actingUserId: $actingUserId,
				organisation: $organisation
			);
		}

		if ($actingUserId !== null && $actingUserId !== '') {
			$personal = $this->firstMatch(
				candidates: $candidates,
				provider: $provider,
				scope: self::SCOPE_PERSONAL,
				predicate: static fn (ObjectEntity $candidate, array $_data): bool => $candidate->getOwner() === $actingUserId
			);

			if ($personal !== null) {
				return $personal;
			}
		}

		if ($organisation !== null && $organisation !== '') {
			$organisationMatch = $this->firstMatch(
				candidates: $candidates,
				provider: $provider,
				scope: self::SCOPE_ORGANISATION,
				predicate: static fn (ObjectEntity $candidate, array $data): bool => (string)($data['organisation'] ?? '') === $organisation
			);

			if ($organisationMatch !== null) {
				return $organisationMatch;
			}
		}

		return null;
	}//end resolve()

	/**
	 * Resolve the scope of ONE known credential id.
	 *
	 * A companion to {@see resolve()}, which picks a credential id by provider. This
	 * answers the inverse question — "what scope is THIS id?" — which the `anthropic-cli`
	 * (Claude Max/Pro subscription) path needs: that credential is PERSONAL-SCOPE ONLY per
	 * the Anthropic Terms of Service and must be refused at organisation scope.
	 *
	 * The broker cannot answer this: `CredentialBrokerService::resolveInjectable()` returns
	 * a bare `string|null`, its `scopeOf()` is private, and its Guard 1 deliberately ADMITS
	 * any organisation member for an organisation-scope credential
	 * (`loadAdmittedCredential()` → `assertOrganisationMember()`). Enforcing a
	 * personal-only ToS constraint therefore has to happen caller-side, and this class is
	 * already this app's sanctioned reader of that one collection (see the class docblock).
	 *
	 * Read-only and guard-free by design: like {@see resolve()}, this is a policy input, not
	 * a trust boundary — the broker still re-runs its own guards before any secret is
	 * touched.
	 *
	 * @param string $credentialId The `credential` object UUID.
	 *
	 * @return string|null `personal`|`organisation`, or null when no such credential exists.
	 *
	 * @spec openspec/changes/cli-runner-text-turn-dispatch/specs/cli-execution-mode/spec.md#requirement-the-subscription-token-is-resolved-through-the-broker-and-never-persisted-by-hermiq
	 */
	public function scopeOfCredential(string $credentialId): ?string {
		foreach ($this->loadCandidates() as $candidate) {
			if ((string)$candidate->getUuid() === $credentialId) {
				return $this->scopeOf(data: $candidate->getObject());
			}
		}

		return null;
	}//end scopeOfCredential()

	/**
	 * The owner of ONE known credential id, or null when no such credential exists.
	 *
	 * The companion of {@see scopeOfCredential()} that the governed push needs: a run
	 * that would assemble two people's personal credentials is refused, and only the
	 * owners can tell (hermiq-runner-git-capability).
	 *
	 * @param string $credentialId The `credential` object UUID.
	 *
	 * @return string|null The owner's uid, or null.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-mismatched-credential-owners-are-refused
	 */
	public function ownerOfCredential(string $credentialId): ?string {
		foreach ($this->loadCandidates() as $candidate) {
			if ((string)$candidate->getUuid() === $credentialId) {
				return (string)($candidate->getOwner() ?? '');
			}
		}

		return null;
	}//end ownerOfCredential()

	/**
	 * The pinned credential, when the broker would admit it for this run; else a stop.
	 *
	 * The same tests the broker runs before it touches a secret, read from the same
	 * collection: the credential exists, is for this provider, allows hermiq, and is
	 * the acting user's own personal key or a key of the agent's organisation. The
	 * broker still re-runs its own guards on use; this check is what keeps a refusal
	 * from ever reaching another credential.
	 *
	 * @param array<int, ObjectEntity> $candidates   Every brokered-credential object.
	 * @param string                   $pin          The pinned credential uuid.
	 * @param string                   $provider     The provider of the turn.
	 * @param string|null              $actingUserId The acting user's uid.
	 * @param string|null              $organisation The agent's organisation.
	 *
	 * @return string The pinned uuid.
	 *
	 * @throws PinnedCredentialRefusedException When the pin cannot be used for this run.
	 *
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
	 */
	private function usablePin(array $candidates, string $pin, string $provider, ?string $actingUserId, ?string $organisation): string {
		foreach ($candidates as $candidate) {
			if ((string)$candidate->getUuid() !== $pin) {
				continue;
			}

			$data = $candidate->getObject();
			$ownerOrMember = ($candidate->getOwner() === $actingUserId && ($actingUserId ?? '') !== '');
			if ($this->scopeOf(data: $data) === self::SCOPE_ORGANISATION) {
				$ownerOrMember = ((string)($data['organisation'] ?? '') === (string)$organisation && ($organisation ?? '') !== '');
			}

			if (($data['provider'] ?? null) === $provider && $this->allowsHermiq(data: $data) === true && $ownerOrMember === true) {
				return $pin;
			}

			break;
		}//end foreach

		throw new PinnedCredentialRefusedException(provider: $provider);
	}//end usablePin()

	/**
	 * Load every brokered-credential object, system-wide — the same small,
	 * admin/user-curated collection `TenantModelPolicyService::getForOrganisation()`
	 * and `ScheduleWebhookSecretService` read in the same `_rbac: false,
	 * _multitenancy: false` shape (this class filters ownership/membership
	 * itself; RBAC/multitenancy scoping would be the wrong lens for a
	 * cross-user, cross-organisation catalogue read).
	 *
	 * @return array<int, ObjectEntity> Every brokered-credential object.
	 */
	private function loadCandidates(): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::SCHEMA_SLUG)
			->findAll(config: [], _rbac: false, _multitenancy: false);

		return array_values(array_filter($objects, static fn ($object): bool => $object instanceof ObjectEntity));
	}//end loadCandidates()

	/**
	 * Find the first candidate matching `provider`, the given `scope`,
	 * `hermiq` in `allowedApps`, and the scope-specific `$predicate` (owner
	 * equality for personal, organisation equality for organisation).
	 *
	 * @param array<int, ObjectEntity> $candidates Every brokered-credential object.
	 * @param string $provider The provider identifier to match.
	 * @param string $scope The scope to match (`personal`|`organisation`).
	 * @param callable(ObjectEntity, array<string,mixed>): bool $predicate The scope-specific match (owner/organisation).
	 *
	 * @return string|null The first match's uuid, or null.
	 */
	private function firstMatch(array $candidates, string $provider, string $scope, callable $predicate): ?string {
		foreach ($candidates as $candidate) {
			$data = $candidate->getObject();

			if (($data['provider'] ?? null) !== $provider) {
				continue;
			}

			if ($this->allowsHermiq(data: $data) === false) {
				continue;
			}

			if ($this->scopeOf(data: $data) !== $scope) {
				continue;
			}

			if ($predicate($candidate, $data) === false) {
				continue;
			}

			return (string)$candidate->getUuid();
		}//end foreach

		return null;
	}//end firstMatch()

	/**
	 * Resolve a credential's scope from its serialised data (absent ⇒ personal) —
	 * mirrors `CredentialBrokerService::scopeOf()` exactly.
	 *
	 * @param array<string, mixed> $data The credential's data (`getObject()`).
	 *
	 * @return string The scope (`personal`|`organisation`).
	 */
	private function scopeOf(array $data): string {
		$scope = (string)($data['scope'] ?? self::SCOPE_PERSONAL);
		if ($scope === self::SCOPE_ORGANISATION) {
			return self::SCOPE_ORGANISATION;
		}

		return self::SCOPE_PERSONAL;
	}//end scopeOf()

	/**
	 * Whether a credential's `allowedApps[]` contains `hermiq`.
	 *
	 * @param array<string, mixed> $data The credential's data (`getObject()`).
	 *
	 * @return bool True when hermiq is allowed to use this credential.
	 */
	private function allowsHermiq(array $data): bool {
		$allowedApps = ($data['allowedApps'] ?? []);
		if (is_array($allowedApps) === false) {
			return false;
		}

		return in_array(self::APP_ID, $allowedApps, true);
	}//end allowsHermiq()
}//end class
