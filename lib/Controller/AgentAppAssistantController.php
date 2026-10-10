<?php

/**
 * Hermiq AgentAppAssistantController.
 *
 * POST /api/agents/{id}/app-assistant: an organisation admin marks the agent that
 * answers in the assistant of the app it serves (agents-bound-to-their-app).
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Agent\AppAssistantService;
use OCA\Hermiq\Service\Agent\AppAssistantChoice;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Marks an app's assistant.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */
class AgentAppAssistantController extends Controller
{

    /**
     * Constructor.
     *
     * @param IRequest            $request     The request.
     * @param AppAssistantService $assistants  Marks the assistant, guards who and how many.
     * @param IUserSession        $userSession The caller.
     *
     * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    public function __construct(
        IRequest $request,
        private readonly AppAssistantService $assistants,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Mark or unmark the agent. Body: `appAssistant` (boolean).
     *
     * The caller's right is decided in AppAssistantService::mark(): 404 when they
     * cannot read the agent, 403 when they do not administer its organisation.
     *
     * @param string $id The agent.
     *
     * @return JSONResponse The flag and app, or 403, 404, 409 or 422.
     *
     * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        $uid  = (string) ($this->userSession->getUser()?->getUID() ?? '');
        $flag = $this->request->getParam('appAssistant');
        try {
            $agent = $this->assistants->mark(
                agentId: $id,
                assistant: ($flag === true || $flag === 'true' || $flag === '1' || $flag === 1),
                uid: $uid
            );
        } catch (RuntimeException $e) {
            $status = (int) $e->getCode();
            $known  = [Http::STATUS_FORBIDDEN, Http::STATUS_NOT_FOUND, Http::STATUS_CONFLICT, Http::STATUS_UNPROCESSABLE_ENTITY];
            if (in_array($status, $known, true) === false) {
                $status = Http::STATUS_INTERNAL_SERVER_ERROR;
            }

            return new JSONResponse(['error' => $e->getMessage()], $status);
        }

        $data = $agent->getObject();
        $app  = strtolower(trim((string) ($data['applicationSlug'] ?? '')));
        return new JSONResponse(
            [
                'appAssistant'    => (new AppAssistantChoice())->answersIn(data: $data, app: $app),
                'applicationSlug' => $app,
            ]
        );

    }//end update()
}//end class
