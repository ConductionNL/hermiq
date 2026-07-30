<?php

/**
 * Hermiq TalkBridgeSettingsController.
 *
 * Admin-only read/write surface for the Talk chat bridge, so an operator can
 * answer "why is this agent replying in this room?" and change the answer
 * without `occ` or database access.
 *
 * Admin-gated via `#[AuthorizedAdminSetting]`, mirroring `LlmSettingsController`.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talk-chat-bridge/tasks.md#7-opt-in-and-admin-visibility
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBridgeStatus;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports and edits the Talk bridge configuration.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
 */
class TalkBridgeSettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request      The request.
     * @param TalkBridgeStatus $status       Reports the bridge's effective configuration.
     * @param TalkAgentBinding $agentBinding Reads and writes the room→agent map.
     * @param LoggerInterface  $logger       PSR-3 logger.
     */
    public function __construct(
        IRequest $request,
        private readonly TalkBridgeStatus $status,
        private readonly TalkAgentBinding $agentBinding,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Read the bridge's effective configuration.
     *
     * Never fails on a Talk-less instance: the payload simply reports
     * `talkAvailable: false` and an empty room list, so the panel renders.
     *
     * @return JSONResponse The bridge status.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function get(): JSONResponse
    {
        try {
            return new JSONResponse($this->status->describe());
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[TalkBridgeSettingsController] Failed to describe the Talk bridge',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Failed to read the Talk bridge configuration'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

    }//end get()

    /**
     * Bind a room to an agent, or unbind it.
     *
     * An empty `agentId` removes the binding, which is how an operator turns a
     * room off from Hermiq's side without touching Talk.
     *
     * @return JSONResponse The updated bridge status.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-integration-is-opt-in-on-both-sides
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function bindRoom(): JSONResponse
    {
        $token   = trim((string) $this->request->getParam('token', ''));
        $agentId = trim((string) $this->request->getParam('agentId', ''));

        if ($token === '') {
            return new JSONResponse(['error' => 'A room token is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->agentBinding->bindRoom(roomToken: $token, agentId: $agentId);

            return new JSONResponse($this->status->describe());
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[TalkBridgeSettingsController] Failed to bind a room to an agent',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Failed to update the room binding'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end bindRoom()
}//end class
