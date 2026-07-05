<?php

/**
 * Hermiq SetupController.
 *
 * Backs the first-run configuration wizard (src/views/SetupWizard.vue) with the two
 * things the browser cannot do itself:
 *   - llmTest: probe an LLM endpoint (Ollama /api/tags) server-side, because the browser
 *     cannot reach the model host directly (network + CORS). The endpoint is allow-listed
 *     to loopback / host.docker.internal to avoid turning this into an SSRF primitive.
 *   - organisations: list the OpenRegister organisations the caller OWNS, so the wizard can
 *     offer a tenancy scope without leaking other tenants' organisation names.
 *
 * Everything else the wizard needs (persisting the completed flag + defaults, seeding a demo
 * agent) reuses existing endpoints (PreferencesController, OpenRegister agents), so this
 * controller stays minimal.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * First-run wizard support endpoints.
 */
class SetupController extends Controller
{

    /**
     * Hosts an LLM endpoint may point at. Keeps llmTest from becoming an SSRF
     * primitive — the realistic Ollama locations in a Nextcloud deployment are
     * the loopback interface or the Docker host gateway.
     *
     * @var array<int, string>
     */
    private const ALLOWED_LLM_HOSTS = ['localhost', '127.0.0.1', '::1', 'host.docker.internal'];

    /**
     * Constructor.
     *
     * @param IRequest           $request            The request.
     * @param IClientService     $clientService      Nextcloud HTTP client factory.
     * @param OrganisationMapper $organisationMapper OpenRegister organisation lookup.
     * @param IUserSession       $userSession        The user session.
     * @param LoggerInterface    $logger             PSR-3 logger.
     */
    public function __construct(
        IRequest $request,
        private readonly IClientService $clientService,
        private readonly OrganisationMapper $organisationMapper,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Probe an LLM endpoint and return the models it advertises (Ollama /api/tags).
     *
     * @param string $endpoint The base URL of the LLM host (defaults to the Docker host gateway).
     *
     * @return JSONResponse `{reachable: bool, models: string[], error?: string}`.
     *
     * @spec exclude First-run wizard connectivity probe; no behavioural spec.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function llmTest(string $endpoint=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['reachable' => false, 'error' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $base = trim($endpoint);
        if ($base === '') {
            $base = 'http://host.docker.internal:11434';
        }

        if ($this->isAllowedEndpoint(endpoint: $base) === false) {
            return new JSONResponse(
                data: [
                    'reachable' => false,
                    'error'     => 'Endpoint host not allowed. Use localhost or host.docker.internal '
                        .'(configure a remote model host in OpenRegister).',
                ]
            );
        }

        $url = rtrim($base, '/').'/api/tags';

        try {
            $client = $this->clientService->newClient();
            // Opt this single request past Nextcloud's local-address guard: the LLM host is a
            // loopback / Docker-gateway service (the realistic Ollama location), and the host is
            // already constrained to the ALLOWED_LLM_HOSTS allow-list above, so this is not an
            // open SSRF. The global guard stays on for every other request.
            $response = $client->get(
                $url,
                [
                    'timeout'         => 5,
                    'connect_timeout' => 3,
                    'nextcloud'       => ['allow_local_address' => true],
                ]
            );
            $decoded  = json_decode((string) $response->getBody(), true);

            $models = [];
            foreach (($decoded['models'] ?? []) as $model) {
                $name = (string) ($model['name'] ?? '');
                if ($name !== '') {
                    $models[] = $name;
                }
            }

            return new JSONResponse(data: ['reachable' => true, 'models' => $models]);
        } catch (Throwable $e) {
            $this->logger->debug('[Hermiq] LLM test failed: '.$e->getMessage());
            return new JSONResponse(data: ['reachable' => false, 'models' => [], 'error' => $e->getMessage()]);
        }//end try

    }//end llmTest()

    /**
     * List the OpenRegister organisations the caller owns (tenancy scope choices).
     *
     * @return JSONResponse `{results: Array<{uuid: string, name: string}>}`.
     *
     * @spec exclude First-run wizard tenancy-scope list; no behavioural spec.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function organisations(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();
        $out = [];
        try {
            foreach ($this->organisationMapper->findAll(limit: 200) as $organisation) {
                // Only surface organisations the caller owns — never leak other tenants' names.
                if ((string) $organisation->getOwner() !== $uid) {
                    continue;
                }

                $out[] = [
                    'uuid' => (string) $organisation->getUuid(),
                    'name' => (string) $organisation->getName(),
                ];
            }
        } catch (Throwable $e) {
            $this->logger->debug('[Hermiq] organisation list failed: '.$e->getMessage());
        }

        return new JSONResponse(data: ['results' => $out]);

    }//end organisations()

    /**
     * Whether an LLM endpoint URL is a well-formed http(s) URL pointing at an allow-listed host.
     *
     * @param string $endpoint The endpoint URL.
     *
     * @return bool True when the endpoint may be probed.
     */
    private function isAllowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if ($parts === false || isset($parts['scheme']) === false || isset($parts['host']) === false) {
            return false;
        }

        if (in_array(strtolower($parts['scheme']), ['http', 'https'], true) === false) {
            return false;
        }

        return in_array(strtolower($parts['host']), self::ALLOWED_LLM_HOSTS, true);

    }//end isAllowedEndpoint()
}//end class
