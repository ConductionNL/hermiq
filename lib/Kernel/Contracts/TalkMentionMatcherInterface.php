<?php

/**
 * Contract of TalkMentionMatcher
 *
 * The public surface of TalkMentionMatcher that other components call, so they depend
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
 * Contract of TalkMentionMatcher.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkMentionMatcherInterface {

	/**
	 * Whether the text addresses any of the given names.
	 *
	 * @param string $content The DECODED message text — not the raw
	 *                        envelope, which also carries the mention
	 *                        parameters and would match a message that
	 *                        merely quotes the name.
	 * @param array<string> $names Candidate names, most specific first.
	 *
	 * @return bool True when one of the names is addressed.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
	 */
	public function matchesAny(string $content, array $names): bool;

	/**
	 * Whether any rendered mention parameter names one of the targets.
	 *
	 * A rendered mention arrives as a payload parameter rather than literal
	 * text. Bots are NOT a source in spreed's collaborator search, so `@` never
	 * autocompletes an agent and this path fires only when a human's display
	 * name happens to equal the agent's — kept because it costs nothing and
	 * would otherwise be a silent gap if spreed ever starts offering bots.
	 *
	 * @param array $parameters The payload's `object.parameters`.
	 * @param array<string> $names Candidate names.
	 *
	 * @return bool True when a parameter names one of the targets.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
	 */
	public function matchesParameters(array $parameters, array $names): bool;
}//end interface
