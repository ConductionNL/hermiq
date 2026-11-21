<?php

/**
 * HermiqToolProvider routes a custom tool id to its invoker.
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
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-validates-its-input-and-calls-only-through-the-flow-tool-or-integriq-req-custool-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Kernel\Contracts\ImageGenerationServiceInterface;
use OCA\Hermiq\Kernel\Contracts\TaskWriteServiceInterface;
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
use OCA\Hermiq\Service\CustomTool\CustomToolInvoker;
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
 * A `hermiq.custom_<slug>` call reaches CustomToolInvoker, and never throws.
 *
 * @covers \OCA\Hermiq\Mcp\HermiqToolProvider
 */
class HermiqToolProviderCustomToolTest extends TestCase {

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
	 * A custom tool id is handed to the invoker with the arguments as sent.
	 *
	 * @return void
	 */
	public function testACustomToolIdReachesTheInvoker(): void {
		$invoker = $this->createMock(CustomToolInvoker::class);
		$invoker->expects($this->once())
			->method('invoke')
			->with('hermiq.custom_vergunningcheck', ['adres' => 'Markt 3', 'triggeredBy' => 'alice'])
			->willReturn(['result' => ['runUuid' => 'run-1']]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $id === CustomToolInvoker::class ? $invoker : throw new RuntimeException('not registered: ' . $id)
		);

		$answer = $this->provider($container)->invokeTool(toolId: 'hermiq.custom_vergunningcheck', arguments: ['adres' => 'Markt 3', 'triggeredBy' => 'alice']);

		$this->assertSame(['result' => ['runUuid' => 'run-1']], $answer);
	}//end testACustomToolIdReachesTheInvoker()

	/**
	 * Without an invoker the call fails as a tool failure, not an exception.
	 *
	 * @return void
	 */
	public function testAnUnavailableInvokerIsAToolFailure(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		$answer = $this->provider($container)->invokeTool(toolId: 'hermiq.custom_vergunningcheck', arguments: []);

		$this->assertSame('tool_failed', $answer['error']['code']);
	}//end testAnUnavailableInvokerIsAToolFailure()
}//end class
