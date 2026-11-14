<?php

/**
 * Contract of OutsideAgentGateway
 *
 * The public surface of OutsideAgentGateway that other components call, so they depend
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

use OCA\Hermiq\Service\OutsideAgent\OutsideCallRefusedException;

/**
 * Contract of OutsideAgentGateway.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OutsideAgentGatewayInterface {

	/**
	 * Run one call: gate one here, gate two in the owning app.
	 *
	 * Nothing in this method impersonates anybody. The call runs as whoever is
	 * already authenticated, so the owning app's own authoriser decides, and a
	 * registration cannot reach a case its principal may not.
	 *
	 * @param string $principal The authenticated caller.
	 * @param string $toolId The tool to call.
	 * @param array<string, mixed> $arguments The call arguments, passed through untouched.
	 *
	 * @return array{result: mixed, isError: bool} The owning app's response, narrowed to the registration's fields.
	 *
	 * @throws OutsideCallRefusedException When either gate refuses.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
	 */
	public function call(string $principal, string $toolId, array $arguments): array;

	/**
	 * The registration belonging to one principal, or null when there is none.
	 *
	 * @param string $principal The Nextcloud user the outside agent authenticated as.
	 *
	 * @return array<string, mixed>|null The registration data, or null.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-outside-agent-must-reach-declared-tools-through-a-registration
	 */
	public function registrationFor(string $principal): ?array;
}//end interface
