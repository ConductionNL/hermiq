<?php

/**
 * ComputerTargetController: the admin routes for the machines an agent may operate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\ComputerUse\ComputerTargetRefusal;
use OCA\Hermiq\Service\ComputerUse\ComputerTargetService;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * List, create, update and remove computer targets, and take a test screenshot.
 *
 * Every route is admin-only (`AuthorizedAdminSetting`): a target names a
 * machine in the organisation's network and the source that reaches it.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */
class ComputerTargetController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request The request.
	 * @param ComputerTargetService $targets The computer targets.
	 */
	public function __construct(
		IRequest $request,
		private readonly ComputerTargetService $targets,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every computer target.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse(['results' => $this->targets->list()]);
	}//end index()

	/**
	 * Create a computer target.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function create(): JSONResponse {
		return $this->answer(work: fn (): array => $this->targets->save(data: $this->request->getParams()), status: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Update a computer target.
	 *
	 * @param string $id The target's uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(string $id): JSONResponse {
		return $this->answer(work: fn (): array => $this->targets->save(data: $this->request->getParams(), id: $id));
	}//end update()

	/**
	 * Remove a computer target.
	 *
	 * @param string $id The target's uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function destroy(string $id): JSONResponse {
		return $this->answer(
			work: function () use ($id): array {
				$this->targets->delete(id: $id);
				return ['deleted' => $id];
			}
		);
	}//end destroy()

	/**
	 * Take a test screenshot of a computer target; it is shown, not stored.
	 *
	 * @param string $id The target's uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function screenshot(string $id): JSONResponse {
		return $this->answer(work: fn (): array => $this->targets->screenshot(id: $id));
	}//end screenshot()

	/**
	 * Run the work and answer it, or the refusal it raised.
	 *
	 * @param callable $work   Returns the response body.
	 * @param int      $status The status of a successful answer.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $work, int $status=Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse($work(), $status);
		} catch (ComputerTargetRefusal $refusal) {
			return new JSONResponse(['error' => $refusal->getMessage()], $refusal->getStatus());
		}
	}//end answer()
}//end class
