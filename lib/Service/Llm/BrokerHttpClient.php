<?php

/**
 * Hermiq BrokerHttpClient.
 *
 * A PSR-18 HTTP client that routes an LLM provider call through OpenRegister's credential
 * broker instead of making it directly.
 *
 * Hermiq used to hold the OpenAI and Fireworks API keys outright — and, unlike the other
 * apps in the fleet, not even encrypted: they sat in cleartext inside the `hermiq.llm`
 * JSON blob in `oc_appconfig`, readable by anything that could read the database, and
 * printed verbatim by `occ config:app:get hermiq llm`. The keys were then handed to
 * LLPhant, which pasted them into an `Authorization` header.
 *
 * Now the key lives in the vault and the broker injects it server-side. This class is the
 * seam that makes that possible without rewriting LLPhant: `OpenAIConfig` accepts a
 * pre-built `OpenAI\Client`, and `OpenAI::factory()` accepts any PSR-18 client — so we
 * hand it this one, and every request the library makes is transparently proxied.
 *
 * As in the other apps:
 *
 *   - the request URI is reduced to a PATH. The host is the broker's host-lock, which is
 *     the point: a client that can name the host can name a different one.
 *   - any `Authorization` header the library set is DROPPED. The broker discards
 *     caller-supplied auth anyway, but dropping it here means the placeholder we hand
 *     openai-php (it requires *some* key) can never be mistaken for a real one.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-1-brokerhttpclient
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use GuzzleHttp\Psr7\Response;
use OCP\Server;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * PSR-18 client that delegates the call — and the secret — to the credential broker.
 *
 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-1-brokerhttpclient
 */
class BrokerHttpClient implements ClientInterface
{
    /**
     * OpenRegister's credential broker. Resolved lazily so Hermiq still boots without
     * OpenRegister installed.
     *
     * @var string
     */
    public const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

    /**
     * The broker `appId` Hermiq identifies itself with. Must match the credential's
     * `allowedApps` grant or the broker refuses the call.
     *
     * @var string
     */
    public const APP_ID = 'hermiq';

    /**
     * Stand-in for the key openai-php insists on being given.
     *
     * `OpenAI::factory()` requires an API key and sets it as a Bearer header before this
     * client ever sees the request. It never reaches the wire: `request()` strips the
     * Authorization header, and the broker injects the real secret from the vault.
     *
     * @var string
     */
    public const BROKER_MANAGED_KEY = '__managed_by_credential_broker__';

    /**
     * Headers the broker owns. Whatever the library sets here is dropped.
     *
     * @var array<int, string>
     */
    private const BROKER_OWNED_HEADERS = ['authorization', 'x-api-key', 'apikey'];

    /**
     * Response headers that describe the upstream transfer rather than the payload.
     * The broker already materialised and decoded the body, so forwarding these
     * would describe a transfer that no longer applies (a stale `Content-Length`
     * or a `Content-Encoding: gzip` on an already-decoded body).
     *
     * @var array<int, string>
     */
    private const HOP_BY_HOP_RESPONSE_HEADERS = [
        'transfer-encoding',
        'content-encoding',
        'content-length',
        'connection',
        'keep-alive',
    ];

    /**
     * Constructor.
     *
     * @param string          $credentialId Broker credential UUID. A reference, not a
     *                                      secret — this process cannot read the key.
     * @param LoggerInterface $logger       The logger.
     * @param string|null     $actingUserId Credential owner. Required on the background /
     *                                      scheduled-agent path, where there is no session
     *                                      for the broker's ownership guard to read.
     */
    public function __construct(
        private string $credentialId,
        private LoggerInterface $logger,
        private ?string $actingUserId=null,
    ) {
    }//end __construct()

    /**
     * Whether OpenRegister's credential broker is installed.
     *
     * @return bool True when the broker class can be resolved.
     *
     * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-1-brokerhttpclient
     */
    public static function isAvailable(): bool
    {
        return class_exists(self::BROKER_CLASS) === true;
    }//end isAvailable()

    /**
     * Send a PSR-7 request through the broker and return the PSR-7 response.
     *
     * @param RequestInterface $request The request the LLM library built.
     *
     * @return ResponseInterface The provider's response.
     *
     * @throws ClientExceptionInterface Never thrown directly; see RuntimeException below.
     * @throws RuntimeException         When the broker is absent, unconfigured, or denies
     *                                  the call. Failing closed is deliberate: there is no
     *                                  app-held key left to fall back to.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) OCP\Server::get is deliberate lazy resolution
     *   of the optional OpenRegister broker (feature-detected via isAvailable()) so this
     *   class stays constructible when the broker is absent.
     *
     * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-1-brokerhttpclient
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (self::isAvailable() === false) {
            throw new RuntimeException(
                'Hermiq LLM: the OpenRegister credential broker is not available; refusing to call the provider.'
            );
        }

        if ($this->credentialId === '') {
            throw new RuntimeException('Hermiq LLM: no broker credential is configured for this provider.');
        }

        $uri  = $request->getUri();
        $path = $uri->getPath();
        if ($path === '') {
            $path = '/';
        }

        if ($uri->getQuery() !== '') {
            $path .= '?'.$uri->getQuery();
        }

        try {
            $broker   = Server::get(self::BROKER_CLASS);
            $response = $broker->request(
                $this->credentialId,
                self::APP_ID,
                $request->getMethod(),
                $path,
                $this->headersWithoutAuth(request: $request),
                (string) $request->getBody(),
                $this->actingUserId
            );
        } catch (Throwable $e) {
            // Never log the body — it carries the prompt, which can carry anything the
            // user typed. Method and path only.
            $this->logger->warning(
                '[Hermiq] LLM broker call failed',
                [
                    'method' => $request->getMethod(),
                    'path'   => $path,
                ]
            );
            throw new RuntimeException('Hermiq LLM: the credential broker refused or could not make the call.', 0, $e);
        }//end try

        return new Response(
            (int) ($response['status'] ?? 502),
            $this->passthroughResponseHeaders(brokerHeaders: ($response['headers'] ?? [])),
            (string) ($response['body'] ?? '')
        );
    }//end sendRequest()

    /**
     * The upstream provider's response headers, minus the transfer-scoped ones.
     *
     * The broker returns the provider's real response headers; forwarding them lets
     * callers read provider signals that only live in headers — `retry-after` and the
     * `anthropic-ratelimit-*` / `x-ratelimit-*` counters most importantly, since
     * without them a 429 is indistinguishable from a hard refusal.
     *
     * @param mixed $brokerHeaders The broker's `headers` entry (name => list<string>).
     *
     * @return array<string, array<int, string>|string> Headers for the PSR-7 response.
     *
     * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-1-brokerhttpclient
     */
    private function passthroughResponseHeaders(mixed $brokerHeaders): array
    {
        if (is_array($brokerHeaders) === false || $brokerHeaders === []) {
            return ['Content-Type' => 'application/json'];
        }

        $out = [];
        foreach ($brokerHeaders as $name => $values) {
            if (in_array(strtolower((string) $name), self::HOP_BY_HOP_RESPONSE_HEADERS, true) === true) {
                continue;
            }

            $out[(string) $name] = $values;
        }

        if ($out === []) {
            return ['Content-Type' => 'application/json'];
        }

        return $out;
    }//end passthroughResponseHeaders()

    /**
     * The request's headers, minus the ones the broker owns.
     *
     * @param RequestInterface $request The request.
     *
     * @return array<string, string> Header name => value.
     */
    private function headersWithoutAuth(RequestInterface $request): array
    {
        $out = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (in_array(strtolower((string) $name), self::BROKER_OWNED_HEADERS, true) === true) {
                continue;
            }

            $out[$name] = implode(', ', $values);
        }

        return $out;
    }//end headersWithoutAuth()
}//end class
