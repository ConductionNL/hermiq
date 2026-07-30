<?php

/**
 * Hermiq GuardrailPolicyController.
 *
 * The org-owner/instance-admin surface for the per-organisation `GuardrailPolicy`
 * (agent-guardrails): read the caller's effective policy (any authenticated user,
 * to inform the chat UI of which controls are active), list the caller-visible
 * policies, and create/update them. Mirrors
 * `TenantModelPolicyController` exactly — same authorization shape, same
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
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\GuardrailPolicyService;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One injected collaborator per seam
 *   (policy service, user session, group manager, org mapper, logger) plus the HTTP
 *   response/exception types every endpoint returns.
 *
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
 */
class GuardrailPolicyController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest               $request            The request object.
     * @param GuardrailPolicyService $guardrailPolicy    The guardrail-policy read/write path.
     * @param IUserSession           $userSession        Resolves the requesting user.
     * @param IGroupManager          $groupManager       Instance-admin check.
     * @param OrganisationMapper     $organisationMapper OpenRegister organisation lookup
     *                                                   (owner check + effective-read
     *                                                   org resolution).
     * @param LoggerInterface        $logger             PSR-3 logger.
     */
    public function __construct(
        IRequest $request,
        private readonly GuardrailPolicyService $guardrailPolicy,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly OrganisationMapper $organisationMapper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
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
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
     */
    public function effective(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $organisation = (string) $this->request->getParam('organisation', $this->resolveActiveOrganisation(uid: $user->getUID()));
            return new JSONResponse($this->guardrailPolicy->effectivePolicyFor(organisation: $organisation));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq guardrail-policy effective read failed: '.$e->getMessage(), ['exception' => $e]);
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
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
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
            foreach ($this->guardrailPolicy->listAll() as $policy) {
                $organisation = (string) ($policy->getOrganisation() ?? '');
                if ($isAdmin === true || $this->ownsOrganisation(organisation: $organisation, uid: $user->getUID()) === true) {
                    $visible[] = $policy;
                }
            }

            $shaped = array_map(
                function (ObjectEntity $policy) {
                    $organisation = (string) ($policy->getOrganisation() ?? '');
                    return $this->guardrailPolicy->effectivePolicyFor(organisation: $organisation);
                },
                $visible
            );

            return new JSONResponse(['policies' => $shaped]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq guardrail-policy list failed: '.$e->getMessage(), ['exception' => $e]);
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
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
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
            return new JSONResponse($this->guardrailPolicy->upsertForOrganisation(organisation: $organisation, payload: $payload));
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq guardrail-policy create failed: '.$e->getMessage(), ['exception' => $e]);
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
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
     */
    public function update(string $policyId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $existing = $this->guardrailPolicy->findById(uuid: $policyId);
        if ($existing === null) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        $organisation = (string) ($existing->getOrganisation() ?? '');
        if ($this->mayAdminister(organisation: $organisation, user: $user) === false) {
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
            $this->logger->error('Hermiq guardrail-policy update failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not update the guardrail policy'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end update()

    /**
     * Whether the user may administer the GuardrailPolicy for the given
     * organisation scope. An empty `$organisation` (the instance-wide default) is
     * admitted ONLY for an instance admin — there is no "owner" of no
     * organisation. A non-empty organisation is admitted for an instance admin OR
     * the owner of that OpenRegister organisation.
     *
     * @param string $organisation The organisation identifier, or '' for the instance default.
     * @param IUser  $user         The requesting user.
     *
     * @return bool
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
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
     * (identity from session — no request parameter required). Falls back to ''
     * (the instance-wide default scope) when the user has no active/default
     * organisation, so the read never errors for a user outside any organisation.
     *
     * @param string $uid The requesting user's id.
     *
     * @return string The organisation identifier, or '' when none resolves.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
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
