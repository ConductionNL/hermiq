<?php

/**
 * WorkspaceToolset read-only surface (hermiq-runner-git-capability, task 3).
 *
 * Real toolset, real provider over a real local forge, real path guard, real
 * ForgeLocator; only the egress guard verdict, the settings and the Nextcloud
 * config are doubles. The descriptors are read from the real HermiqToolProvider
 * catalogue, the one the registry and FacadeToolInvoker dispatch from.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCA\Hermiq\Service\Workspace\ForgeLocator;
use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspacePathGuard;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCA\Hermiq\Service\Workspace\WorkspaceEditor;
use OCA\Hermiq\Service\Workspace\WorkspaceToolset;
use OCA\Hermiq\Service\Workspace\WorkspaceAuditor;
use OCA\Hermiq\Service\Workspace\WorkspacePusher;
use OCA\Hermiq\Service\Workspace\WorkspaceWriteAuthoriser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The closed, named read surface.
 */
final class WorkspaceToolsetReadTest extends TestCase {

	private string $base;

	private bool $egressAllowed = true;

	private WorkspaceRunScope $scope;

	private ServerSideWorkspaceProvider $provider;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/hermiq-wst-' . bin2hex(random_bytes(4));
		$forge = $this->base . '/forge/example-org/example-app.git';
		mkdir($forge . '/lib', 0700, true);
		file_put_contents($forge . '/lib/A.php', "<?php\n");
		file_put_contents($forge . '/README.md', "hello\n");
		self::git($forge, 'init', '-q', '-b', 'development');
		self::git($forge, 'add', '.');
		self::git($forge, '-c', 'user.name=Forge', '-c', 'user.email=forge@example.invalid', 'commit', '-q', '-m', 'first commit');
		$this->scope = new WorkspaceRunScope();
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
	}//end tearDown()

	public function testNoToolTakesACommandSubcommandRefspecOrRemoteUrl(): void {
		$forbidden = ['command', 'cmd', 'args', 'argv', 'subcommand', 'refspec', 'url', 'remote', 'remoteUrl', 'force', 'shell', 'exec', 'script'];
		foreach (WorkspaceToolDescriptors::ALL as $descriptor) {
			$properties = array_keys((array)($descriptor['inputSchema']['properties'] ?? []));
			self::assertSame([], array_values(array_intersect($properties, $forbidden)), (string)$descriptor['id']);
		}

		$ids = array_column(WorkspaceToolDescriptors::ALL, 'id');
		foreach (['merge', 'rebase', 'reset', 'revert', 'cherry', 'tag', 'exec', 'run', 'git', 'shell', 'delete_ref', 'forcePush'] as $absent) {
			foreach ($ids as $id) {
				self::assertStringNotContainsStringIgnoringCase('workspace' . $absent, str_replace('.', '', (string)$id));
			}
		}
	}//end testNoToolTakesACommandSubcommandRefspecOrRemoteUrl()

	public function testTheToolsAreInTheOneCatalogueTheGovernedInvokerDispatches(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/Mcp/HermiqToolProvider.php');
		self::assertStringContainsString('WorkspaceToolDescriptors::ALL', $source);
		self::assertStringContainsString('WorkspaceToolset::class', $source);

		$routes = (string)file_get_contents(__DIR__ . '/../../../../appinfo/routes.php');
		self::assertStringNotContainsStringIgnoringCase('workspace', $routes, 'A workspace route would be a second path to the workspace.');
	}//end testTheToolsAreInTheOneCatalogueTheGovernedInvokerDispatches()

	public function testTheRepositoryIsASlugNeverAUrl(): void {
		$this->scope->enter(runId: 'run-a', agentId: 'agent-1', userId: 'alice');
		foreach (['https://evil.example/x/y', 'git@github.com:x/y', 'x/y/z', '../x/y', 'x/../y', 'file:///etc', '-x/y'] as $repository) {
			$result = $this->toolset()->invoke(toolId: WorkspaceToolDescriptors::OPEN, arguments: ['repository' => $repository, 'ref' => 'development']);
			self::assertSame(WorkspaceException::INVALID_ARGUMENT, $result['error']['code'] ?? null, $repository);
		}

		$this->assertNoWorkspaceDirectory();
	}//end testTheRepositoryIsASlugNeverAUrl()

	public function testAFileOrGitToolBeforeOpenIsRefusedAsAbsent(): void {
		$this->scope->enter(runId: 'run-a', agentId: 'agent-1', userId: 'alice');
		foreach ([WorkspaceToolDescriptors::STATUS, WorkspaceToolDescriptors::DIFF, WorkspaceToolDescriptors::LOG, WorkspaceToolDescriptors::LIST_FILES, WorkspaceToolDescriptors::READ_FILE] as $toolId) {
			$result = $this->toolset()->invoke(toolId: $toolId, arguments: ['path' => 'lib/A.php']);
			self::assertSame(WorkspaceException::WORKSPACE_ABSENT, $result['error']['code'] ?? null, $toolId);
		}

		$this->assertNoWorkspaceDirectory();
	}//end testAFileOrGitToolBeforeOpenIsRefusedAsAbsent()

	public function testOutsideAGovernedRunEveryToolIsRefused(): void {
		$result = $this->toolset()->invoke(toolId: WorkspaceToolDescriptors::OPEN, arguments: ['repository' => 'example-org/example-app', 'ref' => 'development']);

		self::assertSame(WorkspaceException::TOKEN_INVALID, $result['error']['code'] ?? null);
		$this->assertNoWorkspaceDirectory();
	}//end testOutsideAGovernedRunEveryToolIsRefused()

	public function testAPolicyDenialIsEgressDeniedNotATransportFailure(): void {
		$this->egressAllowed = false;
		$this->scope->enter(runId: 'run-a', agentId: 'agent-1', userId: 'alice');
		$result = $this->toolset()->invoke(toolId: WorkspaceToolDescriptors::OPEN, arguments: ['repository' => 'example-org/example-app', 'ref' => 'development']);

		self::assertSame(WorkspaceException::EGRESS_DENIED, $result['error']['code'] ?? null);
		$this->assertNoWorkspaceDirectory();
	}//end testAPolicyDenialIsEgressDeniedNotATransportFailure()

	public function testOneRunCannotAddressAnotherRunsWorkspace(): void {
		$toolset = $this->toolset();
		$this->scope->enter(runId: 'run-b', agentId: 'agent-1', userId: 'bob');
		$other = $toolset->invoke(toolId: WorkspaceToolDescriptors::OPEN, arguments: ['repository' => 'example-org/example-app', 'ref' => 'development']);
		file_put_contents($this->provider->root(runKey: 'run-b') . '/only-b.txt', 'b');

		$this->scope->enter(runId: 'run-a', agentId: 'agent-1', userId: 'alice');
		$refused = $toolset->invoke(toolId: WorkspaceToolDescriptors::READ_FILE, arguments: ['workspaceId' => $other['workspaceId'], 'path' => 'only-b.txt']);
		self::assertSame(WorkspaceException::WORKSPACE_MISMATCH, $refused['error']['code'] ?? null);

		$toolset->invoke(toolId: WorkspaceToolDescriptors::OPEN, arguments: ['repository' => 'example-org/example-app', 'ref' => 'development']);
		$own = $toolset->invoke(toolId: WorkspaceToolDescriptors::READ_FILE, arguments: ['path' => 'only-b.txt']);
		self::assertSame(WorkspaceException::INVALID_ARGUMENT, $own['error']['code'] ?? null, 'Run a sees its own workspace, which has no only-b.txt.');
	}//end testOneRunCannotAddressAnotherRunsWorkspace()

	public function testTheReadToolsWorkOnAnOpenWorkspaceAndNameNoPath(): void {
		$this->scope->enter(runId: 'run-a', agentId: 'agent-1', userId: 'alice');
		$toolset = $this->toolset();
		$opened = $toolset->invoke(toolId: WorkspaceToolDescriptors::OPEN, arguments: ['repository' => 'example-org/example-app', 'ref' => 'development']);
		self::assertSame(2, $opened['fileCount']);

		$read = $toolset->invoke(toolId: WorkspaceToolDescriptors::READ_FILE, arguments: ['path' => 'lib/A.php']);
		self::assertSame("<?php\n", $read['content']);

		$listed = $toolset->invoke(toolId: WorkspaceToolDescriptors::LIST_FILES, arguments: []);
		self::assertSame(['README.md', 'lib/A.php'], $listed['files']);

		file_put_contents($this->provider->root(runKey: 'run-a') . '/lib/A.php', "<?php // changed\n");
		$status = $toolset->invoke(toolId: WorkspaceToolDescriptors::STATUS, arguments: []);
		self::assertSame('development', $status['branch']);
		self::assertSame([['status' => 'M', 'path' => 'lib/A.php']], $status['files']);

		$diff = $toolset->invoke(toolId: WorkspaceToolDescriptors::DIFF, arguments: ['path' => 'lib/A.php']);
		self::assertStringContainsString('+<?php // changed', $diff['diff']);

		$log = $toolset->invoke(toolId: WorkspaceToolDescriptors::LOG, arguments: ['limit' => 5]);
		self::assertSame('first commit', $log['commits'][0]['subject']);

		$everything = (string)json_encode([$opened, $read, $listed, $status, $diff, $log]);
		self::assertStringNotContainsString($this->base, $everything);
	}//end testTheReadToolsWorkOnAnOpenWorkspaceAndNameNoPath()

	public function testAReadThroughATraversalIsRefused(): void {
		$this->scope->enter(runId: 'run-a', agentId: 'agent-1', userId: 'alice');
		$toolset = $this->toolset();
		$toolset->invoke(toolId: WorkspaceToolDescriptors::OPEN, arguments: ['repository' => 'example-org/example-app', 'ref' => 'development']);

		$result = $toolset->invoke(toolId: WorkspaceToolDescriptors::READ_FILE, arguments: ['path' => '../../forge/example-org/example-app.git/README.md']);
		self::assertSame(WorkspaceException::PATH_OUTSIDE_WORKSPACE, $result['error']['code'] ?? null);
	}//end testAReadThroughATraversalIsRefused()

	/**
	 * The toolset under test, over real collaborators.
	 */
	private function toolset(): WorkspaceToolset {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => ($key === 'secret' ? 'test-secret' : $default));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === 'workspace_forge_base_url' ? 'file://' . $this->base . '/forge' : $default)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);

		$guard = $this->createMock(WebResearchEgressGuard::class);
		$guard->method('assertSafe')->willReturnCallback(fn (): array => ['allowed' => $this->egressAllowed, 'code' => null, 'message' => null]);
		$settings = $this->createMock(WebResearchSettingsHandler::class);
		$settings->method('getWebResearchSettingsOnly')->willReturn(['fetchAllowlist' => [], 'fetchDenylist' => []]);

		$git = new GitRunner();
		$this->provider = new ServerSideWorkspaceProvider(config: $config, appConfig: $appConfig, git: $git, time: $time, baseDir: $this->base . '/workspaces');

		return new WorkspaceToolset(
			scope: $this->scope,
			provider: $this->provider,
			guard: new WorkspacePathGuard(),
			forge: new ForgeLocator(appConfig: $appConfig, guard: $guard, settings: $settings),
			git: $git,
			editor: new WorkspaceEditor(provider: $this->provider, guard: new WorkspacePathGuard(), git: $git, userManager: $this->createMock(IUserManager::class)),
			authoriser: new WorkspaceWriteAuthoriser(approvals: $this->createMock(ApprovalService::class)),
			pusher: $this->createMock(WorkspacePusher::class),
			auditor: $this->createMock(WorkspaceAuditor::class)
		);
	}//end toolset()

	/**
	 * Assert no workspace was created on disk.
	 */
	private function assertNoWorkspaceDirectory(): void {
		$entries = is_dir($this->base . '/workspaces') ? array_diff((array)scandir($this->base . '/workspaces'), ['.', '..']) : [];
		self::assertSame([], array_values($entries));
	}//end assertNoWorkspaceDirectory()

	/**
	 * Run git in a directory.
	 */
	private static function git(string $dir, string ...$args): void {
		$command = 'git -C ' . escapeshellarg($dir);
		foreach ($args as $arg) {
			$command .= ' ' . escapeshellarg($arg);
		}

		exec($command . ' 2>&1', $out, $code);
		self::assertSame(0, $code, implode("\n", $out));
	}//end git()
}//end class
