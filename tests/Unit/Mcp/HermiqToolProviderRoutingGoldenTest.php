<?php

/**
 * Golden test for how HermiqToolProvider routes a tool call to the service that
 * owns it.
 *
 * Pins which service answers each routed tool family (graph, Nextcloud tasks,
 * images, and the read and write halves of the run workspace), with exactly which
 * acting user and arguments, and that any other tool falls through to the native
 * tools, so the routing can be rewritten without sending a call to the wrong owner.
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

use OCA\Hermiq\Kernel\Contracts\GraphToolsInterface;
use OCA\Hermiq\Kernel\Contracts\ImageGenerationServiceInterface;
use OCA\Hermiq\Kernel\Contracts\TaskWriteServiceInterface;
use OCA\Hermiq\Mcp\GraphToolDescriptors;
use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Mcp\ImageToolDescriptors;
use OCA\Hermiq\Mcp\NcTaskToolDescriptors;
use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\DelegationService;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCA\Hermiq\Service\Workspace\WorkspaceToolset;
use OCA\Hermiq\Service\Workspace\WorkspaceWrites;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the tool routing.
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#task-4-1
 */
class HermiqToolProviderRoutingGoldenTest extends TestCase {

	/**
	 * Every routed call, as [service, arguments it received].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * A recording stand-in for one routed service.
	 *
	 * @param string $service The service class it stands in for.
	 *
	 * @return object
	 */
	private function recorder(string $service): object {
		$calls = &$this->calls;
		return new class($service, $calls) {
			/**
			 * @param string $service The service class.
			 * @param array<int, mixed> $calls The shared call log.
			 */
			public function __construct(private readonly string $service, private array &$calls) {
			}

			/**
			 * @param mixed ...$args Named arguments.
			 *
			 * @return array<string, mixed>
			 */
			public function invoke(mixed ...$args): array {
				$this->calls[] = [$this->service, $args];
				return ['routedTo' => $this->service];
			}
		};
	}//end recorder()

	/**
	 * The provider, acting as alice, over a container of recording services.
	 *
	 * @return HermiqToolProvider
	 */
	private function provider(): HermiqToolProvider {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $this->recorder($id));

		return new HermiqToolProvider(
			$session,
			$this->createMock(IRootFolder::class),
			$this->createMock(IContactsManager::class),
			$this->createMock(ICalendarManager::class),
			$this->createMock(IMailer::class),
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(CourseRecommendationEngine::class),
			$this->createMock(MemoryService::class),
			$this->createMock(WebSearchClient::class),
			$this->createMock(WebFetchService::class),
			$this->createMock(DelegationService::class),
			$this->createMock(NcNativeWriteService::class),
			$this->createMock(MailReadService::class),
			$this->createMock(ToolAccessRequestService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end provider()

	/**
	 * Each routed family reaches its own service: graph, task and image tools with the
	 * acting user, workspace reads and writes with the arguments only; any other tool
	 * is not routed.
	 *
	 * @return void
	 */
	public function testEachToolFamilyReachesItsOwnService(): void {
		$provider = $this->provider();
		$arguments = ['q' => 1];
		$cases = [
			[GraphToolDescriptors::NEIGHBORS, GraphToolsInterface::class],
			[NcTaskToolDescriptors::LIST_TASKS, TaskWriteServiceInterface::class],
			[ImageToolDescriptors::GENERATE_IMAGE, ImageGenerationServiceInterface::class],
			[WorkspaceToolDescriptors::OPEN, WorkspaceToolset::class],
			[WorkspaceToolDescriptors::WRITE_FILE, WorkspaceWrites::class],
		];
		foreach ($cases as [$toolId, $service]) {
			$this->assertSame(['routedTo' => $service], $provider->invokeTool($toolId, $arguments), $toolId);
		}

		$this->assertSame(
			[
				[GraphToolsInterface::class, ['uid' => 'alice', 'toolId' => GraphToolDescriptors::NEIGHBORS, 'arguments' => $arguments]],
				[TaskWriteServiceInterface::class, ['uid' => 'alice', 'toolId' => NcTaskToolDescriptors::LIST_TASKS, 'arguments' => $arguments]],
				[ImageGenerationServiceInterface::class, ['uid' => 'alice', 'toolId' => ImageToolDescriptors::GENERATE_IMAGE, 'arguments' => $arguments]],
				[WorkspaceToolset::class, ['toolId' => WorkspaceToolDescriptors::OPEN, 'arguments' => $arguments]],
				[WorkspaceWrites::class, ['toolId' => WorkspaceToolDescriptors::WRITE_FILE, 'arguments' => $arguments]],
			],
			$this->calls
		);

		$provider->invokeTool('hermiq.noSuchTool', $arguments);
		$this->assertCount(5, $this->calls);

	}//end testEachToolFamilyReachesItsOwnService()
}//end class
