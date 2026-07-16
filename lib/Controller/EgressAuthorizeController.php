<?php

/**
 * Hermiq EgressAuthorizeController — the governed egress Policy Decision Point
 * (Endpoint 2 of cli-runner-governed-mcp-and-egress).
 *
 * The runner container has NO default route; a forward proxy (the Policy
 * Enforcement Point) is its only path off the box. On every `CONNECT` the proxy
 * calls this endpoint with the destination `{host, port}` and the run's bearer
 * token, and denies the tunnel unless Hermiq returns `allowed: true`. The verdict
 * comes STRAIGHT from `WebResearchEgressGuard::assertSafe()` — the SAME policy
 * source `hermiq.webFetch` (Endpoint 1) uses — so there is one allowlist and no
 * drift. Default action is DENY.
 *
 * **The per-run token IS the authorization** (ADR-005 semantic-auth). This route
 * is `#[PublicPage]` + `#[NoCSRFRequired]` deliberately: the caller is a proxy
 * sidecar with no Nextcloud session and no cookie jar, so there is nothing for NC
 * to authenticate at the framework layer and nothing for CSRF to attack. The body
 * calls no `requireAdmin()`/`isAdmin()`; the bearer token — the SAME token as
 * Endpoint 1 (`RunTokenService`) — is the endpoint's actual and only
 * authorization. A missing/invalid/expired/consumed token is rejected 401 BEFORE
 * any policy is evaluated. This mirrors the ADR's own named `#[PublicPage]`
 * exemplars (OAuth callbacks, webhook receivers).
 *
 * A `CONNECT` exposes only host:port — never the path (it is inside the TLS
 * tunnel) — so this endpoint decides at HOST granularity. Full-URL enforcement
 * lives on Endpoint 1. This is a deliberate limitation (design.md "The CONNECT
 * limitation"), not an oversight; TLS interception was rejected.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The token-gated egress PDP: answers allow/deny per CONNECT from the shared
 * `WebResearchEgressGuard` policy.
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy
 */
class EgressAuthorizeController extends Controller
{

    /**
     * Constructor.
     *
     * @param IRequest                   $request         The request object.
     * @param RunTokenService            $runTokenService Verifies the per-run bearer token
     *                                                    (the SAME token Endpoint 1 uses).
     * @param WebResearchEgressGuard     $guard           THE shared egress policy source (public,
     *                                                    dependency-free — no second allowlist).
     * @param WebResearchSettingsHandler $settingsHandler Reads the same allowlist/denylist/insecure
     *                                                    knobs `hermiq.webFetch` reads.
     * @param LoggerInterface            $logger          PSR-3 logger (never receives a token value).
     */
    public function __construct(
        IRequest $request,
        private readonly RunTokenService $runTokenService,
        private readonly WebResearchEgressGuard $guard,
        private readonly WebResearchSettingsHandler $settingsHandler,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Decide whether the run identified by the bearer token may reach `host:port`.
     *
     * Fails closed: no/invalid token → 401 before any policy evaluation; a missing
     * host → 400; every other outcome is a 200 with `{allowed, code, message}`
     * copied straight from `WebResearchEgressGuard::assertSafe()`. `allowed: true`
     * is the ONLY permit signal.
     *
     * @return JSONResponse The verdict, or a 400/401 error.
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-the-proxy-denies-a-non-allowlisted-host-at-the-network-layer
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-one-policy-source-governs-both-layers
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function authorize(): JSONResponse
    {
        // AUTH FIRST — the per-run token is the authorization. A missing/invalid/
        // expired/consumed token is rejected before any policy is evaluated.
        $binding = $this->runTokenService->verify(token: $this->bearerToken());
        if ($binding === null) {
            return new JSONResponse(['error' => 'invalid_token'], Http::STATUS_UNAUTHORIZED);
        }

        $body = json_decode($this->readRawBody(), true);
        if (is_array($body) === false) {
            return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
        }

        $host = trim((string) ($body['host'] ?? ''));
        $port = (int) ($body['port'] ?? 0);
        if ($host === '' || $port <= 0) {
            return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
        }

        $config  = $this->settingsHandler->getWebResearchSettingsOnly();
        $verdict = $this->guard->assertSafe(
            url: 'https://'.$host.':'.$port.'/',
            isAdminConfiguredEndpoint: false,
            allowlist: (array) ($config['fetchAllowlist'] ?? []),
            denylist: (array) ($config['fetchDenylist'] ?? []),
            allowInsecureHttp: (bool) ($config['allowInsecureHttp'] ?? false)
        );

        return new JSONResponse(
            [
                'allowed' => $verdict['allowed'],
                'code'    => $verdict['code'],
                'message' => $verdict['message'],
            ]
        );

    }//end authorize()

    /**
     * Extract the bearer token from the `Authorization` header. Never logged.
     *
     * @return string The token, or '' when absent/malformed.
     */
    private function bearerToken(): string
    {
        $header = (string) $this->request->getHeader('Authorization');
        if (stripos($header, 'Bearer ') !== 0) {
            return '';
        }

        return trim(substr($header, 7));

    }//end bearerToken()

    /**
     * Read the raw POST body. Indirected (mirrors `WebhookTriggerController`) so
     * tests can override it without stubbing `php://input`.
     *
     * @return string The raw request body, or '' when unreadable.
     */
    protected function readRawBody(): string
    {
        $body = file_get_contents('php://input');
        if ($body === false) {
            return '';
        }

        return $body;

    }//end readRawBody()
}//end class
