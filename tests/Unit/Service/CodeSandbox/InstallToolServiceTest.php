<?php

/**
 * Unit tests for InstallToolService (tools-agent-workspace-and-installed-tools task 4).
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
use OCA\Hermiq\Service\CodeSandbox\InstallToolService;
use OCA\Hermiq\Service\CodeSandbox\RunCodeTool;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for InstallToolService.
 */
final class InstallToolServiceTest extends TestCase {

	private const KEY = 'session:agent-1:conv-1:alice';

	private RunCodeTool&MockObject $runCode;

	private CodeSandboxClient&MockObject $client;

	private GuardrailPolicyService&MockObject $guardrails;

	private WorkspaceRunScope&MockObject $scope;

	private ServerSideWorkspaceProvider&MockObject $workspaces;

	private ObjectService&MockObject $objects;

	private string $store;

	private string $stored = '';

	/**
	 * The tools recorded on the workspace.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	protected function setUp(): void {
		$this->store = sys_get_temp_dir() . '/hermiq-layers-' . bin2hex(random_bytes(4));
		$this->runCode = $this->createMock(RunCodeTool::class);
		$this->runCode->method('featureEnabled')->willReturn(true);
		$this->client = $this->createMock(CodeSandboxClient::class);
		$this->guardrails = $this->createMock(GuardrailPolicyService::class);
		$this->guardrails->method('effectivePolicyFor')->willReturn(['packageAllowlist' => []]);
		$this->scope = $this->createMock(WorkspaceRunScope::class);
		$this->scope->method('current')->willReturn(['runId' => 'run-1', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => 'conv-1', 'workspaceKey' => self::KEY]);
		$this->workspaces = $this->createMock(ServerSideWorkspaceProvider::class);
		$this->workspaces->method('root')->willReturn('/w/repo');
		$this->workspaces->method('toolLayerStore')->willReturn($this->store);
		$this->workspaces->method('recordInstalledTool')->willReturnCallback(
			function (string $runKey, array $tool): void {
				$this->recorded[] = ['key' => $runKey] + $tool;
			}
		);
		$this->objects = $this->createMock(ObjectService::class);
		$agent = new ObjectEntity();
		$agent->setOrganisation('org-a');
		$this->objects->method('find')->willReturn($agent);
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->store));
	}//end tearDown()

	private function service(): InstallToolService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->stored);

		return new InstallToolService(
			runCode: $this->runCode,
			client: $this->client,
			settings: new CodeSandboxSettings(appConfig: $config),
			guardrails: $this->guardrails,
			scope: $this->scope,
			workspaces: $this->workspaces,
			objectService: $this->objects,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

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
			'layer' => ['sha256' => hash('sha256', $bytes), 'archive' => base64_encode($bytes)],
		];
	}//end installed()

	/**
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
	 */
	public function testAnInstallAnswersTheBinariesAndVersionsAndKeepsTheLayerByItsHash(): void {
		$this->client->expects(self::once())->method('install')->with('pip', 'csvkit', '2.1.0', 'alice')->willReturn(self::installed());
		$guardrails = $this->createMock(GuardrailPolicyService::class);
		$guardrails->expects(self::once())->method('effectivePolicyFor')->with('org-a')->willReturn(['packageAllowlist' => ['pip:csvkit']]);
		$this->guardrails = $guardrails;

		$result = $this->service()->invoke(uid: 'alice', toolId: CodeSandboxToolDescriptors::INSTALL_TOOL, arguments: ['manager' => 'pip', 'package' => 'csvkit', 'version' => '2.1.0']);

		$hash = hash('sha256', 'layer-bytes');
		self::assertSame('2.1.0', $result['version']);
		self::assertSame(['csvstat', 'csvcut'], $result['binaries']);
		self::assertSame($hash, $result['sha256']);
		self::assertSame(['type' => 'toolLayer', 'id' => $hash], $result['artefact']);
		self::assertCount(2, $result['versions']);
		self::assertSame('layer-bytes', file_get_contents($this->store . '/' . $hash . '.tar.gz'));
		self::assertSame(
			[['key' => self::KEY, 'manager' => 'pip', 'package' => 'csvkit', 'version' => '2.1.0', 'sha256' => $hash, 'binaries' => ['csvstat', 'csvcut']]],
			$this->recorded
		);
	}//end testAnInstallAnswersTheBinariesAndVersionsAndKeepsTheLayerByItsHash()

	/**
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-a-registry-that-is-not-allowlisted
	 */
	public function testARegistryOffTheAdminsAllowlistIsRefusedBeforeTheInstallerIsCalled(): void {
		$this->stored = '{"backend":"exapp","installManagers":["pip"]}';
		$this->client->expects(self::never())->method('install');

		$result = $this->service()->invoke(uid: 'alice', toolId: CodeSandboxToolDescriptors::INSTALL_TOOL, arguments: ['manager' => 'npm', 'package' => 'cowsay']);

		self::assertSame(['error' => ['code' => 'registry_not_allowed', 'message' => 'npm is not an allowed registry on this instance.']], $result);
	}//end testARegistryOffTheAdminsAllowlistIsRefusedBeforeTheInstallerIsCalled()

	public function testAPackageOffTheOrganisationsAllowlistIsRefusedBeforeTheInstallerIsCalled(): void {
		$guardrails = $this->createMock(GuardrailPolicyService::class);
		$guardrails->method('effectivePolicyFor')->willReturn(['packageAllowlist' => ['pip:csvkit', 'npm:cowsay']]);
		$this->guardrails = $guardrails;
		$this->client->expects(self::never())->method('install');

		$result = $this->service()->invoke(uid: 'alice', toolId: CodeSandboxToolDescriptors::INSTALL_TOOL, arguments: ['manager' => 'pip', 'package' => 'cowsay']);

		self::assertSame('package_not_allowed', $result['error']['code']);
	}//end testAPackageOffTheOrganisationsAllowlistIsRefusedBeforeTheInstallerIsCalled()

	public function testTheOrganisationsAllowlistMatchesByManagerAndPyPiNormalisedName(): void {
		self::assertTrue(InstallToolService::permits(allowlist: [], manager: 'pip', package: 'anything'));
		self::assertTrue(InstallToolService::permits(allowlist: ['pip:Python_Dateutil'], manager: 'pip', package: 'python-dateutil'));
		self::assertTrue(InstallToolService::permits(allowlist: ['csvkit'], manager: 'pip', package: 'csvkit'));
		self::assertFalse(InstallToolService::permits(allowlist: ['npm:csvkit'], manager: 'pip', package: 'csvkit'));
		self::assertFalse(InstallToolService::permits(allowlist: ['pip:csvkit'], manager: 'pip', package: 'csvkit2'));
		self::assertFalse(InstallToolService::permits(allowlist: ['npm:Cowsay2'], manager: 'npm', package: 'cowsay'));
		self::assertTrue(InstallToolService::permits(allowlist: ['npm:@scope/tool'], manager: 'npm', package: '@scope/tool'));
	}//end testTheOrganisationsAllowlistMatchesByManagerAndPyPiNormalisedName()

	public function testTheAiFeatureOffRefusesBeforeAnythingElse(): void {
		$runCode = $this->createMock(RunCodeTool::class);
		$runCode->method('featureEnabled')->willReturn(false);
		$this->runCode = $runCode;
		$this->client->expects(self::never())->method('install');

		$result = $this->service()->invoke(uid: 'alice', toolId: CodeSandboxToolDescriptors::INSTALL_TOOL, arguments: ['manager' => 'pip', 'package' => 'csvkit']);

		self::assertSame('code_execution_not_enabled', $result['error']['code']);
	}//end testTheAiFeatureOffRefusesBeforeAnythingElse()

	public function testArgumentsThatCouldSteerTheResolverAreRefused(): void {
		$this->client->expects(self::never())->method('install');
		$service = $this->service();
		$cases = [
			['manager' => 'cargo', 'package' => 'ripgrep'],
			['manager' => 'pip', 'package' => '--index-url=https://evil.example'],
			['manager' => 'pip', 'package' => 'csvkit; rm -rf /'],
			['manager' => 'pip', 'package' => 'csvkit', 'version' => '>=1'],
			['manager' => 'npm', 'package' => 'git+https://example.org/x.git'],
		];
		foreach ($cases as $arguments) {
			$result = $service->invoke(uid: 'alice', toolId: CodeSandboxToolDescriptors::INSTALL_TOOL, arguments: $arguments);
			self::assertSame('invalid_argument', $result['error']['code'], json_encode($arguments));
		}

		self::assertSame('unknown_tool', $service->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: [])['error']['code']);
	}//end testArgumentsThatCouldSteerTheResolverAreRefused()

	public function testARunWithoutAWorkspaceIsRefused(): void {
		$scope = $this->createMock(WorkspaceRunScope::class);
		$scope->method('current')->willThrowException(new WorkspaceException(errorCode: WorkspaceException::RUN_SCOPE_INVALID, message: 'no run'));
		$this->scope = $scope;
		$this->client->expects(self::never())->method('install');

		$result = $this->service()->invoke(uid: 'alice', toolId: CodeSandboxToolDescriptors::INSTALL_TOOL, arguments: ['manager' => 'pip', 'package' => 'csvkit']);

		self::assertSame(WorkspaceException::WORKSPACE_ABSENT, $result['error']['code']);
	}//end testARunWithoutAWorkspaceIsRefused()

	public function testALayerThatDoesNotMatchItsHashIsNotKept(): void {
		$answer = self::installed();
		$answer['layer']['sha256'] = str_repeat('0', 64);
		$this->client->method('install')->willReturn($answer);

		$result = $this->service()->invoke(uid: 'alice', toolId: CodeSandboxToolDescriptors::INSTALL_TOOL, arguments: ['manager' => 'pip', 'package' => 'csvkit']);

		self::assertSame('install_failed', $result['error']['code']);
		self::assertSame([], $this->recorded);
		self::assertFileDoesNotExist($this->store . '/' . str_repeat('0', 64) . '.tar.gz');
	}//end testALayerThatDoesNotMatchItsHashIsNotKept()

	public function testAnUnavailableSandboxIsAnErrorEnvelope(): void {
		$this->client->method('install')->willThrowException(new CodeSandboxUnavailableException(CodeSandboxUnavailableException::INSTALL_NEEDS_EXAPP));

		$result = $this->service()->invoke(uid: 'alice', toolId: CodeSandboxToolDescriptors::INSTALL_TOOL, arguments: ['manager' => 'npm', 'package' => 'cowsay', 'version' => '']);

		self::assertSame(['error' => ['code' => 'code_sandbox_unavailable', 'message' => CodeSandboxUnavailableException::INSTALL_NEEDS_EXAPP]], $result);
	}//end testAnUnavailableSandboxIsAnErrorEnvelope()

	public function testTheDescriptorIsDefaultDeniedExternalAndDestructive(): void {
		$descriptor = CodeSandboxToolDescriptors::installTool();

		self::assertSame('hermiq.installTool', $descriptor['id']);
		self::assertSame('external', $descriptor['reach']);
		self::assertTrue($descriptor['destructiveHint']);
		self::assertFalse($descriptor['readOnlyHint']);
		self::assertSame('create', $descriptor['scope']);
		self::assertSame(['manager', 'package'], $descriptor['inputSchema']['required']);
		self::assertContains('hermiq.installTool', array_column(CodeSandboxToolDescriptors::forReach(reach: 'self'), 'id'));
	}//end testTheDescriptorIsDefaultDeniedExternalAndDestructive()
}//end class
