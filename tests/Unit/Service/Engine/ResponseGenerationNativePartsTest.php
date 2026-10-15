<?php

/**
 * Hermiq ResponseGenerationHandler: the user turn carries native parts.
 *
 * Drives the real handler with the real AttachmentPartBuilder and
 * ModelCapabilityRegistry; only the provider call, the tool loop, Files and app
 * config are doubles (chat-attachments-and-images task 5).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\AttachmentMessage;
use OCA\Hermiq\Service\Llm\AttachmentPartBuilder;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ModelCapabilityRegistry;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Tests\Unit\Service\Llm\AttachmentPartBuilderTest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the native parts on the user turn.
 */
class ResponseGenerationNativePartsTest extends TestCase {

	/**
	 * The history the provider call received.
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $sent = null;

	/**
	 * The uid Files was opened as.
	 *
	 * @var list<string>
	 */
	private array $readAs = [];

	/**
	 * Build the handler over a driver and a declared capability map.
	 *
	 * @param ChatDriver $driver       The resolved driver.
	 * @param string     $capabilities The stored `hermiq.modelCapabilities`.
	 *
	 * @return ResponseGenerationHandler The handler.
	 */
	private function handler(ChatDriver $driver, string $capabilities): ResponseGenerationHandler {
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => $driver->provider]);
		$factory->method('createChatDriver')->willReturn($driver);
		$factory->method('lastCallUsage')->willReturn([]);
		$capture = function (...$arguments): string {
			$this->sent = $arguments['messageHistory'] ?? $arguments[3];
			return 'Ja.';
		};
		$factory->method('callFireworksChat')->willReturnCallback($capture);
		$factory->method('callAnthropicChat')->willReturnCallback($capture);

		$loop = $this->createMock(ToolLoop::class);
		$loop->method('listAgentFunctions')->willReturn([]);

		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getContent')->willReturn((string)base64_decode(AttachmentPartBuilderTest::PNG_BASE64));
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$file]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($folder): Folder {
				$this->readAs[] = $uid;
				return $folder;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($capabilities);

		return new ResponseGenerationHandler(
			providerFactory: $factory,
			toolLoop: $loop,
			logger: new NullLogger(),
			attachmentParts: new AttachmentPartBuilder(
				rootFolder: $root,
				capabilities: new ModelCapabilityRegistry(appConfig: $config),
				logger: new NullLogger()
			)
		);
	}//end handler()

	/**
	 * Run one turn with one attached photo, spoken by `inspecteur`.
	 *
	 * @param ResponseGenerationHandler $handler The handler.
	 *
	 * @return mixed The user turn the provider call received.
	 */
	private function runTurn(ResponseGenerationHandler $handler): mixed {
		$agent = new ObjectEntity();
		$agent->setObject(['prompt' => 'Je bent een inspecteur.']);

		$handler->generateResponse(
			userMessage: 'Zie je lekkage?',
			context: ['text' => '', 'sources' => []],
			messageHistory: [],
			agent: $agent,
			attachments: [['fileId' => 7, 'name' => 'dakgoot-noordzijde.png', 'mimeType' => 'image/png', 'size' => 70, 'origin' => 'files']],
			speaker: 'inspecteur'
		);

		$this->assertNotNull($this->sent);
		return $this->sent[count($this->sent) - 1];
	}//end runTurn()

	/**
	 * A declared vision model gets the photo as a part of the user turn, read as the speaker.
	 *
	 * @return void
	 */
	public function testADeclaredVisionModelGetsThePhotoOnTheUserTurn(): void {
		$model = 'accounts/fireworks/models/llama-v3p2-11b-vision-instruct';
		$driver = new ChatDriver(provider: 'fireworks', chat: null, model: $model, credentialId: 'cred', baseUrl: 'https://fw.test');

		$turn = $this->runTurn($this->handler($driver, (string)json_encode(['fireworks/' . $model => ['image']])));

		$this->assertInstanceOf(AttachmentMessage::class, $turn);
		$this->assertSame('Zie je lekkage?', $turn->content);
		$this->assertSame('dakgoot-noordzijde.png', $turn->parts[0]['name']);
		$this->assertSame(['inspecteur'], $this->readAs);
	}//end testADeclaredVisionModelGetsThePhotoOnTheUserTurn()

	/**
	 * An undeclared model gets a plain text turn, as before this change.
	 *
	 * @return void
	 */
	public function testAnUndeclaredModelGetsAPlainTurn(): void {
		$driver = new ChatDriver(provider: 'fireworks', chat: null, model: 'llama', credentialId: 'cred', baseUrl: 'https://fw.test');

		$turn = $this->runTurn($this->handler($driver, ''));

		$this->assertNotInstanceOf(AttachmentMessage::class, $turn);
		$this->assertSame('Zie je lekkage?', $turn->content);
	}//end testAnUndeclaredModelGetsAPlainTurn()

	/**
	 * The Anthropic CLI transport carries text only, so a declared model still gets a plain turn.
	 *
	 * @return void
	 */
	public function testTheAnthropicCliTransportGetsAPlainTurn(): void {
		$driver = new ChatDriver(
			provider: 'anthropic',
			chat: null,
			model: 'claude-sonnet-5',
			credentialId: 'cred',
			baseUrl: 'https://api.anthropic.test',
			executionMode: 'cli'
		);

		$turn = $this->runTurn($this->handler($driver, '{"anthropic/claude-sonnet-5":["image"]}'));

		$this->assertNotInstanceOf(AttachmentMessage::class, $turn);
	}//end testTheAnthropicCliTransportGetsAPlainTurn()
}//end class
