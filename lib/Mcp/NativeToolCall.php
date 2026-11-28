<?php

/**
 * Hermiq NativeToolCall.
 *
 * One invocation of a hermiq nc-native tool on behalf of the acting user: the
 * authenticated user, their uid, the run-injected authoring agent id and the
 * tool arguments, so each tool handler in HermiqToolProvider takes one value.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCP\IUser;

/**
 * The acting user and arguments of one nc-native tool invocation.
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
 */
final class NativeToolCall {

	/**
	 * The acting user's id.
	 *
	 * @var string
	 */
	public readonly string $uid;

	/**
	 * The authoring agent id FacadeToolInvoker injected for this run ('' when none).
	 *
	 * @var string
	 */
	public readonly string $agentId;

	/**
	 * Build the call for the acting user.
	 *
	 * @param IUser $user The authenticated acting user.
	 * @param array<string, mixed> $arguments The tool arguments.
	 */
	public function __construct(
		public readonly IUser $user,
		public readonly array $arguments,
	) {
		$this->uid = $user->getUID();
		// Run-injected by FacadeToolInvoker for the tools whose ADR-088 mark
		// records an authoring agent; never a value the LLM supplies for itself.
		$this->agentId = (string)($arguments['agentId'] ?? '');
	}//end __construct()

	/**
	 * A string argument, '' when absent.
	 *
	 * @param string $name The argument name.
	 *
	 * @return string The argument cast to string.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
	 */
	public function text(string $name): string {
		return (string)($this->arguments[$name] ?? '');
	}//end text()
}//end class
