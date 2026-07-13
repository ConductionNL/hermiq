<?php

/**
 * Hermiq WebhookTriggerController.
 *
 * The public inbound webhook trigger (agent-webhook-trigger): `POST
 * /api/agents/{id}/webhook`. Authenticated SOLELY by a per-webhook shared
 * secret (`X-Hermiq-Webhook-Secret` header), never a Nextcloud session —
 * `#[PublicPage]` is deliberate and safe here because the METHOD BODY is the
 * real gate, verified against `WebhookSecretService::verifyAndLoad()` before
 * any other work happens (mirrors `decidesk\ParticipationController::
 * submitAnonymousReaction()`'s identically-shaped "public endpoint, secret-
 * authenticated" case).
 *
 * Deliberately kept separate from `AgentWebhookController` (the session-
 * authenticated management CRUD) — a `#[PublicPage]` method must never sit in
 * the same class as session-authenticated ones (design.md's Nextcloud
 * Integration section).
 *
 * Auth-check-and-enqueue ONLY: `trigger()` never runs the agent inline. It
 * validates the secret + payload size, generates a `correlationId`, enqueues
 * `WebhookAgentRunJob`, and returns `202 Accepted` immediately (design.md
 * Decision 5) — an LLM call can take many seconds, and the caller's own
 * webhook-delivery mechanism typically has its own timeout a synchronous LLM
 * call risks tripping.
 *
 * Every authentication failure mode — unknown agent, no webhook configured, a
 * disabled webhook, or a wrong secret — returns the BYTE-IDENTICAL generic 401
 * response (design.md Decision 6 / the enumeration-safety requirement), so the
 * endpoint can never be used to enumerate valid agent ids.
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
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-3-webhooktriggercontroller-public-secret-authenticated-enumeration-safe
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Cron\WebhookAgentRunJob;
use OCA\Hermiq\Service\WebhookSecretService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public, secret-authenticated per-agent webhook trigger endpoint.
 *
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-3-webhooktriggercontroller-public-secret-authenticated-enumeration-safe
 */
class WebhookTriggerController extends Controller
{

    /**
     * Header carrying the shared secret (design.md Decision 1 — a dedicated
     * header, never `Authorization: Bearer`, so it is never confused with, or
     * accidentally overwritten by, a reverse proxy's own Authorization handling).
     *
     * @var string
     */
    private const SECRET_HEADER = 'X-Hermiq-Webhook-Secret';

    /**
     * Hard payload-size cap in bytes (64 KiB, design.md API Design).
     *
     * @var int
     */
    private const MAX_PAYLOAD_BYTES = 65536;

    /**
     * Constructor.
     *
     * @param IRequest             $request              The request object.
     * @param WebhookSecretService $webhookSecretService The enumeration-safe secret verifier.
     * @param IJobList             $jobList              Enqueues the one-shot WebhookAgentRunJob.
     * @param LoggerInterface      $logger               PSR-3 logger (enqueue failures only — never
     *                                                   auth-failure diagnostics, which must stay silent
     *                                                   to preserve enumeration-safety).
     */
    public function __construct(
        IRequest $request,
        private readonly WebhookSecretService $webhookSecretService,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Trigger a governed agent run via its per-agent webhook secret.
     *
     * @param string $id The agent UUID (from the trigger URL).
     *
     * @return JSONResponse 202 with a correlationId; 401 (identical body for every
     *                      auth-failure mode); 413 over the payload cap; 400 for a
     *                      non-JSON body (checked only AFTER the secret verifies, so
     *                      it can never leak agent existence); 429 via AnonRateLimit.
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-payload-is-size-capped-before-it-is-processed
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-trigger-endpoint-is-rate-limited
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function trigger(string $id): JSONResponse
    {
        // Fast reject via Content-Length BEFORE reading the body or touching any
        // data — the cheapest possible check, independent of agent/secret state.
        $contentLength = (int) $this->request->getHeader('Content-Length');
        if ($contentLength > self::MAX_PAYLOAD_BYTES) {
            return $this->tooLargeResponse();
        }

        $secret  = (string) $this->request->getHeader(self::SECRET_HEADER);
        $webhook = $this->webhookSecretService->verifyAndLoad(agentId: $id, providedSecret: $secret);
        if ($webhook === null) {
            // Enumeration-safe: identical for unknown agent / no webhook / disabled / wrong secret.
            return $this->unauthorizedResponse();
        }

        $rawBody = $this->readRawBody();

        // Re-checked on the ACTUAL byte count — catches a missing or lying
        // Content-Length header (design.md Security Considerations).
        if (strlen($rawBody) > self::MAX_PAYLOAD_BYTES) {
            return $this->tooLargeResponse();
        }

        $payload = [];
        if (trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
                return new JSONResponse(['error' => 'invalid_json'], Http::STATUS_BAD_REQUEST);
            }

            $payload = $decoded;
        }

        $webhookData = $webhook->getObject();
        $context     = [
            'agentId'          => $id,
            'payload'          => $payload,
            'correlationId'    => $this->generateCorrelationId(),
            'requiresApproval' => (($webhookData['requiresApproval'] ?? false) === true),
            'reviewer'         => (string) ($webhookData['reviewer'] ?? ''),
            'reviewerType'     => (string) ($webhookData['reviewerType'] ?? 'user'),
        ];

        try {
            $this->jobList->add(WebhookAgentRunJob::class, $context);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq could not enqueue webhook agent-run job: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not accept the trigger'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Best-effort, non-fatal — never blocks or fails the 202 response.
        $this->webhookSecretService->markUsed(webhook: $webhook);

        return new JSONResponse(
            ['status' => 'accepted', 'correlationId' => $context['correlationId']],
            Http::STATUS_ACCEPTED
        );

    }//end trigger()

    /**
     * Read the raw POST body. Indirected (mirrors pipelinq's
     * `BlastWebhookController::readRawBody()`) so tests can override it without
     * needing to stub `php://input` (which cannot be repopulated per-test in a
     * CLI PHPUnit run).
     *
     * @return string The raw request body, or '' when unreadable.
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-payload-is-size-capped-before-it-is-processed
     */
    protected function readRawBody(): string
    {
        $body = file_get_contents('php://input');
        if ($body === false) {
            return '';
        }

        return $body;

    }//end readRawBody()

    /**
     * The single generic 401 response shape — used for EVERY auth-failure mode
     * (unknown agent, no webhook configured, disabled webhook, wrong secret) so
     * the endpoint cannot be used to enumerate valid agent ids.
     *
     * @return JSONResponse The 401 response.
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
     */
    private function unauthorizedResponse(): JSONResponse
    {
        return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);

    }//end unauthorizedResponse()

    /**
     * The 413 response for an oversized payload.
     *
     * @return JSONResponse The 413 response.
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-payload-is-size-capped-before-it-is-processed
     */
    private function tooLargeResponse(): JSONResponse
    {
        return new JSONResponse(['error' => 'payload_too_large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);

    }//end tooLargeResponse()

    /**
     * Generate a random UUID v4 (pure PHP — no Symfony/Ramsey uuid dependency
     * exists in Hermiq's own composer.json) for a trigger's `correlationId`.
     *
     * @return string The generated UUID v4.
     *
     * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session
     */
    private function generateCorrelationId(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    }//end generateCorrelationId()
}//end class
