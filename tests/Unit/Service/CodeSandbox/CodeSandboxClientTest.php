<?php

/**
 * Unit tests for CodeSandboxClient: dispatch to the sandbox ExApp through AppAPI.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\CodeSandbox
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CodeSandbox;

use InvalidArgumentException;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxSettings;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for CodeSandboxClient.
 */
class CodeSandboxClientTest extends TestCase {

	/**
	 * Settings that read the given stored JSON.
	 *
	 * @param string $stored The stored choice.
	 *
	 * @return CodeSandboxSettings
	 */
	private function settings(string $stored=''): CodeSandboxSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($stored);

		return new CodeSandboxSettings(appConfig: $config);
	}//end settings()

	/**
	 * A fake AppAPI that records each request and answers with the given result.
	 *
	 * @param mixed $answer The exAppRequest() answer: an array failure or a response.
	 *
	 * @return object
	 */
	private function appApi(mixed $answer): object {
		return new class($answer) {
			/**
			 * The recorded requests.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $answer The answer.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * Record and answer.
			 *
			 * @param mixed ...$args The request.
			 *
			 * @return mixed
			 */
			public function exAppRequest(mixed ...$args): mixed {
				$this->calls[] = $args;
				return $this->answer;
			}
		};
	}//end appApi()

	/**
	 * A response with a status and a body.
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 *
	 * @return object
	 */
	private function response(int $status, string $body): object {
		return new class($status, $body) {
			/**
			 * Constructor.
			 *
			 * @param int    $status The status.
			 * @param string $body   The body.
			 */
			public function __construct(private int $status, private string $body) {
			}

			/**
			 * The status.
			 *
			 * @return int
			 */
			public function getStatusCode(): int {
				return $this->status;
			}

			/**
			 * The body.
			 *
			 * @return string
			 */
			public function getBody(): string {
				return $this->body;
			}
		};
	}//end response()

	/**
	 * A client over a container that serves the given AppAPI, or none.
	 *
	 * @param object|null         $appApi   The AppAPI fake, or null for AppAPI absent.
	 * @param CodeSandboxSettings $settings The settings.
	 *
	 * @return CodeSandboxClient
	 */
	private function client(?object $appApi, ?CodeSandboxSettings $settings=null): CodeSandboxClient {
		$container = $this->createMock(ContainerInterface::class);
		if ($appApi === null) {
			$container->method('get')->willThrowException(new RuntimeException('not installed'));
		} else {
			$container->method('get')->with(CodeSandboxClient::APP_API_PUBLIC_FUNCTIONS)->willReturn($appApi);
		}

		return new CodeSandboxClient(
			settings: ($settings ?? $this->settings()),
			logger: $this->createMock(LoggerInterface::class),
			container: $container
		);
	}//end client()

	/**
	 * A run goes to the sandbox ExApp's /run with the contract, clamped limits and a matching timeout.
	 *
	 * @return void
	 */
	public function testARunGoesToTheSandboxExAppWithTheContract(): void {
		$appApi = $this->appApi(
			answer: $this->response(
				status: 200,
				body: '{"exitCode":0,"stdout":"2\n","stderr":"","durationMs":41,"timedOut":false,"files":[]}'
			)
		);

		$result = $this->client(appApi: $appApi)->run(
			language: 'python',
			code: 'print(1 + 1)',
			files: [['name' => 'in.csv', 'content' => 'a,b']],
			limits: ['wallSeconds' => 900, 'memoryMb' => 256],
			uid: 'alice'
		);

		$this->assertSame(
			[
				'exitCode' => 0,
				'stdout' => "2\n",
				'stderr' => '',
				'durationMs' => 41,
				'timedOut' => false,
				'files' => [],
				'limits' => ['wallSeconds' => 300, 'memoryMb' => 256],
			],
			$result
		);
		$this->assertSame(
			[[
				'hermiq-code-sandbox',
				'/run',
				'alice',
				'POST',
				[
					'language' => 'python',
					'code' => 'print(1 + 1)',
					'stdin' => '',
					'files' => [['name' => 'in.csv', 'content' => 'a,b']],
					'limits' => ['wallSeconds' => 300, 'memoryMb' => 256],
				],
				['timeout' => 315],
			]],
			$appApi->calls
		);
	}//end testARunGoesToTheSandboxExAppWithTheContract()

	/**
	 * Absent or non-integer limits take the defaults; too-small ones are raised to 1.
	 *
	 * @return void
	 */
	public function testLimitsTakeDefaultsAndStayWithinBounds(): void {
		$this->assertSame(['wallSeconds' => 30, 'memoryMb' => 512], CodeSandboxClient::clampLimits(limits: []));
		$this->assertSame(['wallSeconds' => 30, 'memoryMb' => 2048], CodeSandboxClient::clampLimits(limits: ['wallSeconds' => '60', 'memoryMb' => 99999]));
		$this->assertSame(['wallSeconds' => 1, 'memoryMb' => 1], CodeSandboxClient::clampLimits(limits: ['wallSeconds' => -5, 'memoryMb' => 0]));
	}//end testLimitsTakeDefaultsAndStayWithinBounds()

	/**
	 * Without AppAPI nothing is sent and the run is refused with the stated message.
	 *
	 * @return void
	 */
	public function testWithoutAppApiTheRunIsRefusedAsNotInstalled(): void {
		$client = $this->client(appApi: null);

		$this->assertFalse($client->isAvailable());
		$this->expectException(CodeSandboxUnavailableException::class);
		$this->expectExceptionMessage('No code sandbox is installed on this instance.');
		$client->run(language: 'python', code: 'print(1)');
	}//end testWithoutAppApiTheRunIsRefusedAsNotInstalled()

	/**
	 * AppAPI's array failure channel means the ExApp is not reachable.
	 *
	 * @return void
	 */
	public function testAnAppApiFailureIsReportedAsNotInstalled(): void {
		$client = $this->client(appApi: $this->appApi(answer: ['error' => 'ExApp not found']));

		$this->assertTrue($client->isAvailable());
		$this->expectException(CodeSandboxUnavailableException::class);
		$this->expectExceptionMessage(CodeSandboxUnavailableException::NOT_INSTALLED);
		$client->run(language: 'node', code: 'console.log(1)');
	}//end testAnAppApiFailureIsReportedAsNotInstalled()

	/**
	 * A non-2xx answer and a malformed body are errors, not results.
	 *
	 * @return void
	 */
	public function testARefusalOrAMalformedAnswerIsAnError(): void {
		try {
			$this->client(appApi: $this->appApi(answer: $this->response(status: 502, body: 'bad gateway')))->run(language: 'python', code: 'x');
			$this->fail('A 502 came back as a result.');
		} catch (RuntimeException $e) {
			$this->assertNotInstanceOf(CodeSandboxUnavailableException::class, $e);
			$this->assertSame('The code sandbox could not run the code (HTTP 502).', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The code sandbox answered without a run result.');
		$this->client(appApi: $this->appApi(answer: $this->response(status: 200, body: '{"stdout":"x"}')))->run(language: 'python', code: 'x');
	}//end testARefusalOrAMalformedAnswerIsAnError()

	/**
	 * A hosted choice sends nothing through AppAPI and refuses the run.
	 *
	 * @return void
	 */
	public function testAHostedChoiceNeverSendsThroughAppApi(): void {
		$appApi = $this->appApi(answer: $this->response(status: 200, body: '{"exitCode":0}'));
		$client = $this->client(appApi: $appApi, settings: $this->settings(stored: '{"backend":"hosted","hostedSource":"hosted-sandbox"}'));

		$this->assertFalse($client->isAvailable());
		try {
			$client->run(language: 'python', code: 'print(1)');
			$this->fail('A hosted run was dispatched.');
		} catch (CodeSandboxUnavailableException $e) {
			$this->assertSame(CodeSandboxClient::HOSTED_NOT_WIRED, $e->getMessage());
		}

		$this->assertSame([], $appApi->calls);
	}//end testAHostedChoiceNeverSendsThroughAppApi()

	/**
	 * A language the image does not carry is refused before anything is sent.
	 *
	 * @return void
	 */
	public function testAnUnknownLanguageIsRefusedBeforeDispatch(): void {
		$appApi = $this->appApi(answer: $this->response(status: 200, body: '{"exitCode":0}'));

		try {
			$this->client(appApi: $appApi)->run(language: 'bash', code: 'ls');
			$this->fail('bash was dispatched.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('The sandbox runs python or node.', $e->getMessage());
		}

		$this->assertSame([], $appApi->calls);
	}//end testAnUnknownLanguageIsRefusedBeforeDispatch()
}//end class
