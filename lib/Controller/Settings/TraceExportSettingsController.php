<?php

/**
 * Hermiq Trace Export Settings Controller.
 *
 * The admin surface over trace export: on or off, the OTLP endpoint, the broker
 * credential by reference, the sources and how much leaves the server. Admin only
 * (hermiq ADR-023 rule 3); the secret itself never passes through here.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-an-administrator-configures-trace-export-with-a-brokered-credential-req-trex-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\TraceExport\TraceExportSettings;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read and set the trace export configuration.
 */
class TraceExportSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request  The request.
	 * @param TraceExportSettings $settings The stored settings.
	 * @param LoggerInterface     $logger   Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly TraceExportSettings $settings,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Read the settings, with the destination and copy notices.
	 *
	 * @return JSONResponse The settings.
	 *
	 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#scenario-an-administrator-connects-langfuse
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function get(): JSONResponse {
		try {
			return new JSONResponse($this->view(settings: $this->settings->get()));
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[TraceExportSettingsController] Failed to read the trace export settings',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to read the trace export settings'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end get()

	/**
	 * Store new settings. A value that cannot be used is a 422 and nothing changes.
	 *
	 * @return JSONResponse The stored settings, or the refusal.
	 *
	 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#scenario-a-non-admin-cannot-change-it
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(): JSONResponse {
		$input = [];
		foreach (array_keys(TraceExportSettings::DEFAULTS) as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$input[$key] = $value;
			}
		}

		try {
			$stored = $this->settings->save(input: $input);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[TraceExportSettingsController] Failed to save the trace export settings',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to save the trace export settings'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->view(settings: $stored));

	}//end update()

	/**
	 * The settings as the admin section shows them.
	 *
	 * @param array<string, mixed> $settings The settings.
	 *
	 * @return array<string, mixed>
	 */
	private function view(array $settings): array {
		return [
			'settings' => $settings,
			'destinationNotice' => $this->settings->destinationNotice(settings: $settings),
			'copyNotice' => TraceExportSettings::COPY_NOTICE,
		];

	}//end view()
}//end class
