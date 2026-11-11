<?php

/**
 * GitRunner: the hardened, shell-free git process runner.
 *
 * Runs the real git binary: what is pinned is what the process sees (its
 * arguments, input, environment and directory) and what comes back (exit code,
 * both streams, the timeout).
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use PHPUnit\Framework\TestCase;

/**
 * Process start, input, environment, output, exit code and timeout.
 */
final class GitRunnerTest extends TestCase {

	private string $dir;

	/**
	 * A fresh empty directory per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (is_executable('/usr/bin/git') === false && is_executable('/usr/local/bin/git') === false && is_executable('/bin/git') === false) {
			$this->markTestSkipped('git is not installed.');
		}

		$this->dir = sys_get_temp_dir() . '/hermiq-git-runner-test-' . bin2hex(random_bytes(4));
		mkdir($this->dir, 0700, true);
	}//end setUp()

	/**
	 * Remove the directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (isset($this->dir) === true && is_dir($this->dir) === true) {
			exec('rm -rf ' . escapeshellarg($this->dir));
		}
	}//end tearDown()

	/**
	 * A successful command returns exit 0, its stdout and an empty stderr.
	 *
	 * @return void
	 */
	public function testSuccessReturnsExitZeroAndOutput(): void {
		$result = (new GitRunner())->run(arguments: ['--version'], workingDir: null, timeoutSeconds: 10);

		$this->assertSame(['exit', 'stdout', 'stderr'], array_keys($result));
		$this->assertSame(0, $result['exit']);
		$this->assertStringStartsWith('git version ', $result['stdout']);
		$this->assertSame('', $result['stderr']);
	}//end testSuccessReturnsExitZeroAndOutput()

	/**
	 * Input reaches the process on stdin.
	 *
	 * @return void
	 */
	public function testStdinReachesTheProcess(): void {
		$result = (new GitRunner())->run(arguments: ['hash-object', '--stdin'], workingDir: $this->dir, timeoutSeconds: 10, stdin: "hello\n");

		$this->assertSame(0, $result['exit']);
		$this->assertSame("ce013625030ba8dba906f756967f9e9ca394464a\n", $result['stdout']);
	}//end testStdinReachesTheProcess()

	/**
	 * The process runs in the given directory.
	 *
	 * @return void
	 */
	public function testRunsInTheWorkingDirectory(): void {
		$runner = new GitRunner();
		$this->assertSame(0, $runner->run(arguments: ['init', '-q'], workingDir: $this->dir, timeoutSeconds: 10)['exit']);

		$result = $runner->run(arguments: ['rev-parse', '--show-toplevel'], workingDir: $this->dir, timeoutSeconds: 10);

		$this->assertSame(realpath($this->dir) . "\n", $result['stdout']);
	}//end testRunsInTheWorkingDirectory()

	/**
	 * The fixed environment (locale, no prompt, a throwaway HOME) is set, and extra
	 * variables are added on top of it.
	 *
	 * @return void
	 */
	public function testEnvironmentIsFixedAndExtraVariablesAreAdded(): void {
		$result = (new GitRunner())->run(
			arguments: ['-c', 'alias.env=!env', 'env'],
			workingDir: $this->dir,
			timeoutSeconds: 10,
			extraEnv: ['GIT_AUTHOR_NAME' => 'Agent Name', 'LC_ALL' => 'C.UTF-8']
		);

		$this->assertSame(0, $result['exit']);
		$this->assertStringContainsString("GIT_AUTHOR_NAME=Agent Name\n", $result['stdout']);
		$this->assertStringContainsString("LC_ALL=C.UTF-8\n", $result['stdout'], 'Extra variables override the fixed ones.');
		$this->assertStringContainsString("GIT_TERMINAL_PROMPT=0\n", $result['stdout']);
		$this->assertStringContainsString("GIT_CONFIG_GLOBAL=/dev/null\n", $result['stdout']);
		$this->assertMatchesRegularExpression('#\nHOME=' . preg_quote(sys_get_temp_dir(), '#') . '/hermiq-git-home-[0-9a-f]{12}\n#', "\n" . $result['stdout']);
	}//end testEnvironmentIsFixedAndExtraVariablesAreAdded()

	/**
	 * The throwaway HOME is removed once the process ends.
	 *
	 * @return void
	 */
	public function testThrowawayHomeIsRemoved(): void {
		$result = (new GitRunner())->run(arguments: ['-c', 'alias.home=!printf %s "$HOME"', 'home'], workingDir: $this->dir, timeoutSeconds: 10);

		$this->assertStringStartsWith(sys_get_temp_dir() . '/hermiq-git-home-', $result['stdout']);
		$this->assertDirectoryDoesNotExist($result['stdout']);
	}//end testThrowawayHomeIsRemoved()

	/**
	 * A failing command returns its exit code and stderr, without throwing.
	 *
	 * @return void
	 */
	public function testFailureReturnsExitCodeAndStderr(): void {
		$result = (new GitRunner())->run(arguments: ['rev-parse', 'HEAD'], workingDir: $this->dir, timeoutSeconds: 10);

		$this->assertNotSame(0, $result['exit']);
		$this->assertSame('', $result['stdout']);
		$this->assertStringContainsString('not a git repository', $result['stderr']);
	}//end testFailureReturnsExitCodeAndStderr()

	/**
	 * A process that outlives its budget is stopped with tool_timeout, and the
	 * throwaway HOME is still removed.
	 *
	 * @return void
	 */
	public function testTimeoutStopsTheProcess(): void {
		$before = glob(sys_get_temp_dir() . '/hermiq-git-home-*');
		$started = microtime(true);
		try {
			(new GitRunner())->run(arguments: ['-c', 'alias.slow=!sleep 5', 'slow'], workingDir: $this->dir, timeoutSeconds: 1);
			$this->fail('Expected a timeout.');
		} catch (WorkspaceException $e) {
			$this->assertSame(WorkspaceException::TOOL_TIMEOUT, $e->getErrorCode());
			$this->assertSame('The operation took too long and was stopped.', $e->getMessage());
		}

		$this->assertLessThan(4.0, (microtime(true) - $started));
		$this->assertSame($before, glob(sys_get_temp_dir() . '/hermiq-git-home-*'));
	}//end testTimeoutStopsTheProcess()
}//end class
