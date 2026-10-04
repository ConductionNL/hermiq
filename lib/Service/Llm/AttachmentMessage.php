<?php

/**
 * Hermiq AttachmentMessage.
 *
 * A user turn that carries files the model reads natively, next to the text. One
 * message, three request shapes:
 *
 * - OpenAI (and Fireworks, which speaks its format): `jsonSerialize()` gives the
 *   text part, then each image as an `image_url` data URL and each PDF as a `file`
 *   part with `file_data`. LLPhant's OpenAIChat sends the message as serialised.
 * - Ollama: LLPhant's OllamaChat reads `$images` off any VisionMessage and sends
 *   their base64 in the message's `images` array, so only image parts go there.
 * - Anthropic: `anthropicContent()` gives `image` and `document` blocks with a
 *   base64 source, then the text block; ProviderFactory::mapHistoryToAnthropicMessages()
 *   uses it instead of casting the turn to a string.
 *
 * Which parts a driver may receive is decided before this message is built
 * (AttachmentPartBuilder): a PDF part never reaches Ollama or Fireworks.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
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

namespace OCA\Hermiq\Service\Llm;

use LLPhant\Chat\Enums\ChatRole;
use LLPhant\Chat\Vision\ImageSource;
use LLPhant\Chat\Vision\VisionMessage;

/**
 * A user message with native image and document parts.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
 */
class AttachmentMessage extends VisionMessage {

	/**
	 * The native parts, in the order they were attached.
	 *
	 * @var list<array{kind: string, name: string, mimeType: string, base64: string}>
	 */
	public array $parts = [];

	/**
	 * Build the user turn from its text and its native parts.
	 *
	 * @param string                                                                     $text  The person's message.
	 * @param list<array{kind: string, name: string, mimeType: string, base64: string}> $parts The native parts.
	 *
	 * @return self The message.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public static function withParts(string $text, array $parts): self {
		$message = new self();
		$message->role = ChatRole::User;
		$message->content = $text;
		$message->parts = array_values($parts);

		$images = [];
		foreach ($message->parts as $part) {
			if ($part['kind'] === ModelCapabilityRegistry::CAPABILITY_IMAGE) {
				$images[] = new ImageSource($part['base64']);
			}
		}

		$message->images = $images;

		return $message;
	}//end withParts()

	/**
	 * The OpenAI-format content parts: the text, then each attachment.
	 *
	 * @return list<array<string, mixed>> The content parts.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function openAiContent(): array {
		$content = [['type' => 'text', 'text' => $this->content]];
		foreach ($this->parts as $part) {
			$dataUrl = 'data:' . $part['mimeType'] . ';base64,' . $part['base64'];
			if ($part['kind'] === ModelCapabilityRegistry::CAPABILITY_IMAGE) {
				$content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
				continue;
			}

			$content[] = ['type' => 'file', 'file' => ['filename' => $part['name'], 'file_data' => $dataUrl]];
		}

		return $content;
	}//end openAiContent()

	/**
	 * The Anthropic content blocks: each attachment, then the text.
	 *
	 * Anthropic advises the files before the question.
	 *
	 * @return list<array<string, mixed>> The content blocks.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function anthropicContent(): array {
		$blocks = [];
		foreach ($this->parts as $part) {
			$type = 'document';
			if ($part['kind'] === ModelCapabilityRegistry::CAPABILITY_IMAGE) {
				$type = 'image';
			}

			$blocks[] = [
				'type' => $type,
				'source' => [
					'type' => 'base64',
					'media_type' => $part['mimeType'],
					'data' => $part['base64'],
				],
			];
		}

		$blocks[] = ['type' => 'text', 'text' => $this->content];

		return $blocks;
	}//end anthropicContent()

	/**
	 * The OpenAI wire shape of this message.
	 *
	 * @return array<string, mixed> The serialised message.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function jsonSerialize(): array {
		return [
			'role' => $this->role->value,
			'content' => $this->openAiContent(),
		];
	}//end jsonSerialize()
}//end class
