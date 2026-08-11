<?php

/**
 * Hermiq WebResearchSettingsHandler.
 *
 * Reads and writes the `hermiq.webResearch` IAppConfig key: the pluggable web-search
 * backend (endpoint, provider shape, optional broker credential, field mapping for a
 * non-SearXNG JSON API) and the `web.fetch` egress-governance knobs (allowlist,
 * denylist, insecure-HTTP opt-in, size cap, timeout). Mirrors
 * `OCA\Hermiq\Service\Llm\LlmSettingsHandler`'s exact `IAppConfig` JSON-blob + PATCH-merge
 * pattern for `hermiq.llm` — no OpenRegister schema, no version bump needed (the same
 * reasoning `hermiq.llm` already established).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\WebResearch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\WebResearch;

use Exception;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Handler for the `hermiq.webResearch` IAppConfig key.
 *
 * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
 */
class WebResearchSettingsHandler
{

    /**
     * The allowed `searchProvider` values. An empty string is the valid, default
     * "unconfigured" state (`web.search` then self-reports `search_unavailable`).
     *
     * @var string[]
     */
    public const ALLOWED_SEARCH_PROVIDERS = ['', 'searxng', 'generic-json'];

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig Nextcloud app configuration service.
     * @param string     $appName   Application name the config is stored under.
     *
     * @return void
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly string $appName='hermiq'
    ) {
    }//end __construct()

    /**
     * Get the web-research settings, defaulting every field when the key is unset.
     *
     * @return array<string, mixed> The web-research configuration (design.md
     *                              "Configuration Shape").
     *
     * @throws RuntimeException If the stored config cannot be decoded.
     *
     * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
     */
    public function getWebResearchSettingsOnly(): array
    {
        try {
            $stored = $this->appConfig->getValueString($this->appName, 'webResearch', '');

            if (empty($stored) === true) {
                return $this->defaultConfig();
            }

            $decoded = json_decode($stored, true);
            if (is_array($decoded) === false) {
                throw new Exception('Stored hermiq.webResearch config is not a JSON object');
            }

            return $this->withDefaults(decoded: $decoded);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve web-research settings: '.$e->getMessage());
        }//end try

    }//end getWebResearchSettingsOnly()

    /**
     * Update the web-research settings (PATCH semantics — merges with the existing
     * stored config; an omitted or unset field keeps its prior value).
     *
     * @param array<string, mixed> $data Partial or full web-research configuration.
     *
     * @return array<string, mixed> The merged, persisted configuration.
     *
     * @throws RuntimeException If persisting fails.
     *
     * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
     */
    public function updateWebResearchSettingsOnly(array $data): array
    {
        try {
            $existing = $this->getWebResearchSettingsOnly();

            $newMapping = (array) ($data['searchFieldMapping'] ?? []);
            $exMapping  = (array) $existing['searchFieldMapping'];

            $config = [
                'searchProvider'     => $data['searchProvider'] ?? $existing['searchProvider'],
                'searchEndpoint'     => $data['searchEndpoint'] ?? $existing['searchEndpoint'],
                // A blank/omitted credentialId never clears an existing one — the
                // controller drops an explicitly-blank submission before this merge
                // ever sees it (mirrors LlmSettingsController::dropBlankCredentials()).
                'searchCredentialId' => $data['searchCredentialId'] ?? $existing['searchCredentialId'],
                'searchFieldMapping' => [
                    'resultsPath'  => $newMapping['resultsPath'] ?? $exMapping['resultsPath'],
                    'titleField'   => $newMapping['titleField'] ?? $exMapping['titleField'],
                    'urlField'     => $newMapping['urlField'] ?? $exMapping['urlField'],
                    'snippetField' => $newMapping['snippetField'] ?? $exMapping['snippetField'],
                ],
                'fetchAllowlist'     => $data['fetchAllowlist'] ?? $existing['fetchAllowlist'],
                'fetchDenylist'      => $data['fetchDenylist'] ?? $existing['fetchDenylist'],
                'allowInsecureHttp'  => $data['allowInsecureHttp'] ?? $existing['allowInsecureHttp'],
                'maxResponseBytes'   => $data['maxResponseBytes'] ?? $existing['maxResponseBytes'],
                'timeoutSeconds'     => $data['timeoutSeconds'] ?? $existing['timeoutSeconds'],
            ];

            $this->appConfig->setValueString($this->appName, 'webResearch', (string) json_encode($config));
            return $config;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update web-research settings: '.$e->getMessage());
        }//end try

    }//end updateWebResearchSettingsOnly()

    /**
     * Backfill any field missing from a decoded, previously-stored config (forward
     * compatibility, mirrors `LlmSettingsHandler::getLLMSettingsOnly()`'s backfill).
     *
     * @param array<string, mixed> $decoded The raw decoded stored config.
     *
     * @return array<string, mixed> The config with every default field present.
     */
    private function withDefaults(array $decoded): array
    {
        $defaults = $this->defaultConfig();
        $decoded['searchFieldMapping'] = array_merge(
            $defaults['searchFieldMapping'],
            (array) ($decoded['searchFieldMapping'] ?? [])
        );

        return array_merge($defaults, $decoded);

    }//end withDefaults()

    /**
     * The default configuration returned when `hermiq.webResearch` has never been set.
     *
     * @return array<string, mixed> Default web-research configuration (design.md
     *                              "Configuration Shape").
     */
    private function defaultConfig(): array
    {
        return [
            'searchProvider'     => '',
            'searchEndpoint'     => '',
            'searchCredentialId' => '',
            'searchFieldMapping' => [
                'resultsPath'  => 'results',
                'titleField'   => 'title',
                'urlField'     => 'url',
                'snippetField' => 'content',
            ],
            'fetchAllowlist'     => [],
            'fetchDenylist'      => [],
            'allowInsecureHttp'  => false,
            'maxResponseBytes'   => 500000,
            'timeoutSeconds'     => 10,
        ];

    }//end defaultConfig()
}//end class
