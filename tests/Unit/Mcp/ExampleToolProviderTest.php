<?php

/**
 * Unit tests for ExampleToolProvider.
 *
 * Verifies the example MCP tool provider's contract: the app id, the two tool
 * descriptors, and that invokeTool() never throws (returns a structured error
 * for unknown tools). Rename / extend this alongside ExampleToolProvider.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Mcp\ExampleToolProvider;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExampleToolProvider.
 */
class ExampleToolProviderTest extends TestCase
{

    /**
     * The user session mock.
     *
     * @var IUserSession&MockObject
     */
    private $userSession;

    /**
     * The group manager mock.
     *
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * The app manager mock.
     *
     * @var IAppManager&MockObject
     */
    private $appManager;

    /**
     * The provider under test.
     *
     * @var ExampleToolProvider
     */
    private ExampleToolProvider $provider;

    /**
     * Set up the provider with mocked dependencies.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->appManager   = $this->createMock(IAppManager::class);
        $this->provider     = new ExampleToolProvider(
            $this->userSession,
            $this->groupManager,
            $this->appManager
        );

    }//end setUp()

    /**
     * getAppId() returns the app id.
     *
     * @return void
     */
    public function testGetAppIdReturnsAppId(): void
    {
        $this->assertSame(Application::APP_ID, $this->provider->getAppId());

    }//end testGetAppIdReturnsAppId()

    /**
     * getTools() returns two well-formed, app-id-prefixed descriptors.
     *
     * @return void
     */
    public function testGetToolsReturnsTwoValidDescriptors(): void
    {
        $tools = $this->provider->getTools();

        $this->assertCount(2, $tools);

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('id', $tool);
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);

            $this->assertStringStartsWith(Application::APP_ID.'.', (string) $tool['id']);
            $this->assertNotEmpty($tool['description']);

            $this->assertIsArray($tool['inputSchema']);
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertArrayHasKey('properties', $tool['inputSchema']);
        }

    }//end testGetToolsReturnsTwoValidDescriptors()

    /**
     * The ping tool echoes the message back without requiring auth.
     *
     * @return void
     */
    public function testInvokePingEchoesMessage(): void
    {
        $result = $this->provider->invokeTool(Application::APP_ID.'.ping', ['message' => 'hi']);

        $this->assertSame(['ok' => true, 'echo' => 'hi'], $result);

    }//end testInvokePingEchoesMessage()

    /**
     * The ping tool returns echo=null when no message is given.
     *
     * @return void
     */
    public function testInvokePingWithoutMessage(): void
    {
        $result = $this->provider->invokeTool(Application::APP_ID.'.ping', []);

        $this->assertSame(['ok' => true, 'echo' => null], $result);

    }//end testInvokePingWithoutMessage()

    /**
     * describeApp returns an auth error when no user is signed in.
     *
     * @return void
     */
    public function testInvokeDescribeAppWithoutUserReturnsError(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->provider->invokeTool(Application::APP_ID.'.describeApp', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('not_authenticated', $result['error']['code']);

    }//end testInvokeDescribeAppWithoutUserReturnsError()

    /**
     * describeApp returns the app id, version, and name for a signed-in user.
     *
     * @return void
     */
    public function testInvokeDescribeAppReturnsAppInfo(): void
    {
        $this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
        $this->appManager->method('getAppInfo')->with(Application::APP_ID)->willReturn(
            ['version' => '1.2.3', 'name' => 'Hermiq']
        );

        $result = $this->provider->invokeTool(Application::APP_ID.'.describeApp', []);

        $this->assertSame(
            ['id' => Application::APP_ID, 'version' => '1.2.3', 'name' => 'Hermiq'],
            $result
        );

    }//end testInvokeDescribeAppReturnsAppInfo()

    /**
     * An unknown tool id returns a structured error and does not throw.
     *
     * @return void
     */
    public function testInvokeUnknownToolReturnsErrorAndDoesNotThrow(): void
    {
        $result = $this->provider->invokeTool(Application::APP_ID.'.bogus', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('unknown_tool', $result['error']['code']);
        $this->assertStringContainsString(Application::APP_ID.'.bogus', $result['error']['message']);

    }//end testInvokeUnknownToolReturnsErrorAndDoesNotThrow()
}//end class
