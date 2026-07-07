<?php

/**
 * Hermiq AiFeatureController.
 *
 * The governance surface for the design-time AI-feature register (EU AI Act
 * registration/oversight): list the tenant's risk-classified features, record the DPO
 * acknowledgement, and enable/disable a feature through OpenRegister's declarative
 * lifecycle. Listing runs in the caller's session context so OR's native RBAC + tenancy
 * deny cross-tenant access (mirrors SkillController).
 *
 * Security (ADR-005 Rule 3 / OWASP A01): `@NoAdminRequired` opens the routes to any
 * authenticated user, so the three governance mutations are NOT open to every caller —
 * each gates on ActionAuthService::requireAction() (ADR-023). The actions seed to
 * admin-only (`["admin"]`); an admin may broaden `aifeature.acknowledge` to a DPO group
 * via the action matrix. A refused caller gets 403; an unauthenticated caller 401.
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
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#3-controller-action-auth-adr-023-mirror-approvalcontroller
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\AlgoritmekaderMapper;
use OCA\Hermiq\Service\PublicationGateway;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Action-auth-gated AI-feature governance endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller orchestrates the
 *   governance service, the ADR-023 action-auth gate, the Algoritmekader readiness/mapper,
 *   and the runtime publication seam — each a distinct single-responsibility collaborator.
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#3-controller-action-auth-adr-023-mirror-approvalcontroller
 */
class AiFeatureController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request            The request object.
     * @param AiFeatureService     $aiFeatureService   The AiFeature read/govern path.
     * @param ActionAuthService    $actionAuth         The ADR-023 action-authorization service.
     * @param IUserSession         $userSession        Resolves the requesting user.
     * @param LoggerInterface      $logger             PSR-3 logger.
     * @param AlgoritmekaderMapper $algoritmekader     Publish-readiness gate + Algoritmekader mapping.
     * @param PublicationGateway   $publicationGateway Runtime seam to the fleet publication path (OpenCatalogi).
     *
     * @spec openspec/changes/algoritmeregister-publication/tasks.md#3-publish-withdraw-action-delegated-to-opencatalogi
     */
    public function __construct(
        IRequest $request,
        private readonly AiFeatureService $aiFeatureService,
        private readonly ActionAuthService $actionAuth,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly AlgoritmekaderMapper $algoritmekader,
        private readonly PublicationGateway $publicationGateway,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the tenant's AiFeature governance objects (RBAC + tenancy scoped).
     *
     * @return JSONResponse The AiFeature list, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-2
     */
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $features = array_map([$this, 'shape'], $this->aiFeatureService->listFeatures());
            return new JSONResponse(['results' => $features, 'total' => count($features)]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq AI-feature list failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load AI features'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end index()

    /**
     * Record the DPO acknowledgement for a feature (action-auth-gated).
     *
     * @param string $slug The AiFeature slug.
     *
     * @return JSONResponse The stamped feature, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-3
     */
    public function acknowledge(string $slug): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'aifeature.acknowledge');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            $feature = $this->aiFeatureService->acknowledge(slug: $slug, uid: $user->getUID());
            if ($feature === null) {
                return new JSONResponse(['error' => 'AI feature not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($this->shape(object: $feature));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq AI-feature acknowledge failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Acknowledge failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end acknowledge()

    /**
     * Enable a feature (action-auth-gated; the lifecycle guard blocks un-acknowledged features).
     *
     * @param string $id The AiFeature UUID.
     *
     * @return JSONResponse The transition outcome, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-4
     */
    public function enable(string $id): JSONResponse
    {
        return $this->drive(id: $id, action: 'aifeature.enable', enable: true);

    }//end enable()

    /**
     * Disable a feature (action-auth-gated; unguarded transition).
     *
     * @param string $id The AiFeature UUID.
     *
     * @return JSONResponse The transition outcome, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-4
     */
    public function disable(string $id): JSONResponse
    {
        return $this->drive(id: $id, action: 'aifeature.disable', enable: false);

    }//end disable()

    /**
     * Publish a feature to the national Algoritmeregister (delegated; action-auth-gated).
     *
     * 401 → action-auth → load RBAC-scoped (404 cross-tenant) → publish-readiness gate
     * (422 naming the failing conditions) → hand the mapped Algoritmekader publication to
     * the fleet publication path via the runtime seam (503 when OpenCatalogi is absent — the
     * feature stays internally governable, no national-portal call from hermiq). On success
     * stamp `algoritmeregisterStatus = gepubliceerd` + the returned reference.
     *
     * @param string $id The AiFeature UUID.
     *
     * @return JSONResponse The publication outcome, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
     */
    public function publishToAlgoritmeregister(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'aifeature.publish-to-algoritmeregister');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            $feature = $this->aiFeatureService->getFeature(id: $id);
            if ($feature === null) {
                return new JSONResponse(['error' => 'AI feature not found'], Http::STATUS_NOT_FOUND);
            }

            $data    = $feature->getObject();
            $failing = $this->algoritmekader->assessReadiness(feature: $data);
            if ($failing !== []) {
                // Fail-closed: never a partial/placeholder national-register entry.
                return new JSONResponse(
                    [
                        'error'   => 'Not ready to publish to the Algoritmeregister.',
                        'missing' => $failing,
                    ],
                    Http::STATUS_UNPROCESSABLE_ENTITY
                );
            }

            if ($this->publicationGateway->isAvailable() === false) {
                // OpenCatalogi absent — the feature remains internally governable.
                return new JSONResponse(
                    ['error' => 'The publication path (OpenCatalogi) is not available.'],
                    Http::STATUS_SERVICE_UNAVAILABLE
                );
            }

            $reference = $this->publicationGateway->publish(
                publication: $this->algoritmekader->map(feature: $data)
            );
            if ($reference === null) {
                return new JSONResponse(
                    ['error' => 'The publication path (OpenCatalogi) is not available.'],
                    Http::STATUS_SERVICE_UNAVAILABLE
                );
            }

            $stamped = $this->aiFeatureService->recordPublication(id: $id, reference: $reference);
            if ($stamped === null) {
                return new JSONResponse(['error' => 'AI feature not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($this->shape(object: $stamped));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq Algoritmeregister publish failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Publish failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end publishToAlgoritmeregister()

    /**
     * Withdraw a feature from the national Algoritmeregister (intrekken; action-auth-gated).
     *
     * 401 → action-auth → load RBAC-scoped (404 cross-tenant) → request unpublication
     * through the runtime seam → stamp `algoritmeregisterStatus = ingetrokken`.
     *
     * @param string $id The AiFeature UUID.
     *
     * @return JSONResponse The withdrawal outcome, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
     */
    public function withdrawFromAlgoritmeregister(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'aifeature.withdraw-from-algoritmeregister');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            $feature = $this->aiFeatureService->getFeature(id: $id);
            if ($feature === null) {
                return new JSONResponse(['error' => 'AI feature not found'], Http::STATUS_NOT_FOUND);
            }

            $reference = (string) ($feature->getObject()['algoritmeregisterRef'] ?? '');
            $this->publicationGateway->withdraw(reference: $reference);

            $stamped = $this->aiFeatureService->recordWithdrawal(id: $id);
            if ($stamped === null) {
                return new JSONResponse(['error' => 'AI feature not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($this->shape(object: $stamped));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq Algoritmeregister withdraw failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Withdraw failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end withdrawFromAlgoritmeregister()

    /**
     * Shared enable/disable driver: 401 → action-auth → transition → HTTP mapping.
     *
     * @param string $id     The AiFeature UUID.
     * @param string $action The ADR-023 action name to require.
     * @param bool   $enable Whether to enable (true) or disable (false) the feature.
     *
     * @return JSONResponse The transition outcome, or an error status.
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-4
     */
    private function drive(string $id, string $action, bool $enable): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: $action);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            if ($enable === true) {
                return $this->mapTransition(id: $id, result: $this->aiFeatureService->enable(id: $id));
            }

            return $this->mapTransition(id: $id, result: $this->aiFeatureService->disable(id: $id));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq AI-feature transition failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Transition failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end drive()

    /**
     * Map an AiFeatureService transition outcome to an HTTP response.
     *
     * @param string $id     The AiFeature UUID.
     * @param string $result One of the AiFeatureService::RESULT_* outcomes.
     *
     * @return JSONResponse The mapped response.
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-4
     */
    private function mapTransition(string $id, string $result): JSONResponse
    {
        switch ($result) {
            case AiFeatureService::RESULT_ENABLED:
            case AiFeatureService::RESULT_DISABLED:
                return new JSONResponse(['id' => $id, 'lifecycle' => $result]);
            case AiFeatureService::RESULT_NOT_FOUND:
                return new JSONResponse(['error' => 'AI feature not found'], Http::STATUS_NOT_FOUND);
            case AiFeatureService::RESULT_BLOCKED:
                return new JSONResponse(
                    ['error' => 'Transition blocked: the DPO has not acknowledged this AI feature.'],
                    Http::STATUS_CONFLICT
                );
            default:
                return new JSONResponse(
                    ['error' => 'Transition not allowed from the current state.'],
                    Http::STATUS_UNPROCESSABLE_ENTITY
                );
        }

    }//end mapTransition()

    /**
     * Shape an AiFeature ObjectEntity into a UUID + payload response map.
     *
     * @param ObjectEntity $object The AiFeature object.
     *
     * @return array<string, mixed> The response payload.
     *
     * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-3-2
     */
    private function shape(ObjectEntity $object): array
    {
        $data         = $object->getObject();
        $data['uuid'] = (string) $object->getUuid();
        return $data;

    }//end shape()
}//end class
