<?php

/**
 * Hermiq native attachment parts: the request body per driver.
 *
 * One AttachmentMessage, four providers, four wire shapes. OpenAI goes through
 * LLPhant's real OpenAIChat over openai-php's ClientFake; Ollama through LLPhant's
 * real OllamaChat over a Guzzle mock handler; Anthropic and Fireworks through
 * ProviderFactory's own history mappers, which build the messages their direct
 * HTTP calls send (chat-attachments-and-images task 5).
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
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LLPhant\Chat\Message;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OllamaConfig;
use LLPhant\OpenAIConfig;
use OCA\Hermiq\Service\Llm\AttachmentMessage;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the request body each driver sends for a turn with native parts.
 */
class NativeAttachmentPartsBodyTest extends TestCase {

	/**
	 * The question asked next to the files.
	 *
	 * @var string
	 */
	private const QUESTION = 'Zie je lekkage?';

	/**
	 * A turn with a photo and, optionally, a PDF.
	 *
	 * @param bool $withPdf Whether a PDF part is included.
	 *
	 * @return AttachmentMessage The turn.
	 */
	private function turn(bool $withPdf): AttachmentMessage {
		$parts = [
			[
				'kind' => 'image',
				'name' => 'dakgoot-noordzijde.png',
				'mimeType' => 'image/png',
				'base64' => AttachmentPartBuilderTest::PNG_BASE64,
			],
		];
		if ($withPdf === true) {
			$parts[] = [
				'kind' => 'pdf',
				'name' => 'jaarverslag-2025.pdf',
				'mimeType' => 'application/pdf',
				'base64' => base64_encode('%PDF-1.7 fake'),
			];
		}

		return AttachmentMessage::withParts(text: self::QUESTION, parts: $parts);
	}//end turn()

	/**
	 * A bare ProviderFactory: the history mappers need none of its collaborators.
	 *
	 * @return ProviderFactory The factory.
	 */
	private function factory(): ProviderFactory {
		return new ProviderFactory(
			settingsHandler: $this->createMock(LlmSettingsHandler::class),
			taskManager: $this->createMock(IManager::class),
			userSession: $this->createMock(IUserSession::class),
			logger: new NullLogger()
		);
	}//end factory()

	/**
	 * OpenAI: an image_url data URL and a file part with file_data, after the text.
	 *
	 * @return void
	 */
	public function testOpenAiBodyCarriesAnImagePartAndAFilePart(): void {
		$fake = new ClientFake([CreateResponse::fake()]);
		$config = new OpenAIConfig();
		$config->client = $fake;
		$config->model = 'gpt-4o';

		(new OpenAIChat($config))->generateChat([Message::system('Je bent een inspecteur.'), $this->turn(withPdf: true)]);

		$sent = null;
		$fake->chat()->assertSent(
			function (string $method, array $parameters) use (&$sent): bool {
				$sent = $parameters;
				return true;
			}
		);

		$body = json_decode((string)json_encode($sent), true);
		$user = $body['messages'][count($body['messages']) - 1];

		$this->assertSame('user', $user['role']);
		$this->assertSame(['type' => 'text', 'text' => self::QUESTION], $user['content'][0]);
		$this->assertSame(
			['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . AttachmentPartBuilderTest::PNG_BASE64]],
			$user['content'][1]
		);
		$this->assertSame(
			[
				'type' => 'file',
				'file' => [
					'filename' => 'jaarverslag-2025.pdf',
					'file_data' => 'data:application/pdf;base64,' . base64_encode('%PDF-1.7 fake'),
				],
			],
			$user['content'][2]
		);
	}//end testOpenAiBodyCarriesAnImagePartAndAFilePart()

	/**
	 * Ollama: the photo's base64 in the message's `images` array, the text as content.
	 *
	 * @return void
	 */
	public function testOllamaBodyCarriesTheImagesArray(): void {
		$history = [];
		$stack = HandlerStack::create(
			new MockHandler(
				[
					new Response(
						200,
						['Content-Type' => 'application/json'],
						(string)json_encode(['message' => ['role' => 'assistant', 'content' => 'Ja.'], 'done' => true])
					),
				]
			)
		);
		$stack->push(Middleware::history($history));

		$config = new OllamaConfig();
		$config->model = 'llava:13b';
		$chat = new OllamaChat($config);
		$chat->client = new Client(['handler' => $stack, 'base_uri' => 'http://ollama.test/api/']);

		$this->assertSame('Ja.', $chat->generateChat([$this->turn(withPdf: false)]));

		$body = json_decode((string)$history[0]['request']->getBody(), true);
		$user = $body['messages'][count($body['messages']) - 1];

		$this->assertSame(self::QUESTION, $user['content']);
		$this->assertSame([AttachmentPartBuilderTest::PNG_BASE64], $user['images']);
	}//end testOllamaBodyCarriesTheImagesArray()

	/**
	 * Anthropic: an image block and a document block with a base64 source, then the text.
	 *
	 * @return void
	 */
	public function testAnthropicMessagesCarryImageAndDocumentBlocks(): void {
		$mapped = $this->factory()->mapHistoryToAnthropicMessages(
			[Message::system('Je bent een inspecteur.'), Message::assistant('Vorige beurt.'), $this->turn(withPdf: true)]
		);

		$this->assertSame('Je bent een inspecteur.', $mapped['system']);
		$this->assertSame(['role' => 'assistant', 'content' => 'Vorige beurt.'], $mapped['messages'][0]);
		$this->assertSame(
			[
				'role' => 'user',
				'content' => [
					[
						'type' => 'image',
						'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => AttachmentPartBuilderTest::PNG_BASE64],
					],
					[
						'type' => 'document',
						'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.7 fake')],
					],
					['type' => 'text', 'text' => self::QUESTION],
				],
			],
			$mapped['messages'][1]
		);
	}//end testAnthropicMessagesCarryImageAndDocumentBlocks()

	/**
	 * Fireworks: the OpenAI-compatible image part on the user message; other turns stay text.
	 *
	 * @return void
	 */
	public function testFireworksMessagesCarryTheImagePart(): void {
		$messages = $this->factory()->mapHistoryToFireworksMessages(
			[Message::system('Je bent een inspecteur.'), $this->turn(withPdf: false)]
		);

		$this->assertSame(['role' => 'system', 'content' => 'Je bent een inspecteur.'], $messages[0]);
		$this->assertSame(
			[
				'role' => 'user',
				'content' => [
					['type' => 'text', 'text' => self::QUESTION],
					['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . AttachmentPartBuilderTest::PNG_BASE64]],
				],
			],
			$messages[1]
		);
	}//end testFireworksMessagesCarryTheImagePart()
}//end class
