<?php

/**
 * Hermiq AgentFilesController.
 *
 * Curated "related files" surface for one agent — a Claude-project-style list of
 * existing Nextcloud files the agent can scan and use. Backed entirely by the existing
 * Context system (ADR-024): the files live on an agent-owned `Context` bundle's `files[]`
 * array, referenced from `agent.contextRefs`, and ride the SAME `ContextAssembler`
 * preamble path at run start (no new assembly). This is DISTINCT from chat attachments
 * (per-turn uploads) — it is the curated, persistent list only.
 *
 * Bundle-identity scheme (stated here so a reviewer can verify it is unambiguous):
 * an agent's files bundle is the ONE Context — among the uuids already in THIS agent's
 * own `contextRefs` — whose `name` equals the reserved machine sentinel
 * {@see self::FILES_BUNDLE_NAME} ("Agent files"). We both WRITE and READ that sentinel.
 * Why it is safe:
 *   - We create at most ONE files bundle per agent (add() find-or-creates, never a second),
 *     so within a single agent's contextRefs the sentinel resolves to exactly one Context.
 *   - The lookup is ALWAYS scoped to the agent's own contextRefs, never a global name
 *     search — so a Context named "Agent files" belonging to a DIFFERENT agent (or a
 *     user-authored bundle that happens to share the name) is never matched here.
 *   - The sentinel is a fixed machine string, not the agent's display name, so renaming
 *     the agent (or the bundle's description) can never break the identity.
 *
 * Acting-user scoped: the user is resolved from IUserSession (401 if none); the agentId
 * comes from the route. All reads/writes run in the caller's session context through
 * OpenRegister's ObjectService, so OR's native RBAC denies cross-tenant access (a
 * foreign-tenant agentId simply resolves to nothing — no content leak). `@NoAdminRequired`
 * opens the routes to any authenticated user; tenancy is the guard.
 *
 * These are references to EXISTING Nextcloud files chosen via the picker — this controller
 * never reads or writes file bytes; ContextAssembler resolves them later (tolerant of a
 * missing file). Paths are stored relative (a leading '/' is stripped), matching the shape
 * ContextAssembler's `resolveFiles()` consumes ({path, description}).
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
 * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Acting-user-scoped related-files management for one agent, over the Context bundle.
 *
 * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
 */
class AgentFilesController extends Controller
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
     * Schema slug for context objects.
     *
     * @var string
     */
    private const CONTEXT_SCHEMA = 'context';

    /**
     * Reserved, deterministic `Context.name` sentinel identifying an agent's related-files
     * bundle. See the class docblock for why this is a safe bundle-identity scheme.
     *
     * @var string
     */
    private const FILES_BUNDLE_NAME = 'Agent files';

    /**
     * Description written onto a freshly-created files bundle.
     *
     * @var string
     */
    private const FILES_BUNDLE_DESCRIPTION = 'Files this agent can scan and use.';

    /**
     * Constructor.
     *
     * @param IRequest        $request       The request object.
     * @param ObjectService   $objectService OpenRegister object read/write (single write-path).
     * @param IUserSession    $userSession   Resolves the requesting user.
     * @param LoggerInterface $logger        PSR-3 logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * List an agent's related files (never creates the bundle on a read).
     *
     * @param string $agentId The agent UUID.
     *
     * @return JSONResponse `{files: [{path, name, description}]}` (empty array when the
     *                      agent has no files bundle yet), or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
     */
    public function list(string $agentId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $agent = $this->findAgent(agentId: $agentId);
            if ($agent === null) {
                return new JSONResponse(['files' => []]);
            }

            $bundle = $this->findFilesBundle(agent: $agent);
            return new JSONResponse(['files' => $this->shapeFiles(bundle: $bundle)]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent files list failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load related files'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end list()

    /**
     * Relate an existing Nextcloud file to an agent: find-or-create the agent's files
     * bundle, append the file to `Context.files[]` (DEDUPED by path — adding a path that
     * already exists is a no-op success), and ensure the bundle uuid is in
     * `agent.contextRefs`.
     *
     * @param string $agentId The agent UUID.
     *
     * @return JSONResponse The updated `{files: [...]}` list, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
     */
    public function add(string $agentId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $path = $this->normalisePath(path: (string) $this->request->getParam('path', ''));
        if ($path === '') {
            return new JSONResponse(['error' => 'A non-empty path is required'], Http::STATUS_BAD_REQUEST);
        }

        $description = trim((string) $this->request->getParam('description', ''));

        try {
            $agent = $this->findAgent(agentId: $agentId);
            if ($agent === null) {
                return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
            }

            $bundle = $this->findFilesBundle(agent: $agent);
            if ($bundle === null) {
                $bundle = $this->createFilesBundle(path: $path, description: $description);
                $this->attachBundleToAgent(agent: $agent, bundleUuid: (string) $bundle->getUuid());
                return new JSONResponse(['files' => $this->shapeFiles(bundle: $bundle)]);
            }

            $bundle = $this->appendFile(bundle: $bundle, path: $path, description: $description);
            // Ensure the ref survives even if a prior write left it out of contextRefs.
            $this->attachBundleToAgent(agent: $agent, bundleUuid: (string) $bundle->getUuid());
            return new JSONResponse(['files' => $this->shapeFiles(bundle: $bundle)]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent files add failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not add the related file'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end add()

    /**
     * Remove a related file from an agent's files bundle by path. Idempotent: a missing
     * path or a not-yet-created bundle still returns 200 with the current list.
     *
     * @param string $agentId The agent UUID.
     *
     * @return JSONResponse The updated `{files: [...]}` list, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
     */
    public function remove(string $agentId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $path = $this->normalisePath(path: (string) $this->request->getParam('path', ''));

        try {
            $agent = $this->findAgent(agentId: $agentId);
            if ($agent === null) {
                return new JSONResponse(['files' => []]);
            }

            $bundle = $this->findFilesBundle(agent: $agent);
            if ($bundle === null || $path === '') {
                return new JSONResponse(['files' => $this->shapeFiles(bundle: $bundle)]);
            }

            $bundle = $this->removeFile(bundle: $bundle, path: $path);
            return new JSONResponse(['files' => $this->shapeFiles(bundle: $bundle)]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent files remove failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not remove the related file'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end remove()

    /**
     * Resolve the agent ObjectEntity by uuid (RBAC/tenancy applied by ObjectService).
     *
     * @param string $agentId The agent UUID.
     *
     * @return ObjectEntity|null The agent, or null when it does not resolve.
     */
    private function findAgent(string $agentId): ?ObjectEntity
    {
        return $this->objectService->find(
            id: $agentId,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA
        );

    }//end findAgent()

    /**
     * Find the agent's files bundle: the Context among the agent's own `contextRefs` whose
     * `name` equals the reserved sentinel (see class docblock).
     *
     * @param ObjectEntity $agent The agent object.
     *
     * @return ObjectEntity|null The files bundle, or null when the agent has none.
     */
    private function findFilesBundle(ObjectEntity $agent): ?ObjectEntity
    {
        $contextRefs = ($agent->getObject()['contextRefs'] ?? []);
        if (is_array($contextRefs) === false) {
            return null;
        }

        foreach ($contextRefs as $contextId) {
            if (is_string($contextId) === false || $contextId === '') {
                continue;
            }

            $context = $this->objectService->find(
                id: $contextId,
                register: self::REGISTER_SLUG,
                schema: self::CONTEXT_SCHEMA
            );
            if ($context === null) {
                continue;
            }

            if ((string) ($context->getObject()['name'] ?? '') === self::FILES_BUNDLE_NAME) {
                return $context;
            }
        }

        return null;

    }//end findFilesBundle()

    /**
     * Create the agent's files bundle Context seeded with the first file.
     *
     * @param string $path        The relative file path.
     * @param string $description The optional per-file note.
     *
     * @return ObjectEntity The newly-created Context.
     */
    private function createFilesBundle(string $path, string $description): ObjectEntity
    {
        return $this->objectService->saveObject(
            object: [
                'name'        => self::FILES_BUNDLE_NAME,
                'description' => self::FILES_BUNDLE_DESCRIPTION,
                'files'       => [$this->fileEntry(path: $path, description: $description)],
            ],
            register: self::REGISTER_SLUG,
            schema: self::CONTEXT_SCHEMA
        );

    }//end createFilesBundle()

    /**
     * Append a file to the bundle's `files[]`, deduped by path. Preserves ALL other
     * Context fields (PUT-guard: `saveObject` NULLS omitted schema properties).
     *
     * @param ObjectEntity $bundle      The files bundle.
     * @param string       $path        The relative file path.
     * @param string       $description The optional per-file note.
     *
     * @return ObjectEntity The (possibly unchanged) bundle.
     */
    private function appendFile(ObjectEntity $bundle, string $path, string $description): ObjectEntity
    {
        $data  = $bundle->getObject();
        $files = ($data['files'] ?? []);
        if (is_array($files) === false) {
            $files = [];
        }

        foreach ($files as $file) {
            if (is_array($file) === true && $this->normalisePath(path: (string) ($file['path'] ?? '')) === $path) {
                // Already related — no-op success.
                return $bundle;
            }
        }

        $files[]       = $this->fileEntry(path: $path, description: $description);
        $data['files'] = $files;

        return $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::CONTEXT_SCHEMA,
            uuid: (string) $bundle->getUuid()
        );

    }//end appendFile()

    /**
     * Remove every `files[]` entry matching the path. Preserves ALL other Context fields
     * (PUT-guard). Returns the bundle unchanged when nothing matched.
     *
     * @param ObjectEntity $bundle The files bundle.
     * @param string       $path   The relative file path to remove.
     *
     * @return ObjectEntity The (possibly unchanged) bundle.
     */
    private function removeFile(ObjectEntity $bundle, string $path): ObjectEntity
    {
        $data  = $bundle->getObject();
        $files = ($data['files'] ?? []);
        if (is_array($files) === false) {
            return $bundle;
        }

        $kept = array_values(
            array_filter(
                $files,
                function ($file) use ($path): bool {
                    if (is_array($file) === false) {
                        return true;
                    }

                    return $this->normalisePath(path: (string) ($file['path'] ?? '')) !== $path;
                }
            )
        );

        if (count($kept) === count($files)) {
            // Nothing matched — idempotent no-op.
            return $bundle;
        }

        $data['files'] = $kept;

        return $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::CONTEXT_SCHEMA,
            uuid: (string) $bundle->getUuid()
        );

    }//end removeFile()

    /**
     * Ensure the bundle uuid is present in the agent's `contextRefs`, appending it if
     * missing. Preserves ALL other agent fields (PUT-guard: `saveObject` NULLS omitted
     * schema properties — so the agent's prompt/status/etc. MUST be spread forward).
     *
     * @param ObjectEntity $agent      The agent object.
     * @param string       $bundleUuid The files bundle uuid.
     *
     * @return void
     */
    private function attachBundleToAgent(ObjectEntity $agent, string $bundleUuid): void
    {
        if ($bundleUuid === '') {
            return;
        }

        $data        = $agent->getObject();
        $contextRefs = ($data['contextRefs'] ?? []);
        if (is_array($contextRefs) === false) {
            $contextRefs = [];
        }

        if (in_array($bundleUuid, $contextRefs, true) === true) {
            return;
        }

        $contextRefs[]       = $bundleUuid;
        $data['contextRefs'] = $contextRefs;

        $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA,
            uuid: (string) $agent->getUuid()
        );

    }//end attachBundleToAgent()

    /**
     * Build a schema-shaped `files[]` entry ({path, description}).
     *
     * @param string $path        The relative file path.
     * @param string $description The optional per-file note.
     *
     * @return array<string, string> The file entry.
     */
    private function fileEntry(string $path, string $description): array
    {
        $entry = ['path' => $path];
        if ($description !== '') {
            $entry['description'] = $description;
        }

        return $entry;

    }//end fileEntry()

    /**
     * Shape a bundle's `files[]` into the response list, deriving `name` from the basename.
     *
     * @param ObjectEntity|null $bundle The files bundle (or null → empty list).
     *
     * @return array<int, array<string, string>> The response files.
     */
    private function shapeFiles(?ObjectEntity $bundle): array
    {
        if ($bundle === null) {
            return [];
        }

        $files = ($bundle->getObject()['files'] ?? []);
        if (is_array($files) === false) {
            return [];
        }

        $out = [];
        foreach ($files as $file) {
            if (is_array($file) === false) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $out[] = [
                'path'        => $path,
                'name'        => $this->basename(path: $path),
                'description' => (string) ($file['description'] ?? ''),
            ];
        }

        return $out;

    }//end shapeFiles()

    /**
     * Normalise a supplied path: trim whitespace and strip a single leading '/' so it is
     * relative like Context.files paths.
     *
     * @param string $path The raw path.
     *
     * @return string The normalised (relative) path, or '' when empty/whitespace.
     */
    private function normalisePath(string $path): string
    {
        return ltrim(trim($path), '/');

    }//end normalisePath()

    /**
     * Extract the basename (last path segment) from a relative path.
     *
     * @param string $path The relative path.
     *
     * @return string The basename, or the original string when it has no separators.
     */
    private function basename(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn ($s): bool => $s !== ''));
        if ($segments === []) {
            return $path;
        }

        return (string) end($segments);

    }//end basename()
}//end class
