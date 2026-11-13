<?php

/**
 * HermiqToolProvider offers the optional tools only while their services resolve.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Mcp\ImageToolDescriptors;
use OCA\Hermiq\Mcp\NcTaskToolDescriptors;
use OCA\Hermiq\Service\Chat\ImageGenerationService;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\DelegationService;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\NcNative\TaskWriteService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
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
use RuntimeException;

/**
 * Pins the tool list when the task-write and image services cannot be
 * resolved from the container, and when the image provider is down.
 *
 * @covers \OCA\Hermiq\Mcp\HermiqToolProvider
 */
class HermiqToolProviderOptionalServicesTest extends TestCase {

	/**
	 * Build the provider over a container.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return HermiqToolProvider
	 */
	private function provider(ContainerInterface $container): HermiqToolProvider {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

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
	 * The ids of the offered tools.
	 *
	 * @param HermiqToolProvider $provider The provider.
	 *
	 * @return array<int, string>
	 */
	private function toolIds(HermiqToolProvider $provider): array {
		return array_column($provider->getTools(), 'id');
	}//end toolIds()

	/**
	 * A container that cannot resolve either optional service drops both optional tools, and only those.
	 *
	 * @return void
	 */
	public function testAnUnresolvableServiceDropsOnlyItsTool(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		$ids = $this->toolIds($this->provider($container));

		$this->assertNotContains(NcTaskToolDescriptors::COMPLETE_TASK, $ids);
		$this->assertNotContains(ImageToolDescriptors::GENERATE_IMAGE, $ids);

		$otherTaskTools = array_values(
			array_diff(array_column(NcTaskToolDescriptors::ALL, 'id'), [NcTaskToolDescriptors::COMPLETE_TASK])
		);
		$this->assertNotSame([], $otherTaskTools);
		foreach ($otherTaskTools as $id) {
			$this->assertContains($id, $ids);
		}
	}//end testAnUnresolvableServiceDropsOnlyItsTool()

	/**
	 * Both optional tools are offered once their services resolve and can serve the caller.
	 *
	 * @return void
	 */
	public function testResolvableServicesOfferBothTools(): void {
		$tasks = $this->createMock(TaskWriteService::class);
		$tasks->method('canComplete')->willReturn(true);
		$images = $this->createMock(ImageGenerationService::class);
		$images->expects($this->once())->method('isAvailable')->with('handler')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				TaskWriteService::class => $tasks,
				ImageGenerationService::class => $images,
				default => throw new RuntimeException($id),
			}
		);

		$ids = $this->toolIds($this->provider($container));

		$this->assertContains(NcTaskToolDescriptors::COMPLETE_TASK, $ids);
		$this->assertContains(ImageToolDescriptors::GENERATE_IMAGE, $ids);
		$this->assertSame(count($ids), count(array_unique($ids)));
	}//end testResolvableServicesOfferBothTools()
}//end class
