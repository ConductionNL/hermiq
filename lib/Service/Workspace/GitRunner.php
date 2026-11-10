<?php

/**
 * Hermiq GitRunner.
 *
 * Runs one git command for a governed workspace tool. Arguments are an array
 * handed to proc_open, never a shell string, so no argument is ever parsed by a
 * shell. Every run is hardened the same way:
 *
 * - no system or global git configuration (GIT_CONFIG_NOSYSTEM, an empty
 *   GIT_CONFIG_GLOBAL and a throwaway HOME), so nothing outside the workspace
 *   configures the command;
 * - hooks, fsmonitor, pager, editor, askpass and an ssh command are switched
 *   off on the command line, which outranks the repository's own config, so a
 *   file inside the workspace cannot make a git command run a program;
 * - no terminal prompt, and a wall-clock budget after which the process is
 *   killed and the call answers `tool_timeout`.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * A hardened, shell-free git process runner.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
 */
class GitRunner {

	/**
	 * Options that outrank any repository-local configuration.
	 *
	 * @var array<int, string>
	 */
	private const HARDENING = [
		'-c', 'core.hooksPath=/dev/null',
		'-c', 'core.fsmonitor=false',
		'-c', 'core.pager=cat',
		'-c', 'core.editor=true',
		'-c', 'core.askPass=',
		'-c', 'core.sshCommand=false',
		'-c', 'credential.helper=',
		'-c', 'protocol.file.allow=user',
		'-c', 'protocol.ext.allow=never',
		'-c', 'submodule.recurse=false',
	];

	/**
	 * Run git with the given arguments.
	 *
	 * @param array<int, string>    $arguments      The git arguments (after the hardening options).
	 * @param string|null           $workingDir     The directory to run in, or null.
	 * @param int                   $timeoutSeconds The wall-clock budget.
	 * @param array<string, string> $extraEnv       Extra environment (identity, never a secret in argv).
	 * @param string|null           $stdin          Input for the process, or null.
	 *
	 * @return array{exit: int, stdout: string, stderr: string}
	 *
	 * @throws WorkspaceException tool_timeout when the budget runs out, git_failed when git cannot start.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
	 */
	public function run(array $arguments, ?string $workingDir, int $timeoutSeconds, array $extraEnv = [], ?string $stdin = null): array {
		$command = new GitCommand(arguments: $arguments, timeoutSeconds: $timeoutSeconds);
		$command->workingDir = $workingDir;
		$command->stdin = $stdin;

		$home = $this->createHome();
		try {
			return $this->spawn(command: $command, env: $this->environment(home: $home, extraEnv: $extraEnv));
		} finally {
			$this->removeHome(home: $home);
		}
	}//end run()

	/**
	 * A throwaway HOME for one run, so no user-level git configuration applies.
	 *
	 * @return string The directory, created with mode 0700.
	 */
	private function createHome(): string {
		$home = sys_get_temp_dir() . '/hermiq-git-home-' . bin2hex(random_bytes(6));
		mkdir($home, 0700, true);
		return $home;
	}//end createHome()

	/**
	 * Remove the throwaway HOME, when it is still there.
	 *
	 * @param string $home The directory.
	 *
	 * @return void
	 */
	private function removeHome(string $home): void {
		if (is_dir($home) === true) {
			rmdir($home);
		}
	}//end removeHome()

	/**
	 * The process environment: a fixed, prompt-free base, then the extra variables on top.
	 *
	 * @param string                $home     The throwaway HOME.
	 * @param array<string, string> $extraEnv Extra environment (identity, never a secret in argv).
	 *
	 * @return array<string, string>
	 */
	private function environment(string $home, array $extraEnv): array {
		return array_merge(
			[
				'PATH' => '/usr/local/bin:/usr/bin:/bin',
				'HOME' => $home,
				'GIT_CONFIG_NOSYSTEM' => '1',
				'GIT_CONFIG_GLOBAL' => '/dev/null',
				'GIT_TERMINAL_PROMPT' => '0',
				'GIT_ASKPASS' => '',
				'SSH_ASKPASS' => '',
				'GIT_PAGER' => 'cat',
				'LC_ALL' => 'C',
			],
			$extraEnv
		);
	}//end environment()

	/**
	 * Start the process and collect its output within the budget.
	 *
	 * @param GitCommand            $command The invocation.
	 * @param array<string, string> $env     The environment.
	 *
	 * @return array{exit: int, stdout: string, stderr: string}
	 *
	 * @throws WorkspaceException git_failed when git cannot start, tool_timeout when the budget runs out.
	 */
	private function spawn(GitCommand $command, array $env): array {
		$pipes = [];
		$process = proc_open(
			array_merge(['git'], self::HARDENING, $command->arguments),
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			$command->workingDir,
			$env
		);
		if (is_resource($process) === false) {
			throw new WorkspaceException(errorCode: WorkspaceException::GIT_FAILED, message: 'The version control tool could not start.');
		}

		$running = new GitProcess(process: $process, pipes: $pipes);
		$running->feed(stdin: $command->stdin);
		return $running->collect(deadline: (microtime(true) + $command->timeoutSeconds));
	}//end spawn()
}//end class
