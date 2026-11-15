<?php

/**
 * Contract of NcNativeWriteService
 *
 * The public surface of NcNativeWriteService that other components call, so they depend
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
 * Contract of NcNativeWriteService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NcNativeWriteServiceInterface {

	/**
	 * Create an event in a calendar the acting user owns and can write to.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 * @param string $agentId The invoking agent id, for the ADR-088 mark.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function createCalendarEvent(string $uid, array $arguments, string $agentId = ''): array;

	/**
	 * Create a note for the acting user.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function createNote(string $uid, array $arguments): array;

	/**
	 * List the acting user's notes.
	 *
	 * @param string $uid The acting user id.
	 *
	 * @return array<string, mixed> The notes, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function listNotes(string $uid): array;

	/**
	 * Update a note the acting user owns.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function updateNote(string $uid, array $arguments): array;

	/**
	 * Create or update a contact in one of the acting user's own address books.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 * @param string $agentId The invoking agent id, for the ADR-088 mark.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function upsertContact(string $uid, array $arguments, string $agentId = ''): array;
}//end interface
