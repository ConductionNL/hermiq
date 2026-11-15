<?php

/**
 * Hermiq OrganisationModelPolicyController.
 *
 * The org-admin/instance-admin surface for the per-organisation ModelPolicy
 * (tenant-model-policy): read the caller's effective policy (any authenticated
 * user, to populate the agent form's provider/model pickers), list the
 * caller-visible policies, and create/update them.
 *
 * Security (ADR-005 / OWASP A01): `@NoAdminRequired` opens every route to any
 * authenticated user; the method body is the guard. Reads of the EFFECTIVE policy
 * (`effective()`) require only an authenticated session — any organisation member
 * may read their own organisation's effective policy (spec: "Any authenticated
 * user with access to the organisation MAY read"). Writes (`create()`/`update()`)
 * are admitted ONLY for a Nextcloud instance admin (`IGroupManager::isAdmin`) or
 * the owner of the target OpenRegister organisation (`Organisation::getOwner`),
 * mirroring `OrganisationControlController::mayAdminister()` — with one addition: an
 * organisation-less write (the instance-wide default policy) is admitted ONLY for
 * an instance admin, never an org owner (there is no "owner" of no organisation).
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\EffectiveModelPolicyInterface;
use OCA\Hermiq\Kernel\Contracts\LlmSettingsHandlerInterface;
use OCA\Hermiq\Kernel\Contracts\OrganisationModelPolicyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ProviderDataUseRegistryInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Effective-policy read (any authenticated user) + admin/owner-gated CRUD.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is
 *   a distinct injected collaborator, not a logic-bearing argument list.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One injected collaborator per seam
 *   (model-policy service, user session, group manager, org mapper, logger) plus the
 *   HTTP response/exception types every endpoint returns.
 *
 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
 */
class OrganisationModelPolicyController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param OrganisationModelPolicyServiceInterface $modelPolicyService The model-policy read/write path.
	 * @param EffectiveModelPolicyInterface $effectivePolicy The policy that governs an organisation.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param IGroupManager $groupManager Instance-admin check.
	 * @param OrganisationMapper $organisationMapper OpenRegister organisation lookup
	 *                                               (owner check + effective-read
	 *                                               org resolution).
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ProviderDataUseRegistryInterface|null $dataUse Each provider's data-use label, for the
	 *                                              no-training switch in the editor.
	 */
	public function __construct(
		IRequest $request,
		private readonly OrganisationModelPolicyServiceInterface $modelPolicyService,
		private readonly EffectiveModelPolicyInterface $effectivePolicy,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly OrganisationMapper $organisationMapper,
		private readonly LoggerInterface $logger,
		private readonly ?ProviderDataUseRegistryInterface $dataUse = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's effective ModelPolicy: their organisation's own policy if one
	 * exists, else the instance-wide default, else the fail-closed fallback.
	 * Populates the agent create/edit form's provider/model pickers
	 * (agent-management-ui).
	 *
	 * @return JSONResponse The effective policy, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
	 */
	public function effective(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$organisation = $this->policyGate()->activeOrganisation(uid: $user->getUID(), logger: $this->logger);
			return new JSONResponse($this->effectivePolicy->effectivePolicyFor(organisation: $organisation));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq model-policy effective read failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load the effective model policy'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end effective()

	/**
	 * List the caller-visible ModelPolicy objects: every policy when the caller
	 * is an instance admin (who may administer any organisation), otherwise only
	 * the policies of organisations the caller owns.
	 *
	 * @return JSONResponse The visible policies, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$visible = $this->policyGate()->visiblePolicies(user: $user, listAll: fn (): array => $this->modelPolicyService->listAll());

			$shaped = array_map(
				function (ObjectEntity $policy) {
					$organisation = (string)($policy->getOrganisation() ?? '');
					$source = 'organisation';
					if ($organisation === '') {
						$source = 'instance';
					}

					return $this->effectivePolicy->effectivePolicyFor(organisation: $organisation) + [
						'id' => (string)($policy->getUuid() ?? ''),
						'organisation' => $organisation,
						'source' => $source,
					];
				},
				$visible
			);

			// Models-no-training-guarantee: what each provider declared about
			// training, so the policy editor can name the providers the switch would
			// refuse before anyone saves. Labels only, never the terms or who declared.
			$dataUse = [];
			foreach (LlmSettingsHandlerInterface::ALLOWED_CHAT_PROVIDERS as $provider) {
				$dataUse[$provider] = ($this->dataUse?->forProvider(provider: $provider)['dataUse'] ?? ProviderDataUseRegistryInterface::UNDECLARED);
			}

			return new JSONResponse(['policies' => $shaped, 'dataUse' => $dataUse]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq model-policy list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load model policies'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end index()

	/**
	 * Create (or upsert) the ModelPolicy for an organisation. Admin/owner-gated;
	 * an empty/omitted `organisation` targets the instance-wide default and is
	 * admitted ONLY for an instance admin.
	 *
	 * @return JSONResponse The persisted policy, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
	 */
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$organisation = (string)$this->request->getParam('organisation', '');
		if ($this->policyGate()->mayAdminister(organisation: $organisation, user: $user) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$payload = $this->request->getParams();
			return new JSONResponse($this->modelPolicyService->upsertForOrganisation(organisation: $organisation, payload: $payload));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq model-policy create failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not create the model policy'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end create()

	/**
	 * Update a ModelPolicy by UUID. Admin/owner-gated against the EXISTING
	 * policy's organisation, not a caller-supplied one — an org-subadmin may only
	 * write their own organisation's policy; only an instance admin may write the
	 * organisation-less instance-default policy.
	 *
	 * @param string $policyId The ModelPolicy object UUID.
	 *
	 * @return JSONResponse The updated policy, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
	 */
	public function update(string $policyId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->modelPolicyService->findById(uuid: $policyId);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$organisation = (string)($existing->getOrganisation() ?? '');
		if ($this->policyGate()->mayAdminister(organisation: $organisation, user: $user) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$payload = $this->request->getParams();
			return new JSONResponse($this->modelPolicyService->update(uuid: $policyId, payload: $payload));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq model-policy update failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not update the model policy'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end update()

	/**
	 * Who may read and administer this controller's policies.
	 *
	 * @return OrganisationPolicyGate The gate over this controller's group manager and organisation mapper.
	 */
	private function policyGate(): OrganisationPolicyGate {
		return new OrganisationPolicyGate(groupManager: $this->groupManager, organisationMapper: $this->organisationMapper);
	}//end policyGate()
}//end class
