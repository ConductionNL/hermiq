<?php

/**
 * Hermiq Provider Data-Use Settings Controller (models-no-training-guarantee).
 *
 * Admin surface over `hermiq.providerDataUse`: what each configured provider does
 * with the data it is sent, stated by an administrator with the terms that say
 * so. The admin's user id and the time are stored with it, so the statement has
 * an owner. Nothing here inspects a provider; a term cannot be verified by code.
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
 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AiFeature\ProviderDataUseRegistry;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read and declare provider data use.
 *
 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
 */
class ProviderDataUseSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ProviderDataUseRegistry $registry The declarations.
	 * @param IUserSession $userSession Who declares.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		IRequest $request,
		private readonly ProviderDataUseRegistry $registry,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Every declaration and the values an admin may state.
	 *
	 * @return JSONResponse The declarations.
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function get(): JSONResponse {
		try {
			$declarations = $this->registry->all();
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ProviderDataUseSettingsController] Failed to read hermiq.providerDataUse',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to read the provider data-use declarations'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['dataUse' => $declarations, 'allowed' => ProviderDataUseRegistry::DECLARABLE]);

	}//end get()

	/**
	 * State what one provider does with the data it is sent.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return JSONResponse The stored declaration, or an error.
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function declare(string $provider): JSONResponse {
		$declaredBy = (string)($this->userSession->getUser()?->getUID() ?? '');

		try {
			$stored = $this->registry->declare(
				provider: $provider,
				dataUse: (string)$this->request->getParam('dataUse', ''),
				termsReference: (string)$this->request->getParam('termsReference', ''),
				declaredBy: $declaredBy
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ProviderDataUseSettingsController] Failed to persist hermiq.providerDataUse',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Failed to save the provider data-use declaration'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($stored);

	}//end declare()

}//end class
