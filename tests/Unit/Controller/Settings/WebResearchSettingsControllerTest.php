<?php

/**
 * Unit tests for WebResearchSettingsController (web-research-tool).
 *
 * Covers: the credential masked to a boolean (and NEVER echoed back, unlike
 * LlmSettingsController's credentialId-in-the-clear precedent — this endpoint's
 * stricter acceptance criterion), provider validation (422 on an unknown provider),
 * and the blank-credential-drop that prevents an unedited masked field from wiping a
 * stored credential.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
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

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\WebResearchSettingsController;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for WebResearchSettingsController.
 *
 * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
 */
class WebResearchSettingsControllerTest extends TestCase
{

    /**
     * A representative stored config with a set search credential.
     *
     * @return array<string, mixed>
     */
    private function storedConfig(): array
    {
        return [
            'searchProvider'     => 'generic-json',
            'searchEndpoint'     => 'https://api.example.test',
            'searchCredentialId' => 'cred-uuid-search',
            'searchFieldMapping' => ['resultsPath' => 'results', 'titleField' => 'title', 'urlField' => 'url', 'snippetField' => 'content'],
            'fetchAllowlist'     => ['en.wikipedia.org'],
            'fetchDenylist'      => [],
            'allowInsecureHttp'  => false,
            'maxResponseBytes'   => 500000,
            'timeoutSeconds'     => 10,
        ];

    }//end storedConfig()

    /**
     * Build a controller over mocked collaborators.
     *
     * @param IRequest                   $request The (already-configured) request mock.
     * @param WebResearchSettingsHandler $handler The (already-configured) settings handler mock.
     *
     * @return WebResearchSettingsController
     */
    private function controller(IRequest $request, WebResearchSettingsHandler $handler): WebResearchSettingsController
    {
        return new WebResearchSettingsController($request, $handler, new NullLogger());

    }//end controller()

    /**
     * get() replaces `searchCredentialId` with a derived boolean and NEVER echoes
     * the raw id back.
     *
     * @return void
     *
     * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
     */
    public function testGetMasksCredentialAndNeverReturnsTheRawId(): void
    {
        $handler = $this->createMock(WebResearchSettingsHandler::class);
        $handler->method('getWebResearchSettingsOnly')->willReturn($this->storedConfig());

        $response = $this->controller($this->createMock(IRequest::class), $handler)->get();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($data['searchCredentialConfigured']);
        $this->assertArrayNotHasKey('searchCredentialId', $data);
        $this->assertSame('generic-json', $data['searchProvider']);

        $serialised = json_encode($data);
        $this->assertStringNotContainsString('cred-uuid-search', (string) $serialised);

    }//end testGetMasksCredentialAndNeverReturnsTheRawId()

    /**
     * get() reports `searchCredentialConfigured: false` when no credential is set.
     *
     * @return void
     */
    public function testGetReportsUnconfiguredCredential(): void
    {
        $config                        = $this->storedConfig();
        $config['searchCredentialId'] = '';

        $handler = $this->createMock(WebResearchSettingsHandler::class);
        $handler->method('getWebResearchSettingsOnly')->willReturn($config);

        $response = $this->controller($this->createMock(IRequest::class), $handler)->get();
        $data     = $response->getData();

        $this->assertFalse($data['searchCredentialConfigured']);

    }//end testGetReportsUnconfiguredCredential()

    /**
     * update() rejects a provider outside the allowed set with 422.
     *
     * @return void
     *
     * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
     */
    public function testUpdateRejectsUnknownProvider(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->with('webResearch')->willReturn(['searchProvider' => 'bing']);

        $handler = $this->createMock(WebResearchSettingsHandler::class);
        $handler->expects($this->never())->method('updateWebResearchSettingsOnly');

        $response = $this->controller($request, $handler)->update();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testUpdateRejectsUnknownProvider()

    /**
     * update() accepts every allowed provider.
     *
     * @return void
     */
    public function testAllAllowedProvidersAccepted(): void
    {
        foreach (WebResearchSettingsHandler::ALLOWED_SEARCH_PROVIDERS as $provider) {
            $request = $this->createMock(IRequest::class);
            $request->method('getParam')->with('webResearch')->willReturn(['searchProvider' => $provider]);

            $merged                     = $this->storedConfig();
            $merged['searchProvider']  = $provider;

            $handler = $this->createMock(WebResearchSettingsHandler::class);
            $handler->method('updateWebResearchSettingsOnly')->willReturn($merged);

            $response = $this->controller($request, $handler)->update();
            $this->assertSame(Http::STATUS_OK, $response->getStatus(), "provider '{$provider}' should be accepted");
        }

    }//end testAllAllowedProvidersAccepted()

    /**
     * update() drops a blank `searchCredentialId` so it never clears a stored
     * credential (mirrors LlmSettingsController's blank-credential-drop).
     *
     * @return void
     *
     * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
     */
    public function testUpdateDropsBlankSearchCredential(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->with('webResearch')->willReturn(
            ['searchProvider' => 'searxng', 'searchCredentialId' => '']
        );

        $handler = $this->createMock(WebResearchSettingsHandler::class);
        $handler->expects($this->once())
            ->method('updateWebResearchSettingsOnly')
            ->with($this->callback(static fn (array $patch): bool => array_key_exists('searchCredentialId', $patch) === false))
            ->willReturn($this->storedConfig());

        $response = $this->controller($request, $handler)->update();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testUpdateDropsBlankSearchCredential()

    /**
     * update() persists an explicitly-set `searchCredentialId` (a non-blank value
     * is NOT dropped).
     *
     * @return void
     */
    public function testUpdatePersistsANonBlankSearchCredential(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->with('webResearch')->willReturn(
            ['searchProvider' => 'generic-json', 'searchCredentialId' => 'new-cred-uuid']
        );

        $handler = $this->createMock(WebResearchSettingsHandler::class);
        $handler->expects($this->once())
            ->method('updateWebResearchSettingsOnly')
            ->with($this->callback(static fn (array $patch): bool => ($patch['searchCredentialId'] ?? null) === 'new-cred-uuid'))
            ->willReturn($this->storedConfig());

        $response = $this->controller($request, $handler)->update();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testUpdatePersistsANonBlankSearchCredential()
}//end class
