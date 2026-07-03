<?php

/**
 * Hermiq MCP Tool Provider (example / copy-me starting point)
 *
 * Minimal, heavily-commented example of OCA\OpenRegister\Mcp\IMcpToolProvider.
 * Every new Conduction app generated from this template ships this class so the
 * AI Chat Companion (hydra ADR-034 + ADR-035) is wired up by default — rename it
 * to <YourApp>ToolProvider, replace the two example tools with real ones, and
 * keep the per-object-authorisation-before-business-logic rule.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Mcp\AbstractToolHandler;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Example MCP tool provider — the AI Chat Companion entry point for this app.
 *
 * Extends AbstractToolHandler to inherit standardised requireWriteRole() and
 * requireAdminUser() helpers (fleet-standard pattern per openbuild PR #173).
 *
 * This is teaching scaffolding. To wire your app into the in-app AI assistant:
 *
 *  1. Implement {@see IMcpToolProvider} (this class already does). The interface
 *     ships in openregister PR #1466 — until that merges, apps implement the
 *     local stub at tests/Stubs/Mcp/IMcpToolProvider.php; once openregister is
 *     installed alongside this app the real interface takes over transparently.
 *  2. Register the provider in lib/AppInfo/Application.php under the service
 *     alias `OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}` — OpenRegister's
 *     McpToolsService discovers per-app providers by exactly that alias key.
 *  3. Namespace every tool id `{appId}.{toolName}` (e.g. `hermiq.ping`).
 *     The companion uses the prefix to route a tool call back to your app.
 *  4. Run per-object authorisation INSIDE invokeTool() — after argument
 *     validation but BEFORE any business logic or data access. Never assume the
 *     LLM (or the user steering it) is allowed to touch a given object.
 *  5. invokeTool() MUST NOT throw. Every failure path returns a structured
 *     `['error' => ['code' => ..., 'message' => ...]]` array so the companion
 *     can surface a clean message instead of a stack trace.
 *
 * See hydra/openspec/architecture/adr-034-ai-chat-companion.md and adr-035 for
 * the full design, and decidesk's `OCA\Decidesk\Mcp\DecideskToolProvider` for a
 * production example with five real tools, deep links, and source descriptors.
 */
class ExampleToolProvider extends AbstractToolHandler implements IMcpToolProvider
{

    /**
     * The tool catalogue this provider exposes (exactly two trivial examples).
     *
     * Hard-coded as a constant so unit tests can assert it as a fixture and so
     * the catalogue is identical between getTools() and the invokeTool() switch.
     * Replace these with your app's real tools — every descriptor needs
     * `id`, `name`, `description`, and `inputSchema` (a JSON Schema object).
     *
     * @var array<int, array<string, mixed>>
     */
    private const TOOL_DESCRIPTORS = [
        [
            // ← edit this: '{appId}.{toolName}' — the companion routes by the prefix.
            'id'          => Application::APP_ID.'.ping',
            // ← edit this: short human label shown in tool pickers.
            'name'        => 'Ping',
            // ← edit this: one sentence the LLM uses to decide when to call it.
            'description' => 'Health check. Returns ok=true and echoes the optional message back.',
            // ← edit this: JSON Schema for the arguments object.
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'message' => [
                        'type'        => 'string',
                        'description' => 'Optional text to echo back in the response.',
                    ],
                ],
                'required'   => [],
            ],
        ],
        [
            // ← edit this: '{appId}.{toolName}'.
            'id'          => Application::APP_ID.'.describeApp',
            // ← edit this.
            'name'        => 'Describe app',
            // ← edit this.
            'description' => 'Returns this app\'s id, version, and display name. Requires an authenticated user.',
            // ← edit this: this tool takes no arguments.
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [],
                'required'   => [],
            ],
        ],
    ];

    /**
     * Constructor.
     *
     * Keep this minimal — inject only what your tools actually need. The two
     * example tools need a way to check "is anyone logged in?" ({@see IUserSession})
     * and a way to read the app manifest ({@see IAppManager}). Real providers
     * usually also inject their service layer (see DecideskToolProvider).
     *
     * @param IUserSession $userSession  The current user session (for auth checks)
     * @param IGroupManager $groupManager The group manager (for admin checks)
     * @param IAppManager  $appManager  The app manager (for reading info.xml)
     */
    public function __construct(
        IUserSession $userSession,
        IGroupManager $groupManager,
        private readonly IAppManager $appManager,
    ) {
        $this->userSession  = $userSession;
        $this->groupManager = $groupManager;
    }//end __construct()

    /**
     * Returns the app id that namespaces every tool id this provider exposes.
     *
     * @return string The app slug — must match the `<id>` in appinfo/info.xml.
     */
    public function getAppId(): string
    {
        return Application::APP_ID;

    }//end getAppId()

    /**
     * Returns the full tool catalogue.
     *
     * Always returns the complete catalogue regardless of caller permissions —
     * per-object authorisation is enforced in {@see invokeTool()}, not here.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * Invoke a single tool by id.
     *
     * Dispatch skeleton — for each tool:
     *   1. validate args
     *   2. authorise (per-object, BEFORE business logic) — use requireWriteRole()
     *      or requireAdminUser() from AbstractToolHandler for standard checks
     *   3. delegate to your service layer
     *   4. return the payload  /  return ['error' => ['code' => ..., 'message' => ...]]
     *
     * NEVER throw. Unknown tool ids return a structured error envelope.
     *
     * @param string               $toolId    The tool id (e.g. "hermiq.ping")
     * @param array<string, mixed> $arguments Tool arguments from the LLM call
     *
     * @return array<string, mixed>
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        switch ($toolId) {
            case Application::APP_ID.'.ping':
                // 1. validate args  — `message` is optional, no validation needed.
                // 2. authorise       — none: a static echo touches no objects.
                // 3. delegate        — trivial, inline below.
                // 4. return.
                return [
                    'ok'   => true,
                    'echo' => ($arguments['message'] ?? null),
                ];

            case Application::APP_ID.'.describeApp':
                // 1. validate args  — none.
                // 2. authorise — require an authenticated user via AbstractToolHandler.
                $authError = $this->requireWriteRole();
                if ($authError !== null) {
                    return $authError;
                }

                // 3. delegate — read the app manifest via IAppManager.
                $info = $this->appManager->getAppInfo(Application::APP_ID);

                // 4. return.
                return [
                    'id'      => Application::APP_ID,
                    'version' => (string) ($info['version'] ?? ''),
                    'name'    => (string) ($info['name'] ?? Application::APP_ID),
                ];

            default:
                // Unknown tool — structured error, never an exception.
                return [
                    'error' => [
                        'code'    => 'unknown_tool',
                        'message' => "Unknown tool id '".$toolId."'. Available tools: "
                            .implode(', ', array_column(self::TOOL_DESCRIPTORS, 'id')).'.',
                    ],
                ];
        }//end switch

    }//end invokeTool()
}//end class
