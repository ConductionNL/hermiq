<?php

/**
 * Contract of StageDispatchService
 *
 * The public surface of StageDispatchService that other components call, so they depend
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
 * Contract of StageDispatchService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StageDispatchServiceInterface {

	/**
	 * Run one stage, as `dispatch()` describes, from one request.
	 *
	 * @param StageRequest $request The stage.
	 *
	 * @return array{exitCode: int, output: string, ref: string, files?: array} The stage result.
	 *
	 * @throws RuntimeException When the stage could not be run.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AppAPI and the optional OpenRegister broker are
	 *   named by class-string and resolved through the injected container; see dispatch().
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md#requirement-a-command-that-ran-and-failed-is-data-not-a-step-failure
	 */
	public function dispatchStage(StageRequest $request): array;
}//end interface
