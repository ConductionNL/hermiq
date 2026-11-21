<?php

/**
 * Contract of ImageGenerationService
 *
 * The public surface of ImageGenerationService that other components call, so they depend
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

use OCA\Hermiq\Service\Chat\ImageGenerationException;

/**
 * Contract of ImageGenerationService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ImageGenerationServiceInterface {

	/**
	 * A created image as a turn attachment reference, never its bytes.
	 *
	 * @param array<string, mixed> $created What create() returned.
	 *
	 * @return array<string, mixed> The fileId, name, mimeType, size and origin `generated`.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-shows-in-the-answer-req-cimg-004
	 */
	public function asAttachment(array $created): array;

	/**
	 * Whether the chat action can be offered: the feature is enabled and a provider is available.
	 *
	 * @param string $uid The person.
	 *
	 * @return bool True when "Create an image" may be shown.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-images-are-created-through-nextcloud-taskprocessing-req-cimg-001
	 */
	public function canCreate(string $uid): bool;

	/**
	 * Create one image for the person, saved in their Files and marked.
	 *
	 * @param string $uid The person.
	 * @param string $prompt What the image shows.
	 *
	 * @return array{fileId: int, name: string, path: string, mimeType: string} The saved file.
	 *
	 * @throws ImageGenerationException When the feature is off, no provider runs, the task
	 *                                  fails, or the file cannot be marked.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002
	 */
	public function create(string $uid, string $prompt): array;

	/**
	 * Whether a text-to-image provider is available to the person.
	 *
	 * @param string|null $uid The person, or null for the instance.
	 *
	 * @return bool True when a provider can run TextToImage.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-images-are-created-through-nextcloud-taskprocessing-req-cimg-001
	 */
	public function isAvailable(?string $uid = null): bool;

	/**
	 * The tool entry point: never throws, and never returns the image.
	 *
	 * @param string $uid The session's person.
	 * @param string $toolId The tool id (only hermiq.generateImage).
	 * @param array<string, mixed> $arguments The tool arguments; `agentId` is run-injected.
	 *
	 * @return array<string, mixed> The created file's id, name and path, or an error.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-an-agent-can-create-an-image-only-with-a-grant-req-cimg-003
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array;
}//end interface
