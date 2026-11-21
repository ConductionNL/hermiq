<?php

/**
 * Contract of SourceSharingInspector
 *
 * The public surface of SourceSharingInspector that other components call, so they depend
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
 * Contract of SourceSharingInspector.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SourceSharingInspectorInterface {

	/**
	 * The knowledge base's sources, each `file` and `folder` one marked with `onlyYou` and `missing`.
	 *
	 * @param array<string, mixed> $knowledgeBase The knowledge base.
	 * @param string               $ownerUid      The owner, the person on the detail page.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003
	 */
	public function inspect(array $knowledgeBase, string $ownerUid): array;
}//end interface
