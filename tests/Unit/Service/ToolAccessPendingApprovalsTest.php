<?php

/**
 * Characterisation tests for ToolAccessRequestService::pendingApprovals().
 *
 * Pins the exact read, the exact shape of every pending approval, which records are
 * skipped, and that a failure part-way keeps what was already collected, so the
 * method can be split into named steps without changing what the chat is shown.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use JsonSerializable;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Characterisation tests for the pending approvals read.
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
 */
class ToolAccessPendingApprovalsTest extends TestCase {

	/**
	 * A service whose OpenRegister read returns the given rows.
	 *
	 * @param array<int, mixed> $rows The rows findAll() returns.
	 * @param array<int, mixed> $reads Captures every findAll() argument list.
	 * @param LoggerInterface|null $logger An optional logger mock.
	 *
	 * @return ToolAccessRequestService
	 */
	private function service(array $rows, array &$reads, ?LoggerInterface $logger = null): ToolAccessRequestService {
		$objectService = new class($rows, $reads) {
			/**
			 * @param array<int, mixed> $rows The rows.
			 * @param array<int, mixed> $reads The captured reads.
			 */
			public function __construct(private readonly array $rows, private array &$reads) {
			}

			/**
			 * @param mixed ...$args Positional.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(...$args): array {
				$this->reads[] = $args;
				return $this->rows;
			}
		};
		$appManager = $this->createMock(\OCP\App\IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $objectService,
				\OCP\App\IAppManager::class => $appManager,
			}
		);

		return new ToolAccessRequestService(
			$container,
			new ToolGrantResolver(),
			$this->createMock(INotificationManager::class),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end service()

	/**
	 * Every pending request becomes one approval with this exact shape; records
	 * without an id or a tool are skipped, and an id may come from `@self`.
	 *
	 * @return void
	 */
	public function testEveryPendingRequestHasThisShape(): void {
		$reads = [];
		$serialised = new class implements JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'req/2'], 'toolId' => 'get_object', 'reach' => 'write'];
			}
		};
		$service = $this->service(
			[
				['id' => 'req-1', 'toolId' => 'hermiq.sendMail', 'reason' => 'to email'],
				$serialised,
				['id' => '', 'toolId' => 'hermiq.x'],
				['id' => 'req-4'],
				'not-a-record',
			],
			$reads
		);

		$approvals = $service->pendingApprovals('agent 1');

		$this->assertSame(
			[
				[
					'id' => 'req-1',
					'kind' => 'tool-access',
					'title' => 'hermiq.sendMail',
					'toolId' => 'hermiq.sendMail',
					'app' => 'hermiq',
					'reach' => 'read',
					'reason' => 'to email',
					'agentId' => 'agent 1',
					'resolveUrl' => '/index.php/apps/hermiq/api/agents/agent%201/tool-access-requests/req-1?toolId=hermiq.sendMail',
				],
				[
					'id' => 'req/2',
					'kind' => 'tool-access',
					'title' => 'get_object',
					'toolId' => 'get_object',
					'app' => 'openregister',
					'reach' => 'write',
					'reason' => '',
					'agentId' => 'agent 1',
					'resolveUrl' => '/index.php/apps/hermiq/api/agents/agent%201/tool-access-requests/req%2F2?toolId=get_object',
				],
			],
			$approvals
		);
		$this->assertSame(
			[[['filters' => ['register' => 'hermiq', 'schema' => 'ToolAccessRequest', 'agentId' => 'agent 1', 'status' => 'pending']]]],
			$reads
		);

	}//end testEveryPendingRequestHasThisShape()

	/**
	 * A failure part-way keeps the approvals already collected and logs the failure.
	 *
	 * @return void
	 */
	public function testAFailurePartWayKeepsWhatWasCollected(): void {
		$reads = [];
		$broken = new class implements JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				throw new RuntimeException('bad row');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('[ToolAccessRequestService] pending approvals lookup failed: bad row');
		$service = $this->service(
			[
				['id' => 'req-1', 'toolId' => 'hermiq.sendMail'],
				$broken,
				['id' => 'req-3', 'toolId' => 'hermiq.readFile'],
			],
			$reads,
			$logger
		);

		$approvals = $service->pendingApprovals('agent-1');

		$this->assertSame(['req-1'], array_column($approvals, 'id'));

	}//end testAFailurePartWayKeepsWhatWasCollected()
}//end class
