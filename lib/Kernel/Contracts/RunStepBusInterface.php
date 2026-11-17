<?php

/**
 * Contract of RunStepBus
 *
 * The public surface of RunStepBus that other components call, so they depend
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
 * Contract of RunStepBus.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RunStepBusInterface {

	/**
	 * A tool call that completed.
	 *
	 * @var string
	 */
	public const OUTCOME_OK = 'ok';

	/**
	 * A tool call that did not complete.
	 *
	 * @var string
	 */
	public const OUTCOME_ERROR = 'error';

	/**
	 * Drop any steps recorded for a conversation.
	 *
	 * @param string $conversationId The conversation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-steps-do-not-outlive-their-turn
	 */
	public function clear(string $conversationId): void;

	/**
	 * Read the steps and clear the bucket, so the next turn starts empty.
	 *
	 * Draining rather than reading is what keeps one turn's steps from being
	 * shown again on the next one.
	 *
	 * @param string $conversationId The conversation.
	 *
	 * @return array<int, array<string, mixed>> The recorded steps, oldest first.
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-steps-do-not-outlive-their-turn
	 */
	public function drain(string $conversationId): array;

	/**
	 * Record one completed tool call against its conversation.
	 *
	 * @param string $conversationId The conversation the run belongs to.
	 * @param string $toolName The tool that ran.
	 * @param array|string $arguments The arguments as the chat may show them; a
	 *                                string when the output filter withheld them.
	 * @param string $result The result payload as the chat may show it.
	 * @param int $durationMs How long it took.
	 * @param string $outcome `ok` or `error`, stored verbatim.
	 *
	 * ⚠️ `$outcome` is the STORED VALUE, not a boolean the method converts. It
	 * was a `bool $ok` that was mapped to exactly these two strings one line
	 * later — a flag argument whose only job was to pick between two constants
	 * the caller could simply name. The stored vocabulary is now the same at the
	 * call site, in this signature, and in the cache.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-cli-turns-tool-calls-are-visible-in-the-chat
	 */
	public function record(
		string $conversationId,
		string $toolName,
		array|string $arguments,
		string $result,
		int $durationMs,
		string $outcome = self::OUTCOME_OK,
	): void;
}//end interface
