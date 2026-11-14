<?php

/**
 * Hermiq CustomToolController
 *
 * Organisation admins and instance admins list, create and edit the custom
 * tools of an organisation. Nobody else may.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\CustomTool\CustomToolService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The custom tools API: GET and POST /api/custom-tools, PUT /api/custom-tools/{id}.
 *
 * Every route checks OrganisationPolicyGate::mayAdminister() for the
 * organisation the tool belongs to: its owner or an instance admin.
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
class CustomToolController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request            The request.
	 * @param CustomToolService  $customTools        Stores the tools.
	 * @param IUserSession       $userSession        The caller.
	 * @param IGroupManager      $groupManager       Instance admins.
	 * @param OrganisationMapper $organisationMapper Organisation owners and the active organisation.
	 * @param LoggerInterface    $logger             Logs a failed read or write.
	 */
	public function __construct(
		IRequest $request,
		private readonly CustomToolService $customTools,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly OrganisationMapper $organisationMapper,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The custom tools of an organisation, the caller's active one by default.
	 *
	 * Access control: owner of the organisation or instance admin.
	 *
	 * @return JSONResponse 200 {tools}; 401 or 403
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$organisation = $this->organisationOf(user: $user);
		if ($this->policyGate()->mayAdminister(organisation: $organisation, user: $user) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse(['tools' => $this->customTools->listForOrganisation(organisation: $organisation)]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq custom tool list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load the custom tools'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * Create a custom tool in an organisation, the caller's active one by default.
	 *
	 * Access control: owner of the organisation or instance admin.
	 *
	 * @return JSONResponse 201 the tool; 400 naming the wrong field; 401 or 403
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$organisation = $this->organisationOf(user: $user);
		if ($this->policyGate()->mayAdminister(organisation: $organisation, user: $user) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $this->write(organisation: $organisation, uuid: null, status: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Edit a custom tool.
	 *
	 * Access control: owner of the tool's organisation or instance admin.
	 *
	 * @param string $id The custom tool's uuid.
	 *
	 * @return JSONResponse 200 the tool; 400 naming the wrong field; 401, 403 or 404
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->customTools->findById(uuid: $id);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$organisation = (string)($existing->getOrganisation() ?? '');
		if ($this->policyGate()->mayAdminister(organisation: $organisation, user: $user) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $this->write(organisation: $organisation, uuid: $id, status: Http::STATUS_OK);
	}//end update()

	/**
	 * Save the request's tool, answering a validation failure with 400.
	 *
	 * @param string      $organisation The tool's organisation.
	 * @param string|null $uuid         The tool to update, or null.
	 * @param int         $status       The status on success.
	 *
	 * @return JSONResponse
	 */
	private function write(string $organisation, ?string $uuid, int $status): JSONResponse {
		try {
			return new JSONResponse($this->customTools->save(organisation: $organisation, payload: $this->request->getParams(), uuid: $uuid), $status);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq custom tool save failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not save the custom tool'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end write()

	/**
	 * The organisation the request names, else the caller's active one.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return string
	 */
	private function organisationOf(IUser $user): string {
		$named = $this->request->getParam('organisation');
		if (is_string($named) === true && $named !== '') {
			return $named;
		}

		return $this->policyGate()->activeOrganisation(uid: $user->getUID(), logger: $this->logger);
	}//end organisationOf()

	/**
	 * The organisation gate.
	 *
	 * @return OrganisationPolicyGate
	 */
	private function policyGate(): OrganisationPolicyGate {
		return new OrganisationPolicyGate(groupManager: $this->groupManager, organisationMapper: $this->organisationMapper);
	}//end policyGate()
}//end class
