<?php

/**
 * Hermiq SpeechVoiceSettingsController.
 *
 * The administrator's map from a language to a voice of the configured speech
 * model, used to speak a translated recording. Admin only: the routes carry no
 * NoAdminRequired, so Nextcloud refuses every other caller before this runs.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller\Settings
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

namespace OCA\Hermiq\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Speech\SpeechVoiceMap;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read and replace the language-to-voice map.
 *
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
 */
class SpeechVoiceSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request The request.
	 * @param SpeechVoiceMap  $voices  The map.
	 * @param LoggerInterface $logger  Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly SpeechVoiceMap $voices,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read the map.
	 *
	 * @return JSONResponse `{voices: {<language>: <voice>}}`.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function get(): JSONResponse {
		return new JSONResponse(['voices' => (object)$this->voices->all()]);
	}//end get()

	/**
	 * Replace the map.
	 *
	 * @return JSONResponse The stored map, or 422 for an invalid language or voice.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(): JSONResponse {
		$voices = $this->request->getParam('voices', []);

		try {
			$stored = $this->voices->save(map: $voices);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[SpeechVoiceSettingsController] Failed to save the voice map',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to save the voice map'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['voices' => (object)$stored]);
	}//end update()
}//end class
