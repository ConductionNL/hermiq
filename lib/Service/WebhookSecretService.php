<?php

/**
 * Hermiq WebhookSecretService.
 *
 * Owns the lifecycle (create/rotate/revoke/patch/status) and constant-time
 * verification of a per-agent `AgentWebhook` secret (agent-webhook-trigger).
 * Exactly one `AgentWebhook` OpenRegister object exists per agent, storing only
 * `hash('sha256', $secret)` — the plaintext secret is generated here, returned
 * to the caller ONCE (create/rotate), and never persisted or retrievable again.
 *
 * `verifyAndLoad()` is the single entry point the public trigger endpoint uses:
 * it ALWAYS computes a `hash_equals()` comparison — against the real stored hash
 * when an `AgentWebhook` exists, or a fixed dummy hash (`hash('sha256', '')`)
 * when it does not — so "no such webhook" and "wrong secret" are code-path-
 * identical (enumeration-safety, see design.md Decision 6). It returns the
 * matched `AgentWebhook` ObjectEntity only when the secret verifies AND the
 * webhook is enabled; every other combination returns null, collapsing every
 * auth-failure mode into one shape for the controller to turn into a single
 * generic 401.
 *
 * Every write impersonates the webhook's own owner (mirrors
 * `ApprovalService::persistApproval()`), never relying on the calling context's
 * incidental session — `markUsed()` in particular is called from the PUBLIC,
 * unauthenticated trigger endpoint, where there is no session at all; without
 * explicit impersonation the write would re-stamp owner/organisation to the
 * system fallback on every single valid trigger, corrupting tenant attribution.
 *
 * This is a recognised ADR-031 imperative exception: a side-effecting secret-
 * lifecycle service, not a derived value or declarative lifecycle. Persistence
 * flows through OpenRegister's ObjectService single write-path (ADR-001).
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
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Creates, rotates, revokes, patches, and verifies per-agent webhook secrets.
 *
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
 */
class WebhookSecretService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for AgentWebhook objects.
     *
     * @var string
     */
    private const SCHEMA_SLUG = 'agentwebhook';

    /**
     * Plaintext secret prefix (also the first 4 chars of secretPrefix).
     *
     * @var string
     */
    private const SECRET_PREFIX = 'hwh_';

    /**
     * Bytes of entropy for a generated secret (>= 32 bytes per tasks.md).
     *
     * @var int
     */
    private const SECRET_ENTROPY_BYTES = 32;

    /**
     * Length of the admin-facing secret prefix (design.md: first 8 chars, e.g. "hwh_ab12").
     *
     * @var int
     */
    private const SECRET_PREFIX_DISPLAY_LEN = 8;

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object read/write (single write-path).
     * @param IUserSession    $userSession   Session used to impersonate the webhook's owner on save.
     * @param IUserManager    $userManager   Resolves the owner UID to an IUser.
     * @param LoggerInterface $logger        PSR-3 logger (non-fatal bookkeeping diagnostics).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Find the (at most one) AgentWebhook configured for an agent, system-wide
     * (no RBAC/multitenancy) — callers apply their own guard: the management
     * controller checks agent ownership before calling in, and the public
     * trigger endpoint has no session to scope by.
     *
     * @param string $agentId The agent UUID.
     *
     * @return ObjectEntity|null The AgentWebhook object, or null when none exists.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
     */
    public function findForAgent(string $agentId): ?ObjectEntity
    {
        if ($agentId === '') {
            return null;
        }

        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEMA_SLUG)
            ->findAll(
                config: ['filters' => ['agentId' => $agentId]],
                _rbac: false,
                _multitenancy: false
            );

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getObject()['agentId'] ?? '') === $agentId) {
                return $object;
            }
        }

        return null;

    }//end findForAgent()

    /**
     * Create a new webhook secret for an agent that has none.
     *
     * @param string $agentId The agent UUID.
     * @param string $owner   The agent's owner UID (impersonated around the save,
     *                        exactly like `ApprovalService::persistApproval()`).
     *
     * @return array{secret:string, object:ObjectEntity} The plaintext secret (shown
     *                                                    once) and the persisted object.
     *
     * @throws RuntimeException When a webhook already exists for this agent (409 — use rotate()).
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
     */
    public function create(string $agentId, string $owner): array
    {
        if ($this->findForAgent(agentId: $agentId) !== null) {
            throw new RuntimeException('A webhook already exists for this agent; rotate it instead.');
        }

        $secret = $this->generateSecret();
        $now    = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

        $data = [
            'agentId'          => $agentId,
            'secretHash'       => $this->hash(secret: $secret),
            'secretPrefix'     => $this->prefixOf(secret: $secret),
            'enabled'          => true,
            'requiresApproval' => false,
            'reviewer'         => '',
            'reviewerType'     => 'user',
            'createdAt'        => $now,
            'rotatedAt'        => null,
            'lastUsedAt'       => null,
        ];

        $object = $this->persist(data: $data, uuid: null, owner: $owner);

        return ['secret' => $secret, 'object' => $object];

    }//end create()

    /**
     * Rotate an existing webhook's secret — the previous secret's hash no
     * longer verifies immediately (no rotation grace window).
     *
     * @param ObjectEntity $webhook The existing AgentWebhook object.
     *
     * @return array{secret:string, object:ObjectEntity} The new plaintext secret
     *                                                    (shown once) and the persisted object.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
     */
    public function rotate(ObjectEntity $webhook): array
    {
        $secret = $this->generateSecret();
        $now    = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

        $data = $webhook->getObject();
        $data['secretHash']   = $this->hash(secret: $secret);
        $data['secretPrefix'] = $this->prefixOf(secret: $secret);
        $data['rotatedAt']    = $now;

        $object = $this->persist(data: $data, uuid: (string) $webhook->getUuid(), owner: (string) ($webhook->getOwner() ?? ''));

        return ['secret' => $secret, 'object' => $object];

    }//end rotate()

    /**
     * Revoke a webhook: disable it without deleting its configuration.
     *
     * @param ObjectEntity $webhook The existing AgentWebhook object.
     *
     * @return ObjectEntity The persisted, now-disabled object.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
     */
    public function revoke(ObjectEntity $webhook): ObjectEntity
    {
        $data            = $webhook->getObject();
        $data['enabled'] = false;

        return $this->persist(data: $data, uuid: (string) $webhook->getUuid(), owner: (string) ($webhook->getOwner() ?? ''));

    }//end revoke()

    /**
     * Update only the approval-gate fields (requiresApproval/reviewer/reviewerType) —
     * mirrors Schedule's identical fields. Never touches the secret.
     *
     * @param ObjectEntity         $webhook The existing AgentWebhook object.
     * @param array<string, mixed> $fields  The fields to update (requiresApproval/reviewer/reviewerType).
     *
     * @return ObjectEntity The persisted, updated object.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-2-agentwebhookcontroller-session-authenticated-owner-guarded-crud
     */
    public function patch(ObjectEntity $webhook, array $fields): ObjectEntity
    {
        $data = $webhook->getObject();

        if (array_key_exists('requiresApproval', $fields) === true) {
            $data['requiresApproval'] = ($fields['requiresApproval'] === true);
        }

        if (array_key_exists('reviewer', $fields) === true) {
            $data['reviewer'] = (string) $fields['reviewer'];
        }

        if (array_key_exists('reviewerType', $fields) === true) {
            $type = (string) $fields['reviewerType'];

            $data['reviewerType'] = 'user';
            if ($type === 'group') {
                $data['reviewerType'] = 'group';
            }
        }

        return $this->persist(data: $data, uuid: (string) $webhook->getUuid(), owner: (string) ($webhook->getOwner() ?? ''));

    }//end patch()

    /**
     * Shape an AgentWebhook object (or its absence) into the status payload the
     * management GET endpoint and the AgentDetail panel consume. NEVER includes
     * secretHash or the plaintext secret.
     *
     * @param ObjectEntity|null $webhook The AgentWebhook object, or null when unconfigured.
     *
     * @return array<string, mixed> The status payload.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-2-agentwebhookcontroller-session-authenticated-owner-guarded-crud
     */
    public function status(?ObjectEntity $webhook): array
    {
        if ($webhook === null) {
            return ['configured' => false];
        }

        $data = $webhook->getObject();

        return [
            'configured'       => true,
            'enabled'          => (($data['enabled'] ?? false) === true),
            'secretPrefix'     => (string) ($data['secretPrefix'] ?? ''),
            'createdAt'        => ($data['createdAt'] ?? null),
            'rotatedAt'        => ($data['rotatedAt'] ?? null),
            'lastUsedAt'       => ($data['lastUsedAt'] ?? null),
            'requiresApproval' => (($data['requiresApproval'] ?? false) === true),
            'reviewer'         => (string) ($data['reviewer'] ?? ''),
            'reviewerType'     => (string) ($data['reviewerType'] ?? 'user'),
        ];

    }//end status()

    /**
     * Verify a trigger request's secret and return the matched, enabled
     * AgentWebhook — the SINGLE enumeration-safe auth entry point (design.md
     * Decision 6). ALWAYS computes a hash_equals() comparison, even when no
     * AgentWebhook exists for the given agent id (against a fixed, process-local
     * dummy hash), so "no such webhook", "disabled", and "wrong secret" are all
     * code-path-identical — never a shortcut that skips hash_equals() entirely.
     *
     * @param string $agentId        The agent UUID from the trigger URL.
     * @param string $providedSecret The secret from the X-Hermiq-Webhook-Secret header.
     *
     * @return ObjectEntity|null The matched, enabled AgentWebhook, or null on ANY
     *                           auth-failure mode (unknown agent / no webhook /
     *                           disabled / wrong secret).
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-3-webhooktriggercontroller-public-secret-authenticated-enumeration-safe
     */
    public function verifyAndLoad(string $agentId, string $providedSecret): ?ObjectEntity
    {
        $webhook = $this->findForAgent(agentId: $agentId);

        $storedHash = $this->dummyHash();
        $enabled    = false;
        if ($webhook !== null) {
            $data       = $webhook->getObject();
            $storedHash = (string) ($data['secretHash'] ?? $this->dummyHash());
            $enabled    = (($data['enabled'] ?? false) === true);
        }

        // ALWAYS compare — even for a nonexistent webhook (dummy hash) — so the
        // code path never short-circuits before hash_equals() runs.
        $matches = hash_equals($storedHash, $this->hash(secret: $providedSecret));

        if ($webhook !== null && $enabled === true && $matches === true) {
            return $webhook;
        }

        return null;

    }//end verifyAndLoad()

    /**
     * Best-effort, non-fatal bookkeeping: record that a valid, enabled webhook
     * just accepted a request. Never throws — a failure here must not affect
     * the trigger response.
     *
     * @param ObjectEntity $webhook The verified AgentWebhook object.
     *
     * @return void
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
     */
    public function markUsed(ObjectEntity $webhook): void
    {
        try {
            $data = $webhook->getObject();
            $data['lastUsedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
            $this->persist(data: $data, uuid: (string) $webhook->getUuid(), owner: (string) ($webhook->getOwner() ?? ''));
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not record webhook lastUsedAt for '.((string) $webhook->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end markUsed()

    /**
     * Generate a new plaintext secret: an `hwh_`-prefixed token with >= 32
     * bytes of cryptographic entropy (random_bytes, PHP core CSPRNG).
     *
     * @return string The plaintext secret.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
     */
    private function generateSecret(): string
    {
        return self::SECRET_PREFIX.bin2hex(random_bytes(self::SECRET_ENTROPY_BYTES));

    }//end generateSecret()

    /**
     * Hash a secret for storage/comparison (SHA-256 digest only — design.md Decision 2).
     *
     * @param string $secret The plaintext secret.
     *
     * @return string The hex-encoded SHA-256 digest.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
     */
    private function hash(string $secret): string
    {
        return hash('sha256', $secret);

    }//end hash()

    /**
     * The fixed, process-local dummy hash compared against when no AgentWebhook
     * exists — `hash('sha256', '')` (design.md Decision 6).
     *
     * @return string The dummy SHA-256 digest.
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
     */
    private function dummyHash(): string
    {
        return hash('sha256', '');

    }//end dummyHash()

    /**
     * The admin-facing secret prefix (first 8 chars of the plaintext, e.g. "hwh_ab12").
     *
     * @param string $secret The plaintext secret.
     *
     * @return string The display prefix.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
     */
    private function prefixOf(string $secret): string
    {
        return substr($secret, 0, self::SECRET_PREFIX_DISPLAY_LEN);

    }//end prefixOf()

    /**
     * Persist an AgentWebhook payload through OpenRegister, impersonating the
     * given owner around the save (mirrors `ApprovalService::persistApproval()`)
     * — the object must be owned by (and tenant-scoped to) the agent's owner
     * regardless of the calling context's own session (or its total absence, on
     * the public trigger endpoint's `markUsed()` path). The prior session user
     * is always restored.
     *
     * @param array<string, mixed> $data  The AgentWebhook payload.
     * @param string|null          $uuid  The target UUID (null to create).
     * @param string               $owner The agent owner UID to impersonate.
     *
     * @return ObjectEntity The persisted object.
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-1-agentwebhook-schema-secret-lifecycle-service
     */
    private function persist(array $data, ?string $uuid, string $owner): ObjectEntity
    {
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
                uuid: $uuid,
                _rbac: false,
                _multitenancy: false
            );
        } finally {
            $this->userSession->setUser($priorUser);
        }

    }//end persist()
}//end class
