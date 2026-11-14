<?php

/**
 * Contract of AttachmentStore
 *
 * The public surface of AttachmentStore that other components call, so they depend
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

use OCA\Hermiq\Service\Chat\AttachmentRefusedException;
use RuntimeException;

/**
 * Contract of AttachmentStore.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AttachmentStoreInterface {

	/**
	 * Keep one upload in the person's Files.
	 *
	 * @param string $uid The person.
	 * @param array<string, mixed> $upload The PHP upload slot (name, tmp_name, size).
	 *
	 * @return array{path: string, name: string, fileId: int, mimeType: string, size: int}
	 *
	 * @throws AttachmentRefusedException For a refused type or a file over the cap; nothing is written.
	 * @throws RuntimeException When the file could not be read or written.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
	 */
	public function store(string $uid, array $upload): array;
}//end interface
