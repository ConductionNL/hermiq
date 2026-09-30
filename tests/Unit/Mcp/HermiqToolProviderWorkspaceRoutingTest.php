<?php

/**
 * The provider routes every write-shaped workspace tool to WorkspaceWrites and
 * every other workspace tool to WorkspaceToolset: the one governed dispatch
 * path, asserted from the caller.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Mcp
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

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\HermiqToolProvider;
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
use OCP\Calendar\IManager as ICalendarManager;
use OCP\App\IAppManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-workspace-tools-dispatch-through-the-single-governed-path
 */
final class HermiqToolProviderWorkspaceRoutingTest extends TestCase {

	/**
	 * Write-shaped ids reach the write half; read ids reach the read half.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-workspace-tools-dispatch-through-the-single-governed-path
	 */
	public function testWriteToolsReachTheWriteHalfAndReadToolsTheReadHalf(): void {
		$calls = [];
		$writes = $this->createMock(WorkspaceWrites::class);
		$writes->method('invoke')->willReturnCallback(
			function (string $toolId) use (&$calls): array {
				$calls[] = 'writes:' . $toolId;
				return ['ok' => true];
			}
		);
		$reads = $this->createMock(WorkspaceToolset::class);
		$reads->method('invoke')->willReturnCallback(
			function (string $toolId) use (&$calls): array {
				$calls[] = 'reads:' . $toolId;
				return ['ok' => true];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === WorkspaceWrites::class ? $writes : $reads)
		);

		$provider = $this->provider(container: $container);
		foreach (WorkspaceToolDescriptors::IDS as $id) {
			$provider->invokeTool(toolId: $id, arguments: []);
		}

		$expected = [];
		foreach (WorkspaceToolDescriptors::IDS as $id) {
			$half = 'reads:';
			if (in_array($id, WorkspaceToolDescriptors::WRITE_IDS, true) === true) {
				$half = 'writes:';
			}

			$expected[] = $half . $id;
		}

		self::assertSame($expected, $calls);
		self::assertContains('writes:' . WorkspaceToolDescriptors::COMMIT, $calls);
	}//end testWriteToolsReachTheWriteHalfAndReadToolsTheReadHalf()

	/**
	 * The provider, signed in as alice, over this container.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return HermiqToolProvider
	 */
	private function provider(ContainerInterface $container): HermiqToolProvider {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
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
}//end class
