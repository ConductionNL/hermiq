<?php

/**
 * Contract of KnowledgeBaseService
 *
 * The public surface of KnowledgeBaseService that other components call, so they depend
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of KnowledgeBaseService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KnowledgeBaseServiceInterface {

	/**
	 * Write the uploads into the knowledge base's folder and add each as a `file` source.
	 *
	 * @param string                           $id        The knowledge base uuid.
	 * @param string                           $callerUid The person uploading; must own the knowledge base.
	 * @param array<int, array<string, mixed>> $uploads   PHP upload slots (name, tmp_name, size, error).
	 *
	 * @return array<int, array<string, mixed>> The knowledge base's sources after the upload.
	 *
	 * @throws \OCA\Hermiq\Service\KnowledgeBase\KnowledgeBaseException 404 when the caller does not own it, 400 for an unusable upload.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	public function upload(string $id, string $callerUid, array $uploads): array;

	/**
	 * The knowledge base when the caller owns it.
	 *
	 * @param string $id        The knowledge base uuid.
	 * @param string $callerUid The caller.
	 *
	 * @return ObjectEntity
	 *
	 * @throws \OCA\Hermiq\Service\KnowledgeBase\KnowledgeBaseException 404 when it is missing or someone else's.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	public function owned(string $id, string $callerUid): ObjectEntity;
}//end interface
