<?php

/**
 * Hermiq ImageGenerationService: an image is created through TaskProcessing and kept only when marked.
 *
 * Drives the real service with the real AgentArtefactMarker; TaskProcessing, Files,
 * the system-tag manager and mapper, and the feature register are doubles
 * (chat-attachments-and-images task 7).
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
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\AiFeatureService;
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
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for image creation.
 */
class ImageGenerationServiceTest extends TestCase {

	/**
	 * The tasks handed to runTask().
	 *
	 * @var list<Task>
	 */
	private array $ran = [];

	/**
	 * Files written into the person's folder, by name.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Whether the written file was deleted.
	 *
	 * @var bool
	 */
	private bool $deleted = false;

	/**
	 * The uids whose Files were opened.
	 *
	 * @var list<string>
	 */
	private array $openedAs = [];

	/**
	 * The service over doubles.
	 *
	 * @param bool $provider    Whether a TextToImage provider is available.
	 * @param bool $enabled     Whether the AiFeature is enabled.
	 * @param bool $tagFails    Whether assigning the system tag throws.
	 *
	 * @return ImageGenerationService The service.
	 */
	private function service(bool $provider = true, bool $enabled = true, bool $tagFails = false): ImageGenerationService {
		$manager = $this->createMock(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn($provider === true ? [TextToImage::ID] : ['core:text2text']);
		$manager->method('runTask')->willReturnCallback(
			function (Task $task): Task {
				$this->ran[] = $task;
				$task->setStatus(Task::STATUS_SUCCESSFUL);
				$task->setOutput(['images' => [501]]);
				return $task;
			}
		);

		$output = $this->createMock(File::class);
		$output->method('getContent')->willReturn("\x89PNG-bytes");

		$created = $this->createMock(File::class);
		$created->method('getId')->willReturn(902);
		$created->method('getName')->willReturn('image-1.png');
		$created->method('delete')->willReturnCallback(function (): void {
			$this->deleted = true;
		});

		$leaf = $this->createMock(Folder::class);
		$leaf->method('getNonExistingName')->willReturnArgument(0);
		$leaf->method('newFile')->willReturnCallback(
			function (string $name, $content) use ($created): File {
				$this->written[$name] = (string)$content;
				return $created;
			}
		);
		$middle = $this->createMock(Folder::class);
		$middle->method('nodeExists')->willReturn(false);
		$middle->method('newFolder')->willReturn($leaf);
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->willReturn(true);
		$home->method('get')->willReturn($middle);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => ($id === 501 ? $output : null));
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($home): Folder {
				$this->openedAs[] = $uid;
				return $home;
			}
		);

		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn('12');
		$tags = $this->createMock(ISystemTagManager::class);
		$tags->method('getTag')->willReturn($tag);
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		if ($tagFails === true) {
			$mapper->method('assignTags')->willThrowException(new RuntimeException('tag store down'));
		}

		$feature = new ObjectEntity();
		$feature->setObject(['slug' => 'image-generation', 'enabled' => $enabled]);
		$features = $this->createMock(AiFeatureService::class);
		$features->method('findBySlugForGate')->willReturn($feature);

		return new ImageGenerationService(
			taskManager: $manager,
			files: new GeneratedImageFiles(rootFolder: $root),
			marker: new AgentArtefactMarker(tagManager: $tags, tagMapper: $mapper, logger: new NullLogger()),
			features: $features,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * A granted agent's call saves a PNG in the person's Files, marked, and returns its id, not the image.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#scenario-a-communications-advisor-asks-for-an-illustration
	 */
	public function testAnImageIsSavedInTheirFilesAndTheResultHoldsItsId(): void {
		$result = $this->service()->invoke(
			uid: 'communicatie',
			toolId: 'hermiq.generateImage',
			arguments: ['prompt' => 'Een fietsenstalling bij station Zwolle in de ochtendzon', 'agentId' => 'agent-3']
		);

		$this->assertSame(902, $result['fileId']);
		$this->assertSame('Hermiq/Generated images/image-1.png', $result['path']);
		$this->assertSame('agent-3', $result['agentId']);
		$this->assertStringNotContainsString('PNG-bytes', (string)json_encode($result), 'The result never holds the image.');
		$this->assertSame(["\x89PNG-bytes"], array_values($this->written));
		$this->assertSame(['communicatie'], $this->openedAs);
		$this->assertSame('communicatie', $this->ran[0]->getUserId());
		$this->assertSame(TextToImage::ID, $this->ran[0]->getTaskTypeId());
		$this->assertFalse($this->deleted);
	}//end testAnImageIsSavedInTheirFilesAndTheResultHoldsItsId()

	/**
	 * A failing tag mapper: the file is deleted and the tool returns an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#scenario-a-file-that-cannot-be-marked-is-not-kept
	 */
	public function testAFileThatCannotBeMarkedIsNotKept(): void {
		$result = $this->service(tagFails: true)->invoke(uid: 'communicatie', toolId: 'hermiq.generateImage', arguments: ['prompt' => 'Een kaart']);

		$this->assertSame('marking_failed', $result['error']['code']);
		$this->assertTrue($this->deleted);
	}//end testAFileThatCannotBeMarkedIsNotKept()

	/**
	 * No provider: not available, and a call is refused before any task runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#scenario-no-provider-no-button
	 */
	public function testWithoutAProviderNothingRuns(): void {
		$service = $this->service(provider: false);

		$this->assertFalse($service->isAvailable(uid: 'communicatie'));
		$this->assertSame('provider_unavailable', $service->invoke(uid: 'communicatie', toolId: 'hermiq.generateImage', arguments: ['prompt' => 'x'])['error']['code']);
		$this->assertSame([], $this->ran);
	}//end testWithoutAProviderNothingRuns()

	/**
	 * A feature the DPO has not enabled refuses before any task runs.
	 *
	 * @return void
	 */
	public function testADisabledFeatureRefuses(): void {
		$result = $this->service(enabled: false)->invoke(uid: 'communicatie', toolId: 'hermiq.generateImage', arguments: ['prompt' => 'x']);

		$this->assertSame('feature_not_enabled', $result['error']['code']);
		$this->assertSame([], $this->ran);
		$this->assertSame([], $this->written);
	}//end testADisabledFeatureRefuses()

	/**
	 * An empty description is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyDescriptionIsRefused(): void {
		$this->assertSame('invalid_prompt', $this->service()->invoke(uid: 'communicatie', toolId: 'hermiq.generateImage', arguments: [])['error']['code']);
	}//end testAnEmptyDescriptionIsRefused()

	/**
	 * An image the agent created through the tool is handed to the answer once, as a generated attachment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-shows-in-the-answer-req-cimg-004
	 */
	public function testAToolCreatedImageIsHandedToTheAnswerOnce(): void {
		$service = $this->service();
		$service->invoke(uid: 'communicatie', toolId: 'hermiq.generateImage', arguments: ['prompt' => 'Een afvalkalender']);

		$this->assertSame(
			[['fileId' => 902, 'name' => 'image-1.png', 'mimeType' => 'image/png', 'size' => 0, 'origin' => 'generated']],
			$service->takeCreated()
		);
		$this->assertSame([], $service->takeCreated(), 'A second answer does not carry the same image.');
	}//end testAToolCreatedImageIsHandedToTheAnswerOnce()

	/**
	 * A failed tool call hands nothing to the answer.
	 *
	 * @return void
	 */
	public function testAFailedToolCallHandsNothingToTheAnswer(): void {
		$service = $this->service(tagFails: true);
		$service->invoke(uid: 'communicatie', toolId: 'hermiq.generateImage', arguments: ['prompt' => 'Een kaart']);

		$this->assertSame([], $service->takeCreated());
	}//end testAFailedToolCallHandsNothingToTheAnswer()

	/**
	 * The chat action is offered only with the feature enabled and a provider installed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#scenario-no-provider-no-button
	 */
	public function testTheChatActionNeedsTheFeatureAndAProvider(): void {
		$this->assertTrue($this->service()->canCreate(uid: 'communicatie'));
		$this->assertFalse($this->service(provider: false)->canCreate(uid: 'communicatie'));
		$this->assertFalse($this->service(enabled: false)->canCreate(uid: 'communicatie'));
	}//end testTheChatActionNeedsTheFeatureAndAProvider()
}//end class
