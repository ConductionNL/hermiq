<?php

/**
 * Contract of AgentContextBuilder
 *
 * The public surface of AgentContextBuilder that other components call, so they depend
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
 * Contract of AgentContextBuilder.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgentContextBuilderInterface {

	/**
	 * Build the bounded context from an object's data and its schema configuration.
	 *
	 * @param array<string,mixed> $objectData The object's data (its
	 *                                        `jsonSerialize()` output or `getObject()`).
	 * @param array<string,mixed> $schemaConfiguration The target schema's configuration array
	 *                                                 (`Schema::getConfiguration()`), from which
	 *                                                 the `x-openregister-agent-context` allowlist
	 *                                                 is read. Anything else in it is ignored.
	 *
	 * @return array<string,mixed> The bounded context — ONLY allowlisted properties present on the
	 *                             instance, capped where declared. Empty when the allowlist is
	 *                             absent or empty (fail-closed).
	 *
	 * @spec openspec/changes/archive/2026-09-06-hermiq-agent-leaf/tasks.md#task-3-1
	 */
	public function build(array $objectData, array $schemaConfiguration): array;
}//end interface
