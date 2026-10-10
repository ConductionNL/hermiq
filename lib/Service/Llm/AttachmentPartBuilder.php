<?php

/**
 * Hermiq AttachmentPartBuilder.
 *
 * Splits a turn's attachments into the ones the model reads natively and the rest.
 * An attachment becomes a native part only when all three hold:
 *
 * 1. its type maps to a capability (`image/png|jpeg|gif|webp` to `image`,
 *    `application/pdf` to `pdf`);
 * 2. the driver can carry that part at all: images on anthropic, openai, ollama and
 *    fireworks; PDFs on anthropic and openai only (Ollama takes no PDF part, the
 *    Fireworks path sends images only, the Nextcloud TextToText task takes no file,
 *    and the Anthropic CLI transport takes text);
 * 3. an administrator declared that capability for this provider and model
 *    (ModelCapabilityRegistry). Nothing is inferred from a model name.
 *
 * The bytes are read as the turn's speaker, the same person the attachment was
 * resolved for (TurnAttachmentResolver). A file that cannot be read now, or whose
 * bytes are not the image its type claims, is not sent natively; it goes to the
 * fallback list with the rest.
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

use InvalidArgumentException;
use OCA\Hermiq\Service\Chat\AttachmentTextReader;
use LLPhant\Chat\Message;
use LLPhant\Chat\Vision\ImageSource;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides, per attachment, whether it goes to the model as a native part.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
 */
class AttachmentPartBuilder {

	/**
	 * The transport id for Anthropic over the CLI runner, which carries text only.
	 *
	 * @var string
	 */
	public const ANTHROPIC_CLI = 'anthropic-cli';

	/**
	 * Which capability each attachable type maps to.
	 *
	 * @var array<string, string>
	 */
	private const CAPABILITY_BY_MIME = [
		'image/png' => ModelCapabilityRegistry::CAPABILITY_IMAGE,
		'image/jpeg' => ModelCapabilityRegistry::CAPABILITY_IMAGE,
		'image/gif' => ModelCapabilityRegistry::CAPABILITY_IMAGE,
		'image/webp' => ModelCapabilityRegistry::CAPABILITY_IMAGE,
		'application/pdf' => ModelCapabilityRegistry::CAPABILITY_PDF,
	];

	/**
	 * Which capabilities each driver can carry as a native part.
	 *
	 * @var array<string, list<string>>
	 */
	private const DRIVER_CARRIES = [
		'anthropic' => [ModelCapabilityRegistry::CAPABILITY_IMAGE, ModelCapabilityRegistry::CAPABILITY_PDF],
		'openai' => [ModelCapabilityRegistry::CAPABILITY_IMAGE, ModelCapabilityRegistry::CAPABILITY_PDF],
		'ollama' => [ModelCapabilityRegistry::CAPABILITY_IMAGE],
		'fireworks' => [ModelCapabilityRegistry::CAPABILITY_IMAGE],
	];

	/**
	 * Constructor.
	 *
	 * @param IRootFolder             $rootFolder   Reads the file as the speaker.
	 * @param ModelCapabilityRegistry $capabilities The declared native inputs per model.
	 * @param LoggerInterface         $logger       Logger.
	 * @param AttachmentTextReader|null $textReader The text fallback and notices for the
	 *                                              attachments not sent natively (D6);
	 *                                              null leaves them out without a notice.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly ModelCapabilityRegistry $capabilities,
		private readonly LoggerInterface $logger,
		private readonly ?AttachmentTextReader $textReader = null,
	) {
	}//end __construct()

	/**
	 * The person's turn and the notices for the answer.
	 *
	 * Native parts for what the model reads; the rest goes as text or is left out
	 * by the text reader, which words a notice per file.
	 *
	 * @param string                           $text        The person's message.
	 * @param ChatDriver                       $driver      The driver the turn runs on.
	 * @param array<int, array<string, mixed>> $attachments The resolved attachments.
	 * @param string                           $speaker     The uid they were resolved for.
	 *
	 * @return array{turn: Message, notices: list<string>} The user turn and the notices.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) LLPhant's Message role factory is the library's public API.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function compose(string $text, ChatDriver $driver, array $attachments, string $speaker): array {
		if ($attachments === []) {
			return ['turn' => Message::user($text), 'notices' => []];
		}

		$split = $this->forDriver(driver: $driver, attachments: $attachments, speaker: $speaker);
		$fallback = ($this->textReader?->read(attachments: $split['fallback'], speaker: $speaker)
			?? ['text' => '', 'notices' => []]);

		$text .= $fallback['text'];
		if ($split['parts'] === []) {
			return ['turn' => Message::user($text), 'notices' => $fallback['notices']];
		}

		return ['turn' => AttachmentMessage::withParts(text: $text, parts: $split['parts']), 'notices' => $fallback['notices']];
	}//end compose()

	/**
	 * Split the attachments into native parts and the rest, for the driver the turn runs on.
	 *
	 * The Anthropic CLI transport carries text only, so it is asked for as `anthropic-cli`.
	 *
	 * @param ChatDriver                       $driver      The driver the turn runs on.
	 * @param array<int, array<string, mixed>> $attachments The resolved attachments.
	 * @param string                           $speaker     The uid they were resolved for.
	 *
	 * @return array{parts: list<array{kind: string, name: string, mimeType: string, base64: string}>, fallback: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function forDriver(ChatDriver $driver, array $attachments, string $speaker): array {
		$provider = $driver->provider;
		if ($provider === 'anthropic' && $driver->executionMode === 'cli') {
			$provider = self::ANTHROPIC_CLI;
		}

		return $this->build(provider: $provider, model: $driver->model, attachments: $attachments, speaker: $speaker);
	}//end forDriver()

	/**
	 * Split the attachments into native parts and the rest.
	 *
	 * @param string                           $provider    The driver's provider, or `anthropic-cli`.
	 * @param string                           $model       The model the turn runs on.
	 * @param array<int, array<string, mixed>> $attachments The resolved attachments.
	 * @param string                           $speaker     The uid the attachments were resolved for.
	 *
	 * @return array{parts: list<array{kind: string, name: string, mimeType: string, base64: string}>, fallback: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004
	 */
	public function build(string $provider, string $model, array $attachments, string $speaker): array {
		$parts = [];
		$fallback = [];

		foreach ($attachments as $attachment) {
			$part = $this->nativePart(provider: $provider, model: $model, attachment: $attachment, speaker: $speaker);
			if ($part === null) {
				$fallback[] = $attachment;
				continue;
			}

			$parts[] = $part;
		}

		return ['parts' => $parts, 'fallback' => $fallback];
	}//end build()

	/**
	 * The native part for one attachment, or null when it is not sent natively.
	 *
	 * @param string               $provider   The provider.
	 * @param string               $model      The model.
	 * @param array<string, mixed> $attachment The resolved attachment.
	 * @param string               $speaker    The uid.
	 *
	 * @return array{kind: string, name: string, mimeType: string, base64: string}|null The part.
	 */
	private function nativePart(string $provider, string $model, array $attachment, string $speaker): ?array {
		$mimeType = strtolower((string)($attachment['mimeType'] ?? ''));
		$kind = (self::CAPABILITY_BY_MIME[$mimeType] ?? null);
		if ($kind === null
			|| in_array($kind, $this->carries(provider: $provider), true) === false
			|| $this->capabilities->supports(provider: $provider, model: $model, capability: $kind) === false
		) {
			return null;
		}

		$bytes = $this->read(fileId: (int)($attachment['fileId'] ?? 0), speaker: $speaker);
		if ($bytes === null) {
			return null;
		}

		$base64 = base64_encode($bytes);
		if ($kind === ModelCapabilityRegistry::CAPABILITY_IMAGE && $this->isImage(base64: $base64) === false) {
			return null;
		}

		return [
			'kind' => $kind,
			'name' => (string)($attachment['name'] ?? ''),
			'mimeType' => $mimeType,
			'base64' => $base64,
		];
	}//end nativePart()

	/**
	 * The capabilities a driver can carry as a native part; none for an unknown one.
	 *
	 * @param string $provider The provider, or `anthropic-cli`.
	 *
	 * @return list<string> The capabilities.
	 */
	private function carries(string $provider): array {
		if (array_key_exists($provider, self::DRIVER_CARRIES) === false) {
			return [];
		}

		return self::DRIVER_CARRIES[$provider];
	}//end carries()

	/**
	 * Read a file's bytes as the speaker; null when it cannot be read now.
	 *
	 * @param int    $fileId  The file id.
	 * @param string $speaker The uid.
	 *
	 * @return string|null The bytes.
	 */
	private function read(int $fileId, string $speaker): ?string {
		if ($fileId < 1 || $speaker === '') {
			return null;
		}

		try {
			$node = ($this->rootFolder->getUserFolder($speaker)->getById($fileId)[0] ?? null);
			if ($node instanceof File === false || $node->isReadable() === false) {
				return null;
			}

			return $node->getContent();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[AttachmentPartBuilder] An attachment could not be read; it is not sent natively',
				context: ['fileId' => $fileId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end read()

	/**
	 * Whether the bytes are an image type the drivers accept (png, jpeg, gif, webp).
	 *
	 * @param string $base64 The base64 bytes.
	 *
	 * @return bool True when LLPhant recognises the image.
	 */
	private function isImage(string $base64): bool {
		try {
			new ImageSource($base64);
			return true;
		} catch (InvalidArgumentException $e) {
			return false;
		}
	}//end isImage()
}//end class
