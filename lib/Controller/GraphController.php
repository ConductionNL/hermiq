<?php

/**
 * Hermiq GraphController
 *
 * Runs an authored agent graph against a subject OpenRegister object. The primary
 * trigger for a graph is a Nextcloud event ({@see \OCA\Hermiq\Listener\GraphRunRequestedListener});
 * this endpoint is the manual / test entry point, mirroring RunNowController for
 * schedules. Admin-gated: executing an arbitrary graph is a privileged action.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://hermiq.app
 *
 * @spec openspec/changes/agent-graph-builder/specs/agent-graph/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Graph\GraphExecutor;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Manual / test entry point for running an agent graph.
 */
class GraphController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request       HTTP request.
     * @param ObjectService   $objectService Resolves the subject object.
     * @param GraphExecutor   $graphExecutor Walks the graph.
     * @param IUserSession    $userSession   Requesting user.
     * @param IGroupManager   $groupManager  Admin gate.
     * @param LoggerInterface $logger        PSR-3 logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly GraphExecutor $graphExecutor,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Run a graph against a subject object.
     *
     * Body: `{ graph: {nodes,edges,limits}, subjectUuid, subjectRegister, subjectSchema }`.
     *
     * @return JSONResponse The final run state, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-graph-builder/specs/agent-graph/spec.md
     */
    public function run(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
        }

        $graph           = $this->request->getParam('graph');
        $subjectUuid     = (string) $this->request->getParam('subjectUuid', '');
        $subjectRegister = (string) $this->request->getParam('subjectRegister', '');
        $subjectSchema   = (string) $this->request->getParam('subjectSchema', '');

        if (is_array($graph) === false || $subjectUuid === '' || $subjectRegister === '' || $subjectSchema === '') {
            return new JSONResponse(['error' => 'graph (object) and subjectUuid/subjectRegister/subjectSchema are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $object = $this->objectService->find(
                id: $subjectUuid,
                register: $subjectRegister,
                schema: $subjectSchema,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            return new JSONResponse(['error' => 'Subject object not found', 'message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        if (($object instanceof ObjectEntity) === false) {
            return new JSONResponse(['error' => 'Subject object not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $state = $this->graphExecutor->run(graph: $graph, object: $object);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq graph run failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Graph run failed', 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Strip @meta keys from the returned state for a compact response.
        $out = [];
        foreach ($state as $k => $v) {
            if (is_string($k) === true && str_starts_with($k, '@') === false) {
                $out[$k] = $v;
            }
        }

        return new JSONResponse(['subjectUuid' => $subjectUuid, 'state' => $out, 'trace' => $this->graphExecutor->trace]);
    }//end run()
}//end class
