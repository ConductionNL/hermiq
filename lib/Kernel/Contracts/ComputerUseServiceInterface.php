<?php

/**
 * Contract of ComputerUseService
 *
 * The public surface of ComputerUseService that other components call, so they depend
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

/**
 * Contract of ComputerUseService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComputerUseServiceInterface {

	/**
	 * Run one computer tool for the person, after the AI feature gate.
	 *
	 * Never throws: a refusal is the `['error' => ['code', 'message']]` envelope.
	 *
	 * @param string               $uid       The person the run is for.
	 * @param string               $toolId    One of ComputerToolDescriptors::IDS.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed> The screenshot the machine answered with, or an error envelope.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-computer-use-is-a-high-risk-ai-feature-that-a-grant-alone-cannot-unlock-req-computer-002
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array;
}//end interface
