<?php

/**
 * Contract of SessionForkService
 *
 * The part of SessionForkService that callers outside its component use, so they depend on this contract instead of the implementation.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of SessionForkService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SessionForkServiceInterface {

	/**
	 * Fork the caller's session at one of its answers.
	 *
	 * @param string $uuid      The source session uuid.
	 * @param string $turnUuid  The assistant turn to fork at.
	 * @param string $callerUid The caller, who must own the source session.
	 *
	 * @return array{session: ObjectEntity, turns: int} The new session and how many turns it holds.
	 *
	 * @throws \OCA\Hermiq\Service\SessionForkException 404 when the caller does not own the session, 400 when the
	 *                              turn is not an answer in it.
	 *
	 * @spec openspec/changes/archive/2026-11-24-chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001
	 */
	public function fork(string $uuid, string $turnUuid, string $callerUid): array;
}//end interface
