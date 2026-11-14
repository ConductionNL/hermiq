<?php

/**
 * Contract of AsyncStageDispatchService
 *
 * The public surface of AsyncStageDispatchService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\Hermiq\Service\StageRequest;
use RuntimeException;

/**
 * Contract of AsyncStageDispatchService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AsyncStageDispatchServiceInterface {

	/**
	 * Collect a started stage by its handle.
	 *
	 * ⚠️ THE THREE TERMINAL ANSWERS ARE NOT INTERCHANGEABLE:
	 *
	 *   done     the stage RAN. `result` carries the exit code, which may be
	 *            non-zero — that is a stage that ran and failed.
	 *   failed   the stage could NOT be carried out. A refused push lands here
	 *            with its stable `code`, and must never be read as a completed
	 *            stage with an unlucky field.
	 *   unknown  this runner has no such job — it restarted, or the result aged
	 *            out. TERMINAL, never "still running": a poller that cannot tell
	 *            those apart waits forever.
	 *
	 * @param string $jobId The handle from `dispatchAsync()`.
	 * @param string|null $uid The acting user's UID.
	 *
	 * @return array{status: string, result?: array, error?: string, code?: string} The job state.
	 *
	 * @throws RuntimeException When the runner cannot be reached or answers nonsense.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Mirrors `dispatch()`; AppAPI is resolved lazily.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function collect(string $jobId, ?string $uid = null): array;

	/**
	 * Start one stage, as `dispatchAsync()` describes, from one request.
	 *
	 * @param StageRequest $request The stage, with its job key when the caller has one.
	 *
	 * @return array{job: array{id: string, status: string}} The handle.
	 *
	 * @throws RuntimeException When the stage could not be started.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function dispatchStageAsync(StageRequest $request): array;
}//end interface
