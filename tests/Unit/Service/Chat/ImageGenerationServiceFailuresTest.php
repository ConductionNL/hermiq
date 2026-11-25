<?php

/**
 * Hermiq ImageGenerationService: what happens when TaskProcessing or the feature register fails.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Chat
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
 * @spec openspec/specs/image-generation/spec.md#requirement-images-are-created-through-nextcloud-taskprocessing-req-cimg-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\Chat\GeneratedImageFiles;
use OCA\Hermiq\Service\Chat\ImageGenerationException;
use OCA\Hermiq\Service\Chat\ImageGenerationService;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Failures fail closed: no provider, no feature, no image.
 *
 * @covers \OCA\Hermiq\Service\Chat\ImageGenerationService
 * @uses   \OCA\Hermiq\Service\Chat\ImageGenerationException
 * @uses   \OCA\Hermiq\Service\Chat\GeneratedImageFiles
 * @uses   \OCA\Hermiq\Service\NcNative\AgentArtefactMarker
 */
class ImageGenerationServiceFailuresTest extends TestCase {

	/**
	 * The service over a task manager and feature lookup double; Files holds image 501 and never saves.
	 *
	 * @param IManager        $manager  TaskProcessing.
	 * @param AiFeatureLookup $features The feature register.
	 *
	 * @return ImageGenerationService
	 */
	private function service(IManager $manager, AiFeatureLookup $features): ImageGenerationService {
		$root = $this->createMock(IRootFolder::class);
		$output = $this->createMock(File::class);
		$output->method('getContent')->willReturn("\x89PNG-bytes");
		$root->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => ($id === 501 ? $output : null));
		$root->expects($this->never())->method('getUserFolder');

		return new ImageGenerationService(
			taskManager: $manager,
			files: new GeneratedImageFiles(rootFolder: $root),
			marker: new AgentArtefactMarker(
				tagManager: $this->createMock(ISystemTagManager::class),
				tagMapper: $this->createMock(ISystemTagObjectMapper::class),
				logger: new NullLogger()
			),
			features: $features,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * A feature lookup answering an enabled (or disabled) feature.
	 *
	 * @param bool $enabled Whether it is enabled.
	 *
	 * @return AiFeatureLookup
	 */
	private function features(bool $enabled = true): AiFeatureLookup {
		$feature = new ObjectEntity();
		$feature->setObject(['slug' => 'image-generation', 'enabled' => $enabled]);
		$features = $this->createMock(AiFeatureLookup::class);
		$features->method('findBySlugForGate')->willReturn($feature);
		return $features;
	}//end features()

	/**
	 * A task manager with a provider whose runTask() does what $run says.
	 *
	 * @param callable $run Called with the task.
	 *
	 * @return IManager
	 */
	private function manager(callable $run): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn([TextToImage::ID]);
		$manager->method('runTask')->willReturnCallback($run);
		return $manager;
	}//end manager()

	/**
	 * Create and answer the refusal code.
	 *
	 * @param ImageGenerationService $service The service.
	 *
	 * @return string The code.
	 */
	private function refusal(ImageGenerationService $service): string {
		try {
			$service->create(uid: 'alice', prompt: 'A lighthouse at dusk');
		} catch (ImageGenerationException $e) {
			$this->assertSame('The image could not be created.', $e->getMessage());
			return $e->errorCode;
		}

		$this->fail('No refusal');
	}//end refusal()

	/**
	 * Task types that cannot be read mean no provider, so no action is offered.
	 *
	 * @return void
	 */
	public function testUnreadableTaskTypesMeanNoProvider(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willThrowException(new RuntimeException('down'));
		$service = $this->service($manager, $this->features());

		$this->assertFalse($service->isAvailable('alice'));
		$this->assertFalse($service->canCreate('alice'));
	}//end testUnreadableTaskTypesMeanNoProvider()

	/**
	 * A feature lookup that fails is treated as not enabled.
	 *
	 * @return void
	 */
	public function testAFailingFeatureLookupIsNotEnabled(): void {
		$features = $this->createMock(AiFeatureLookup::class);
		$features->method('findBySlugForGate')->willThrowException(new RuntimeException('register down'));
		$service = $this->service($this->manager(static fn (Task $task): Task => $task), $features);

		$this->assertTrue($service->isAvailable('alice'));
		$this->assertFalse($service->canCreate('alice'));
	}//end testAFailingFeatureLookupIsNotEnabled()

	/**
	 * A missing feature is not enabled either.
	 *
	 * @return void
	 */
	public function testAMissingFeatureIsNotEnabled(): void {
		$features = $this->createMock(AiFeatureLookup::class);
		$features->method('findBySlugForGate')->willReturn(null);

		$this->assertFalse($this->service($this->manager(static fn (Task $task): Task => $task), $features)->canCreate('alice'));
	}//end testAMissingFeatureIsNotEnabled()

	/**
	 * A task that throws is refused as task_failed, and the prompt was what the task was given.
	 *
	 * @return void
	 */
	public function testAThrowingTaskIsRefused(): void {
		$given = null;
		$manager = $this->manager(
			static function (Task $task) use (&$given): Task {
				$given = $task->getInput();
				throw new RuntimeException('provider crashed');
			}
		);

		$this->assertSame('task_failed', $this->refusal($this->service($manager, $this->features())));
		$this->assertSame(['input' => 'A lighthouse at dusk', 'numberOfImages' => 1], $given);
	}//end testAThrowingTaskIsRefused()

	/**
	 * A task that did not succeed, or gave no image, is refused and nothing is saved.
	 *
	 * @return void
	 */
	public function testAnUnsuccessfulOrEmptyTaskIsRefused(): void {
		$failed = $this->manager(
			static function (Task $task): Task {
				$task->setStatus(Task::STATUS_FAILED);
				$task->setOutput(['images' => [501]]);
				return $task;
			}
		);
		$empty = $this->manager(
			static function (Task $task): Task {
				$task->setStatus(Task::STATUS_SUCCESSFUL);
				$task->setOutput(['images' => 'not-a-list']);
				return $task;
			}
		);

		$this->assertSame('task_failed', $this->refusal($this->service($failed, $this->features())));
		$this->assertSame('task_failed', $this->refusal($this->service($empty, $this->features())));
	}//end testAnUnsuccessfulOrEmptyTaskIsRefused()
}//end class
