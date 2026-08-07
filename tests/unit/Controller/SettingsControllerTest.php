<?php

/**
 * Unit tests for SettingsController.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SettingsController;
use OCA\Hermiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SettingsController.
 */
class SettingsControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var SettingsController
     */
    private SettingsController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock SettingsService.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);

        $this->controller = new SettingsController(
            request: $this->request,
            settingsService: $this->settingsService,
            // The controller now translates a throwable into a JSON error rather
            // than letting the framework render a stack trace, and it records
            // why. A mock is enough here: these tests assert the RESPONSE, and
            // asserting on log calls would pin the wording rather than the
            // behaviour.
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end setUp()

    /**
     * index() for a non-admin user does NOT include the register binding.
     *
     * @return void
     */
    public function testIndexStripsRegisterForNonAdmin(): void
    {
        $settings = [
            'register'      => 'some-uuid',
            'openregisters' => true,
            'isAdmin'       => false,
        ];

        $this->settingsService->expects($this->once())
            ->method('getSettings')
            ->willReturn($settings);

        $result = $this->controller->index();

        self::assertInstanceOf(JSONResponse::class, $result);
        $data = $result->getData();
        self::assertArrayNotHasKey('register', $data, 'register must be stripped for non-admin users');
        self::assertSame(true, $data['openregisters']);
        self::assertSame(false, $data['isAdmin']);

    }//end testIndexStripsRegisterForNonAdmin()

    /**
     * index() for an admin user includes the register binding.
     *
     * @return void
     */
    public function testIndexIncludesRegisterForAdmin(): void
    {
        $settings = [
            'register'      => 'some-uuid',
            'openregisters' => true,
            'isAdmin'       => true,
        ];

        $this->settingsService->expects($this->once())
            ->method('getSettings')
            ->willReturn($settings);

        $result = $this->controller->index();

        self::assertInstanceOf(JSONResponse::class, $result);
        $data = $result->getData();
        self::assertArrayHasKey('register', $data, 'register must be present for admin users');
        self::assertSame('some-uuid', $data['register']);

    }//end testIndexIncludesRegisterForAdmin()

    /**
     * Test that create() calls updateSettings with request params and returns success.
     *
     * @return void
     */
    public function testCreateCallsUpdateSettingsAndReturnsSuccess(): void
    {
        $params  = ['register' => 'new-uuid'];
        $updated = ['register' => 'new-uuid', 'openregisters' => true, 'isAdmin' => false];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($params);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($params)
            ->willReturn($updated);

        $result = $this->controller->create();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertTrue($result->getData()['success']);
        self::assertArrayHasKey('config', $result->getData());

    }//end testCreateCallsUpdateSettingsAndReturnsSuccess()

    /**
     * Test that load() returns the result of loadConfiguration.
     *
     * @return void
     */
    public function testLoadReturnsConfigurationResult(): void
    {
        $loadResult = [
            'success' => true,
            'message' => 'Configuration imported successfully.',
            'version' => '0.1.0',
        ];

        $this->settingsService->expects($this->once())
            ->method('loadConfiguration')
            ->with(force: true)
            ->willReturn($loadResult);

        $result = $this->controller->load();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertTrue($result->getData()['success']);

    }//end testLoadReturnsConfigurationResult()

    /**
     * index() translates a thrown error instead of letting it escape.
     *
     * The catch block exists because an uncaught throwable becomes a framework
     * 500 with a stack trace — and index() is #[NoAdminRequired], so that trace
     * would reach a NON-ADMIN. A catch nobody exercises is indistinguishable
     * from no catch at all, which is why this asserts the translated response
     * rather than merely that the method survives.
     *
     * @return void
     */
    public function testIndexTranslatesAFailure(): void
    {
        $this->settingsService->expects($this->once())
            ->method('getSettings')
            ->willThrowException(new \RuntimeException('OpenRegister unreachable'));

        $result = $this->controller->index();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        self::assertArrayHasKey('error', $result->getData());
        // The internal message must NOT be echoed back — that is the leak the
        // translation exists to prevent.
        self::assertStringNotContainsString('unreachable', (string) $result->getData()['error']);

    }//end testIndexTranslatesAFailure()

    /**
     * create() reports a failed write as success:false, not as a 500 page.
     *
     * The caller already branches on `success`, so the failure stays legible to
     * the UI instead of being a status code it has to interpret.
     *
     * @return void
     */
    public function testCreateTranslatesAFailure(): void
    {
        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->willThrowException(new \RuntimeException('write refused'));

        $result = $this->controller->create();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        self::assertFalse($result->getData()['success']);

    }//end testCreateTranslatesAFailure()

    /**
     * load() says nothing was imported when the import throws.
     *
     * This is the method most likely to fail for an environmental reason —
     * OpenRegister absent, a malformed register.d fragment — and the one thing
     * the admin who clicked "reload" needs to know is that the configuration
     * was NOT changed.
     *
     * @return void
     */
    public function testLoadTranslatesAFailure(): void
    {
        $this->settingsService->expects($this->once())
            ->method('loadConfiguration')
            ->willThrowException(new \RuntimeException('fragment parse error'));

        $result = $this->controller->load();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        self::assertStringContainsString('Nothing was changed', (string) $result->getData()['error']);

    }//end testLoadTranslatesAFailure()
}//end class
