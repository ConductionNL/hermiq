<?php

/**
 * Hermiq ResponseGenerationHandler: attachments a model cannot read natively.
 *
 * Drives the real handler with the real AttachmentPartBuilder, AttachmentTextReader
 * and ModelCapabilityRegistry; only the provider call, the tool loop, Files, app
 * config and the text source are doubles (chat-attachments-and-images task 6).
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Chat\AttachmentTextReader;
use OCA\Hermiq\Service\Chat\AttachmentTextSource;
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
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the text fallback on the user turn.
 */
class ResponseGenerationTextFallbackTest extends TestCase {

	/**
	 * The history the provider call received.
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $sent = null;

	/**
	 * Build the handler over a Fireworks model and a declared capability map.
	 *
	 * @param string $capabilities The stored `hermiq.modelCapabilities`.
	 *
	 * @return ResponseGenerationHandler The handler.
	 */
	private function handler(string $capabilities): ResponseGenerationHandler {
		$driver = new ChatDriver(provider: 'fireworks', chat: null, model: 'llama', credentialId: 'cred', baseUrl: 'https://fw.test');
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => 'fireworks']);
		$factory->method('createChatDriver')->willReturn($driver);
		$factory->method('lastCallUsage')->willReturn([]);
		$factory->method('callFireworksChat')->willReturnCallback(
			function (...$arguments): string {
				$this->sent = $arguments['messageHistory'] ?? $arguments[3];
				return 'Ja.';
			}
		);

		$loop = $this->createMock(ToolLoop::class);
		$loop->method('listAgentFunctions')->willReturn([]);

		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getContent')->willReturn((string)base64_decode(AttachmentPartBuilderTest::PNG_BASE64));
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$file]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($capabilities);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		$source = $this->createMock(AttachmentTextSource::class);
		$source->method('isAvailable')->willReturn(true);
		$source->method('extractText')->willReturn('Totale kosten: EUR 412.000');

		return new ResponseGenerationHandler(
			providerFactory: $factory,
			toolLoop: $loop,
			logger: new NullLogger(),
			attachmentParts: new AttachmentPartBuilder(
				rootFolder: $root,
				capabilities: new ModelCapabilityRegistry(appConfig: $config),
				logger: new NullLogger()
			),
			attachmentText: new AttachmentTextReader(rootFolder: $root, l10n: $l10n, logger: new NullLogger(), textSource: $source)
		);
	}//end handler()

	/**
	 * Run one turn with the given attachments.
	 *
	 * @param ResponseGenerationHandler        $handler     The handler.
	 * @param array<int, array<string, mixed>> $attachments The resolved attachments.
	 *
	 * @return mixed The user turn the provider call received.
	 */
	private function runTurn(ResponseGenerationHandler $handler, array $attachments): mixed {
		$agent = new ObjectEntity();
		$agent->setObject(['prompt' => 'Je bent een controller.']);

		$handler->generateResponse(
			userMessage: 'Wat zijn de totale kosten?',
			context: ['text' => '', 'sources' => []],
			messageHistory: [],
			agent: $agent,
			attachments: $attachments,
			speaker: 'controller'
		);

		$this->assertNotNull($this->sent);
		return $this->sent[count($this->sent) - 1];
	}//end runTurn()

	/**
	 * A model without `pdf` gets the PDF's text on the user turn, and the notice is kept for the answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#scenario-a-pdf-is-read-as-text-by-a-model-without-pdf-support
	 */
	public function testAModelWithoutPdfGetsTheTextAndTheNoticeIsKept(): void {
		$handler = $this->handler('');

		$turn = $this->runTurn($handler, [['fileId' => 31, 'name' => 'jaarverslag-2025.pdf', 'mimeType' => 'application/pdf', 'size' => 9, 'origin' => 'files']]);

		$this->assertNotInstanceOf(AttachmentMessage::class, $turn);
		$this->assertStringStartsWith('Wat zijn de totale kosten?', $turn->content);
		$this->assertStringContainsString('Totale kosten: EUR 412.000', $turn->content);
		$this->assertSame(
			['This model does not read PDFs directly. hermiq used the text of jaarverslag-2025.pdf instead.'],
			$handler->lastAttachmentNotices
		);
	}//end testAModelWithoutPdfGetsTheTextAndTheNoticeIsKept()

	/**
	 * A declared vision model gets the photo natively and the PDF as text, with one notice.
	 *
	 * @return void
	 */
	public function testNativePartsAndTheFallbackShareOneTurn(): void {
		$handler = $this->handler('{"fireworks/llama":["image"]}');

		$turn = $this->runTurn(
			$handler,
			[
				['fileId' => 7, 'name' => 'dakgoot.png', 'mimeType' => 'image/png', 'size' => 70, 'origin' => 'files'],
				['fileId' => 31, 'name' => 'jaarverslag-2025.pdf', 'mimeType' => 'application/pdf', 'size' => 9, 'origin' => 'files'],
			]
		);

		$this->assertInstanceOf(AttachmentMessage::class, $turn);
		$this->assertSame('dakgoot.png', $turn->parts[0]['name']);
		$this->assertCount(1, $turn->parts);
		$this->assertStringContainsString('Totale kosten: EUR 412.000', $turn->content);
		$this->assertCount(1, $handler->lastAttachmentNotices);
	}//end testNativePartsAndTheFallbackShareOneTurn()

	/**
	 * A turn without attachments clears the previous turn's notices.
	 *
	 * @return void
	 */
	public function testANextTurnStartsWithoutNotices(): void {
		$handler = $this->handler('');
		$this->runTurn($handler, [['fileId' => 9, 'name' => 'plattegrond.png', 'mimeType' => 'image/png', 'size' => 70, 'origin' => 'files']]);
		$this->assertSame(['This model cannot see images. plattegrond.png was not sent.'], $handler->lastAttachmentNotices);

		$turn = $this->runTurn($handler, []);

		$this->assertSame('Wat zijn de totale kosten?', $turn->content);
		$this->assertSame([], $handler->lastAttachmentNotices);
	}//end testANextTurnStartsWithoutNotices()
}//end class
