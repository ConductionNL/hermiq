<?php

/**
 * Unit tests for InstallToolService, InstallPolicy and ToolLayerKeeper
 * (tools-agent-workspace-and-installed-tools task 4).
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CodeSandbox;

use OCA\Hermiq\Mcp\CodeSandboxToolDescriptors;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxSettings;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException;
use OCA\Hermiq\Service\CodeSandbox\InstallPolicy;
use OCA\Hermiq\Service\CodeSandbox\InstallToolService;
use OCA\Hermiq\Service\CodeSandbox\RunCodeTool;
use OCA\Hermiq\Service\CodeSandbox\ToolLayerKeeper;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the installTool tool.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
 */
final class InstallToolServiceTest extends TestCase {

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
	 * The stored code sandbox settings JSON.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * Whether the code execution AI feature is on.
	 *
	 * @var boolean
	 */
	private bool $featureOn = true;

	/**
	 * Whether the run has a workspace.
	 *
	 * @var boolean
	 */
	private bool $hasWorkspace = true;

	/**
	 * The organisation's package allowlist.
	 *
	 * @var array<int, string>
	 */
	private array $allowlist = [];

	/**
	 * The organisations the policy was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $askedOrganisations = [];

	/**
	 * The installs the client was asked for.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $installs = [];

	/**
	 * What the client answers: an install answer or an exception.
	 *
	 * @var array<string, mixed>|\Throwable
	 */
	private array|\Throwable $answer = [];

	/**
	 * The tools recorded on the workspace.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * A fresh layer store per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = sys_get_temp_dir() . '/hermiq-layers-' . bin2hex(random_bytes(4));
		$this->answer = self::installed();
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
	 * @return InstallToolService
	 */
	private function service(): InstallToolService {
		$runCode = $this->createMock(originalClassName: RunCodeTool::class);
		$runCode->method('featureEnabled')->willReturnCallback(fn (): bool => $this->featureOn);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->stored);

		$guardrails = $this->createMock(originalClassName: GuardrailPolicyService::class);
		$guardrails->method('effectivePolicyFor')->willReturnCallback(
			function (string $organisation): array {
				$this->askedOrganisations[] = $organisation;
				return ['packageAllowlist' => $this->allowlist];
			}
		);

		$agent = new ObjectEntity();
		$agent->setOrganisation('org-a');
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('find')->willReturn($agent);

		$client = $this->createMock(originalClassName: CodeSandboxClient::class);
		$client->method('install')->willReturnCallback(
			function (mixed ...$args): array {
				$this->installs[] = $args;
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		);

		return new InstallToolService(
			policy: new InstallPolicy(
				runCode: $runCode,
				settings: new CodeSandboxSettings(appConfig: $config),
				guardrails: $guardrails,
				objectService: $objects
			),
			client: $client,
			keeper: new ToolLayerKeeper(scope: $this->scope(), workspaces: $this->workspaces()),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end service()

	/**
	 * The run scope: bound to agent-1's session workspace, or outside a run.
	 *
	 * @return WorkspaceRunScope
	 */
	private function scope(): WorkspaceRunScope {
		$scope = $this->createMock(originalClassName: WorkspaceRunScope::class);
		$scope->method('current')->willReturnCallback(
			function (): array {
				if ($this->hasWorkspace === false) {
					throw new WorkspaceException(errorCode: WorkspaceException::RUN_SCOPE_INVALID, message: 'no run');
				}

				return ['runId' => 'run-1', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => 'conv-1', 'workspaceKey' => self::KEY];
			}
		);

		return $scope;
	}//end scope()

	/**
	 * The workspaces: a layer store in the temporary directory, and a record of tools.
	 *
	 * @return ServerSideWorkspaceProvider
	 */
	private function workspaces(): ServerSideWorkspaceProvider {
		$workspaces = $this->createMock(originalClassName: ServerSideWorkspaceProvider::class);
		$workspaces->method('root')->willReturn('/w/repo');
		$workspaces->method('toolLayerStore')->willReturn($this->store);
		$workspaces->method('recordInstalledTool')->willReturnCallback(
			function (string $runKey, array $tool): void {
				$this->recorded[] = ['key' => $runKey] + $tool;
			}
		);

		return $workspaces;
	}//end workspaces()

	/**
	 * An installer answer with a layer whose hash matches its bytes.
	 *
	 * @param string $bytes The layer's bytes.
	 *
	 * @return array<string, mixed>
	 */
	private static function installed(string $bytes='layer-bytes'): array {
		return [
			'manager' => 'pip',
			'package' => 'csvkit',
			'version' => '2.1.0',
			'versions' => [['name' => 'csvkit', 'version' => '2.1.0'], ['name' => 'agate', 'version' => '1.9.1']],
			'binaries' => ['csvstat', 'csvcut'],
			'layer' => ['sha256' => hash(algo: 'sha256', data: $bytes), 'archive' => base64_encode(string: $bytes)],
		];
	}//end installed()

	/**
	 * Install a package through the tool.
	 *
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function install(array $arguments): array {
		return $this->service()->invoke(uid: 'alice', toolId: CodeSandboxToolDescriptors::INSTALL_TOOL, arguments: $arguments);
	}//end install()

	/**
	 * An install answers the binaries and versions, keeps the layer by its
	 * hash and records the tool on the conversation's workspace.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
	 */
	public function testAnInstallAnswersTheBinariesAndVersionsAndKeepsTheLayerByItsHash(): void {
		$this->allowlist = ['pip:csvkit'];

		$result = $this->install(arguments: ['manager' => 'pip', 'package' => 'csvkit', 'version' => '2.1.0']);

		$hash = hash(algo: 'sha256', data: 'layer-bytes');
		$this->assertSame(expected: [['pip', 'csvkit', '2.1.0', 'alice']], actual: $this->installs);
		$this->assertSame(expected: ['org-a'], actual: $this->askedOrganisations);
		$this->assertSame(expected: '2.1.0', actual: $result['version']);
		$this->assertSame(expected: ['csvstat', 'csvcut'], actual: $result['binaries']);
		$this->assertSame(expected: ['type' => 'toolLayer', 'id' => $hash], actual: $result['artefact']);
		$this->assertCount(expectedCount: 2, haystack: $result['versions']);
		$this->assertSame(expected: 'layer-bytes', actual: file_get_contents(filename: $this->store . '/' . $hash . '.tar.gz'));
		$this->assertSame(
			expected: [
				['key' => self::KEY, 'manager' => 'pip', 'package' => 'csvkit', 'version' => '2.1.0', 'sha256' => $hash, 'binaries' => ['csvstat', 'csvcut']],
			],
			actual: $this->recorded
		);
	}//end testAnInstallAnswersTheBinariesAndVersionsAndKeepsTheLayerByItsHash()

	/**
	 * A registry off the admin's allowlist is refused before the installer is called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-a-registry-that-is-not-allowlisted
	 */
	public function testARegistryOffTheAdminsAllowlistIsRefusedBeforeTheInstallerIsCalled(): void {
		$this->stored = '{"backend":"exapp","installManagers":["pip"]}';

		$result = $this->install(arguments: ['manager' => 'npm', 'package' => 'cowsay']);

		$this->assertSame(
			expected: ['error' => ['code' => 'registry_not_allowed', 'message' => 'npm is not an allowed registry on this instance.']],
			actual: $result
		);
		$this->assertSame(expected: [], actual: $this->installs);
	}//end testARegistryOffTheAdminsAllowlistIsRefusedBeforeTheInstallerIsCalled()

	/**
	 * A package off the organisation's allowlist is refused before the installer is called.
	 *
	 * @return void
	 */
	public function testAPackageOffTheOrganisationsAllowlistIsRefusedBeforeTheInstallerIsCalled(): void {
		$this->allowlist = ['pip:csvkit', 'npm:cowsay'];

		$result = $this->install(arguments: ['manager' => 'pip', 'package' => 'cowsay']);

		$this->assertSame(expected: 'package_not_allowed', actual: $result['error']['code']);
		$this->assertSame(expected: [], actual: $this->installs);
	}//end testAPackageOffTheOrganisationsAllowlistIsRefusedBeforeTheInstallerIsCalled()

	/**
	 * The allowlist matches by manager and by the name as PyPI normalises it.
	 *
	 * @return void
	 */
	public function testTheOrganisationsAllowlistMatchesByManagerAndPyPiNormalisedName(): void {
		$this->assertTrue(condition: InstallPolicy::permits(allowlist: [], manager: 'pip', package: 'anything'));
		$this->assertTrue(condition: InstallPolicy::permits(allowlist: ['pip:Python_Dateutil'], manager: 'pip', package: 'python-dateutil'));
		$this->assertTrue(condition: InstallPolicy::permits(allowlist: ['csvkit'], manager: 'pip', package: 'csvkit'));
		$this->assertTrue(condition: InstallPolicy::permits(allowlist: ['npm:@scope/tool'], manager: 'npm', package: '@scope/tool'));
		$this->assertFalse(condition: InstallPolicy::permits(allowlist: ['npm:csvkit'], manager: 'pip', package: 'csvkit'));
		$this->assertFalse(condition: InstallPolicy::permits(allowlist: ['pip:csvkit'], manager: 'pip', package: 'csvkit2'));
		$this->assertFalse(condition: InstallPolicy::permits(allowlist: ['npm:Cowsay2', 7], manager: 'npm', package: 'cowsay'));
	}//end testTheOrganisationsAllowlistMatchesByManagerAndPyPiNormalisedName()

	/**
	 * With the AI feature off nothing is installed.
	 *
	 * @return void
	 */
	public function testTheAiFeatureOffRefusesBeforeAnythingElse(): void {
		$this->featureOn = false;

		$result = $this->install(arguments: ['manager' => 'pip', 'package' => 'csvkit']);

		$this->assertSame(expected: 'code_execution_not_enabled', actual: $result['error']['code']);
		$this->assertSame(expected: [], actual: $this->installs);
	}//end testTheAiFeatureOffRefusesBeforeAnythingElse()

	/**
	 * Arguments that could steer the resolver, and another tool's id, are refused.
	 *
	 * @return void
	 */
	public function testArgumentsThatCouldSteerTheResolverAreRefused(): void {
		$cases = [
			['manager' => 'cargo', 'package' => 'ripgrep'],
			['manager' => 'pip', 'package' => '--index-url=https://evil.example'],
			['manager' => 'pip', 'package' => 'csvkit; rm -rf /'],
			['manager' => 'pip', 'package' => 'csvkit', 'version' => '>=1'],
			['manager' => 'npm', 'package' => 'git+https://example.org/x.git'],
		];
		foreach ($cases as $arguments) {
			$result = $this->install(arguments: $arguments);
			$this->assertSame(expected: 'invalid_argument', actual: $result['error']['code'], message: (string)json_encode(value: $arguments));
		}

		$other = $this->service()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: []);
		$this->assertSame(expected: 'unknown_tool', actual: $other['error']['code']);
		$this->assertSame(expected: [], actual: $this->installs);
	}//end testArgumentsThatCouldSteerTheResolverAreRefused()

	/**
	 * A run without a workspace installs nothing.
	 *
	 * @return void
	 */
	public function testARunWithoutAWorkspaceIsRefused(): void {
		$this->hasWorkspace = false;

		$result = $this->install(arguments: ['manager' => 'pip', 'package' => 'csvkit']);

		$this->assertSame(expected: WorkspaceException::WORKSPACE_ABSENT, actual: $result['error']['code']);
		$this->assertSame(expected: [], actual: $this->installs);
	}//end testARunWithoutAWorkspaceIsRefused()

	/**
	 * A layer that does not match its hash is neither stored nor recorded.
	 *
	 * @return void
	 */
	public function testALayerThatDoesNotMatchItsHashIsNotKept(): void {
		$answer = self::installed();
		$answer['layer']['sha256'] = str_repeat(string: '0', times: 64);
		$this->answer = $answer;

		$result = $this->install(arguments: ['manager' => 'pip', 'package' => 'csvkit']);

		$this->assertSame(expected: 'install_failed', actual: $result['error']['code']);
		$this->assertSame(expected: [], actual: $this->recorded);
		$this->assertFileDoesNotExist(filename: $this->store . '/' . str_repeat(string: '0', times: 64) . '.tar.gz');
	}//end testALayerThatDoesNotMatchItsHashIsNotKept()

	/**
	 * A sandbox that cannot install is an error envelope.
	 *
	 * @return void
	 */
	public function testAnUnavailableSandboxIsAnErrorEnvelope(): void {
		$this->answer = new CodeSandboxUnavailableException(message: CodeSandboxUnavailableException::INSTALL_NEEDS_EXAPP);

		$result = $this->install(arguments: ['manager' => 'npm', 'package' => 'cowsay', 'version' => '']);

		$this->assertSame(
			expected: ['error' => ['code' => 'code_sandbox_unavailable', 'message' => CodeSandboxUnavailableException::INSTALL_NEEDS_EXAPP]],
			actual: $result
		);
		$this->assertSame(expected: [['npm', 'cowsay', null, 'alice']], actual: $this->installs);
	}//end testAnUnavailableSandboxIsAnErrorEnvelope()

	/**
	 * The descriptor is default-denied: external, destructive, a create.
	 *
	 * @return void
	 */
	public function testTheDescriptorIsDefaultDeniedExternalAndDestructive(): void {
		$descriptor = CodeSandboxToolDescriptors::installTool();

		$this->assertSame(expected: 'hermiq.installTool', actual: $descriptor['id']);
		$this->assertSame(expected: 'external', actual: $descriptor['reach']);
		$this->assertTrue(condition: $descriptor['destructiveHint']);
		$this->assertFalse(condition: $descriptor['readOnlyHint']);
		$this->assertSame(expected: 'create', actual: $descriptor['scope']);
		$this->assertSame(expected: ['manager', 'package'], actual: $descriptor['inputSchema']['required']);
		$ids = array_column(array: CodeSandboxToolDescriptors::forReach(reach: 'self'), column_key: 'id');
		$this->assertContains(needle: 'hermiq.installTool', haystack: $ids);
	}//end testTheDescriptorIsDefaultDeniedExternalAndDestructive()
}//end class
