<?php

/**
 * Hermiq Provider Residency Settings Controller.
 *
 * Admin surface over `hermiq.providerResidency`: where each configured chat provider
 * runs, stated by the administrator who configured it. Mirrors
 * `WebResearchSettingsController`'s guard and shape. Nothing here accepts an endpoint
 * or a hostname, because nothing here is allowed to guess: the whole value of the
 * field is that somebody can be held to it.
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
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read and state where each configured provider runs.
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
 */
class ProviderResidencySettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ProviderResidencyRegistry $registry Reads/writes the declarations.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ProviderResidencyRegistry $registry,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Read every residency declaration, with the vocabulary an administrator may use.
	 *
	 * @return JSONResponse The declarations.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function get(): JSONResponse {
		try {
			$declarations = $this->registry->all();
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ProviderResidencySettingsController] Failed to read hermiq.providerResidency',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to read the provider residency declarations'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			[
				'residencies' => $declarations,
				'allowed' => ProviderResidencyRegistry::DECLARABLE_RESIDENCIES,
			]
		);

	}//end get()

	/**
	 * State where one provider runs.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return JSONResponse The stored declaration, or 422 on an unsupported value.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function declareResidency(string $provider): JSONResponse {
		$residency = $this->request->getParam('residency');
		$location = $this->request->getParam('location', '');

		try {
			$stored = $this->registry->declareResidency(
				provider: $provider,
				residency: (string)$residency,
				location: (string)$location
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ProviderResidencySettingsController] Failed to persist hermiq.providerResidency',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to save the provider residency declaration'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($stored);
	}//end declareResidency()
}//end class
