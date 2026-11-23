<?php

/**
 * Unit tests for RunInstalledToolService and the keeper's tool lookup and
 * layer read (tools-agent-workspace-and-installed-tools task 5).
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CodeSandbox;

use OCA\Hermiq\Mcp\CodeSandboxToolDescriptors;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException;
use OCA\Hermiq\Service\CodeSandbox\RunCodeTool;
use OCA\Hermiq\Service\CodeSandbox\RunInstalledToolService;
use OCA\Hermiq\Service\CodeSandbox\ToolLayerKeeper;
use OCA\Hermiq\Service\CodeSandbox\WorkspaceTransfer;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the runInstalledTool tool.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005
 */
final class RunInstalledToolServiceTest extends TestCase {

	/**
	 * The run's workspace key.
	 *
	 * @var string
	 */
	private const KEY = 'session:agent-1:conv-1:alice';

	/**
	 * The layer store, a fresh temporary directory per test.
	 *
	 * @var string
	 */
	private string $store = '';

	/**
	 * The hash of the kept layer.
	 *
	 * @var string
	 */
	private string $hash = '';

	/**
	 * Whether the code execution AI feature is on.
	 *
	 * @var boolean
	 */
	private bool $featureOn = true;

	/**
	 * The tools installed in the conversation.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $installed = [];

	/**
	 * The runs the client was asked for, with the layer the loader gave.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $runs = [];

	/**
	 * Whether the sandbox lacks the layer, so the client loads it.
	 *
	 * @var boolean
	 */
	private bool $sandboxLacksLayer = false;

	/**
	 * What the client throws, if anything.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $clientFailure = null;

	/**
	 * What writeBack was asked to write.
	 *
	 * @var array<int, mixed>
	 */
	private array $writtenBack = [];

	/**
	 * A kept layer and csvkit installed in the conversation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = sys_get_temp_dir() . '/hermiq-run-layers-' . bin2hex(random_bytes(4));
		mkdir(directory: $this->store, permissions: 0700, recursive: true);
		$this->hash = hash(algo: 'sha256', data: 'layer-bytes');
		file_put_contents(filename: $this->store . '/' . $this->hash . '.tar.gz', data: 'layer-bytes');
		$this->installed = [
			['manager' => 'npm', 'package' => 'cowsay', 'version' => '1.6.0', 'sha256' => str_repeat(string: 'b', times: 64), 'binaries' => ['cowsay']],
			['manager' => 'pip', 'package' => 'csvkit', 'version' => '2.1.0', 'sha256' => $this->hash, 'binaries' => ['csvstat', 'csvcut']],
		];
	}//end setUp()

	/**
	 * Remove the layer store.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->store));
	}//end tearDown()

	/**
	 * The service over doubles that read this test's state.
	 *
	 * @return RunInstalledToolService
	 */
	private function service(): RunInstalledToolService {
		$runCode = $this->createMock(originalClassName: RunCodeTool::class);
		$runCode->method('featureEnabled')->willReturnCallback(fn (): bool => $this->featureOn);

		$scope = $this->createMock(originalClassName: WorkspaceRunScope::class);
		$scope->method('current')->willReturn(
			['runId' => 'run-1', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => 'conv-1', 'workspaceKey' => self::KEY]
		);

		$workspaces = $this->createMock(originalClassName: ServerSideWorkspaceProvider::class);
		$workspaces->method('root')->willReturn('/w/repo');
		$workspaces->method('toolLayerStore')->willReturn($this->store);
		$workspaces->method('installedTools')->willReturnCallback(fn (): array => $this->installed);

		$transfer = $this->createMock(originalClassName: WorkspaceTransfer::class);
		$transfer->method('pack')->willReturn([['name' => 'aanvragen.csv', 'content' => "a,b\n"]]);
		$transfer->method('writeBack')->willReturnCallback(
			function (string $workspaceKey, array $sent, mixed $returned): array {
				$this->writtenBack[] = [$workspaceKey, $returned];
				return ['written' => ['seen.txt'], 'refused' => []];
			}
		);

		return new RunInstalledToolService(
			runCode: $runCode,
			client: $this->client(),
			keeper: new ToolLayerKeeper(scope: $scope, workspaces: $workspaces),
			transfer: $transfer
		);
	}//end service()

	/**
	 * A client that records each run, and loads the layer when the sandbox lacks it.
	 *
	 * @return CodeSandboxClient
	 */
	private function client(): CodeSandboxClient {
		$client = $this->createMock(originalClassName: CodeSandboxClient::class);
		$client->method('runInstalled')->willReturnCallback(
			function (array $call, array $files, callable $layer, ?string $uid): array {
				if ($this->clientFailure !== null) {
					throw $this->clientFailure;
				}

				$sent = null;
				if ($this->sandboxLacksLayer === true) {
					$sent = $layer();
				}

				$this->runs[] = ['call' => $call, 'files' => $files, 'layer' => $sent, 'uid' => $uid];
				return [
					'exitCode' => 1, 'stdout' => '', 'stderr' => "No such file: aanvragen.csv; rm -rf .\n",
					'durationMs' => 5, 'timedOut' => false, 'files' => [['name' => 'seen.txt']],
				];
			}
		);

		return $client;
	}//end client()

	/**
	 * Run the tool.
	 *
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function runTool(array $arguments): array {
		return $this->service()->invoke(uid: 'alice', toolId: CodeSandboxToolDescriptors::RUN_INSTALLED_TOOL, arguments: $arguments);
	}//end runTool()

	/**
	 * Shell syntax goes to the sandbox as one argument of the installed tool's
	 * layer, and the run's changes are written back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-shell-syntax-is-not-interpreted
	 */
	public function testTheArgumentsGoToTheToolsLayerAsAListAndTheChangesAreWrittenBack(): void {
		$result = $this->runTool(arguments: ['tool' => 'csvstat', 'args' => ['aanvragen.csv; rm -rf .'], 'timeoutSeconds' => 60]);

		$this->assertCount(expectedCount: 1, haystack: $this->runs);
		$this->assertSame(
			expected: [
				'sha256' => $this->hash, 'tool' => 'csvstat', 'args' => ['aanvragen.csv; rm -rf .'], 'stdin' => '',
				'limits' => ['wallSeconds' => 60, 'memoryMb' => null],
			],
			actual: $this->runs[0]['call']
		);
		$this->assertSame(expected: 'alice', actual: $this->runs[0]['uid']);
		$this->assertNull(actual: $this->runs[0]['layer']);
		$this->assertSame(expected: [[self::KEY, [['name' => 'seen.txt']]]], actual: $this->writtenBack);
		$this->assertSame(expected: ['written' => ['seen.txt'], 'refused' => []], actual: $result['workspace']);
		$this->assertSame(expected: [], actual: $result['files']);
		$this->assertSame(expected: 'csvkit', actual: $result['package']);
		$this->assertSame(expected: 1, actual: $result['exitCode']);
	}//end testTheArgumentsGoToTheToolsLayerAsAListAndTheChangesAreWrittenBack()

	/**
	 * When the sandbox lacks the layer, the kept bytes are loaded for it.
	 *
	 * @return void
	 */
	public function testALayerTheSandboxLacksIsLoadedFromTheStore(): void {
		$this->sandboxLacksLayer = true;

		$this->runTool(arguments: ['tool' => 'csvcut']);

		$this->assertSame(expected: base64_encode(string: 'layer-bytes'), actual: $this->runs[0]['layer']);
	}//end testALayerTheSandboxLacksIsLoadedFromTheStore()

	/**
	 * A layer that is gone or changed in the store is not sent.
	 *
	 * @return void
	 */
	public function testALayerThatNoLongerMatchesItsHashIsNotSent(): void {
		$this->sandboxLacksLayer = true;
		file_put_contents(filename: $this->store . '/' . $this->hash . '.tar.gz', data: 'tampered');

		$result = $this->runTool(arguments: ['tool' => 'csvstat']);

		$this->assertSame(expected: 'tool_run_failed', actual: $result['error']['code']);
		$this->assertSame(expected: [], actual: $this->runs);
	}//end testALayerThatNoLongerMatchesItsHashIsNotSent()

	/**
	 * A binary no installed tool offers is refused before the sandbox is asked.
	 *
	 * @return void
	 */
	public function testABinaryNoInstalledToolOffersIsRefused(): void {
		$result = $this->runTool(arguments: ['tool' => 'csvjson']);

		$this->assertSame(expected: 'tool_not_installed', actual: $result['error']['code']);
		$this->assertSame(expected: [], actual: $this->runs);
	}//end testABinaryNoInstalledToolOffersIsRefused()

	/**
	 * A tool name that is a path, arguments that are not a list of strings,
	 * another tool's id and the AI feature off are refused.
	 *
	 * @return void
	 */
	public function testDoubtfulCallsAreRefusedBeforeTheSandboxIsAsked(): void {
		$cases = [
			['tool' => '../../bin/sh'],
			['tool' => 'csvstat; rm -rf .'],
			['tool' => 'csvstat', 'args' => 'aanvragen.csv; rm -rf .'],
			['tool' => 'csvstat', 'args' => [1]],
			['tool' => 'csvstat', 'args' => ['a' => 'x']],
			['tool' => 'csvstat', 'args' => array_fill(start_index: 0, count: 65, value: 'x')],
		];
		foreach ($cases as $arguments) {
			$result = $this->runTool(arguments: $arguments);
			$this->assertSame(expected: 'invalid_argument', actual: $result['error']['code'], message: (string)json_encode(value: $arguments));
		}

		$other = $this->service()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['tool' => 'csvstat']);
		$this->assertSame(expected: 'unknown_tool', actual: $other['error']['code']);

		$this->featureOn = false;
		$this->assertSame(expected: 'code_execution_not_enabled', actual: $this->runTool(arguments: ['tool' => 'csvstat'])['error']['code']);
		$this->assertSame(expected: [], actual: $this->runs);
	}//end testDoubtfulCallsAreRefusedBeforeTheSandboxIsAsked()

	/**
	 * Workspace and sandbox failures are error envelopes.
	 *
	 * @return void
	 */
	public function testWorkspaceAndSandboxFailuresAreErrorEnvelopes(): void {
		$this->clientFailure = new CodeSandboxUnavailableException(message: CodeSandboxUnavailableException::RUN_INSTALLED_NEEDS_EXAPP);
		$this->assertSame(
			expected: ['error' => ['code' => 'code_sandbox_unavailable', 'message' => CodeSandboxUnavailableException::RUN_INSTALLED_NEEDS_EXAPP]],
			actual: $this->runTool(arguments: ['tool' => 'csvstat'])
		);

		$this->clientFailure = new WorkspaceException(errorCode: WorkspaceException::WORKSPACE_QUOTA, message: 'over budget');
		$this->assertSame(expected: WorkspaceException::WORKSPACE_QUOTA, actual: $this->runTool(arguments: ['tool' => 'csvstat'])['error']['code']);
	}//end testWorkspaceAndSandboxFailuresAreErrorEnvelopes()

	/**
	 * The descriptor matches runCode: write-classified, reach at least user.
	 *
	 * @return void
	 */
	public function testTheDescriptorMatchesRunCode(): void {
		$descriptor = CodeSandboxToolDescriptors::runInstalledTool(reach: 'self');

		$this->assertSame(expected: 'hermiq.runInstalledTool', actual: $descriptor['id']);
		$this->assertSame(expected: 'user', actual: $descriptor['reach']);
		$this->assertSame(expected: 'external', actual: CodeSandboxToolDescriptors::runInstalledTool(reach: 'external')['reach']);
		$this->assertSame(expected: 'create', actual: $descriptor['scope']);
		$this->assertFalse(condition: $descriptor['readOnlyHint']);
		$this->assertSame(expected: ['tool'], actual: $descriptor['inputSchema']['required']);
	}//end testTheDescriptorMatchesRunCode()
}//end class
