<?php

/**
 * Hermiq AttachmentPartBuilder compose and driver-routing unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use LLPhant\Chat\Message;
use OCA\Hermiq\Service\Chat\AttachmentTextReader;
use OCA\Hermiq\Service\Llm\AttachmentMessage;
use OCA\Hermiq\Service\Llm\AttachmentPartBuilder;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ModelCapabilityRegistry;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for compose(), forDriver() and the unreadable-file paths.
 *
 * @covers \OCA\Hermiq\Service\Llm\AttachmentPartBuilder
 * @uses   \OCA\Hermiq\Service\Llm\AttachmentMessage
 * @uses   \OCA\Hermiq\Service\Llm\ChatDriver
 * @uses   \OCA\Hermiq\Service\Llm\ModelCapabilityRegistry
 */
class AttachmentPartBuilderComposeTest extends TestCase {

	/**
	 * The uid every file is read as.
	 *
	 * @var string
	 */
	private const SPEAKER = 'inspecteur';

	/**
	 * Build the builder over files by id.
	 *
	 * @param array<int, string>        $contents     File id to bytes.
	 * @param string                    $capabilities The stored `hermiq.modelCapabilities`.
	 * @param AttachmentTextReader|null $reader       The text fallback reader.
	 * @param LoggerInterface|null      $logger       The logger.
	 * @param IRootFolder|null          $root         A root folder overriding $contents.
	 *
	 * @return AttachmentPartBuilder The builder.
	 */
	private function builder(
		array $contents,
		string $capabilities,
		?AttachmentTextReader $reader = null,
		?LoggerInterface $logger = null,
		?IRootFolder $root = null,
	): AttachmentPartBuilder {
		if ($root === null) {
			$folder = $this->createMock(Folder::class);
			$folder->method('getById')->willReturnCallback(
				function (int $id) use ($contents): array {
					if (isset($contents[$id]) === false) {
						return [];
					}

					$file = $this->createMock(File::class);
					$file->method('isReadable')->willReturn(true);
					$file->method('getContent')->willReturn($contents[$id]);
					return [$file];
				}
			);
			$root = $this->createMock(IRootFolder::class);
			$root->method('getUserFolder')->willReturn($folder);
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($capabilities);

		return new AttachmentPartBuilder(
			rootFolder: $root,
			capabilities: new ModelCapabilityRegistry(appConfig: $config),
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			textReader: $reader
		);
	}//end builder()

	/**
	 * A 1x1 PNG's bytes.
	 *
	 * @return string The bytes.
	 */
	private function png(): string {
		return (string)base64_decode(AttachmentPartBuilderTest::PNG_BASE64);
	}//end png()

	/**
	 * A turn without attachments is a plain user message and no notices, and the reader is not asked.
	 *
	 * @return void
	 */
	public function testComposeWithoutAttachmentsIsAPlainUserTurn(): void {
		$reader = $this->createMock(AttachmentTextReader::class);
		$reader->expects($this->never())->method('read');

		$result = $this->builder([], '{}', $reader)->compose(
			text: 'Hoe staat het ervoor?',
			driver: new ChatDriver(provider: 'openai', chat: null, model: 'gpt-4o'),
			attachments: [],
			speaker: self::SPEAKER
		);

		$this->assertNotInstanceOf(AttachmentMessage::class, $result['turn']);
		$this->assertInstanceOf(Message::class, $result['turn']);
		$this->assertSame('Hoe staat het ervoor?', $result['turn']->content);
		$this->assertSame([], $result['notices']);
	}//end testComposeWithoutAttachmentsIsAPlainUserTurn()

	/**
	 * A native image becomes an AttachmentMessage; the text fallback is appended and its notices passed on.
	 *
	 * @return void
	 */
	public function testComposeSendsNativePartsAndAppendsTheFallbackText(): void {
		$image = ['fileId' => 7, 'name' => 'foto.png', 'mimeType' => 'image/png'];
		$doc = ['fileId' => 8, 'name' => 'notities.txt', 'mimeType' => 'text/plain'];

		$reader = $this->createMock(AttachmentTextReader::class);
		$reader->expects($this->once())
			->method('read')
			->with([$doc], self::SPEAKER)
			->willReturn(['text' => "\n\nTEKST", 'notices' => ['notice-a']]);

		$result = $this->builder([7 => $this->png()], (string)json_encode(['openai/gpt-4o' => ['image']]), $reader)->compose(
			text: 'Kijk hiernaar',
			driver: new ChatDriver(provider: 'openai', chat: null, model: 'gpt-4o'),
			attachments: [$image, $doc],
			speaker: self::SPEAKER
		);

		$this->assertInstanceOf(AttachmentMessage::class, $result['turn']);
		$this->assertSame("Kijk hiernaar\n\nTEKST", $result['turn']->content);
		$this->assertCount(1, $result['turn']->parts);
		$this->assertSame('foto.png', $result['turn']->parts[0]['name']);
		$this->assertSame(['notice-a'], $result['notices']);
	}//end testComposeSendsNativePartsAndAppendsTheFallbackText()

	/**
	 * With no native part the turn stays a plain message carrying the fallback text.
	 *
	 * @return void
	 */
	public function testComposeWithoutNativePartsIsAPlainTurnWithTheFallback(): void {
		$doc = ['fileId' => 8, 'name' => 'notities.txt', 'mimeType' => 'text/plain'];
		$reader = $this->createMock(AttachmentTextReader::class);
		$reader->method('read')->willReturn(['text' => ' + bijlage', 'notices' => ['n1', 'n2']]);

		$result = $this->builder([], '{}', $reader)->compose(
			text: 'Vraag',
			driver: new ChatDriver(provider: 'openai', chat: null, model: 'gpt-4o'),
			attachments: [$doc],
			speaker: self::SPEAKER
		);

		$this->assertNotInstanceOf(AttachmentMessage::class, $result['turn']);
		$this->assertSame('Vraag + bijlage', $result['turn']->content);
		$this->assertSame(['n1', 'n2'], $result['notices']);
	}//end testComposeWithoutNativePartsIsAPlainTurnWithTheFallback()

	/**
	 * Without a text reader the fallback adds nothing and no notices.
	 *
	 * @return void
	 */
	public function testComposeWithoutAReaderLeavesTheFallbackOut(): void {
		$result = $this->builder([], '{}')->compose(
			text: 'Vraag',
			driver: new ChatDriver(provider: 'openai', chat: null, model: 'gpt-4o'),
			attachments: [['fileId' => 8, 'name' => 'a.txt', 'mimeType' => 'text/plain']],
			speaker: self::SPEAKER
		);

		$this->assertSame('Vraag', $result['turn']->content);
		$this->assertSame([], $result['notices']);
	}//end testComposeWithoutAReaderLeavesTheFallbackOut()

	/**
	 * The Anthropic CLI transport carries no native parts, even for a model declared for images.
	 *
	 * @return void
	 */
	public function testTheAnthropicCliDriverSendsNothingNatively(): void {
		$image = ['fileId' => 7, 'name' => 'foto.png', 'mimeType' => 'image/png'];
		$builder = $this->builder(
			[7 => $this->png()],
			(string)json_encode(['anthropic/claude-sonnet-5' => ['image']])
		);

		$cli = $builder->forDriver(
			driver: new ChatDriver(provider: 'anthropic', chat: null, model: 'claude-sonnet-5', executionMode: 'cli'),
			attachments: [$image],
			speaker: self::SPEAKER
		);
		$http = $builder->forDriver(
			driver: new ChatDriver(provider: 'anthropic', chat: null, model: 'claude-sonnet-5'),
			attachments: [$image],
			speaker: self::SPEAKER
		);

		$this->assertSame([], $cli['parts']);
		$this->assertSame([$image], $cli['fallback']);
		$this->assertCount(1, $http['parts']);
		$this->assertSame([], $http['fallback']);
	}//end testTheAnthropicCliDriverSendsNothingNatively()

	/**
	 * A missing file id or an empty speaker is never looked up and falls back.
	 *
	 * @return void
	 */
	public function testAMissingFileIdOrSpeakerFallsBackWithoutALookup(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');
		$builder = $this->builder([], (string)json_encode(['openai/gpt-4o' => ['image']]), root: $root);
		$noId = ['name' => 'foto.png', 'mimeType' => 'image/png'];
		$withId = ['fileId' => 7, 'name' => 'foto.png', 'mimeType' => 'image/png'];

		$first = $builder->build(provider: 'openai', model: 'gpt-4o', attachments: [$noId], speaker: self::SPEAKER);
		$second = $builder->build(provider: 'openai', model: 'gpt-4o', attachments: [$withId], speaker: '');

		$this->assertSame(['parts' => [], 'fallback' => [$noId]], $first);
		$this->assertSame(['parts' => [], 'fallback' => [$withId]], $second);
	}//end testAMissingFileIdOrSpeakerFallsBackWithoutALookup()

	/**
	 * A read that throws is logged as a warning and the attachment falls back.
	 *
	 * @return void
	 */
	public function testAThrowingReadIsLoggedAndFallsBack(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willThrowException(new RuntimeException('storage offline'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'[AttachmentPartBuilder] An attachment could not be read; it is not sent natively',
				['fileId' => 7, 'error' => 'storage offline']
			);
		$attachment = ['fileId' => 7, 'name' => 'foto.png', 'mimeType' => 'image/png'];

		$split = $this->builder([], (string)json_encode(['openai/gpt-4o' => ['image']]), logger: $logger, root: $root)
			->build(provider: 'openai', model: 'gpt-4o', attachments: [$attachment], speaker: self::SPEAKER);

		$this->assertSame(['parts' => [], 'fallback' => [$attachment]], $split);
	}//end testAThrowingReadIsLoggedAndFallsBack()
}//end class
