<?php

/**
 * Hermiq API version header.
 *
 * Every response of a Hermiq `/api/` endpoint names the version of the API
 * that answered in the `API-Version` header, as the NL API Design Rules
 * require (/core/version-header). The API version is the installed app
 * version, the same value as `info.version` in `/api/openapi.json`.
 *
 * @category Middleware
 * @package  OCA\Hermiq\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Middleware;

use OCA\Hermiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Adds `API-Version` to the responses of Hermiq's `/api/` endpoints.
 *
 * @category Middleware
 * @package  OCA\Hermiq\Middleware
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */
class ApiVersionMiddleware extends Middleware {

	/**
	 * The response header that names the answering API version.
	 */
	public const HEADER = 'API-Version';

	/**
	 * Constructor.
	 *
	 * @param IRequest    $request    The current request.
	 * @param IAppManager $appManager Resolves the installed app version.
	 *
	 * @psalm-suppress PossiblyUnusedMethod Instantiated via Nextcloud dependency injection.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Name the API version on a response of an `/api/` endpoint.
	 *
	 * Other responses (the SPA pages) are returned unchanged.
	 *
	 * @param \OCP\AppFramework\Controller $controller The controller that answered.
	 * @param string                       $methodName The controller method.
	 * @param Response                     $response   The response.
	 *
	 * @return Response The same response.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the framework's.
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		if ($this->isApiRequest() === false) {
			return $response;
		}

		$version = $this->appManager->getAppVersion(Application::APP_ID);
		if ($version === '') {
			return $response;
		}

		$response->addHeader(self::HEADER, $version);

		return $response;
	}//end afterController()

	/**
	 * Whether the request is for one of the app's `/api/` endpoints.
	 *
	 * @return bool True for an `/api/` path.
	 */
	private function isApiRequest(): bool {
		$path = $this->request->getPathInfo();

		return is_string($path) === true && str_contains($path, '/api/') === true;
	}//end isApiRequest()
}//end class
