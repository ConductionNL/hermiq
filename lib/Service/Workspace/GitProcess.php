<?php

/**
 * Hermiq GitProcess.
 *
 * One started git process: feeding its input and collecting both output streams
 * within the budget, capped per stream.
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
 * A started git process and what it has written so far.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
 */
final class GitProcess {

	/**
	 * Output kept per stream; the rest is dropped so a huge diff cannot hold memory.
	 *
	 * @var int
	 */
	private const MAX_OUTPUT_BYTES = 4194304;

	/**
	 * What the process wrote to stdout so far.
	 *
	 * @var string
	 */
	private string $stdout = '';

	/**
	 * What the process wrote to stderr so far.
	 *
	 * @var string
	 */
	private string $stderr = '';

	/**
	 * Wrap a started process.
	 *
	 * @param resource              $process The `proc_open()` handle.
	 * @param array<int, resource> $pipes   Its stdin, stdout and stderr pipes.
	 */
	public function __construct(
		private readonly mixed $process,
		private readonly array $pipes,
	) {
	}//end __construct()

	/**
	 * Write the input, if any, close stdin and make both output pipes non-blocking.
	 *
	 * @param string|null $stdin Input for the process, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
	 */
	public function feed(?string $stdin): void {
		if ($stdin !== null) {
			fwrite($this->pipes[0], $stdin);
		}

		fclose($this->pipes[0]);
		stream_set_blocking($this->pipes[1], false);
		stream_set_blocking($this->pipes[2], false);
	}//end feed()

	/**
	 * Collect output until the process ends, or stop it once the deadline passes.
	 *
	 * @param float $deadline The `microtime(true)` moment the budget runs out.
	 *
	 * @return array{exit: int, stdout: string, stderr: string}
	 *
	 * @throws WorkspaceException tool_timeout when the deadline passes first.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
	 */
	public function collect(float $deadline): array {
		while (true) {
			$this->drainBoth();
			$status = proc_get_status($this->process);
			if ($status['running'] === false) {
				return $this->finish(exitCode: (int)$status['exitcode']);
			}

			if (microtime(true) > $deadline) {
				$this->stop();
			}

			usleep(10000);
		}
	}//end collect()

	/**
	 * Take the last output, close the process and return the result.
	 *
	 * @param int $exitCode The process's exit code.
	 *
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private function finish(int $exitCode): array {
		$this->drainBoth();
		$this->close();
		return ['exit' => $exitCode, 'stdout' => $this->stdout, 'stderr' => $this->stderr];
	}//end finish()

	/**
	 * Kill the process, close it and report the timeout.
	 *
	 * @return never
	 *
	 * @throws WorkspaceException tool_timeout, always.
	 */
	private function stop(): never {
		proc_terminate($this->process, 9);
		$this->close();
		throw new WorkspaceException(errorCode: WorkspaceException::TOOL_TIMEOUT, message: 'The operation took too long and was stopped.');
	}//end stop()

	/**
	 * Close both output pipes, then the process.
	 *
	 * @return void
	 */
	private function close(): void {
		fclose($this->pipes[1]);
		fclose($this->pipes[2]);
		proc_close($this->process);
	}//end close()

	/**
	 * Append what each output pipe has now.
	 *
	 * @return void
	 */
	private function drainBoth(): void {
		$this->stdout .= $this->drain(pipe: $this->pipes[1], have: strlen($this->stdout));
		$this->stderr .= $this->drain(pipe: $this->pipes[2], have: strlen($this->stderr));
	}//end drainBoth()

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
