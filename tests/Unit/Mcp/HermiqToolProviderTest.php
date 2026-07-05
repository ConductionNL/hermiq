<?php

/**
 * Unit tests for HermiqToolProvider (nc-native-tools).
 *
 * Covers the tool catalogue (six namespaced hermiq.* descriptors) and the never-throws
 * contract: invokeTool returns a structured error for an unauthenticated caller and for an
 * unknown tool id.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the nc-native-tools HermiqToolProvider.
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#task-4-1
 */
class HermiqToolProviderTest extends TestCase
{

    /**
     * Build the provider with a session that resolves to $uid (or null for anonymous).
     *
     * @param string|null $uid The acting user id, or null for unauthenticated.
     *
     * @return HermiqToolProvider
     */
    private function provider(?string $uid): HermiqToolProvider
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $session->method('getUser')->willReturn($user);
        }

        return new HermiqToolProvider(
            $session,
            $this->createMock(IGroupManager::class),
            $this->createMock(IRootFolder::class),
            $this->createMock(IContactsManager::class),
            $this->createMock(ICalendarManager::class),
            $this->createMock(IMailer::class),
            $this->createMock(IAppManager::class),
            $this->createMock(ContainerInterface::class),
            $this->createMock(LoggerInterface::class)
        );

    }//end provider()

    /**
     * getAppId is the hermiq app slug and every tool id is namespaced by it.
     *
     * @return void
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-1
     */
    public function testToolCatalogueIsNamespaced(): void
    {
        $provider = $this->provider('alice');

        $this->assertSame('hermiq', $provider->getAppId());

        $tools = $provider->getTools();
        $this->assertCount(6, $tools);

        $ids = array_column($tools, 'id');
        $this->assertContains('hermiq.listFiles', $ids);
        $this->assertContains('hermiq.readFile', $ids);
        $this->assertContains('hermiq.searchContacts', $ids);
        $this->assertContains('hermiq.listCalendarEvents', $ids);
        $this->assertContains('hermiq.sendMail', $ids);
        $this->assertContains('hermiq.listDeckBoards', $ids);

        foreach ($ids as $id) {
            $this->assertStringStartsWith('hermiq.', $id);
        }

    }//end testToolCatalogueIsNamespaced()

    /**
     * An unauthenticated caller gets a structured error, never an exception.
     *
     * @return void
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-7
     */
    public function testUnauthenticatedReturnsError(): void
    {
        $result = $this->provider(null)->invokeTool('hermiq.listFiles', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('unauthenticated', $result['error']['code']);

    }//end testUnauthenticatedReturnsError()

    /**
     * An unknown tool id returns a structured error, never an exception.
     *
     * @return void
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-7
     */
    public function testUnknownToolReturnsError(): void
    {
        $result = $this->provider('alice')->invokeTool('hermiq.doesNotExist', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('unknown_tool', $result['error']['code']);

    }//end testUnknownToolReturnsError()

    /**
     * sendMail with missing arguments returns an invalid_argument error (no throw).
     *
     * @return void
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-5
     */
    public function testSendMailWithoutArgumentsReturnsError(): void
    {
        $result = $this->provider('alice')->invokeTool('hermiq.sendMail', ['to' => '']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_argument', $result['error']['code']);

    }//end testSendMailWithoutArgumentsReturnsError()
}//end class
