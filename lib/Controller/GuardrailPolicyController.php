<?php

/**
 * Hermiq GuardrailPolicyController.
 *
 * The org-owner/instance-admin surface for the per-organisation `GuardrailPolicy`
 * (agent-guardrails): read the caller's effective policy (any authenticated user,
 * to inform the chat UI of which controls are active), list the caller-visible
 * policies, and create/update them. Mirrors
 * `OrganisationModelPolicyController` exactly — same authorization shape, same
 * effective/index/create/update method split — because this is the same kind of
 * per-organisation governance object (`ObjectEntity.organisation`, `_rbac: false,
 * _multitenancy: false`) with the same admin/owner-gated write surface.
 *
 * Security (ADR-005 / OWASP A01): `@NoAdminRequired` opens every route to any
 * authenticated user; the method body is the guard. Reads of the EFFECTIVE policy
 * (`effective()`) require only an authenticated session. Writes (`create()`/
 * `update()`) are admitted ONLY for a Nextcloud instance admin
 * (`IGroupManager::isAdmin`) or the owner of the target OpenRegister organisation
 * (`Organisation::getOwner`) — an organisation-less write (the instance-wide
 * default policy) is admitted ONLY for an instance admin, never an org owner.
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
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\GuardrailPolicyServiceInterface;
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
 *   (policy service, user session, group manager, org mapper, logger) plus the HTTP
 *   response/exception types every endpoint returns.
 *
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
 */
class GuardrailPolicyController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param GuardrailPolicyServiceInterface $guardrailPolicy The guardrail-policy read/write path.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param IGroupManager $groupManager Instance-admin check.
	 * @param OrganisationMapper $organisationMapper OpenRegister organisation lookup
	 *                                               (owner check + effective-read
	 *                                               org resolution).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly GuardrailPolicyServiceInterface $guardrailPolicy,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly OrganisationMapper $organisationMapper,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's effective GuardrailPolicy: their organisation's own policy if
	 * one exists and is enabled, else the instance-wide default, else the
	 * fully-open fallback. Lets the chat UI surface which controls are active.
	 *
	 * @return JSONResponse The effective policy, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
	 */
	public function effective(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$uid = $user->getUID();
			$organisation = (string)$this->request->getParam('organisation', $this->policyGate()->activeOrganisation(uid: $uid, logger: $this->logger));

			// The `organisation` parameter OVERRIDES the caller's own, and the
			// lookup behind it (GuardrailPolicyService::getForOrganisation)
			// reads with `_rbac: false, _multitenancy: false` — both guards
			// off by design, because the write path needs a policy's
			// organisation before it can decide who may administer it. That
			// makes this the one place the caller's scope has to be checked,
			// and it was not: any authenticated user could name any
			// organisation and read its effective policy — which PII and
			// prompt-injection filters are off, and which tools are `auto`.
			// That is another tenant's security posture.
			//
			// Same gate index() already applies to the list: an instance admin
			// may read any organisation, everyone else only their own. The
			// empty organisation is the instance default and stays readable —
			// effectivePolicyFor() falls back to it for everyone anyway.
			// Written as a positive permission rather than as "refuse unless
			// admin": this endpoint is NOT admin-only, and phrasing it that way
			// misreads it both to a human and to gate-9, which flags
			// NoAdminRequired next to an `isAdmin === false` refusal. Reading
			// your own organisation is the ordinary case; instance admin is a
			// widening on top of it, and the empty organisation is the instance
			// default that effectivePolicyFor() falls back to for everyone.
			$mayRead = ($organisation === ''
				|| $this->policyGate()->ownsOrganisation(organisation: $organisation, uid: $uid) === true
				|| $this->groupManager->isAdmin($uid) === true);

			if ($mayRead === false) {
				return new JSONResponse(
					['error' => 'You are not allowed to read this organisation\'s guardrail policy'],
					Http::STATUS_FORBIDDEN
				);
			}

			return new JSONResponse($this->guardrailPolicy->effectivePolicyFor(organisation: $organisation));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq guardrail-policy effective read failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load the effective guardrail policy'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end effective()

	/**
	 * List the caller-visible GuardrailPolicy objects: every policy when the
	 * caller is an instance admin (who may administer any organisation),
	 * otherwise only the policies of organisations the caller owns.
	 *
	 * @return JSONResponse The visible policies, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$visible = $this->policyGate()->visiblePolicies(user: $user, listAll: fn (): array => $this->guardrailPolicy->listAll());

			$shaped = array_map(
				function (ObjectEntity $policy) {
					$organisation = (string)($policy->getOrganisation() ?? '');
					return $this->guardrailPolicy->effectivePolicyFor(organisation: $organisation);
				},
				$visible
			);

			return new JSONResponse(['policies' => $shaped]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq guardrail-policy list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load guardrail policies'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end index()

	/**
	 * Create (or upsert) the GuardrailPolicy for an organisation. Admin/owner-gated;
	 * an empty/omitted `organisation` targets the instance-wide default and is
	 * admitted ONLY for an instance admin.
	 *
	 * @return JSONResponse The persisted policy, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
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
			return new JSONResponse($this->guardrailPolicy->upsertForOrganisation(organisation: $organisation, payload: $payload));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq guardrail-policy create failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not create the guardrail policy'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end create()

	/**
	 * Update a GuardrailPolicy by UUID. Admin/owner-gated against the EXISTING
	 * policy's organisation, not a caller-supplied one — an org-subadmin may only
	 * write their own organisation's policy; only an instance admin may write the
	 * organisation-less instance-default policy.
	 *
	 * @param string $policyId The GuardrailPolicy object UUID.
	 *
	 * @return JSONResponse The updated policy, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
	 */
	public function update(string $policyId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->guardrailPolicy->findById(uuid: $policyId);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$organisation = (string)($existing->getOrganisation() ?? '');
		if ($this->policyGate()->mayAdminister(organisation: $organisation, user: $user) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$payload = $this->request->getParams();
			return new JSONResponse($this->guardrailPolicy->update(uuid: $policyId, payload: $payload));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq guardrail-policy update failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not update the guardrail policy'], Http::STATUS_INTERNAL_SERVER_ERROR);
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
