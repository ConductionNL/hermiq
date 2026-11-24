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
use OCA\Hermiq\Service\CodeSandbox\HostedSandboxDispatch;
use OCA\OpenRegister\Db\ObjectEntity;
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
	 * A hosted choice never goes through AppAPI: without integriq it refuses
	 * the run and sends nothing.
	 *
	 * @return void
	 */
	public function testAHostedChoiceNeverSendsThroughAppApi(): void {
		$appApi = $this->appApi(answer: $this->response(status: 200, body: '{"exitCode":0}'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($appApi): object {
				if ($id === CodeSandboxClient::APP_API_PUBLIC_FUNCTIONS) {
					return $appApi;
				}

				throw new RuntimeException('not installed');
			}
		);
		$client = new CodeSandboxClient(
			settings: $this->settings(stored: '{"backend":"hosted","hostedSource":"hosted-sandbox"}'),
			logger: $this->createMock(LoggerInterface::class),
			container: $container
		);

		$this->assertFalse($client->isAvailable());
		try {
			$client->run(language: 'python', code: 'print(1)');
			$this->fail('A hosted run was dispatched.');
		} catch (CodeSandboxUnavailableException $e) {
			$this->assertSame(HostedSandboxDispatch::SOURCE_MISSING, $e->getMessage());
		}

		$this->assertSame([], $appApi->calls);
	}//end testAHostedChoiceNeverSendsThroughAppApi()

	/**
	 * A hosted run goes through integriq with the contract and the clamped
	 * limits, and its answer is mapped like the own sandbox's.
	 *
	 * @return void
	 */
	public function testAHostedRunGoesThroughIntegriq(): void {
		$calls = [];
		$source = new ObjectEntity();
		$source->setUuid('src-uuid');
		$source->setObject(['slug' => 'hosted-sandbox']);
		$callLog = new ObjectEntity();
		$callLog->setObject(['response' => ['statusCode' => 200, 'body' => '{"exitCode":0,"stdout":"2\\n","stderr":"","durationMs":5,"timedOut":false}']]);
		$callService = new class($callLog, $calls) {
			/**
			 * Constructor.
			 *
			 * @param ObjectEntity       $callLog The answer.
			 * @param array<int, mixed>  $calls   The recorded calls.
			 */
			public function __construct(private ObjectEntity $callLog, public array &$calls) {
			}//end __construct()

			/**
			 * Record a call.
			 *
			 * @param mixed  $source         The source.
			 * @param string $endpoint       The endpoint.
			 * @param string $method         The method.
			 * @param array  $config         The config.
			 * @param bool   $withholdBodies Whether bodies are kept out of the call log.
			 *
			 * @return ObjectEntity
			 */
			public function call(mixed $source, string $endpoint='', string $method='GET', array $config=[], bool $withholdBodies=false): ObjectEntity {
				$this->calls[] = [$source->getUuid(), $endpoint, $method, $config, $withholdBodies];
				return $this->callLog;
			}//end call()
		};
		$objects = new class($source) {
			/**
			 * Constructor.
			 *
			 * @param ObjectEntity $source The one source.
			 */
			public function __construct(private ObjectEntity $source) {
			}//end __construct()

			/**
			 * The sources.
			 *
			 * @param array $config        The query.
			 * @param bool  $_rbac         RBAC.
			 * @param bool  $_multitenancy Multitenancy.
			 *
			 * @return array<int, ObjectEntity>
			 */
			public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				return [new ObjectEntity(), $this->source];
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\\Integriq\\Service\\CallService' => $callService,
				HostedSandboxDispatch::OBJECT_SERVICE => $objects,
				default => throw new RuntimeException('not installed'),
			}
		);
		$client = new CodeSandboxClient(
			settings: $this->settings(stored: '{"backend":"hosted","hostedSource":"hosted-sandbox"}'),
			logger: $this->createMock(LoggerInterface::class),
			container: $container
		);

		$this->assertTrue($client->isAvailable());
		$result = $client->run(language: 'python', code: 'print(1 + 1)', limits: ['wallSeconds' => 999]);

		$this->assertSame(0, $result['exitCode']);
		$this->assertSame("2\n", $result['stdout']);
		$this->assertSame(['wallSeconds' => 300, 'memoryMb' => 512], $result['limits']);
		$this->assertSame(
			[[
				'src-uuid',
				'/run',
				'POST',
				[
					'json' => ['language' => 'python', 'code' => 'print(1 + 1)', 'stdin' => '', 'files' => [], 'limits' => ['wallSeconds' => 300, 'memoryMb' => 512]],
					'timeout' => 315,
				],
				true,
			]],
			$callService->calls
		);
	}//end testAHostedRunGoesThroughIntegriq()

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

	/**
	 * An install goes to the sandbox's /install with only manager, package and
	 * version, and answers the layer, versions and binaries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 */
	public function testAnInstallGoesToTheInstallerAndAnswersTheLayer(): void {
		$hash = hash('sha256', 'x');
		$appApi = $this->appApi(
			answer: $this->response(
				status: 200,
				body: (string)json_encode(
					[
						'manager' => 'pip', 'package' => 'csvkit', 'version' => '2.1.0',
						'versions' => [['name' => 'csvkit', 'version' => '2.1.0'], 'junk'],
						'binaries' => ['csvstat', 7],
						'layer' => ['sha256' => $hash, 'size' => 1, 'archive' => base64_encode('x')],
					]
				)
			)
		);

		$result = $this->client(appApi: $appApi)->install(manager: 'pip', package: 'csvkit', version: '2.1.0', uid: 'alice');

		$this->assertSame(
			expected: [
				'hermiq-code-sandbox', '/install', 'alice', 'POST',
				['manager' => 'pip', 'package' => 'csvkit', 'version' => '2.1.0'], ['timeout' => 600],
			],
			actual: $appApi->calls[0]
		);
		$this->assertSame(expected: [['name' => 'csvkit', 'version' => '2.1.0']], actual: $result['versions']);
		$this->assertSame(expected: ['csvstat'], actual: $result['binaries']);
		$this->assertSame(expected: ['sha256' => $hash, 'archive' => base64_encode('x')], actual: $result['layer']);
	}//end testAnInstallGoesToTheInstallerAndAnswersTheLayer()

	/**
	 * A hosted backend refuses before anything is sent; a refusal carries the
	 * installer's reason; an answer without a layer is malformed.
	 *
	 * @return void
	 */
	public function testAnInstallIsRefusedOnAHostedBackendAndCarriesTheInstallersReason(): void {
		$appApi = $this->appApi(answer: $this->response(status: 400, body: '{"error":"npm is not an allowed registry on this instance."}'));
		try {
			$hosted = $this->client(appApi: $appApi, settings: $this->settings(stored: '{"backend":"hosted","hostedSource":"src-1"}'));
			$hosted->install(manager: 'npm', package: 'cowsay', version: null, uid: 'alice');
			$this->fail(message: 'A hosted backend took an install.');
		} catch (CodeSandboxUnavailableException $e) {
			$this->assertSame(expected: CodeSandboxUnavailableException::INSTALL_NEEDS_EXAPP, actual: $e->getMessage());
		}

		$this->assertSame(expected: [], actual: $appApi->calls);

		try {
			$this->client(appApi: $appApi)->install(manager: 'npm', package: 'cowsay', version: null, uid: 'alice');
			$this->fail(message: 'A refused install answered.');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'npm is not an allowed registry on this instance.', actual: $e->getMessage());
		}

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'The installer answered without a tool layer.');
		$malformed = $this->appApi(answer: $this->response(status: 200, body: '{"layer":{"sha256":"nope","archive":""}}'));
		$this->client(appApi: $malformed)->install(manager: 'pip', package: 'csvkit', version: null, uid: null);
	}//end testAnInstallIsRefusedOnAHostedBackendAndCarriesTheInstallersReason()

	/**
	 * An installed tool runs by its layer's hash; on 409 the layer is sent once
	 * with the same run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005
	 */
	public function testAnInstalledToolRunSendsTheLayerOnlyWhenTheSandboxLacksIt(): void {
		$answers = [
			$this->response(status: 409, body: '{"error":"The sandbox does not hold this tool layer yet.","code":"layer_missing"}'),
			$this->response(status: 200, body: '{"exitCode":0,"stdout":"ok\n","stderr":"","durationMs":9,"timedOut":false,"files":[]}'),
		];
		$appApi = new class($answers) {
			/**
			 * The recorded requests.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, object> $answers The answers, in order.
			 */
			public function __construct(private array $answers) {
			}

			/**
			 * Record and answer the next.
			 *
			 * @param mixed ...$args The request.
			 *
			 * @return mixed
			 */
			public function exAppRequest(mixed ...$args): mixed {
				$this->calls[] = $args;
				return array_shift($this->answers);
			}
		};
		$loads = 0;

		$result = $this->client(appApi: $appApi)->runInstalled(
			call: [
				'sha256' => str_repeat(string: 'a', times: 64), 'tool' => 'csvstat',
				'args' => ['x.csv; rm -rf .'], 'limits' => ['wallSeconds' => 10],
			],
			files: [['name' => 'x.csv', 'content' => "a\n"]],
			layer: function () use (&$loads): string {
				$loads++;
				return 'bGF5ZXI=';
			},
			uid: 'alice'
		);

		$this->assertSame(expected: 0, actual: $result['exitCode']);
		$this->assertSame(expected: 1, actual: $loads);
		$this->assertCount(expectedCount: 2, haystack: $appApi->calls);
		$this->assertArrayNotHasKey(key: 'layer', array: $appApi->calls[0][4]);
		$this->assertSame(expected: 'bGF5ZXI=', actual: $appApi->calls[1][4]['layer']);
		$this->assertSame(expected: ['x.csv; rm -rf .'], actual: $appApi->calls[1][4]['installed']['args']);
		$this->assertSame(expected: ['wallSeconds' => 10, 'memoryMb' => 512], actual: $result['limits']);
	}//end testAnInstalledToolRunSendsTheLayerOnlyWhenTheSandboxLacksIt()

	/**
	 * A hosted backend does not run installed tools, and a refusal carries the sandbox's reason.
	 *
	 * @return void
	 */
	public function testAnInstalledToolRunIsRefusedOnAHostedBackendAndCarriesTheSandboxesReason(): void {
		$refused = $this->response(status: 404, body: '{"error":"The tool layer has no binary named csvjson.","code":"tool_missing"}');
		$appApi = $this->appApi(answer: $refused);
		$call = ['sha256' => str_repeat(string: 'a', times: 64), 'tool' => 'csvjson'];
		$noLayer = static fn (): string => '';

		try {
			$hosted = $this->client(appApi: $appApi, settings: $this->settings(stored: '{"backend":"hosted","hostedSource":"src-1"}'));
			$hosted->runInstalled(call: $call, files: [], layer: $noLayer, uid: 'alice');
			$this->fail(message: 'A hosted backend ran an installed tool.');
		} catch (CodeSandboxUnavailableException $e) {
			$this->assertSame(expected: CodeSandboxUnavailableException::RUN_INSTALLED_NEEDS_EXAPP, actual: $e->getMessage());
		}

		$this->assertSame(expected: [], actual: $appApi->calls);
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'The tool layer has no binary named csvjson.');
		$this->client(appApi: $appApi)->runInstalled(call: $call, files: [], layer: $noLayer, uid: 'alice');
	}//end testAnInstalledToolRunIsRefusedOnAHostedBackendAndCarriesTheSandboxesReason()
}//end class
