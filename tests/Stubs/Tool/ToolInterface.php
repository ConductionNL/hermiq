<?php

/**
 * Minimal OpenRegister ToolInterface stub for standalone unit runs and static analysis.
 *
 * Signatures mirrored verbatim from openregister lib/Tool/ToolInterface.php.
 * Registered at TEST TIME only by tests/bootstrap.php and tests/bootstrap-unit.php
 * (`OCA\OpenRegister\` -> tests/Stubs/) and scanned, never executed, by
 * phpstan/psalm. See the note in either bootstrap on why these mappings must not
 * live in composer.json `autoload-dev`.
 *
 * Declaration only, and deliberately so: the registry's behaviour lives in
 * openregister, and a stub carrying any of it would be a second copy of rules
 * this repository does not own. `OCA\Hermiq\Tool\GitHubTool` implements this
 * interface, so without the stub that class cannot even be loaded where
 * OpenRegister is absent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tool;

/**
 * Minimal ToolInterface stub.
 */
interface ToolInterface {
	/**
	 * The tool's unique name.
	 *
	 * @return string The tool name.
	 */
	public function getName(): string;

	/**
	 * What the tool is for, for the model.
	 *
	 * @return string The tool description.
	 */
	public function getDescription(): string;

	/**
	 * The callable function definitions.
	 *
	 * @return array The function definitions.
	 */
	public function getFunctions(): array;

	/**
	 * Execute one function.
	 *
	 * @param string $functionName The function to call.
	 * @param array  $parameters   Its arguments.
	 * @param string|null $userId  The acting user.
	 *
	 * @return array The result.
	 */
	public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array;

	/**
	 * Receive the agent this tool is serving.
	 *
	 * @param \OCA\OpenRegister\Db\Agent|null $agent The agent, or null.
	 *
	 * @return void
	 */
	public function setAgent(?\OCA\OpenRegister\Db\Agent $agent): void;
}//end interface
