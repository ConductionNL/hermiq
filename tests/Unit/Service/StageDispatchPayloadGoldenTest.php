<?php

/**
 * Golden tests for the stage dispatch transport, sync and async.
 *
 * Pins, through the public `dispatch()` and `dispatchAsync()`, the exact payload
 * each sends, the exact AppAPI call (ExApp, route, user, method, timeout), the order
 * in which broker credentials are spent, and the logged and thrown outcome of an
 * unreachable ExApp and of a refused stage.
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

use OCA\Hermiq\Service\AsyncStageDispatchService;
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
 * A sync dispatcher whose broker seams record instead of calling OpenRegister.
 */
class RecordingStageDispatchService extends StageDispatchService {

	/**
	 * Every broker seam call, in order.
	 *
	 * @var array<int, string>
	 */
	public array $brokerCalls = [];

	/**
	 * The archive the tool fetch answers, or null.
	 *
	 * @var string|null
	 */
	public ?string $archive = null;

	/**
	 * Record the tool archive fetch.
	 *
	 * @param string $credentialId The broker credential.
	 * @param string|null $uid The acting user.
	 * @param string $repo The tool repository.
	 * @param string $ref The ref.
	 *
	 * @return string|null The configured archive.
	 */
	protected function fetchToolArchive(string $credentialId, ?string $uid, string $repo, string $ref): ?string {
		$this->brokerCalls[] = 'fetch ' . $credentialId . ' ' . (string)$uid . ' ' . $repo . ' ' . $ref;
		return $this->archive;
	}//end fetchToolArchive()

	/**
	 * Record the injection.
	 *
	 * @param string $credentialId The credential.
	 * @param string|null $uid The acting user.
	 *
	 * @return string|null A token named after the credential, or null for "proxy".
	 */
	protected function resolveForgeToken(string $credentialId, ?string $uid): ?string {
		$this->brokerCalls[] = 'inject ' . $credentialId . ' ' . (string)$uid;
		if ($credentialId === 'proxy') {
			return null;
		}

		return 'token-' . $credentialId;
	}//end resolveForgeToken()
}//end class

/**
 * An async dispatcher whose broker seams and runner call record.
 */
class RecordingAsyncStageDispatchService extends AsyncStageDispatchService {

	/**
	 * Every broker seam and runner call, in order.
	 *
	 * @var array<int, mixed>
	 */
	public array $calls = [];

	/**
	 * The canned runner response.
	 *
	 * @var mixed
	 */
	public mixed $nextResponse = null;

	/**
	 * Record the tool archive fetch.
	 *
	 * @param string $credentialId The broker credential.
	 * @param string|null $uid The acting user.
	 * @param string $repo The tool repository.
	 * @param string $ref The ref.
	 *
	 * @return string|null Always an archive.
	 */
	protected function fetchToolArchive(string $credentialId, ?string $uid, string $repo, string $ref): ?string {
		$this->calls[] = 'fetch ' . $credentialId . ' ' . (string)$uid . ' ' . $repo . ' ' . $ref;
		return 'ARCHIVE';
	}//end fetchToolArchive()

	/**
	 * Record the injection.
	 *
	 * @param string $credentialId The credential.
	 * @param string|null $uid The acting user.
	 *
	 * @return string|null A token named after the credential.
	 */
	protected function resolveForgeToken(string $credentialId, ?string $uid): ?string {
		$this->calls[] = 'inject ' . $credentialId . ' ' . (string)$uid;
		return 'token-' . $credentialId;
	}//end resolveForgeToken()

	/**
	 * Record the runner call.
	 *
	 * @param string $route The route.
	 * @param string $method The method.
	 * @param array $params The params.
	 * @param string|null $uid The user.
	 *
	 * @return mixed The canned response.
	 */
	protected function callRunner(string $route, string $method, array $params, ?string $uid): mixed {
		$this->calls[] = ['route' => $route, 'method' => $method, 'params' => $params, 'uid' => $uid];
		return $this->nextResponse;
	}//end callRunner()
}//end class

/**
 * @covers \OCA\Hermiq\Service\StageDispatchService
 * @covers \OCA\Hermiq\Service\AsyncStageDispatchService
 *
 * @uses \OCA\Hermiq\Service\Llm\RunTokenService
 */
class StageDispatchPayloadGoldenTest extends TestCase {

	/**
	 * Every AppAPI call, as its argument list.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $appApiCalls = [];

	/**
	 * Every logger warning, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $warnings = [];

	/**
	 * A runner response.
	 *
	 * @param int $status The HTTP status.
	 * @param string $body The body.
	 *
	 * @return object
	 */
	private function response(int $status, string $body): object {
		return new class($status, $body) {
			/**
			 * @param int $status The status.
			 * @param string $body The body.
			 */
			public function __construct(
				private int $status,
				private string $body,
			) {
			}

			public function getStatusCode(): int {
				return $this->status;
			}

			public function getBody(): string {
				return $this->body;
			}
		};
	}//end response()

	/**
	 * A container whose AppAPI records each call and answers the given result.
	 *
	 * @param mixed $answer What `exAppRequest()` returns.
	 *
	 * @return ContainerInterface
	 */
	private function container(mixed $answer): ContainerInterface {
		$appApi = new class($answer, $this->appApiCalls) {
			/**
			 * @param mixed $answer The answer.
			 * @param array $calls The call log, by reference.
			 */
			public function __construct(
				private mixed $answer,
				private array &$calls,
			) {
			}

			public function exAppRequest(mixed ...$args): mixed {
				$this->calls[] = $args;
				return $this->answer;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($appApi) {
				if ($id !== 'OCA\\AppAPI\\PublicFunctions') {
					throw new RuntimeException('unexpected ' . $id);
				}

				return $appApi;
			}
		);
		return $container;
	}//end container()

	/**
	 * A logger that records warnings.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
		return $logger;
	}//end logger()

	/**
	 * A real run-token service minting a fixed token.
	 *
	 * @return RunTokenService
	 */
	private function runTokens(): RunTokenService {
		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('run-token');
		return new RunTokenService(new InMemoryRunTokenStore(), $secureRandom, $this->createMock(ITimeFactory::class));
	}//end runTokens()

	/**
	 * A sync dispatcher over a recording AppAPI.
	 *
	 * @param mixed $answer What AppAPI answers.
	 *
	 * @return RecordingStageDispatchService
	 */
	private function sync(mixed $answer): RecordingStageDispatchService {
		return new RecordingStageDispatchService($this->logger(), $this->runTokens(), $this->container($answer));
	}//end sync()

	/**
	 * A stage with every field set sends every field, in order, with the
	 * credentials spent fetch first, then the push credential, then the model one.
	 *
	 * @return void
	 */
	public function testAFullStageSendsEveryFieldInOrder(): void {
		$service = $this->sync($this->response(200, (string)json_encode(['exitCode' => 3, 'output' => 'o', 'ref' => 'r'])));
		$service->archive = 'TARBALL';

		$result = $service->dispatch(
			repo: 'https://github.com/o/target',
			ref: 'main',
			command: ['a' => 'run', 'b' => 'it'],
			uid: 'alice',
			credentialId: 'broker',
			timeoutMs: 125000,
			toolRepo: 'https://github.com/o/tools',
			toolRef: 'v2',
			push: ['branch' => 'b'],
			pushCredentialId: 'pusher',
			llmCredentialId: 'model',
			collect: ['report.json'],
		);

		$this->assertSame(['exitCode' => 3, 'output' => 'o', 'ref' => 'r'], $result);
		$this->assertSame(
			['fetch broker alice https://github.com/o/tools v2', 'inject pusher alice', 'inject model alice'],
			$service->brokerCalls
		);
		$this->assertCount(1, $this->appApiCalls);
		$this->assertSame(
			[
				'hermiq-llm-runner',
				'/stage',
				'alice',
				'POST',
				[
					'repo' => 'https://github.com/o/target',
					'ref' => 'main',
					'command' => ['run', 'it'],
					'timeoutMs' => 125000,
					'runToken' => 'run-token',
					'push' => ['branch' => 'b'],
					'collect' => ['report.json'],
					'toolRepo' => 'https://github.com/o/tools',
					'toolTarball' => 'TARBALL',
					'forgeToken' => 'token-pusher',
					'forgeUser' => 'x-access-token',
					'credentialEnv' => ['CLAUDE_CODE_OAUTH_TOKEN' => 'token-model'],
				],
				['timeout' => 185],
			],
			$this->appApiCalls[0]
		);
		$this->assertSame([], $this->warnings);
	}//end testAFullStageSendsEveryFieldInOrder()

	/**
	 * Defaults: the 30-minute ceiling, no credentials and no broker calls; a tool
	 * tree without a credential is cloned with its ref.
	 *
	 * @return void
	 */
	public function testADefaultStageUsesTheDefaultCeilingAndNoCredentials(): void {
		$service = $this->sync($this->response(299, (string)json_encode(['exitCode' => 0])));

		$service->dispatch(repo: 'r', ref: 'f', command: ['x'], toolRepo: 't', toolRef: 'tr');

		$this->assertSame([], $service->brokerCalls);
		$this->assertSame(
			[
				'hermiq-llm-runner',
				'/stage',
				null,
				'POST',
				[
					'repo' => 'r',
					'ref' => 'f',
					'command' => ['x'],
					'timeoutMs' => 1800000,
					'runToken' => 'run-token',
					'toolRepo' => 't',
					'toolRef' => 'tr',
				],
				['timeout' => 1860],
			],
			$this->appApiCalls[0]
		);
	}//end testADefaultStageUsesTheDefaultCeilingAndNoCredentials()

	/**
	 * A failed archive keeps the tool ref and an archive without ref asks for HEAD;
	 * a proxy credential that cannot inject sends no forge token.
	 *
	 * @return void
	 */
	public function testAFailedArchiveKeepsTheToolRefAndAProxyCredentialInjectsNothing(): void {
		$service = $this->sync($this->response(200, (string)json_encode(['exitCode' => 0])));

		$service->dispatch(repo: 'r', ref: 'f', command: ['x'], uid: 'bob', credentialId: 'proxy', toolRepo: 't');

		$this->assertSame(['fetch proxy bob t HEAD', 'inject proxy bob'], $service->brokerCalls);
		$this->assertSame(
			['repo' => 'r', 'ref' => 'f', 'command' => ['x'], 'timeoutMs' => 1800000, 'runToken' => 'run-token', 'toolRepo' => 't'],
			$this->appApiCalls[0][4]
		);
	}//end testAFailedArchiveKeepsTheToolRefAndAProxyCredentialInjectsNothing()

	/**
	 * An unreachable ExApp is logged with its reason and thrown.
	 *
	 * @return void
	 */
	public function testAnUnreachableExAppIsLoggedAndThrown(): void {
		$service = $this->sync(['error' => 'ExApp not found']);

		try {
			$service->dispatch(repo: 'r', ref: 'f', command: ['x']);
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame(
				'The workload could not reach the "hermiq-llm-runner" ExApp. Check that the ExApp is running.',
				$e->getMessage()
			);
		}

		$this->assertSame(
			[['[StageDispatchService] stage dispatch failed at the AppAPI transport', ['reason' => 'ExApp not found']]],
			$this->warnings
		);
	}//end testAnUnreachableExAppIsLoggedAndThrown()

	/**
	 * A refused stage is logged with its status and reason and thrown.
	 *
	 * @return void
	 */
	public function testARefusedStageIsLoggedAndThrown(): void {
		$service = $this->sync($this->response(502, (string)json_encode(['error' => 'clone failed'])));

		try {
			$service->dispatch(repo: 'r', ref: 'f', command: ['x']);
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame('The workload could not be run: clone failed', $e->getMessage());
		}

		$this->assertSame(
			[['[StageDispatchService] the runner refused or could not run the stage', ['status' => 502, 'reason' => 'clone failed']]],
			$this->warnings
		);
	}//end testARefusedStageIsLoggedAndThrown()

	/**
	 * An async stage with every field sends the same payload plus async and the job
	 * key, on the same route, and answers the handle.
	 *
	 * @return void
	 */
	public function testAFullAsyncStageSendsTheSamePayloadPlusAsyncAndTheKey(): void {
		$service = new RecordingAsyncStageDispatchService($this->logger(), $this->runTokens());
		$service->nextResponse = $this->response(202, (string)json_encode(['jobId' => 'j-1']));

		$handle = $service->dispatchAsync(
			repo: 'r',
			ref: 'f',
			command: ['x'],
			uid: 'carol',
			credentialId: 'broker',
			timeoutMs: 5000,
			toolRepo: 't',
			toolRef: '',
			push: ['branch' => 'b'],
			pushCredentialId: '',
			llmCredentialId: 'model',
			jobKey: ' key-1 ',
			collect: ['c'],
		);

		$this->assertSame(['job' => ['id' => 'j-1', 'status' => 'running']], $handle);
		$this->assertSame(
			[
				'fetch broker carol t HEAD',
				'inject broker carol',
				'inject model carol',
				[
					'route' => '/stage',
					'method' => 'POST',
					'params' => [
						'repo' => 'r',
						'ref' => 'f',
						'command' => ['x'],
						'timeoutMs' => 5000,
						'runToken' => 'run-token',
						'push' => ['branch' => 'b'],
						'collect' => ['c'],
						'toolRepo' => 't',
						'toolTarball' => 'ARCHIVE',
						'forgeToken' => 'token-broker',
						'forgeUser' => 'x-access-token',
						'credentialEnv' => ['CLAUDE_CODE_OAUTH_TOKEN' => 'token-model'],
						'async' => true,
						'jobKey' => 'key-1',
					],
					'uid' => 'carol',
				],
			],
			$service->calls
		);
	}//end testAFullAsyncStageSendsTheSamePayloadPlusAsyncAndTheKey()

	/**
	 * An unreachable runner refuses an async start without logging.
	 *
	 * @return void
	 */
	public function testAnUnreachableRunnerRefusesAnAsyncStart(): void {
		$service = new RecordingAsyncStageDispatchService($this->logger(), $this->runTokens());
		$service->nextResponse = ['error' => 'down'];

		try {
			$service->dispatchAsync(repo: 'r', ref: 'f', command: ['x']);
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame(
				'The workload could not reach the "hermiq-llm-runner" ExApp. Check that the ExApp is running.',
				$e->getMessage()
			);
		}

		$this->assertSame([], $this->warnings);
		$this->assertSame(1800000, $service->calls[0]['params']['timeoutMs']);
	}//end testAnUnreachableRunnerRefusesAnAsyncStart()
}//end class
