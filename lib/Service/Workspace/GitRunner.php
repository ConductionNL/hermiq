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
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * A hardened, shell-free git process runner.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
 */
class GitRunner {

	/**
	 * Output kept per stream; the rest is dropped so a huge diff cannot hold memory.
	 *
	 * @var int
	 */
	private const MAX_OUTPUT_BYTES = 4194304;

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
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
	 */
	public function run(array $arguments, ?string $workingDir, int $timeoutSeconds, array $extraEnv = [], ?string $stdin = null): array {
		$home = sys_get_temp_dir() . '/hermiq-git-home-' . bin2hex(random_bytes(6));
		mkdir($home, 0700, true);

		$env = array_merge(
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

		$command = array_merge(['git'], self::HARDENING, $arguments);

		try {
			return $this->spawn(command: $command, workingDir: $workingDir, env: $env, timeoutSeconds: $timeoutSeconds, stdin: $stdin);
		} finally {
			if (is_dir($home) === true) {
				rmdir($home);
			}
		}
	}//end run()

	/**
	 * Start the process and collect its output within the budget.
	 *
	 * @param array<int, string>    $command        The argv.
	 * @param string|null           $workingDir     The cwd.
	 * @param array<string, string> $env            The environment.
	 * @param int                   $timeoutSeconds The budget.
	 * @param string|null           $stdin          Input, or null.
	 *
	 * @return array{exit: int, stdout: string, stderr: string}
	 *
	 * @throws WorkspaceException
	 */
	private function spawn(array $command, ?string $workingDir, array $env, int $timeoutSeconds, ?string $stdin): array {
		$pipes = [];
		$process = proc_open(
			$command,
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			$workingDir,
			$env
		);
		if (is_resource($process) === false) {
			throw new WorkspaceException(errorCode: WorkspaceException::GIT_FAILED, message: 'The version control tool could not start.');
		}

		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$deadline = (microtime(true) + $timeoutSeconds);
		while (true) {
			$stdout .= $this->drain(pipe: $pipes[1], have: strlen($stdout));
			$stderr .= $this->drain(pipe: $pipes[2], have: strlen($stderr));
			$status = proc_get_status($process);
			if ($status['running'] === false) {
				$stdout .= $this->drain(pipe: $pipes[1], have: strlen($stdout));
				$stderr .= $this->drain(pipe: $pipes[2], have: strlen($stderr));
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				return ['exit' => (int)$status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
			}

			if (microtime(true) > $deadline) {
				proc_terminate($process, 9);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				throw new WorkspaceException(errorCode: WorkspaceException::TOOL_TIMEOUT, message: 'The operation took too long and was stopped.');
			}

			usleep(10000);
		}//end while
	}//end spawn()

	/**
	 * Read what a non-blocking pipe has, up to the output cap.
	 *
	 * @param resource $pipe The pipe.
	 * @param int      $have Bytes already collected.
	 *
	 * @return string
	 */
	private function drain($pipe, int $have): string {
		$chunk = (string)stream_get_contents($pipe);
		$room = (self::MAX_OUTPUT_BYTES - $have);
		if ($room <= 0) {
			return '';
		}

		return substr($chunk, 0, $room);
	}//end drain()
}//end class
