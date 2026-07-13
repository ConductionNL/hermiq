<?php

/**
 * Hermiq ScheduleWebhookSecretService.
 *
 * Owns the lifecycle (mint/rotate/revoke/status) of a per-SCHEDULE outbound
 * webhook signing secret (delivery-channels). Distinct from the pre-existing
 * `WebhookSecretService` (agent-webhook-trigger), which custodies a per-AGENT
 * INBOUND trigger secret as a hash on its own `AgentWebhook` OpenRegister
 * object — a different direction (inbound vs outbound), a different owner
 * shape (agent vs schedule), and a different custody requirement (hash-only
 * comparison vs a retrievable plaintext needed to compute an HMAC on every
 * delivery). Named distinctly to avoid colliding with that class.
 *
 * design.md's Decision 1 investigated OpenRegister's credential broker
 * (`CredentialBrokerService::request()`) and Doriath (ciphertext custody) at
 * HEAD and rejected both for this shape: the broker host-locks every call to
 * an admin-curated `credential-providers.json` entry (an outbound webhook's
 * whole point is an arbitrary, user-configured URL) and exposes no signing
 * operation; Doriath has no `sign()`/`hmac()` operation anywhere in its
 * source, only RSA encrypt/decrypt, and adopting it would mean provisioning
 * an RSA keypair and re-implementing ~470 lines of lazy-resolution/migration
 * logic for one per-schedule signing secret. This service instead stores the
 * secret via Nextcloud's OWN sanctioned "store/retrieve a secret an app must
 * read back later" API, `OCP\Security\ICredentialsManager` — never in an
 * OpenRegister object field (which would be readable through the generic
 * object API by anything with RBAC read on the `schedule` schema), never in
 * `oc_appconfig`, never logged.
 *
 * The Schedule object itself only ever carries three DERIVED hints written by
 * this service: `deliverWebhookSecretConfigured` (boolean) and
 * `deliverWebhookSecretRotatedAt` (the mint/rotate timestamp) — see
 * design.md's Database Changes table, which deliberately does not add a
 * separate `createdAt` schema field; a first mint and a later rotation are
 * both just "the secret changed at this moment", so `rotatedAt` alone covers
 * both without inventing an undocumented extra column.
 *
 * This is a recognised ADR-031 imperative exception: a side-effecting secret-
 * lifecycle service, not a derived value or declarative lifecycle. Schedule
 * persistence flows through OpenRegister's ObjectService single write-path
 * (ADR-001), impersonating the schedule's own owner around the save exactly
 * like `WebhookSecretService::persist()` does.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/delivery-channels/tasks.md#task-2-webhooksecretservice-icredentialsmanager-backed-mintrotaterevoke
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Mints, rotates, revokes, and reports the status of a per-schedule outbound
 * webhook signing secret custodied in `ICredentialsManager`.
 *
 * @spec openspec/changes/delivery-channels/tasks.md#task-2-webhooksecretservice-icredentialsmanager-backed-mintrotaterevoke
 */
class ScheduleWebhookSecretService
{

    /**
     * OpenRegister register slug that holds Hermiq schedule objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for schedule objects.
     *
     * @var string
     */
    private const SCHEMA_SLUG = 'schedule';

    /**
     * `ICredentialsManager` identifier prefix; the full key is this plus the
     * schedule's UUID (design.md Decision 1).
     *
     * @var string
     */
    private const CREDENTIAL_IDENTIFIER_PREFIX = 'hermiq/webhook-secret/';

    /**
     * Plaintext secret prefix (proposal.md's API Design example: `hws_…`).
     *
     * @var string
     */
    private const SECRET_PREFIX = 'hws_';

    /**
     * Bytes of entropy for a generated secret — same entropy class as the
     * inbound `WebhookSecretService::generateSecret()` (design.md Decision 1).
     *
     * @var int
     */
    private const SECRET_ENTROPY_BYTES = 32;

    /**
     * Constructor.
     *
     * @param ICredentialsManager $credentialsManager Nextcloud's sanctioned secret store.
     * @param ObjectService       $objectService      OpenRegister object read/write (single write-path).
     * @param IUserSession        $userSession        Session used to impersonate the schedule owner on save.
     * @param IUserManager        $userManager        Resolves the owner UID to an IUser.
     * @param LoggerInterface     $logger             PSR-3 logger (non-fatal bookkeeping diagnostics).
     */
    public function __construct(
        private readonly ICredentialsManager $credentialsManager,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * The `ICredentialsManager` identifier for a schedule's webhook secret.
     *
     * A single source of truth for the key format so `DeliveryService`'s
     * `deliverWebhook()` reads back the exact identifier this service writes.
     *
     * @param string $scheduleUuid The schedule UUID.
     *
     * @return string The credential identifier.
     *
     * @spec openspec/changes/delivery-channels/tasks.md#task-2-webhooksecretservice-icredentialsmanager-backed-mintrotaterevoke
     */
    public static function credentialIdentifier(string $scheduleUuid): string
    {
        return self::CREDENTIAL_IDENTIFIER_PREFIX.$scheduleUuid;

    }//end credentialIdentifier()

    /**
     * Mint a new webhook signing secret for a schedule that has none.
     *
     * @param ObjectEntity $schedule The schedule to mint a secret for.
     *
     * @return array{secret:string, rotatedAt:string} The plaintext secret (shown
     *                                                 once) and the mint timestamp.
     *
     * @throws RuntimeException When a secret already exists for this schedule (409 — use rotate()).
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function mint(ObjectEntity $schedule): array
    {
        if ($this->isConfigured(schedule: $schedule) === true) {
            throw new RuntimeException('A webhook signing secret already exists for this schedule; rotate it instead.');
        }

        return $this->storeNewSecret(schedule: $schedule);

    }//end mint()

    /**
     * Rotate an existing webhook signing secret, invalidating the previous one
     * immediately (no grace window — mirrors the inbound `WebhookSecretService::rotate()`).
     *
     * @param ObjectEntity $schedule The schedule whose secret is rotated.
     *
     * @return array{secret:string, rotatedAt:string} The new plaintext secret
     *                                                 (shown once) and the rotation timestamp.
     *
     * @throws RuntimeException When no secret exists yet for this schedule (404 — use mint()).
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function rotate(ObjectEntity $schedule): array
    {
        if ($this->isConfigured(schedule: $schedule) === false) {
            throw new RuntimeException('No webhook signing secret is configured for this schedule; mint one instead.');
        }

        return $this->storeNewSecret(schedule: $schedule);

    }//end rotate()

    /**
     * Revoke a schedule's webhook signing secret. Idempotent: safe to call
     * even when none is configured (deletes nothing, still returns success) so
     * an owner never gets a surprising error just tidying up.
     *
     * @param ObjectEntity $schedule The schedule whose secret is revoked.
     *
     * @return ObjectEntity The persisted schedule with `deliverWebhookSecretConfigured=false`.
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function revoke(ObjectEntity $schedule): ObjectEntity
    {
        $uuid = (string) $schedule->getUuid();
        $this->credentialsManager->delete((string) ($schedule->getOwner() ?? ''), self::credentialIdentifier(scheduleUuid: $uuid));

        $data = $schedule->getObject();
        $data['deliverWebhookSecretConfigured'] = false;

        return $this->persist(schedule: $schedule, data: $data);

    }//end revoke()

    /**
     * Shape a schedule's webhook-secret state into the status payload the
     * management endpoints and the UI consume. NEVER includes the plaintext secret.
     *
     * @param ObjectEntity $schedule The schedule to report on.
     *
     * @return array<string, mixed> The status payload.
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function status(ObjectEntity $schedule): array
    {
        $data = $schedule->getObject();

        return [
            'configured' => (($data['deliverWebhookSecretConfigured'] ?? false) === true),
            'rotatedAt'  => ($data['deliverWebhookSecretRotatedAt'] ?? null),
        ];

    }//end status()

    /**
     * Retrieve the plaintext signing secret for a schedule's outbound webhook
     * delivery, or null when none is configured / retrieval fails. Used ONLY
     * by `DeliveryService::deliverWebhook()` immediately before signing — the
     * plaintext is never persisted anywhere else and falls out of scope
     * immediately after use (design.md Decision 1).
     *
     * @param ObjectEntity $schedule The schedule being delivered.
     *
     * @return string|null The plaintext secret, or null when unavailable.
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
     */
    public function retrieveSecret(ObjectEntity $schedule): ?string
    {
        try {
            $secret = $this->credentialsManager->retrieve(
                (string) ($schedule->getOwner() ?? ''),
                self::credentialIdentifier(scheduleUuid: (string) $schedule->getUuid())
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not retrieve webhook signing secret for schedule '.((string) $schedule->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }//end try

        if (is_string($secret) === false || $secret === '') {
            return null;
        }

        return $secret;

    }//end retrieveSecret()

    /**
     * Whether a schedule's `deliverWebhookSecretConfigured` hint currently reads true.
     *
     * @param ObjectEntity $schedule The schedule to check.
     *
     * @return bool
     *
     * @spec openspec/changes/delivery-channels/tasks.md#task-2-webhooksecretservice-icredentialsmanager-backed-mintrotaterevoke
     */
    private function isConfigured(ObjectEntity $schedule): bool
    {
        $data = $schedule->getObject();
        return (($data['deliverWebhookSecretConfigured'] ?? false) === true);

    }//end isConfigured()

    /**
     * Generate a new secret, store it via `ICredentialsManager`, stamp the
     * schedule's derived fields, and return the plaintext once. Shared body
     * of `mint()`/`rotate()` (design.md Decision 1: rotate overwrites the same
     * identifier — the previous value is gone the instant `store()` succeeds).
     *
     * @param ObjectEntity $schedule The schedule to (re)configure.
     *
     * @return array{secret:string, rotatedAt:string} The new plaintext secret and timestamp.
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    private function storeNewSecret(ObjectEntity $schedule): array
    {
        $secret = $this->generateSecret();
        $now    = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
        $owner  = (string) ($schedule->getOwner() ?? '');

        $this->credentialsManager->store($owner, self::credentialIdentifier(scheduleUuid: (string) $schedule->getUuid()), $secret);

        $data = $schedule->getObject();
        $data['deliverWebhookSecretConfigured'] = true;
        $data['deliverWebhookSecretRotatedAt']  = $now;

        $this->persist(schedule: $schedule, data: $data);

        return ['secret' => $secret, 'rotatedAt' => $now];

    }//end storeNewSecret()

    /**
     * Generate a new plaintext secret: an `hws_`-prefixed token with >= 32
     * bytes of cryptographic entropy (random_bytes, PHP core CSPRNG).
     *
     * @return string The plaintext secret.
     *
     * @spec openspec/changes/delivery-channels/tasks.md#task-2-webhooksecretservice-icredentialsmanager-backed-mintrotaterevoke
     */
    private function generateSecret(): string
    {
        return self::SECRET_PREFIX.bin2hex(random_bytes(self::SECRET_ENTROPY_BYTES));

    }//end generateSecret()

    /**
     * Persist the schedule payload through OpenRegister, impersonating the
     * schedule's own owner around the save (mirrors the inbound
     * `WebhookSecretService::persist()`) — the object must stay owned by (and
     * tenant-scoped to) the schedule's owner regardless of the calling
     * context's own session. The prior session user is always restored.
     *
     * @param ObjectEntity        $schedule The schedule being updated.
     * @param array<string,mixed> $data     The updated schedule payload.
     *
     * @return ObjectEntity The persisted object.
     *
     * @spec openspec/changes/delivery-channels/tasks.md#task-2-webhooksecretservice-icredentialsmanager-backed-mintrotaterevoke
     */
    private function persist(ObjectEntity $schedule, array $data): ObjectEntity
    {
        $owner     = (string) ($schedule->getOwner() ?? '');
        $priorUser = $this->userSession->getUser();

        $user = null;
        if ($owner !== '') {
            $user = $this->userManager->get($owner);
        }

        if ($user !== null) {
            $this->userSession->setUser($user);
        }

        try {
            return $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER_SLUG,
                schema: self::SCHEMA_SLUG,
                uuid: (string) $schedule->getUuid(),
                _rbac: false,
                _multitenancy: false
            );
        } finally {
            $this->userSession->setUser($priorUser);
        }

    }//end persist()
}//end class
