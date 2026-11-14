<?php

/**
 * Contract of RunTokenService
 *
 * The public surface of RunTokenService that other components call, so they depend
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
 * Contract of RunTokenService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RunTokenServiceInterface {

	/**
	 * Whether this token was genuinely issued by this instance, whatever its state
	 * now: live, consumed or expired (within the recognition window).
	 *
	 * This is NOT an authorisation check, and callers must never treat it as one:
	 * `verify()` is. It answers the throttler's question. A token that fails
	 * `verify()` but `isKnown()` is a legitimate component presenting a spent
	 * credential (a CLI still flushing telemetry after its turn ended, a pooled
	 * process outliving its run), and counting that as a brute-force guess lets
	 * one stale component throttle the egress path for every run on the host.
	 * An attacker cannot reach this branch: it requires the preimage of a stored
	 * digest, which is the token.
	 *
	 * @param string $token The presented bearer token.
	 *
	 * @return bool True when a record for this token exists.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-a-request-without-a-valid-token-is-rejected-before-any-tool-work
	 */
	public function isKnown(string $token): bool;

	/**
	 * Verify a presented token and return its binding, or null when it is missing,
	 * malformed, unknown, expired or already consumed. The comparison is
	 * constant-time; the code path always reaches the `hash_equals()` call rather
	 * than short-circuiting on a fast negative.
	 *
	 * @param string $token The presented bearer token.
	 *
	 * @return array{runId: string, agentId: string, userId: string, conversationId: string}|null
	 *                                                                                            The binding, or null when the token is not valid.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-a-request-without-a-valid-token-is-rejected-before-any-tool-work
	 */
	public function verify(string $token): ?array;
}//end interface
