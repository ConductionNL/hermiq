<?php

/**
 * Hermiq Report Similarity Settings Controller.
 *
 * The admin surface over the two grouping thresholds and the per-type comparison
 * window. Both thresholds are shown and both are editable, because the cost of each
 * kind of mistake is the municipality's to weigh: a genuinely separate report buried
 * inside a group of two hundred, or two hundred and one items where there was one
 * event.
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
 * @spec openspec/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilaritySettings;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read and set the grouping thresholds and windows.
 *
 * @spec openspec/specs/report-similarity/spec.md#requirement-the-comparison-window-must-be-bounded-and-administered
 */
class ReportSimilaritySettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ReportSimilaritySettings $settings The thresholds and windows.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ReportSimilaritySettings $settings,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Read both thresholds and the per-type windows.
	 *
	 * @return JSONResponse The settings.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function get(): JSONResponse {
		try {
			return new JSONResponse($this->settings->all());
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ReportSimilaritySettingsController] Failed to read the grouping settings',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to read the grouping settings'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end get()

	/**
	 * Set the thresholds, the windows, or both.
	 *
	 * @return JSONResponse The stored settings, or 422 naming what was wrong.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(): JSONResponse {
		try {
			$stored = $this->settings->store(
				upper: $this->numberParam(name: 'upper'),
				lower: $this->numberParam(name: 'lower'),
				windows: $this->windowsParam()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ReportSimilaritySettingsController] Failed to persist the grouping settings',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to save the grouping settings'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($stored);
	}//end update()

	/**
	 * A numeric request param as a float, or null when it is absent or not a number.
	 *
	 * @param string $name The param name.
	 *
	 * @return float|null
	 */
	private function numberParam(string $name): ?float {
		$value = $this->request->getParam($name);
		if (is_numeric($value) === false) {
			return null;
		}

		return (float)$value;
	}//end numberParam()

	/**
	 * The `windows` request param, or null when it is not a list.
	 *
	 * @return array|null
	 */
	private function windowsParam(): ?array {
		$windows = $this->request->getParam('windows');
		if (is_array($windows) === false) {
			return null;
		}

		return $windows;
	}//end windowsParam()
}//end class
