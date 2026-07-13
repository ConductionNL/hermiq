<?php

/**
 * Hermiq TenantModelPolicyController.
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
 * mirroring `TenantControlController::mayAdminister()` — with one addition: an
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
 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Effective-policy read (any authenticated user) + admin/owner-gated CRUD.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is
 *   a distinct injected collaborator, not a logic-bearing argument list.
 *
 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
 */
class TenantModelPolicyController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                 $request            The request object.
     * @param TenantModelPolicyService $modelPolicyService The model-policy read/write path.
     * @param IUserSession             $userSession        Resolves the requesting user.
     * @param IGroupManager            $groupManager       Instance-admin check.
     * @param OrganisationMapper       $organisationMapper OpenRegister organisation lookup
     *                                                     (owner check + effective-read
     *                                                     org resolution).
     * @param LoggerInterface          $logger             PSR-3 logger.
     */
    public function __construct(
        IRequest $request,
        private readonly TenantModelPolicyService $modelPolicyService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly OrganisationMapper $organisationMapper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
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
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
     */
    public function effective(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $organisation = $this->resolveActiveOrganisation(uid: $user->getUID());
            return new JSONResponse($this->modelPolicyService->effectivePolicyFor(organisation: $organisation));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq model-policy effective read failed: '.$e->getMessage(), ['exception' => $e]);
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
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $isAdmin = $this->groupManager->isAdmin($user->getUID());

            $visible = [];
            foreach ($this->modelPolicyService->listAll() as $policy) {
                $organisation = (string) ($policy->getOrganisation() ?? '');
                if ($isAdmin === true || $this->ownsOrganisation(organisation: $organisation, uid: $user->getUID()) === true) {
                    $visible[] = $policy;
                }
            }

            $shaped = array_map(
                function (ObjectEntity $policy) {
                    $organisation = (string) ($policy->getOrganisation() ?? '');
                    $source       = 'organisation';
                    if ($organisation === '') {
                        $source = 'instance';
                    }

                    return $this->modelPolicyService->effectivePolicyFor(organisation: $organisation) + [
                        'id'           => (string) ($policy->getUuid() ?? ''),
                        'organisation' => $organisation,
                        'source'       => $source,
                    ];
                },
                $visible
            );

            return new JSONResponse(['policies' => $shaped]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq model-policy list failed: '.$e->getMessage(), ['exception' => $e]);
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
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
     */
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $organisation = (string) $this->request->getParam('organisation', '');
        if ($this->mayAdminister(organisation: $organisation, user: $user) === false) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        try {
            $payload = $this->request->getParams();
            return new JSONResponse($this->modelPolicyService->upsertForOrganisation(organisation: $organisation, payload: $payload));
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq model-policy create failed: '.$e->getMessage(), ['exception' => $e]);
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
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
     */
    public function update(string $policyId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $existing = $this->modelPolicyService->findById(uuid: $policyId);
        if ($existing === null) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $organisation = (string) ($existing->getOrganisation() ?? '');
        if ($this->mayAdminister(organisation: $organisation, user: $user) === false) {
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
            $this->logger->error('Hermiq model-policy update failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not update the model policy'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end update()

    /**
     * Whether the user may administer the ModelPolicy for the given organisation
     * scope. An empty `$organisation` (the instance-wide default) is admitted
     * ONLY for an instance admin — there is no "owner" of no organisation. A
     * non-empty organisation is admitted for an instance admin OR the owner of
     * that OpenRegister organisation, mirroring
     * `TenantControlController::mayAdminister()`.
     *
     * @param string $organisation The organisation identifier, or '' for the instance default.
     * @param IUser  $user         The requesting user.
     *
     * @return bool
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
     */
    private function mayAdminister(string $organisation, IUser $user): bool
    {
        $isAdmin = $this->groupManager->isAdmin($user->getUID());

        if ($organisation === '') {
            return $isAdmin;
        }

        if ($isAdmin === true) {
            return true;
        }

        return $this->ownsOrganisation(organisation: $organisation, uid: $user->getUID());

    }//end mayAdminister()

    /**
     * Whether the given user owns the given OpenRegister organisation.
     *
     * @param string $organisation The organisation identifier.
     * @param string $uid          The user id.
     *
     * @return bool
     */
    private function ownsOrganisation(string $organisation, string $uid): bool
    {
        if ($organisation === '') {
            return false;
        }

        try {
            $org = $this->organisationMapper->findByUuid($organisation);
        } catch (Throwable $e) {
            return false;
        }

        return (string) ($org->getOwner() ?? '') === $uid;

    }//end ownsOrganisation()

    /**
     * Resolve the calling user's active organisation for the `effective()` read
     * (identity from session — no request parameter). Falls back to '' (the
     * instance-wide default scope) when the user has no active/default
     * organisation, so the read never errors for a user outside any organisation.
     *
     * @param string $uid The requesting user's id.
     *
     * @return string The organisation identifier, or '' when none resolves.
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
     */
    private function resolveActiveOrganisation(string $uid): string
    {
        try {
            if (method_exists($this->organisationMapper, 'getActiveOrganisationWithFallback') === true) {
                return (string) ($this->organisationMapper->getActiveOrganisationWithFallback($uid) ?? '');
            }
        } catch (Throwable $e) {
            $this->logger->warning('Hermiq could not resolve active organisation: '.$e->getMessage(), ['exception' => $e]);
        }

        return '';

    }//end resolveActiveOrganisation()
}//end class
