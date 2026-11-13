<?php

/**
 * Hermiq SpeechVoiceSettingsController: admin-only read and replace of the voice map.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
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
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\SpeechVoiceSettingsController;
use OCA\Hermiq\Service\Speech\SpeechVoiceMap;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Tests for the voice-map settings controller.
 */
final class SpeechVoiceSettingsControllerTest extends TestCase {

	/**
	 * The stored app config value.
	 *
	 * @var string
	 */
	private string $stored = '{"fr":"ff_siwis"}';

	/**
	 * The controller over a real map and a fake app config.
	 *
	 * @param mixed $voices The request's `voices` parameter.
	 *
	 * @return SpeechVoiceSettingsController The controller.
	 */
	private function controller(mixed $voices = []): SpeechVoiceSettingsController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored = $value;
				return true;
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($key === 'voices' ? $voices : $default));

		return new SpeechVoiceSettingsController(request: $request, voices: new SpeechVoiceMap(appConfig: $config), logger: new NullLogger());
	}//end controller()

	/**
	 * Both routes are admin settings and neither opens to non-admins, so Nextcloud answers 403 to them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	public function testBothRoutesAreAdminOnly(): void {
		foreach (['get', 'update'] as $method) {
			$reflection = new ReflectionMethod(SpeechVoiceSettingsController::class, $method);
			$admin = $reflection->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $admin, $method . ' is an admin setting.');
			$this->assertSame(AdminSettings::class, $admin[0]->getArguments()[0]);
			$this->assertSame([], $reflection->getAttributes(NoAdminRequired::class), $method . ' must not open to non-admins.');
		}
	}//end testBothRoutesAreAdminOnly()

	/**
	 * An administrator maps `fr` to a voice and the stored map holds it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	public function testAnAdministratorMapsALanguageToAVoice(): void {
		$response = $this->controller(voices: ['fr' => 'ff_siwis', 'nl' => 'nf_dutch'])->update();

		$this->assertSame(200, $response->getStatus());
		$this->assertEquals((object)['fr' => 'ff_siwis', 'nl' => 'nf_dutch'], $response->getData()['voices']);
		$this->assertSame('{"fr":"ff_siwis","nl":"nf_dutch"}', $this->stored);
		$this->assertEquals((object)['fr' => 'ff_siwis', 'nl' => 'nf_dutch'], $this->controller()->get()->getData()['voices']);
	}//end testAnAdministratorMapsALanguageToAVoice()

	/**
	 * An invalid language answers 422 and leaves the stored map unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	public function testAnInvalidEntryIsA422(): void {
		$response = $this->controller(voices: ['Frans' => 'ff_siwis'])->update();

		$this->assertSame(422, $response->getStatus());
		$this->assertStringContainsString('"Frans" is not a language code', $response->getData()['error']);
		$this->assertSame('{"fr":"ff_siwis"}', $this->stored);
	}//end testAnInvalidEntryIsA422()
}//end class
