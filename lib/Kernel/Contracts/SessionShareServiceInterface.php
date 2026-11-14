<?php

/**
 * Contract of SessionShareService
 *
 * The public surface of SessionShareService that other components call, so they depend
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

use OCA\Hermiq\Service\SessionShareException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of SessionShareService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SessionShareServiceInterface {

	/**
	 * The session's shares, for its owner.
	 *
	 * @param string $uuid      The session uuid.
	 * @param string $callerUid The caller.
	 *
	 * @return list<array<string, mixed>> The shares with display names.
	 *
	 * @throws SessionShareException 404 when the caller does not own the session.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
	 */
	public function list(string $uuid, string $callerUid): array;

	/**
	 * Share the session read-only with a user or a group.
	 *
	 * @param string $uuid      The session uuid.
	 * @param string $callerUid The caller, who must own the session.
	 * @param string $type      `user` or `group`.
	 * @param string $id        The user id or group id.
	 *
	 * @return list<array<string, mixed>> The shares after the change.
	 *
	 * @throws SessionShareException 404, 400 or 403.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
	 */
	public function add(string $uuid, string $callerUid, string $type, string $id): array;

	/**
	 * Take a share back.
	 *
	 * @param string $uuid      The session uuid.
	 * @param string $callerUid The caller, who must own the session.
	 * @param string $type      `user` or `group`.
	 * @param string $id        The user id or group id.
	 *
	 * @return list<array<string, mixed>> The shares after the change.
	 *
	 * @throws SessionShareException 404 when the caller does not own the session.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-revoke-a-share-at-once-req-sshare-003
	 */
	public function remove(string $uuid, string $callerUid, string $type, string $id): array;

	/**
	 * The share that lets the user read the session, or null when none does.
	 *
	 * @param array<string, mixed> $sessionData The session's data.
	 * @param string               $uid         The reader.
	 *
	 * @return array<string, mixed>|null The share entry.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
	 */
	public function shareFor(array $sessionData, string $uid): ?array;

	/**
	 * The session when the user may read it through a share, else null.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $uid  The reader.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
	 */
	public function sharedSession(string $uuid, string $uid): ?ObjectEntity;

	/**
	 * The sessions shared with the user, directly or through a group.
	 *
	 * @param string $uid The reader.
	 *
	 * @return list<ObjectEntity>
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
	 */
	public function sharedWith(string $uid): array;
}//end interface
