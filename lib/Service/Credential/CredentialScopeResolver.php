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
class CredentialScopeResolver
{

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
     * @param string      $provider     The provider identifier (e.g. "openai", "fireworks").
     * @param string|null $actingUserId The acting user's uid, or null when there is no
     *                                  identity to check a personal credential against
     *                                  (the personal branch is then skipped entirely).
     * @param string|null $organisation The agent's organisation, or null/'' to skip the
     *                                  organisation branch (matches the
     *                                  `createChatDriver()`/`enforceModelPolicy()` opt-in
     *                                  shape — an organisation-less call never resolves
     *                                  an organisation-scope credential).
     *
     * @return string|null The resolved credential uuid, or null when neither a personal
     *                     nor an organisation match exists (fall back to instance).
     *
     * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
     */
    public function resolve(string $provider, ?string $actingUserId, ?string $organisation): ?string
    {
        $candidates = $this->loadCandidates();

        if ($actingUserId !== null && $actingUserId !== '') {
            $personal = $this->firstMatch(
                candidates: $candidates,
                provider: $provider,
                scope: self::SCOPE_PERSONAL,
                predicate: static fn (ObjectEntity $candidate, array $data): bool => $candidate->getOwner() === $actingUserId
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
                predicate: static fn (ObjectEntity $candidate, array $data): bool => (string) ($data['organisation'] ?? '') === $organisation
            );

            if ($organisationMatch !== null) {
                return $organisationMatch;
            }
        }

        return null;

    }//end resolve()

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
    private function loadCandidates(): array
    {
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
     * @param array<int, ObjectEntity>                          $candidates Every brokered-credential object.
     * @param string                                            $provider   The provider identifier to match.
     * @param string                                            $scope      The scope to match (`personal`|`organisation`).
     * @param callable(ObjectEntity, array<string,mixed>): bool $predicate  The scope-specific match (owner/organisation).
     *
     * @return string|null The first match's uuid, or null.
     */
    private function firstMatch(array $candidates, string $provider, string $scope, callable $predicate): ?string
    {
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

            return (string) $candidate->getUuid();
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
    private function scopeOf(array $data): string
    {
        $scope = (string) ($data['scope'] ?? self::SCOPE_PERSONAL);
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
    private function allowsHermiq(array $data): bool
    {
        $allowedApps = ($data['allowedApps'] ?? []);
        if (is_array($allowedApps) === false) {
            return false;
        }

        return in_array(self::APP_ID, $allowedApps, true);

    }//end allowsHermiq()
}//end class
