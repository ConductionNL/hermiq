<?php

/**
 * Contract of AgentArtefactMarker
 *
 * The part of AgentArtefactMarker that callers outside its component use, so they depend on this contract instead of the implementation.
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
 * Contract of AgentArtefactMarker.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgentArtefactMarkerInterface {

	/**
	 * Mark a file an agent wrote as agent-authored.
	 *
	 * Throws on failure by design: the caller fails the write rather than report
	 * success on an unmarked artefact.
	 *
	 * @param int $fileId The file id to mark.
	 *
	 * @return void
	 *
	 * @throws \OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException When the tag cannot be resolved, created or assigned.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function markFile(int $fileId): void;

	/**
	 * Whether a file already carries the agent-authored mark.
	 *
	 * Read-only probe used by tests and by callers that must not double-assign;
	 * never used to skip marking, since assigning an already-assigned tag is a
	 * no-op in Nextcloud.
	 *
	 * @param int $fileId The file id to check.
	 *
	 * @return bool True when the mark is present.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-every-object-an-agent-writes-is-marked-as-agent-authored-req-ailbl-001
	 */
	public function isFileMarked(int $fileId): bool;

	/**
	 * Mark an agent-authored file as reviewed by a person.
	 *
	 * The caller has already checked that the person may read the file and that it
	 * carries the agent-authored tag; the agent-authored tag is left in place.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return void
	 *
	 * @throws \OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException When the reviewed tag cannot be resolved or assigned.
	 *
	 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
	 */
	public function markReviewed(int $fileId): void;
}//end interface
