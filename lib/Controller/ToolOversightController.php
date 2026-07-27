<?php

/**
 * Hermiq ToolOversightController (agent-tool-governance-and-disclosure).
 *
 * Three read/write endpoints over the ADR-063 consumer-side governance layer:
 *
 * - `GET tool-catalog`     — the grant-annotated derived catalog for the grant
 *   editor (design.md §"API Design").
 * - `PUT tool-grants`      — persists `Agent.tools` (owner-gated, single write-path
 *   via `ObjectService`).
 * - `GET tool-invocations` — the per-agent oversight surface (EU AI Act art.12/14),
 *   sourced from OpenRegister's MCP invocation AuditTrail entries when available,
 *   degrading gracefully to the coarser `run`-action entries `run-audit-log`
 *   already reads when the richer shape is absent (LIMITATION, carried from the
 *   cross-repo brief: OR's audit entry records the ambient NC session user, not an
 *   agent principal, so "per-agent" here is CORRELATED via this agent's own owner
 *   + its schedules' owners — not a first-class agent-identity column upstream).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tool-catalog / tool-grants / tool-invocations endpoints.
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-5
 */
class ToolOversightController extends Controller
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for agent objects.
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * Schema slug for schedule objects (owner-correlation for the oversight read).
     *
     * @var string
     */
    private const SCHEDULE_SCHEMA = 'schedule';

    /**
     * Default progressive-disclosure threshold — mirrors `ToolLoop`'s default.
     *
     * @var int
     */
    private const DEFAULT_DISCLOSURE_THRESHOLD = 30;

    /**
     * The audit `action` values an MCP tool invocation entry carries
     * (`AuditTrailMapper::createToolInvocationEntry()`: `mcp.{verb}`).
     *
     * @var string
     */
    private const MCP_ACTIONS = 'mcp.search,mcp.get,mcp.create,mcp.update,mcp.delete';

    /**
     * Constructor.
     *
     * @param IRequest           $request          The request object.
     * @param ObjectService      $objectService    OpenRegister object read/write (single write-path).
     * @param ToolRegistryFacade $toolRegistry     OR's public tool read surface (gate-27 contract).
     * @param ToolGrantResolver  $grantResolver    Schema-scoped grant expansion + default-deny.
     * @param AuditTrailMapper   $auditTrailMapper OR audit read (MCP invocation / run entries).
     * @param IAppConfig         $appConfig        Reads `hermiq.tools.disclosureThreshold`.
     * @param IUserSession       $userSession      Resolves the requesting user.
     * @param IGroupManager      $groupManager     Instance-admin check for the oversight bypass.
     * @param LoggerInterface    $logger           PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
     *   distinct injected collaborator, not a logic-bearing argument list.
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-5
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly ToolRegistryFacade $toolRegistry,
        private readonly ToolGrantResolver $grantResolver,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * The grant-annotated derived catalog for the grant editor.
     *
     * @param string $agentId Agent UUID.
     *
     * @return JSONResponse The catalog payload, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/design.md#api-design
     */
    public function toolCatalog(string $agentId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $agent = $this->findAgent(agentId: $agentId);
        if ($agent === null) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        if ($this->canAccessAgent(agent: $agent, userId: $user->getUID()) === false) {
            return new JSONResponse(['error' => 'Access denied to this agent'], Http::STATUS_FORBIDDEN);
        }

        try {
            $grants      = $this->agentGrants(agent: $agent);
            $catalog     = $this->toolRegistry->listTools();
            $resolvedIds = $this->grantResolver->resolve(grants: $grants, catalog: $catalog);
            $resolvedSet = array_flip($resolvedIds);

            $tools = [];
            foreach ($catalog as $descriptor) {
                $id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
                if (is_string($id) === false || $id === '') {
                    continue;
                }

                $isWrite = ToolGrantResolver::isWriteOrDestructive(id: $id);
                $granted = isset($resolvedSet[$id]);

                $scope = 'read';
                if ($isWrite === true) {
                    $scope = 'write';
                }

                $tools[] = [
                    'id'                    => $id,
                    'name'                  => (string) ($descriptor['name'] ?? $id),
                    'description'           => (string) ($descriptor['description'] ?? ''),
                    'scope'                 => $scope,
                    'destructiveHint'       => $isWrite,
                    'granted'               => $granted,
                    'grantedBy'             => $this->grantedBy(id: $id, grants: $grants, granted: $granted),
                    'requiresExplicitGrant' => ($isWrite === true && $granted === false),
                ];
            }//end foreach

            $threshold     = $this->disclosureThreshold();
            $resolvedCount = count($resolvedIds);

            return new JSONResponse(
                [
                    'agentId'             => $agentId,
                    'disclosureThreshold' => $threshold,
                    'resolvedCount'       => $resolvedCount,
                    'disclosureActive'    => ($resolvedCount > $threshold),
                    'tools'               => $tools,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error('Hermiq tool-catalog read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load tool catalog'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end toolCatalog()

    /**
     * Persist the `Agent.tools` grant array (owner-only, single write-path).
     *
     * Deliberately NOT `@NoCSRFRequired` — unlike the two read endpoints, this one
     * MUTATES an authorization boundary (which tools an agent may call), so the CSRF
     * guard stays ON. `@nextcloud/axios` sends the requesttoken on every request, so
     * `src/api/toolOversight.js`'s PUT works unchanged; a cross-site forger cannot
     * silently widen an agent's grants.
     *
     * @param string $agentId Agent UUID.
     *
     * @return JSONResponse The updated grant array, or an error status.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/design.md#put-apiagentsagentidtool-grants
     */
    public function updateToolGrants(string $agentId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $agent = $this->findAgent(agentId: $agentId);
        if ($agent === null) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        // Owner-only modification guard (gate-7 no-admin-idor), mirroring
        // AgentsController::canUserModifyAgent().
        if ($agent->getOwner() !== $user->getUID() || $user->getUID() === '') {
            return new JSONResponse(
                ['error' => 'You do not have permission to modify this agent'],
                Http::STATUS_FORBIDDEN
            );
        }

        $rawGrants = $this->request->getParam('grants');
        if (is_array($rawGrants) === false) {
            return new JSONResponse(['error' => 'grants must be an array of strings'], Http::STATUS_BAD_REQUEST);
        }

        $grants = [];
        foreach ($rawGrants as $grant) {
            if (is_string($grant) === true && $grant !== '') {
                $grants[] = $grant;
            }
        }

        try {
            $payload = array_merge($agent->getObject(), ['tools' => $grants]);
            $updated = $this->objectService->saveObject(
                object: $payload,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA,
                uuid: $agentId
            );

            return new JSONResponse(
                [
                    'agentId' => $agentId,
                    'tools'   => ($updated->getObject()['tools'] ?? $grants),
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error('Hermiq tool-grants update failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not update tool grants'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end updateToolGrants()

    /**
     * The per-agent oversight read (EU AI Act art.12/14): recorded tool
     * invocations, newest first, tenant-scoped, with a retention note and a
     * CSV/JSON export.
     *
     * @param string $agentId Agent UUID.
     * @param string $format  `json` (default) or `csv`.
     * @param string $from    Optional ISO 8601 lower bound (inclusive).
     * @param string $to      Optional ISO 8601 upper bound (inclusive).
     *
     * @return JSONResponse|DataDownloadResponse The oversight payload, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art1214
     */
    public function toolInvocations(string $agentId, string $format='json', string $from='', string $to=''): JSONResponse|DataDownloadResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $agent = $this->findAgent(agentId: $agentId);
        if ($agent === null) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        if ($this->canAccessAgent(agent: $agent, userId: $user->getUID()) === false) {
            return new JSONResponse(['error' => 'Access denied to this agent'], Http::STATUS_FORBIDDEN);
        }

        try {
            $available = $this->richAuditAvailable();
            $ownerUids = $this->correlatedOwnerUids(agentId: $agentId, agent: $agent);

            $fromFilter = null;
            if ($from !== '') {
                $fromFilter = $from;
            }

            $toFilter = null;
            if ($to !== '') {
                $toFilter = $to;
            }

            $source = 'run-audit-log';
            $rows   = $this->degradedRows(agentId: $agentId, from: $fromFilter, to: $toFilter);
            if ($available === true) {
                $source = 'or-mcp-invocation-audit';
                $rows   = $this->richRows(ownerUids: $ownerUids, from: $fromFilter, to: $toFilter);
            }

            $payload = [
                'agentId'   => $agentId,
                'available' => $available,
                'source'    => $source,
                'retention' => "Retention follows OpenRegister's AuditTrail policy.",
                'rows'      => $rows,
            ];

            if ($format === 'csv') {
                return new DataDownloadResponse(
                    data: $this->toCsv(rows: $rows),
                    filename: 'hermiq-tool-invocations-'.$agentId.'.csv',
                    contentType: 'text/csv'
                );
            }

            return new JSONResponse($payload);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq tool-invocations read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load tool invocations'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end toolInvocations()

    /**
     * Load an agent (tenant-scoped by `ObjectService` multitenancy/RBAC, exactly
     * like `AgentsController::show()`).
     *
     * @param string $agentId Agent UUID.
     *
     * @return ObjectEntity|null
     */
    private function findAgent(string $agentId): ?ObjectEntity
    {
        $agent = $this->objectService->find(id: $agentId, register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA);
        if (($agent instanceof ObjectEntity) === false) {
            return null;
        }

        return $agent;

    }//end findAgent()

    /**
     * Whether the user may view an agent: non-private OR owner OR invited —
     * mirrors `AgentsController::canUserAccessAgent()` (duplicated locally; no
     * cross-controller coupling for a small visibility rule).
     *
     * @param ObjectEntity $agent  Agent object.
     * @param string       $userId Nextcloud user id.
     *
     * @return bool
     */
    private function canAccessAgent(ObjectEntity $agent, string $userId): bool
    {
        $data      = $agent->getObject();
        $isPrivate = ($data['isPrivate'] ?? null);

        if ($isPrivate === false || $isPrivate === null) {
            return true;
        }

        if ($agent->getOwner() === $userId) {
            return true;
        }

        $invitedUsers = ($data['invitedUsers'] ?? []);
        if (is_array($invitedUsers) === true && in_array($userId, $invitedUsers, true) === true) {
            return true;
        }

        // Instance-admin oversight bypass. This is a governance surface (EU AI Act
        // art.12/14 tool-invocation oversight), so an instance admin must be able to
        // inspect any agent's tool catalogue/activity — including system-owned seeded
        // agents (owner `__system__`) that no human owns and OpenRegister lets admins
        // read anyway. Deliberately scoped to this oversight controller; it does NOT
        // widen general private-agent access elsewhere.
        return $this->groupManager->isAdmin($userId);

    }//end canAccessAgent()

    /**
     * The agent's raw `Agent.tools` grant strings, sanitized.
     *
     * @param ObjectEntity $agent Agent object.
     *
     * @return array<int, string>
     */
    private function agentGrants(ObjectEntity $agent): array
    {
        $tools = ($agent->getObject()['tools'] ?? []);
        if (is_array($tools) === false) {
            return [];
        }

        $clean = [];
        foreach ($tools as $tool) {
            if (is_string($tool) === true && $tool !== '') {
                $clean[] = $tool;
            }
        }

        return $clean;

    }//end agentGrants()

    /**
     * Best-effort "which grant entry produced this id" for the editor's UX —
     * informational only, never load-bearing for the grant/deny decision itself.
     *
     * @param string             $id      The resolved tool id.
     * @param array<int, string> $grants  The agent's raw grant strings.
     * @param bool               $granted Whether `$id` is in the resolved set.
     *
     * @return string|null
     */
    private function grantedBy(string $id, array $grants, bool $granted): ?string
    {
        if ($granted === false) {
            return null;
        }

        foreach ($grants as $grant) {
            if ($grant === $id) {
                return $grant;
            }
        }

        $parts = explode('.', $id);
        if (count($parts) === 3) {
            $prefix = $parts[0].'.'.$parts[1];
            foreach ($grants as $grant) {
                if ($grant === ($prefix.'.*') || $grant === ($prefix.'.*:write')) {
                    return $grant;
                }
            }
        }

        if ($grants === []) {
            return '(default: all discovered tools)';
        }

        return null;

    }//end grantedBy()

    /**
     * The configured progressive-disclosure threshold.
     *
     * @return int
     */
    private function disclosureThreshold(): int
    {
        return $this->appConfig->getValueInt('hermiq', 'tools.disclosureThreshold', self::DEFAULT_DISCLOSURE_THRESHOLD);

    }//end disclosureThreshold()

    /**
     * Whether OpenRegister's richer per-invocation MCP audit shape
     * (`AuditTrailMapper::createToolInvocationEntry()`) is present — detected by
     * the DECLARED `toolId` property existing on the installed `AuditTrail`
     * entity class (real accessors are Nextcloud `Entity::__call()` magic, so a
     * `method_exists()` check would be unreliable; the declared property is not).
     * False in standalone unit runs (the test stub predates this chain) and on
     * any OpenRegister install that has not yet shipped it (Risk 4 fallback).
     *
     * `protected`, not `private`: a class-shape check like this cannot be
     * per-test-mocked any other way (it is not behind an injected collaborator),
     * so `ToolOversightControllerTest` exercises both branches via an anonymous
     * subclass overriding this one method — the rest of the controller is
     * exercised unchanged.
     *
     * @return bool
     */
    protected function richAuditAvailable(): bool
    {
        return property_exists(AuditTrail::class, 'toolId');

    }//end richAuditAvailable()

    /**
     * The owner UIDs correlated with this agent: its own owner plus the owners
     * of every schedule bound to it (the LIMITATION documented in the class
     * docblock — OR's MCP audit entry records the ambient session user, not an
     * agent principal, so this is the best available correlation).
     *
     * @param string       $agentId Agent UUID.
     * @param ObjectEntity $agent   Agent object.
     *
     * @return array<int, string>
     */
    private function correlatedOwnerUids(string $agentId, ObjectEntity $agent): array
    {
        $owners = [];

        $agentOwner = (string) ($agent->getOwner() ?? '');
        if ($agentOwner !== '') {
            $owners[$agentOwner] = true;
        }

        $schedules = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEDULE_SCHEMA)
            ->findAll(config: ['limit' => 1000]);

        foreach ($schedules as $schedule) {
            if (($schedule instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $schedule->getObject();
            if ((string) ($data['agentId'] ?? '') !== $agentId) {
                continue;
            }

            $owner = (string) ($schedule->getOwner() ?? '');
            if ($owner !== '') {
                $owners[$owner] = true;
            }
        }

        return array_keys($owners);

    }//end correlatedOwnerUids()

    /**
     * Read the richer per-invocation MCP audit rows, correlated by acting user
     * to this agent's owner set.
     *
     * @param array<int, string> $ownerUids Correlated owner UIDs.
     * @param string|null        $from      Optional ISO 8601 lower bound (inclusive).
     * @param string|null        $to        Optional ISO 8601 upper bound (inclusive).
     *
     * @return array<int, array<string, mixed>> Newest-first rows.
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-an-operator-reviews-an-agents-tool-activity
     */
    private function richRows(array $ownerUids, ?string $from, ?string $to): array
    {
        if ($ownerUids === []) {
            return [];
        }

        $ownerSet = array_flip($ownerUids);
        $logs     = $this->auditTrailMapper->findAll(filters: ['action' => self::MCP_ACTIONS]);

        $rows = [];
        foreach ($logs as $log) {
            $user = (string) ($log->getUser() ?? '');
            if (isset($ownerSet[$user]) === false) {
                continue;
            }

            $created = $log->getCreated();
            $at      = null;
            if ($created !== null) {
                $at = $created->format('c');
            }

            if ($this->withinRange(at: $at, from: $from, to: $to) === false) {
                continue;
            }

            $rows[] = [
                'at'            => $at,
                'toolId'        => $log->getToolId(),
                'actingUser'    => $user,
                // The audit entry never carries raw argument values
                // (REQ-DERIVED-006: "never raw object payloads") — only a
                // SHA-256 digest survives. Surfacing the digest is honest;
                // inventing decoded field values is not.
                'paramsDigest'  => $log->getParamsDigest(),
                'resultSummary' => $log->getResultSummary(),
                'dataTouched'   => array_values(array_filter([$log->getObjectUuid()])),
            ];
        }//end foreach

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return $rows;

    }//end richRows()

    /**
     * Degraded fallback: coarse `action='run'` entries for this agent's
     * schedules (the same source `run-audit-log`/`AnalyticsService` already
     * read), used when the richer per-invocation shape is not yet available.
     *
     * @param string      $agentId Agent UUID.
     * @param string|null $from    Optional ISO 8601 lower bound (inclusive).
     * @param string|null $to      Optional ISO 8601 upper bound (inclusive).
     *
     * @return array<int, array<string, mixed>> Newest-first rows.
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-the-richer-invocation-audit-shape-is-not-yet-available
     */
    private function degradedRows(string $agentId, ?string $from, ?string $to): array
    {
        $scheduleUuids = [];

        $schedules = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEDULE_SCHEMA)
            ->findAll(config: ['limit' => 1000]);

        foreach ($schedules as $schedule) {
            if (($schedule instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($schedule->getObject()['agentId'] ?? '') === $agentId) {
                $scheduleUuids[(string) $schedule->getUuid()] = true;
            }
        }

        if ($scheduleUuids === []) {
            return [];
        }

        $logs = $this->auditTrailMapper->findAll(filters: ['action' => 'run']);

        $rows = [];
        foreach ($logs as $log) {
            if (isset($scheduleUuids[(string) $log->getObjectUuid()]) === false) {
                continue;
            }

            $created = $log->getCreated();
            $at      = null;
            if ($created !== null) {
                $at = $created->format('c');
            }

            if ($this->withinRange(at: $at, from: $from, to: $to) === false) {
                continue;
            }

            $context = ($log->getChanged() ?? []);

            $rows[] = [
                'at'            => $at,
                'toolId'        => null,
                'actingUser'    => $log->getUser(),
                'paramsDigest'  => null,
                'resultSummary' => ['status' => ($context['status'] ?? 'unknown')],
                'dataTouched'   => [],
            ];
        }//end foreach

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return $rows;

    }//end degradedRows()

    /**
     * Whether a row's timestamp falls within an optional `[from, to]` window
     * (string comparison — both are ISO 8601, sortable lexically).
     *
     * @param string|null $at   The row's timestamp, or null.
     * @param string|null $from Optional lower bound (inclusive).
     * @param string|null $to   Optional upper bound (inclusive).
     *
     * @return bool
     */
    private function withinRange(?string $at, ?string $from, ?string $to): bool
    {
        if ($at === null) {
            return ($from === null && $to === null);
        }

        if ($from !== null && $at < $from) {
            return false;
        }

        if ($to !== null && $at > $to) {
            return false;
        }

        return true;

    }//end withinRange()

    /**
     * Render rows as CSV text (header + one line per row; `resultSummary`/
     * `dataTouched` flattened to JSON so the export stays one row per invocation).
     *
     * @param array<int, array<string, mixed>> $rows The rows to export.
     *
     * @return string
     */
    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // Explicit $escape: PHP 8.4 deprecates relying on the default (RFC 4180
        // knows no escape character — pass '' once the minimum PHP allows it).
        fputcsv($handle, ['at', 'toolId', 'actingUser', 'paramsDigest', 'resultSummary', 'dataTouched'], ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv(
                $handle,
                [
                    (string) ($row['at'] ?? ''),
                    (string) ($row['toolId'] ?? ''),
                    (string) ($row['actingUser'] ?? ''),
                    (string) ($row['paramsDigest'] ?? ''),
                    (string) json_encode($row['resultSummary'] ?? null),
                    (string) json_encode($row['dataTouched'] ?? []),
                ],
                ',',
                '"',
                '\\'
            );
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            return '';
        }

        return $csv;

    }//end toCsv()
}//end class
