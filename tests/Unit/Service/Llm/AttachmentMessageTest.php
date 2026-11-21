<?php

/**
 * Hermiq AttachmentMessage wire-shape unit tests.
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

use LLPhant\Chat\Enums\ChatRole;
use OCA\Hermiq\Service\Llm\AttachmentMessage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the OpenAI and Anthropic content blocks of one attachment turn.
 *
 * @covers \OCA\Hermiq\Service\Llm\AttachmentMessage
 */
class AttachmentMessageTest extends TestCase {

	/**
	 * A turn with one image and one PDF.
	 *
	 * @return AttachmentMessage The turn.
	 */
	private function turn(): AttachmentMessage {
		return AttachmentMessage::withParts(
			text: 'Wat zie je?',
			parts: [
				3 => ['kind' => 'image', 'name' => 'a.png', 'mimeType' => 'image/png', 'base64' => AttachmentPartBuilderTest::PNG_BASE64],
				9 => ['kind' => 'pdf', 'name' => 'b.pdf', 'mimeType' => 'application/pdf', 'base64' => 'JVBERg=='],
			]
		);
	}//end turn()

	/**
	 * withParts() makes a user turn, reindexes the parts and lists only images as LLPhant images.
	 *
	 * @return void
	 */
	public function testWithPartsBuildsAUserTurnWithOnlyImagesAsImages(): void {
		$turn = $this->turn();

		$this->assertSame(ChatRole::User, $turn->role);
		$this->assertSame('Wat zie je?', $turn->content);
		$this->assertSame([0, 1], array_keys($turn->parts));
		$this->assertCount(1, $turn->images);
	}//end testWithPartsBuildsAUserTurnWithOnlyImagesAsImages()

	/**
	 * The OpenAI content is the text first, then an image_url and a file part as data URLs.
	 *
	 * @return void
	 */
	public function testOpenAiContentPutsTheTextFirst(): void {
		$this->assertSame(
			[
				['type' => 'text', 'text' => 'Wat zie je?'],
				['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . AttachmentPartBuilderTest::PNG_BASE64]],
				['type' => 'file', 'file' => ['filename' => 'b.pdf', 'file_data' => 'data:application/pdf;base64,JVBERg==']],
			],
			$this->turn()->openAiContent()
		);
	}//end testOpenAiContentPutsTheTextFirst()

	/**
	 * The Anthropic content is the image and document blocks first, then the text.
	 *
	 * @return void
	 */
	public function testAnthropicContentPutsTheTextLast(): void {
		$this->assertSame(
			[
				['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => AttachmentPartBuilderTest::PNG_BASE64]],
				['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'JVBERg==']],
				['type' => 'text', 'text' => 'Wat zie je?'],
			],
			$this->turn()->anthropicContent()
		);
	}//end testAnthropicContentPutsTheTextLast()

	/**
	 * JSON serialisation is the user role with the OpenAI content.
	 *
	 * @return void
	 */
	public function testJsonSerializeUsesTheOpenAiContent(): void {
		$turn = $this->turn();

		$this->assertSame(['role' => 'user', 'content' => $turn->openAiContent()], $turn->jsonSerialize());
	}//end testJsonSerializeUsesTheOpenAiContent()
}//end class
