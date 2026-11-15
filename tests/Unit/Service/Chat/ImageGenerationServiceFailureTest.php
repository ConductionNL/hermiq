<?php

/**
 * Tests for the ways image creation fails without creating anything.
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
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-images-are-created-through-nextcloud-taskprocessing-req-cimg-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\Chat\GeneratedImageFiles;
use OCA\Hermiq\Service\Chat\ImageGenerationService;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Chat\ImageGenerationService
 * @covers \OCA\Hermiq\Service\Chat\GeneratedImageFiles
 * @uses \OCA\Hermiq\Service\Chat\ImageGenerationException
 * @uses \OCA\Hermiq\Service\NcNative\AgentArtefactMarker
 */
class ImageGenerationServiceFailureTest extends TestCase {

	/**
	 * The warnings logged, as message => context.
	 *
	 * @var list<array{string, array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Files written into the person's folder, by name.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * The service over doubles.
	 *
	 * @param IManager                 $manager  TaskProcessing.
	 * @param ObjectEntity|null|RuntimeException $feature The feature the lookup answers, or what it throws.
	 * @param File|null                $node     What the output id resolves to.
	 *
	 * @return ImageGenerationService The service.
	 */
	private function service(IManager $manager, ObjectEntity|null|RuntimeException $feature, ?File $node = null): ImageGenerationService {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		$features = $this->createMock(AiFeatureLookup::class);
		if ($feature instanceof RuntimeException) {
			$features->method('findBySlugForGate')->willThrowException($feature);
		} else {
			$features->method('findBySlugForGate')->willReturn($feature);
		}

		$created = $this->createMock(File::class);
		$created->method('getId')->willReturn(902);
		$created->method('getName')->willReturn('image-1.png');
		$created->method('getSize')->willReturn(10);
		$leaf = $this->createMock(Folder::class);
		$leaf->method('nodeExists')->willReturn(true);
		$leaf->method('get')->willReturnSelf();
		$leaf->method('getNonExistingName')->willReturnArgument(0);
		$leaf->method('newFile')->willReturnCallback(
			function (string $name, $content) use ($created): File {
				$this->written[$name] = (string)$content;
				return $created;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($leaf);
		$root->method('getFirstNodeById')->willReturn($node);

		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn('12');
		$tags = $this->createMock(ISystemTagManager::class);
		$tags->method('getTag')->willReturn($tag);

		return new ImageGenerationService(
			taskManager: $manager,
			files: new GeneratedImageFiles(rootFolder: $root),
			marker: new AgentArtefactMarker(tagManager: $tags, tagMapper: $this->createMock(ISystemTagObjectMapper::class), logger: new NullLogger()),
			features: $features,
			logger: $logger
		);
	}//end service()

	/**
	 * An enabled image-generation feature.
	 *
	 * @return ObjectEntity The feature.
	 */
	private static function enabled(): ObjectEntity {
		$feature = new ObjectEntity();
		$feature->setObject(['slug' => 'image-generation', 'enabled' => true]);
		return $feature;
	}//end enabled()

	/**
	 * A task manager with a provider whose task ends as given.
	 *
	 * @param string $status The status the task ends in.
	 * @param mixed  $image  The first output image.
	 *
	 * @return IManager The manager.
	 */
	private function manager(string|int $status, mixed $image): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn([TextToImage::ID]);
		$manager->method('runTask')->willReturnCallback(
			static function (Task $task) use ($status, $image): Task {
				$task->setStatus($status);
				$task->setOutput(['images' => [$image]]);
				return $task;
			}
		);
		return $manager;
	}//end manager()

	/**
	 * Task types that cannot be read mean no provider, and the reason is logged.
	 *
	 * @return void
	 */
	public function testUnreadableTaskTypesOfferNoImageCreation(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willThrowException(new RuntimeException('task store down'));
		$manager->expects($this->never())->method('runTask');
		$service = $this->service($manager, self::enabled());

		$this->assertFalse($service->isAvailable(uid: 'communicatie'));
		$this->assertFalse($service->canCreate(uid: 'communicatie'));
		$this->assertSame(
			['[ImageGenerationService] The task types could not be read; no image creation offered', ['error' => 'task store down']],
			$this->warnings[0]
		);
	}//end testUnreadableTaskTypesOfferNoImageCreation()

	/**
	 * A feature lookup that fails, or finds nothing, reads as not enabled.
	 *
	 * @return void
	 */
	public function testAFailedOrEmptyFeatureLookupRefuses(): void {
		$manager = $this->manager(Task::STATUS_SUCCESSFUL, 'png');
		$manager->expects($this->never())->method('runTask');

		$failed = $this->service($manager, new RuntimeException('register gone'))->invoke(uid: 'u', toolId: 'hermiq.generateImage', arguments: ['prompt' => 'a map']);
		$missing = $this->service($manager, null)->invoke(uid: 'u', toolId: 'hermiq.generateImage', arguments: ['prompt' => 'a map']);

		$this->assertSame('feature_not_enabled', $failed['error']['code']);
		$this->assertSame('feature_not_enabled', $missing['error']['code']);
		$this->assertSame(['[ImageGenerationService] The feature lookup failed; treated as not enabled', ['error' => 'register gone']], $this->warnings[0]);
		$this->assertSame([], $this->written);
	}//end testAFailedOrEmptyFeatureLookupRefuses()

	/**
	 * A task that throws is a task_failed refusal and nothing is written.
	 *
	 * @return void
	 */
	public function testAThrowingTaskIsRefusedAndLogged(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn([TextToImage::ID]);
		$manager->method('runTask')->willThrowException(new RuntimeException('provider timeout'));

		$result = $this->service($manager, self::enabled())->invoke(uid: 'u', toolId: 'hermiq.generateImage', arguments: ['prompt' => 'a map']);

		$this->assertSame(['code' => 'task_failed', 'message' => 'The image could not be created.'], $result['error']);
		$this->assertSame(['[ImageGenerationService] The text-to-image task failed', ['error' => 'provider timeout']], $this->warnings[0]);
		$this->assertSame([], $this->written);
	}//end testAThrowingTaskIsRefusedAndLogged()

	/**
	 * A task that ends without an image is refused, whatever it returned.
	 *
	 * @return array<string, array{int, mixed, File|null}>
	 */
	public static function noImage(): array {
		return [
			'not successful' => [Task::STATUS_FAILED, 'png-bytes', null],
			'empty bytes' => [Task::STATUS_SUCCESSFUL, '', null],
			'not a file id or bytes' => [Task::STATUS_SUCCESSFUL, ['x'], null],
			'an id that is no file' => [Task::STATUS_SUCCESSFUL, 77, null],
		];
	}//end noImage()

	/**
	 * @dataProvider noImage
	 *
	 * @param int       $status The task status.
	 * @param mixed     $image  The first output image.
	 * @param File|null $node   What the id resolves to.
	 *
	 * @return void
	 */
	public function testATaskWithoutAnImageIsRefused(int $status, mixed $image, ?File $node): void {
		$result = $this->service($this->manager($status, $image), self::enabled(), $node)->invoke(uid: 'u', toolId: 'hermiq.generateImage', arguments: ['prompt' => 'a map']);

		$this->assertSame('task_failed', $result['error']['code']);
		$this->assertSame([], $this->written);
	}//end testATaskWithoutAnImageIsRefused()

	/**
	 * Bytes returned inline are saved as they are.
	 *
	 * @return void
	 */
	public function testInlineBytesAreSaved(): void {
		$result = $this->service($this->manager(Task::STATUS_SUCCESSFUL, "\x89PNG-inline"), self::enabled())->create(uid: 'u', prompt: 'a map');

		$this->assertSame(902, $result['fileId']);
		$this->assertSame(["\x89PNG-inline"], array_values($this->written));
	}//end testInlineBytesAreSaved()
}//end class
