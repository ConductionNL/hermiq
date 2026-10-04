<?php

/**
 * Hermiq ImageGenerationService.
 *
 * Creates an image from a description by running a Nextcloud TaskProcessing
 * text-to-image task for the person (chat-attachments-and-images D7). The PNG is
 * written to their Files under `Hermiq/Generated images/` and tagged "Agent
 * authored" in the same operation; when the tag cannot be applied the file is
 * deleted and the call fails (hydra ADR-088, decisions 5 and 6). No outside image
 * API is called. The feature is the AiFeature `image-generation`, which a DPO has
 * to acknowledge and enable first.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs a text-to-image task for the person and keeps the result in their Files, marked.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-images-are-created-through-nextcloud-taskprocessing-req-cimg-001
 */
class ImageGenerationService {

	/**
	 * The AiFeature that has to be enabled before an image is created.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'image-generation';

	/**
	 * Where created images land, relative to the person's Files root.
	 *
	 * @var string
	 */
	public const FOLDER = 'Hermiq/Generated images';

	/**
	 * Constructor.
	 *
	 * @param IManager            $taskManager Nextcloud TaskProcessing.
	 * @param IRootFolder         $rootFolder  The person's Files and the task's output file.
	 * @param AgentArtefactMarker $marker      Tags the file "Agent authored".
	 * @param AiFeatureService    $features    The AI-feature governance register.
	 * @param LoggerInterface     $logger      Logger.
	 */
	public function __construct(
		private readonly IManager $taskManager,
		private readonly IRootFolder $rootFolder,
		private readonly AgentArtefactMarker $marker,
		private readonly AiFeatureService $features,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a text-to-image provider is available to the person.
	 *
	 * @param string|null $uid The person, or null for the instance.
	 *
	 * @return bool True when a provider can run TextToImage.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-images-are-created-through-nextcloud-taskprocessing-req-cimg-001
	 */
	public function isAvailable(?string $uid = null): bool {
		try {
			return in_array(TextToImage::ID, $this->taskManager->getAvailableTaskTypeIds(false, $uid), true);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ImageGenerationService] The task types could not be read; no image creation offered',
				context: ['error' => $e->getMessage()]
			);
			return false;
		}
	}//end isAvailable()

	/**
	 * The tool entry point: never throws, and never returns the image.
	 *
	 * @param string               $uid       The session's person.
	 * @param string               $toolId    The tool id (only hermiq.generateImage).
	 * @param array<string, mixed> $arguments The tool arguments; `agentId` is run-injected.
	 *
	 * @return array<string, mixed> The created file's id, name and path, or an error.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-an-agent-can-create-an-image-only-with-a-grant-req-cimg-003
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		unset($toolId);
		try {
			$created = $this->create(uid: $uid, prompt: (string)($arguments['prompt'] ?? ''));
		} catch (ImageGenerationException $e) {
			return ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
		}

		return $created + ['agentId' => (string)($arguments['agentId'] ?? '')];
	}//end invoke()

	/**
	 * Create one image for the person, saved in their Files and marked.
	 *
	 * @param string $uid    The person.
	 * @param string $prompt What the image shows.
	 *
	 * @return array{fileId: int, name: string, path: string, mimeType: string} The saved file.
	 *
	 * @throws ImageGenerationException When the feature is off, no provider runs, the task
	 *                                  fails, or the file cannot be marked.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002
	 */
	public function create(string $uid, string $prompt): array {
		if (trim($prompt) === '') {
			throw new ImageGenerationException('invalid_prompt', 'Describe the image to create.');
		}

		if ($this->featureEnabled() === false) {
			throw new ImageGenerationException(
				'feature_not_enabled',
				'Image creation must be enabled as an AI feature before an image can be created.'
			);
		}

		if ($this->isAvailable(uid: $uid) === false) {
			throw new ImageGenerationException('provider_unavailable', 'No text-to-image provider is available.');
		}

		$bytes = $this->runTask(uid: $uid, prompt: $prompt);
		$file = $this->save(uid: $uid, bytes: $bytes);

		try {
			$this->marker->markFile(fileId: (int)$file->getId());
		} catch (ArtefactMarkingFailedException $e) {
			// ADR-088 decision 6: an unmarked artefact is not kept.
			$file->delete();
			throw new ImageGenerationException(
				'marking_failed',
				'The image could not be marked as agent-authored, so it was not kept.'
			);
		}

		return [
			'fileId' => (int)$file->getId(),
			'name' => $file->getName(),
			'path' => self::FOLDER . '/' . $file->getName(),
			'mimeType' => 'image/png',
		];
	}//end create()

	/**
	 * Whether the AiFeature is explicitly enabled.
	 *
	 * @return bool True only when enabled.
	 */
	private function featureEnabled(): bool {
		try {
			$feature = $this->features->findBySlugForGate(slug: self::FEATURE_SLUG);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ImageGenerationService] The feature lookup failed; treated as not enabled',
				context: ['error' => $e->getMessage()]
			);
			return false;
		}

		return ($feature !== null && ($feature->getObject()['enabled'] ?? false) === true);
	}//end featureEnabled()

	/**
	 * Run the task synchronously and return the image bytes.
	 *
	 * @param string $uid    The person the task runs for.
	 * @param string $prompt The description.
	 *
	 * @return string The PNG bytes.
	 *
	 * @throws ImageGenerationException When the task fails or returns no image.
	 */
	private function runTask(string $uid, string $prompt): string {
		$task = new Task(TextToImage::ID, ['input' => $prompt, 'numberOfImages' => 1], Application::APP_ID, $uid);
		try {
			$done = $this->taskManager->runTask($task);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ImageGenerationService] The text-to-image task failed',
				context: ['error' => $e->getMessage()]
			);
			throw new ImageGenerationException('task_failed', 'The image could not be created.');
		}

		$images = ($done->getOutput()['images'] ?? []);
		$first = null;
		if (is_array($images) === true) {
			$first = ($images[0] ?? null);
		}

		$bytes = $this->outputBytes(output: $first);
		if ($done->getStatus() !== Task::STATUS_SUCCESSFUL || $bytes === null || $bytes === '') {
			throw new ImageGenerationException('task_failed', 'The image could not be created.');
		}

		return $bytes;
	}//end runTask()

	/**
	 * The bytes of one task output: a file id Nextcloud stored it under, or the bytes.
	 *
	 * @param mixed $output The output value.
	 *
	 * @return string|null The bytes.
	 */
	private function outputBytes(mixed $output): ?string {
		if (is_string($output) === true) {
			return $output;
		}

		if (is_int($output) === false) {
			return null;
		}

		$node = $this->rootFolder->getFirstNodeById($output);
		if ($node instanceof File === false) {
			return null;
		}

		return $node->getContent();
	}//end outputBytes()

	/**
	 * Write the PNG into the person's `Hermiq/Generated images` folder.
	 *
	 * @param string $uid   The person.
	 * @param string $bytes The PNG bytes.
	 *
	 * @return File The new file.
	 */
	private function save(string $uid, string $bytes): File {
		$folder = $this->rootFolder->getUserFolder($uid);
		foreach (explode('/', self::FOLDER) as $segment) {
			$next = null;
			if ($folder->nodeExists($segment) === true) {
				$next = $folder->get($segment);
			}

			if ($next instanceof Folder === false) {
				$next = $folder->newFolder($segment);
			}

			$folder = $next;
		}

		$name = $folder->getNonExistingName('image-' . gmdate('Y-m-d-His') . '.png');
		return $folder->newFile($name, $bytes);
	}//end save()
}//end class
