<?php

/**
 * Hermiq Code Sandbox Settings Controller
 *
 * Reads and saves where code runs: hermiq's own sandbox ExApp, or a hosted
 * sandbox reached through an integriq source (tools-code-sandbox, Task 2).
 * Admin only.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxSettings;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read and save the code sandbox backend.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */
class CodeSandboxSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request  The request.
	 * @param CodeSandboxSettings $settings The stored choice.
	 * @param CodeSandboxClient   $client   Says whether the chosen sandbox can take runs.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CodeSandboxSettings $settings,
		private readonly CodeSandboxClient $client,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The stored choice, its reach, and whether the chosen sandbox can take runs now.
	 *
	 * @return JSONResponse `{backend, hostedSource, reach, available}`.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function get(): JSONResponse {
		return new JSONResponse($this->settings->toArray() + ['available' => $this->client->isAvailable()]);
	}//end get()

	/**
	 * Save where code runs. A hosted choice needs the integriq source it is
	 * reached through; anything else is refused with 422 and stores nothing.
	 *
	 * @return JSONResponse The saved choice as get() answers it, or 422 with the reason.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(): JSONResponse {
		$backend = $this->request->getParam('backend');
		$source = $this->request->getParam('hostedSource');
		if (is_string($backend) === false || ($source !== null && is_string($source) === false)) {
			return new JSONResponse(['error' => 'Choose where code runs: exapp or hosted.'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		try {
			$this->settings->save(backend: $backend, hostedSource: $source);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->get();
	}//end update()
}//end class
