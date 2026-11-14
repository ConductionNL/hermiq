<?php

/**
 * ServerSideWorkspaceProvider for conversation-long workspaces
 * (tools-agent-workspace-and-installed-tools, task 1).
 *
 * Real git, a real local "forge" repository and a real temporary base
 * directory; only the Nextcloud config and clock are doubles.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Session lifetime, session budget and the end of a session workspace.
 *
 * @covers \OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider
 * @uses \OCA\Hermiq\Service\Workspace\SessionWorkspaceKey
 * @uses \OCA\Hermiq\Service\Workspace\GitRunner
 * @uses \OCA\Hermiq\Service\Workspace\GitCommand
 * @uses \OCA\Hermiq\Service\Workspace\GitProcess
 * @uses \OCA\Hermiq\Service\Workspace\CheckoutRequest
 * @uses \OCA\Hermiq\Service\Workspace\WorkspaceException
 */
final class ServerSideWorkspaceProviderSessionTest extends TestCase {

	private const SESSION = 'session:agent-1:conv-1:alice';

	private const OTHER_SESSION = 'session:agent-1:conv-2:alice';

	private string $base;

	private string $forge;

	private int $now = 1000000;

	/**
	 * @var array<string, int>
	 */
	private array $ints = [];

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/hermiq-wss-' . bin2hex(random_bytes(4));
		$this->forge = $this->base . '/forge/example-app';
		mkdir($this->forge, 0700, true);
		self::git($this->forge, 'init', '-q', '-b', 'development');
		file_put_contents($this->forge . '/README.md', "hello\n");
		mkdir($this->forge . '/lib');
		file_put_contents($this->forge . '/lib/A.php', "<?php\n");
		self::git($this->forge, 'add', '.');
		self::git($this->forge, '-c', 'user.name=Forge', '-c', 'user.email=forge@example.invalid', 'commit', '-q', '-m', 'init');
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
	}//end tearDown()

	private function open(ServerSideWorkspaceProvider $provider, string $key): void {
		$provider->open(runKey: $key, cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);
	}//end open()

	private function meta(ServerSideWorkspaceProvider $provider, string $key): array {
		return (array)json_decode((string)file_get_contents(dirname($provider->root(runKey: $key)) . '/meta.json'), true);
	}//end meta()

	public function testASessionWorkspaceRecordsItsLifetimeAndConversation(): void {
		$provider = $this->provider();
		$this->open(provider: $provider, key: self::SESSION);
		$this->open(provider: $provider, key: 'run-a');

		$session = $this->meta(provider: $provider, key: self::SESSION);
		self::assertSame('session', $session['lifetime']);
		self::assertSame('conv-1', $session['conversationId']);
		self::assertArrayNotHasKey('lifetime', $this->meta(provider: $provider, key: 'run-a'));
	}//end testASessionWorkspaceRecordsItsLifetimeAndConversation()

	public function testAFileFromTheFirstRunIsThereForTheNext(): void {
		$provider = $this->provider();
		$this->open(provider: $provider, key: self::SESSION);
		file_put_contents($provider->root(runKey: self::SESSION) . '/kwartaal-q3.csv', "a,b\n");

		$this->now += 7200;
		$this->open(provider: $provider, key: self::SESSION);

		self::assertSame(0, $provider->reap());
		self::assertFileExists($provider->root(runKey: self::SESSION) . '/kwartaal-q3.csv');
	}//end testAFileFromTheFirstRunIsThereForTheNext()

	public function testASessionEndsFourteenDaysAfterItsLastActivityWhileARunEndsAfterAnHour(): void {
		$provider = $this->provider();
		$this->open(provider: $provider, key: self::SESSION);
		$this->open(provider: $provider, key: 'run-a');

		$this->now += ServerSideWorkspaceProvider::DEFAULT_RETENTION_SECONDS + 1;
		self::assertSame(1, $provider->reap());
		$this->assertAbsent(provider: $provider, runKey: 'run-a');
		self::assertFileExists($provider->root(runKey: self::SESSION) . '/README.md');

		$this->now += ServerSideWorkspaceProvider::DEFAULT_SESSION_RETENTION_SECONDS - 1;
		self::assertSame(0, $provider->reap());

		$this->now += ServerSideWorkspaceProvider::DEFAULT_SESSION_RETENTION_SECONDS + 1;
		self::assertSame(1, $provider->reap());
		$this->assertAbsent(provider: $provider, runKey: self::SESSION);
	}//end testASessionEndsFourteenDaysAfterItsLastActivityWhileARunEndsAfterAnHour()

	public function testTheSessionRetentionIsSetPerInstance(): void {
		$this->ints['workspace_session_retention_seconds'] = 60;
		$provider = $this->provider();
		$this->open(provider: $provider, key: self::SESSION);

		$this->now += 61;
		self::assertSame(1, $provider->reap());
	}//end testTheSessionRetentionIsSetPerInstance()

	public function testOnlyTheWorkspaceOfADeletedConversationEnds(): void {
		$provider = $this->provider();
		$this->open(provider: $provider, key: self::SESSION);
		$this->open(provider: $provider, key: self::OTHER_SESSION);
		$this->open(provider: $provider, key: 'run-a');
		$asked = [];

		$removed = $provider->reapEndedSessions(
			conversationGone: static function (string $conversationId) use (&$asked): bool {
				$asked[] = $conversationId;
				return $conversationId === 'conv-1';
			}
		);

		self::assertSame(1, $removed);
		sort($asked);
		self::assertSame(['conv-1', 'conv-2'], $asked);
		$this->assertAbsent(provider: $provider, runKey: self::SESSION);
		self::assertFileExists($provider->root(runKey: self::OTHER_SESSION) . '/README.md');
		self::assertFileExists($provider->root(runKey: 'run-a') . '/README.md');
	}//end testOnlyTheWorkspaceOfADeletedConversationEnds()

	public function testASessionHasItsOwnFileBudget(): void {
		$this->ints['workspace_session_max_files'] = 1;
		$provider = $this->provider();

		$this->open(provider: $provider, key: 'run-a');
		try {
			$this->open(provider: $provider, key: self::SESSION);
			self::fail('A session tree over its own file budget was accepted.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::WORKSPACE_QUOTA, $e->getErrorCode());
		}
	}//end testASessionHasItsOwnFileBudget()

	public function testTheDefaultSessionBudgetIsFiveThousandFilesWhereARunHasMore(): void {
		$provider = $this->provider();
		$this->open(provider: $provider, key: self::SESSION);
		$this->open(provider: $provider, key: 'run-a');

		// The same tree and the same write: a run may grow to 20,000 files, a
		// conversation-long workspace stops at 5,000.
		$provider->reserve(runKey: 'run-a', addBytes: 1, addFiles: ServerSideWorkspaceProvider::DEFAULT_SESSION_MAX_FILES);
		try {
			$provider->reserve(runKey: self::SESSION, addBytes: 1, addFiles: ServerSideWorkspaceProvider::DEFAULT_SESSION_MAX_FILES);
			self::fail('A session write over 5,000 files was accepted.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::WORKSPACE_QUOTA, $e->getErrorCode());
		}

		$provider->reserve(runKey: self::SESSION, addBytes: 1, addFiles: 1);
		self::assertSame(5000, ServerSideWorkspaceProvider::DEFAULT_SESSION_MAX_FILES);
	}//end testTheDefaultSessionBudgetIsFiveThousandFilesWhereARunHasMore()

	private function provider(): ServerSideWorkspaceProvider {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => ($key === 'secret' ? 'test-secret' : $default));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => ($this->ints[$key] ?? $default));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new ServerSideWorkspaceProvider(config: $config, appConfig: $appConfig, git: new GitRunner(), time: $time, baseDir: $this->base . '/workspaces');
	}//end provider()

	/**
	 * Assert a run has no workspace.
	 */
	private function assertAbsent(ServerSideWorkspaceProvider $provider, string $runKey): void {
		try {
			$provider->root(runKey: $runKey);
			self::fail('A workspace is still reachable.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::WORKSPACE_ABSENT, $e->getErrorCode());
		}
	}//end assertAbsent()

	/**
	 * Run git in a directory and return trimmed stdout.
	 */
	private static function git(string $dir, string ...$args): string {
		$command = 'git -C ' . escapeshellarg($dir);
		foreach ($args as $arg) {
			$command .= ' ' . escapeshellarg($arg);
		}

		exec($command . ' 2>&1', $out, $code);
		self::assertSame(0, $code, implode("\n", $out));
		return trim(implode("\n", $out));
	}//end git()
}//end class
