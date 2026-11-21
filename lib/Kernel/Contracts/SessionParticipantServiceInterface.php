<?php

/**
 * Contract of SessionParticipantService
 *
 * The public surface of SessionParticipantService that other components call, so they depend
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

use OCA\Hermiq\Service\SessionParticipantException;

/**
 * Contract of SessionParticipantService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SessionParticipantServiceInterface {

	/**
	 * Add a colleague to a session the caller owns, and notify them.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $callerUid The caller.
	 * @param string $uid The colleague to add.
	 *
	 * @return array<int, array{uid: string, displayName: string}> The participants after the change.
	 *
	 * @throws SessionParticipantException 404 not the owner, 409 Talk-bound, 400 owner or unknown uid.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function add(string $uuid, string $callerUid, string $uid): array;

	/**
	 * The participant list of a session the caller owns.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $callerUid The caller.
	 *
	 * @return array<int, array{uid: string, displayName: string}> The participants.
	 *
	 * @throws SessionParticipantException 404 when the caller does not own the session.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function list(string $uuid, string $callerUid): array;

	/**
	 * Take a colleague off a session the caller owns.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $callerUid The caller.
	 * @param string $uid The colleague to remove.
	 *
	 * @return array<int, array{uid: string, displayName: string}> The participants after the change.
	 *
	 * @throws SessionParticipantException 404 not the owner, 409 Talk-bound.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function remove(string $uuid, string $callerUid, string $uid): array;
}//end interface
