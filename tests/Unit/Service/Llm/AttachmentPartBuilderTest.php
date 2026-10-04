<?php

/**
 * Hermiq AttachmentPartBuilder unit tests.
 *
 * Which attachment goes to the model as a native part: only a type that maps to a
 * capability, on a driver that can carry it, for a model an administrator declared,
 * with bytes read as the speaker (chat-attachments-and-images task 5).
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

use OCA\Hermiq\Service\Llm\AttachmentPartBuilder;
use OCA\Hermiq\Service\Llm\ModelCapabilityRegistry;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the native-or-fallback split.
 */
class AttachmentPartBuilderTest extends TestCase {

	/**
	 * A 1x1 PNG.
	 *
	 * @var string
	 */
	public const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	/**
	 * The uid every file is read as in the happy path.
	 *
	 * @var string
	 */
	private const SPEAKER = 'inspecteur';

	/**
	 * The uids getUserFolder() was asked for.
	 *
	 * @var list<string>
	 */
	private array $readAs = [];

	/**
	 * Build the builder over files by id and a declared capability map.
	 *
	 * @param array<int, string> $contents     File id to bytes, readable by SPEAKER.
	 * @param string             $capabilities The stored `hermiq.modelCapabilities`.
	 *
	 * @return AttachmentPartBuilder The builder.
	 */
	private function builder(array $contents, string $capabilities): AttachmentPartBuilder {
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
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($folder): Folder {
				$this->readAs[] = $uid;
				return $folder;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($capabilities);

		return new AttachmentPartBuilder(
			rootFolder: $root,
			capabilities: new ModelCapabilityRegistry(appConfig: $config),
			logger: new NullLogger()
		);
	}//end builder()

	/**
	 * A resolved attachment as TurnAttachmentResolver returns it.
	 *
	 * @param int    $fileId   The file id.
	 * @param string $name     The name.
	 * @param string $mimeType The type.
	 *
	 * @return array<string, mixed> The attachment.
	 */
	private function attachment(int $fileId, string $name, string $mimeType): array {
		return ['fileId' => $fileId, 'name' => $name, 'mimeType' => $mimeType, 'size' => 10, 'origin' => 'files'];
	}//end attachment()

	/**
	 * A photo reaches a declared vision model natively on each of the four drivers.
	 *
	 * @return void
	 */
	public function testAPhotoIsNativeForADeclaredVisionModelOnEveryImageDriver(): void {
		$png = (string)base64_decode(self::PNG_BASE64);
		$models = [
			'anthropic' => 'claude-sonnet-5',
			'openai' => 'gpt-4o',
			'ollama' => 'llava:13b',
			'fireworks' => 'accounts/fireworks/models/llama-v3p2-11b-vision-instruct',
		];

		foreach ($models as $provider => $model) {
			$builder = $this->builder([7 => $png], (string)json_encode([$provider . '/' . $model => ['image']]));

			$plan = $builder->build(
				provider: $provider,
				model: $model,
				attachments: [$this->attachment(7, 'dakgoot-noordzijde.png', 'image/png')],
				speaker: self::SPEAKER
			);

			$this->assertSame(
				[['kind' => 'image', 'name' => 'dakgoot-noordzijde.png', 'mimeType' => 'image/png', 'base64' => self::PNG_BASE64]],
				$plan['parts'],
				$provider
			);
			$this->assertSame([], $plan['fallback'], $provider);
		}

		$this->assertSame(array_fill(0, 4, self::SPEAKER), $this->readAs);
	}//end testAPhotoIsNativeForADeclaredVisionModelOnEveryImageDriver()

	/**
	 * An undeclared model gets nothing natively, whatever its name, and no file is read.
	 *
	 * @return void
	 */
	public function testAnUndeclaredModelGetsNothingNatively(): void {
		$builder = $this->builder([7 => (string)base64_decode(self::PNG_BASE64)], '');
		$photo = $this->attachment(7, 'plattegrond.png', 'image/png');

		$plan = $builder->build(provider: 'openai', model: 'gpt-4o', attachments: [$photo], speaker: self::SPEAKER);

		$this->assertSame([], $plan['parts']);
		$this->assertSame([$photo], $plan['fallback']);
		$this->assertSame([], $this->readAs);
	}//end testAnUndeclaredModelGetsNothingNatively()

	/**
	 * A PDF is native on anthropic and openai for a declared PDF model.
	 *
	 * @return void
	 */
	public function testAPdfIsNativeOnAnthropicAndOpenAi(): void {
		foreach (['anthropic' => 'claude-sonnet-5', 'openai' => 'gpt-4o'] as $provider => $model) {
			$builder = $this->builder([9 => '%PDF-1.7 fake'], (string)json_encode([$provider . '/' . $model => ['pdf']]));

			$plan = $builder->build(
				provider: $provider,
				model: $model,
				attachments: [$this->attachment(9, 'jaarverslag-2025.pdf', 'application/pdf')],
				speaker: self::SPEAKER
			);

			$this->assertSame('pdf', ($plan['parts'][0]['kind'] ?? null), $provider);
			$this->assertSame(base64_encode('%PDF-1.7 fake'), $plan['parts'][0]['base64'], $provider);
		}
	}//end testAPdfIsNativeOnAnthropicAndOpenAi()

	/**
	 * Ollama, Fireworks, Nextcloud and the Anthropic CLI never get a PDF part, even when declared.
	 *
	 * @return void
	 */
	public function testAPdfFallsBackWhereTheDriverCannotCarryIt(): void {
		foreach (['ollama', 'fireworks', 'nextcloud', 'anthropic-cli'] as $provider) {
			$builder = $this->builder([9 => '%PDF-1.7 fake'], (string)json_encode([$provider . '/m' => ['image', 'pdf']]));
			$pdf = $this->attachment(9, 'offerte-2026.pdf', 'application/pdf');

			$plan = $builder->build(provider: $provider, model: 'm', attachments: [$pdf], speaker: self::SPEAKER);

			$this->assertSame([], $plan['parts'], $provider);
			$this->assertSame([$pdf], $plan['fallback'], $provider);
		}
	}//end testAPdfFallsBackWhereTheDriverCannotCarryIt()

	/**
	 * A declared image model gets a PDF as fallback, and the image natively, in one turn.
	 *
	 * @return void
	 */
	public function testOnlyTheDeclaredCapabilityIsNative(): void {
		$builder = $this->builder(
			[7 => (string)base64_decode(self::PNG_BASE64), 9 => '%PDF-1.7 fake'],
			'{"openai/gpt-4o":["image"]}'
		);
		$pdf = $this->attachment(9, 'jaarverslag-2025.pdf', 'application/pdf');

		$plan = $builder->build(
			provider: 'openai',
			model: 'gpt-4o',
			attachments: [$this->attachment(7, 'foto.png', 'image/png'), $pdf],
			speaker: self::SPEAKER
		);

		$this->assertCount(1, $plan['parts']);
		$this->assertSame('image', $plan['parts'][0]['kind']);
		$this->assertSame([$pdf], $plan['fallback']);
	}//end testOnlyTheDeclaredCapabilityIsNative()

	/**
	 * A file that cannot be read now, or is not the image its type claims, falls back.
	 *
	 * @return void
	 */
	public function testAnUnreadableOrFakeImageFallsBack(): void {
		$builder = $this->builder([8 => 'not a png at all'], '{"openai/gpt-4o":["image"]}');
		$gone = $this->attachment(7, 'weg.png', 'image/png');
		$fake = $this->attachment(8, 'nep.png', 'image/png');

		$plan = $builder->build(provider: 'openai', model: 'gpt-4o', attachments: [$gone, $fake], speaker: self::SPEAKER);

		$this->assertSame([], $plan['parts']);
		$this->assertSame([$gone, $fake], $plan['fallback']);
	}//end testAnUnreadableOrFakeImageFallsBack()
}//end class
