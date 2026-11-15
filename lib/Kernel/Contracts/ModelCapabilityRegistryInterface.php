<?php

/**
 * Contract of ModelCapabilityRegistry
 *
 * The public surface of ModelCapabilityRegistry that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of ModelCapabilityRegistry.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ModelCapabilityRegistryInterface {

	/**
	 * Every declared model with its capabilities, keyed by `provider/model`.
	 *
	 * Stored values outside the declarable set are dropped on read.
	 *
	 * @return array<string, list<string>> The declared map.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function all(): array;

	/**
	 * Declare several models at once: every entry is validated before anything is written.
	 *
	 * Models not named keep what they had.
	 *
	 * @param array<mixed> $declarations Map of `provider/model` to a capability list.
	 *
	 * @return array<string, list<string>> The full declared map after the write.
	 *
	 * @throws InvalidArgumentException When any entry is not valid; nothing is written then.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function declareMany(array $declarations): array;

	/**
	 * Validate a declaration map and return it in canonical form, without writing.
	 *
	 * @param array<mixed> $declarations Map of `provider/model` to a capability list.
	 *
	 * @return array<string, list<string>> The validated map.
	 *
	 * @throws InvalidArgumentException When a key or a capability is not valid.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function normalizeDeclarations(array $declarations): array;
}//end interface
