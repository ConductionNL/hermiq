<?php

/**
 * Unit tests for WebResearchSettingsHandler (web-research-tool).
 *
 * Covers the `hermiq.webResearch` IAppConfig round trip: full defaults when the key
 * is unset, and PATCH-merge semantics (a partial patch preserves every untouched
 * field, including `searchCredentialId`).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\WebResearch
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

namespace OCA\Hermiq\Tests\Unit\Service\WebResearch;

use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the hermiq.webResearch settings handler.
 *
 * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
 */
class WebResearchSettingsHandlerTest extends TestCase
{

    /**
     * An IAppConfig mock whose `hermiq.webResearch` value is the given string and
     * whose writes are captured into $written.
     *
     * @param string      $stored  The stored JSON (empty = unset).
     * @param string|null $written Out-param: the last written value.
     *
     * @return IAppConfig
     */
    private function appConfig(string $stored, ?string &$written=null): IAppConfig
    {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default='') use ($stored): string {
                $this->assertSame('hermiq', $app);
                $this->assertSame('webResearch', $key);
                if ($stored === '') {
                    return $default;
                }

                return $stored;
            }
        );
        $config->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value) use (&$written): bool {
                $this->assertSame('hermiq', $app);
                $this->assertSame('webResearch', $key);
                $written = $value;
                return true;
            }
        );

        return $config;

    }//end appConfig()

    /**
     * An unset key returns the full default configuration shape (design.md
     * "Configuration Shape").
     *
     * @return void
     *
     * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
     */
    public function testDefaultsWhenUnset(): void
    {
        $handler  = new WebResearchSettingsHandler($this->appConfig(''));
        $settings = $handler->getWebResearchSettingsOnly();

        $this->assertSame('', $settings['searchProvider']);
        $this->assertSame('', $settings['searchEndpoint']);
        $this->assertSame('', $settings['searchCredentialId']);
        $this->assertSame([], $settings['fetchAllowlist']);
        $this->assertSame([], $settings['fetchDenylist']);
        $this->assertFalse($settings['allowInsecureHttp']);
        $this->assertSame(500000, $settings['maxResponseBytes']);
        $this->assertSame(10, $settings['timeoutSeconds']);
        $this->assertSame('results', $settings['searchFieldMapping']['resultsPath']);
        $this->assertSame('title', $settings['searchFieldMapping']['titleField']);
        $this->assertSame('url', $settings['searchFieldMapping']['urlField']);
        $this->assertSame('content', $settings['searchFieldMapping']['snippetField']);

    }//end testDefaultsWhenUnset()

    /**
     * A stored config missing newer fields gets them backfilled (forward
     * compatibility).
     *
     * @return void
     */
    public function testBackfillsMissingFieldsOnDecode(): void
    {
        $stored = json_encode(['searchProvider' => 'searxng', 'searchEndpoint' => 'https://searxng.internal']);

        $handler  = new WebResearchSettingsHandler($this->appConfig((string) $stored));
        $settings = $handler->getWebResearchSettingsOnly();

        $this->assertSame('searxng', $settings['searchProvider']);
        $this->assertSame(500000, $settings['maxResponseBytes']);
        $this->assertSame(10, $settings['timeoutSeconds']);
        $this->assertSame([], $settings['fetchAllowlist']);

    }//end testBackfillsMissingFieldsOnDecode()

    /**
     * A partial patch (only `fetchAllowlist`) preserves every other existing field,
     * INCLUDING `searchCredentialId` (Task 1 acceptance criterion).
     *
     * @return void
     *
     * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
     */
    public function testPartialPatchPreservesOtherFields(): void
    {
        $stored = json_encode(
            [
                'searchProvider'     => 'generic-json',
                'searchEndpoint'     => 'https://api.example.test',
                'searchCredentialId' => 'cred-uuid-search',
                'fetchAllowlist'     => [],
                'fetchDenylist'      => [],
                'allowInsecureHttp'  => false,
                'maxResponseBytes'   => 500000,
                'timeoutSeconds'     => 10,
            ]
        );

        $written = null;
        $handler = new WebResearchSettingsHandler($this->appConfig((string) $stored, $written));
        $result  = $handler->updateWebResearchSettingsOnly(['fetchAllowlist' => ['en.wikipedia.org']]);

        $this->assertSame(['en.wikipedia.org'], $result['fetchAllowlist']);
        // Everything else survives untouched.
        $this->assertSame('generic-json', $result['searchProvider']);
        $this->assertSame('https://api.example.test', $result['searchEndpoint']);
        $this->assertSame('cred-uuid-search', $result['searchCredentialId']);

        $this->assertNotNull($written);
        $persisted = json_decode((string) $written, true);
        $this->assertSame(['en.wikipedia.org'], $persisted['fetchAllowlist']);
        $this->assertSame('cred-uuid-search', $persisted['searchCredentialId']);

    }//end testPartialPatchPreservesOtherFields()

    /**
     * The `searchFieldMapping` sub-object merges field-by-field, like `openaiConfig`
     * does for `LlmSettingsHandler`.
     *
     * @return void
     */
    public function testFieldMappingMergesIndividualFields(): void
    {
        $stored = json_encode(
            [
                'searchFieldMapping' => [
                    'resultsPath'  => 'data.items',
                    'titleField'   => 'headline',
                    'urlField'     => 'link',
                    'snippetField' => 'summary',
                ],
            ]
        );

        $handler = new WebResearchSettingsHandler($this->appConfig((string) $stored));
        $result  = $handler->updateWebResearchSettingsOnly(['searchFieldMapping' => ['titleField' => 'title2']]);

        $this->assertSame('title2', $result['searchFieldMapping']['titleField']);
        $this->assertSame('data.items', $result['searchFieldMapping']['resultsPath']);
        $this->assertSame('link', $result['searchFieldMapping']['urlField']);

    }//end testFieldMappingMergesIndividualFields()

    /**
     * The allowed search-provider list carries the three valid values.
     *
     * @return void
     */
    public function testAllowedSearchProviders(): void
    {
        $this->assertSame(['', 'searxng', 'generic-json'], WebResearchSettingsHandler::ALLOWED_SEARCH_PROVIDERS);

    }//end testAllowedSearchProviders()
}//end class
