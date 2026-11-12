<?php

/**
 * The stage dispatcher's tool-archive fetch, through a recording broker.
 *
 * Pins `fetchToolArchive()` before it is split into named steps: which repo
 * URLs it refuses to fetch for, the exact broker request it makes, the archive
 * it hands back, and the two warnings it logs when the broker throws or the
 * forge answers without an archive. Every refusal is a null, never a throw.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\StageDispatchService;
use OCA\Hermiq\Tests\Unit\Support\InMemoryRunTokenStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Exposes the real tool-archive fetch.
 */
class ToolArchiveStageDispatchService extends StageDispatchService {

	/**
	 * The real fetchToolArchive(), made callable.
	 *
	 * @param array<int, mixed> $args credentialId, uid, repo, ref.
	 *
	 * @return string|null The base64 archive, or null.
	 */
	public function fetch(array $args): ?string {
		return $this->fetchToolArchive($args[0], $args[1], $args[2], $args[3]);
	}//end fetch()
}//end class

/**
 * Golden outcomes of StageDispatchService::fetchToolArchive().
 */
class StageDispatchToolArchiveGoldenTest extends TestCase {

	/**
	 * Every broker request made, as its argument list.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $requests = [];

	/**
	 * The service over a broker that answers $answer (or throws it).
	 *
	 * @param array<string, mixed>|\Throwable $answer The broker's answer.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return ToolArchiveStageDispatchService
	 */
	private function service(array|\Throwable $answer, LoggerInterface $logger): ToolArchiveStageDispatchService {
		$this->requests = [];
		$test = $this;
		$broker = new class($answer, $test) {
			/**
			 * @param array<string, mixed>|\Throwable $answer The answer.
			 * @param StageDispatchToolArchiveGoldenTest $test The recording test.
			 */
			public function __construct(
				private array|\Throwable $answer,
				private StageDispatchToolArchiveGoldenTest $test,
			) {
			}

			/**
			 * Record the request and answer it.
			 *
			 * @param mixed ...$args The request arguments.
			 *
			 * @return array<string, mixed> The answer.
			 */
			public function request(...$args): array {
				$this->test->requests[] = $args;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BrokerHttpClient::BROKER_CLASS)->willReturn($broker);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('stub-run-token');

		return new ToolArchiveStageDispatchService(
			$logger,
			new RunTokenService(new InMemoryRunTokenStore(), $secureRandom, $this->createMock(ITimeFactory::class)),
			$container
		);
	}//end service()

	/**
	 * A repo URL that is not exactly `owner/name` is never fetched for.
	 *
	 * @return void
	 */
	public function testARepoThatIsNotOwnerSlashNameIsNotFetched(): void {
		foreach (['', 'https://github.com/', 'https://github.com/owner', 'https://github.com/a/b/c', 'https://github.com/.git', 'not a url'] as $repo) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->never())->method('warning');
			$service = $this->service(['status' => 200, 'body' => 'x'], $logger);
			$this->assertNull($service->fetch(['cred', 'alice', $repo, 'main']), $repo);
			$this->assertSame([], $this->requests, $repo);
		}
	}//end testARepoThatIsNotOwnerSlashNameIsNotFetched()

	/**
	 * The broker is asked for exactly the tarball of owner/name at ref, and the body comes back base64.
	 *
	 * @return void
	 */
	public function testTheTarballIsFetchedThroughTheBroker(): void {
		$cases = [
			['https://github.com/Owner/tool.git', 'Owner/tool'],
			['https://github.com/owner/tool/', 'owner/tool'],
			['https://github.com/owner/tool', 'owner/tool'],
		];
		foreach ($cases as [$repo, $path]) {
			$service = $this->service(['status' => 200, 'body' => "\x1f\x8bTAR"], $this->createMock(LoggerInterface::class));
			$this->assertSame(base64_encode("\x1f\x8bTAR"), $service->fetch(['cred-9', null, $repo, 'v1.2']));
			$this->assertSame([['cred-9', 'hermiq', 'GET', '/repos/' . $path . '/tarball/v1.2', [], null, null]], $this->requests);
		}
	}//end testTheTarballIsFetchedThroughTheBroker()

	/**
	 * A broker that throws is a warning with its reason and a null.
	 *
	 * @return void
	 */
	public function testABrokerFailureIsANull(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[StageDispatchService] the tool archive could not be fetched through the broker',
			['reason' => 'Request not permitted']
		);
		$service = $this->service(new RuntimeException('Request not permitted'), $logger);
		$this->assertNull($service->fetch(['cred', 'alice', 'https://github.com/o/n', 'main']));
	}//end testABrokerFailureIsANull()

	/**
	 * A non-2xx status or an empty body is a warning with the status and a null.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutAnArchiveIsANull(): void {
		$answers = [
			[['status' => 404, 'body' => 'nope'], 404],
			[['status' => 199, 'body' => 'x'], 199],
			[['status' => 300, 'body' => 'x'], 300],
			[['status' => 200, 'body' => ''], 200],
			[['status' => '204'], 204],
			[[], 0],
		];
		foreach ($answers as [$answer, $status]) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('warning')->with(
				'[StageDispatchService] the forge did not return a tool archive',
				['status' => $status]
			);
			$service = $this->service($answer, $logger);
			$this->assertNull($service->fetch(['cred', 'alice', 'https://github.com/o/n', 'main']), (string)$status);
		}

		$service = $this->service(['status' => '299', 'body' => 'ok'], $this->createMock(LoggerInterface::class));
		$this->assertSame(base64_encode('ok'), $service->fetch(['cred', 'alice', 'https://github.com/o/n', 'main']));
	}//end testAnAnswerWithoutAnArchiveIsANull()
}//end class
