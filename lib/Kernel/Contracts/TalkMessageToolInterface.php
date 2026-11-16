<?php

/**
 * Contract of TalkMessageTool
 *
 * The public surface of TalkMessageTool that the tool provider calls, so it depends
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
 * Contract of TalkMessageTool.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkMessageToolInterface {

	/**
	 * Run the Talk message tool for the acting user; never throws.
	 *
	 * @param string               $uid       The acting user's uid.
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The arguments, with the run-injected `agentId`.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/changes/tools-post-a-talk-message/specs/nc-native-tools/spec.md#requirement-an-agent-can-post-a-message-in-a-talk-conversation-it-is-a-member-of-req-nctalk-001
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array;
}//end interface
