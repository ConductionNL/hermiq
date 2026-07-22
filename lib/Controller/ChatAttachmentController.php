<?php

/**
 * Hermiq ChatAttachmentController.
 *
 * Hermiq's FIRST file-write primitive (verified: no `newFile`/`putContent`/
 * `newFolder` anywhere in `lib/` before this change). Accepts one
 * `multipart/form-data` upload and stores it in the ACTING USER'S OWN
 * Nextcloud, under the target agent's configured `uploadFolder` (created on
 * demand) — or the app default `Hermiq/Attachments/` when no agent is given or
 * the agent names no folder — returning a `{path, name}` reference the chat
 * endpoints (`ChatController::sendMessage()` / `ChatStreamController::stream()`)
 * accept in their existing JSON body.
 *
 * The optional `agentId` request parameter only selects WHERE the file lands
 * (via `resolveUploadFolder()`): the agent read is RBAC-scoped through
 * `ObjectService`, and the resolved folder is sanitised (`sanitizeFolder()`) so
 * a stored value can never traverse out of the acting user's Files. Uploads are
 * NOT auto-added to the agent's related files — attachments stay per-message
 * (Message lifecycle), distinct from the agent's curated Context.files.
 *
 * This endpoint is intentionally SEPARATE from the chat endpoints rather than
 * accepting multipart there: `ChatStreamController::readRequestBody()` reads
 * `php://input`, which PHP does not populate for `multipart/form-data`
 * requests, so the SSE endpoint cannot accept a file directly (design.md
 * Decision 1).
 *
 * Scoped to text-decodable files (valid UTF-8) within a 20000-byte cap —
 * `ContextAssembler::MAX_FILE_BYTES`'s established precedent, duplicated here
 * as a literal because it is a private constant on a different class. No
 * binary/image/vision affordance: a rejected upload writes nothing and is
 * never passed to a model in any form (design.md Decision 4).
 *
 * Security (design.md Security Considerations):
 * - The acting user is resolved from `IUserSession`, NEVER from a request
 *   parameter, so a caller can only ever write into their own storage.
 * - The uploaded filename is reduced to a basename (never interpreted as a
 *   path) and passed through `Folder::verifyPath()` before the write, so
 *   `../../` cannot escape `Hermiq/Attachments/`.
 * - `Folder::getNonExistingName()` de-duplicates so an upload never
 *   overwrites an existing file.
 * - CSRF is REQUIRED (no `@NoCSRFRequired`): this is a state-changing write
 *   into the user's storage, reachable from a browser form.
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
 * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
 * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-uploads-are-restricted-to-text-decodable-files-within-a-size-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * ChatAttachmentController stores one uploaded file into the acting user's own
 * Nextcloud and returns the `{path, name}` reference the chat endpoints carry.
 *
 * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
 */
class ChatAttachmentController extends Controller
{

    /**
     * The multipart field name the upload is read from.
     *
     * @var string
     */
    private const UPLOAD_FIELD = 'file';

    /**
     * OpenRegister register slug that holds Hermiq agent objects — the source
     * of a per-agent `uploadFolder` override.
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
     * Default destination folder (relative to the acting user's Nextcloud
     * folder), created on demand, used when no agent is given or the agent
     * carries no `uploadFolder`. Deliberately visible (not dot-hidden): a user
     * must be able to find and delete what they uploaded (design.md Decision 2).
     *
     * @var string
     */
    private const ATTACHMENTS_FOLDER = 'Hermiq/Attachments';

    /**
     * Per-file byte cap. Mirrors `ContextAssembler::MAX_FILE_BYTES` (the
     * established precedent for a Nextcloud-file read/write bound in this
     * app) — duplicated as a literal because that constant is private on a
     * different class; both MUST be changed together if this cap ever moves.
     *
     * @var int
     */
    private const MAX_FILE_BYTES = 20000;

    /**
     * Fallback stored name when the uploaded filename reduces to nothing
     * (e.g. a bare `..`/`/`), so `Folder::verifyPath()` always has something
     * plausible to validate.
     *
     * @var string
     */
    private const FALLBACK_NAME = 'attachment.txt';

    /**
     * Constructor.
     *
     * @param IRequest        $request       The request object.
     * @param IRootFolder     $rootFolder    Files root (scoped per acting user) — the
     *                                       SAME OCP surface
     *                                       `ContextAssembler`/`HermiqToolProvider`
     *                                       already use for reads; this is the app's first
     *                                       write through it.
     * @param IUserSession    $userSession   Resolves the requesting (acting) user —
     *                                       the ONLY source of the write target, never
     *                                       a request parameter.
     * @param IL10N           $l10n          Localization service for translations.
     * @param LoggerInterface $logger        PSR-3 logger.
     * @param ObjectService   $objectService OpenRegister object read — resolves the
     *                                       target agent's `uploadFolder` override.
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
     */
    public function __construct(
        IRequest $request,
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Upload one chat attachment into the acting user's own Nextcloud.
     *
     * @return JSONResponse 200 with `{path, name}`; 400 on a missing/oversized/
     *                      binary file; 401 with no authenticated user; 500 on a
     *                      write failure (quota, storage error).
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-uploads-are-restricted-to-text-decodable-files-within-a-size-cap
     */
    public function upload(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Authentication required')],
                statusCode: 401
            );
        }

        $uploaded = $this->request->getUploadedFile(self::UPLOAD_FIELD);
        // IRequest::getUploadedFile()'s OCP docblock declares `@return array`, but the real
        // implementation (OC\AppFramework\Http\Request::getUploadedFile()) returns null when the
        // key is absent from $_FILES — verified against lib/private/AppFramework/Http/Request.php.
        // @phpstan-ignore-next-line -- deliberate defensive fallback, see above.
        if (is_array($uploaded) === false || empty($uploaded['tmp_name']) === true) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('No file was uploaded')],
                statusCode: 400
            );
        }

        if ((int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('The upload failed')],
                statusCode: 400
            );
        }

        $size = (int) ($uploaded['size'] ?? 0);
        if ($size > self::MAX_FILE_BYTES) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('The file exceeds the %s byte size limit', [(string) self::MAX_FILE_BYTES])],
                statusCode: 400
            );
        }

        $content = file_get_contents((string) $uploaded['tmp_name']);
        if ($content === false || $this->isTextDecodable(content: $content) === false) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Only text files are supported')],
                statusCode: 400
            );
        }

        $name = $this->basenameOf(filename: (string) ($uploaded['name'] ?? self::FALLBACK_NAME));

        // Optional per-agent folder override. Absent/unknown agent → the app
        // default, so pre-existing callers (no agentId) are unaffected.
        $agentId = trim((string) $this->request->getParam('agentId', ''));
        $folder  = $this->resolveUploadFolder(agentId: $agentId);

        try {
            $stored = $this->store(userId: $user->getUID(), folder: $folder, name: $name, content: $content);
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[ChatAttachmentController] Failed to store attachment',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: ['error' => $this->l10n->t('The file could not be stored')],
                statusCode: 500
            );
        }

        $this->logger->info(
            message: '[ChatAttachmentController] Attachment stored',
            context: [
                'file' => __FILE__,
                'line' => __LINE__,
                'path' => $stored['path'],
            ]
        );

        return new JSONResponse(data: $stored, statusCode: 200);
    }//end upload()

    /**
     * Whether content decodes as valid UTF-8 text — the "text-decodable" scope
     * boundary (design.md Decision 4): no PDF/Office extraction library is a
     * dependency of this app, and no vision/binary handling exists anywhere in
     * `lib/Service/Llm/`, so binary content is rejected rather than degraded
     * into mojibake. A stray NUL byte is treated as binary too, even though it
     * can technically appear inside a UTF-8-valid byte sequence, because no
     * genuine text file contains one.
     *
     * @param string $content The raw uploaded bytes.
     *
     * @return bool
     */
    private function isTextDecodable(string $content): bool
    {
        if (str_contains($content, "\0") === true) {
            return false;
        }

        return mb_check_encoding($content, 'UTF-8');
    }//end isTextDecodable()

    /**
     * Reduce an attacker-controlled uploaded filename to a safe basename —
     * never interpreted as a path, so `../../` cannot escape
     * `Hermiq/Attachments/` (design.md Security Considerations §2).
     *
     * @param string $filename The raw uploaded filename.
     *
     * @return string The basename, or `FALLBACK_NAME` when nothing usable remains.
     */
    private function basenameOf(string $filename): string
    {
        $safe = trim(basename(str_replace('\\', '/', $filename)));
        if ($safe === '' || $safe === '.' || $safe === '..') {
            return self::FALLBACK_NAME;
        }

        return $safe;
    }//end basenameOf()

    /**
     * Resolve the destination folder for an upload: the target agent's
     * `uploadFolder` when it names one, else the app default.
     *
     * The agent read goes through `ObjectService`, so it is organisation- and
     * RBAC-scoped exactly like every other agent read — a caller can only
     * resolve a folder for an agent they may already see. The stored value is
     * still sanitised (an admin could have typed anything into it), so a
     * malicious/typo'd `../` value can never escape the acting user's Files.
     *
     * @param string $agentId The agent UUID from the request, or '' for none.
     *
     * @return string A sanitised folder path relative to the acting user's
     *                Files (never empty; falls back to `ATTACHMENTS_FOLDER`).
     */
    private function resolveUploadFolder(string $agentId): string
    {
        if ($agentId === '') {
            return self::ATTACHMENTS_FOLDER;
        }

        try {
            $agent = $this->objectService->find(
                id: $agentId,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA
            );
        } catch (Throwable $e) {
            // A missing/unreadable agent is not an upload error — fall back to
            // the default folder rather than failing the whole upload.
            return self::ATTACHMENTS_FOLDER;
        }

        if ($agent === null) {
            return self::ATTACHMENTS_FOLDER;
        }

        $configured = trim((string) ($agent->getObject()['uploadFolder'] ?? ''));

        return $this->sanitizeFolder(folder: $configured);
    }//end resolveUploadFolder()

    /**
     * Reduce a configured folder path to a safe, relative one — the folder-path
     * counterpart of `basenameOf()`. Any absolute path, any `.`/`..` segment,
     * or an empty result falls back to `ATTACHMENTS_FOLDER`, so a stored value
     * can never traverse out of the acting user's Files.
     *
     * @param string $folder The configured folder path (may be empty/hostile).
     *
     * @return string A safe relative folder path (never empty).
     */
    private function sanitizeFolder(string $folder): string
    {
        $normalised = trim(str_replace('\\', '/', $folder));
        if ($normalised === '') {
            return self::ATTACHMENTS_FOLDER;
        }

        $segments = [];
        foreach (explode('/', $normalised) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                // Any traversal attempt voids the whole configured value.
                return self::ATTACHMENTS_FOLDER;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return self::ATTACHMENTS_FOLDER;
        }

        return implode('/', $segments);
    }//end sanitizeFolder()

    /**
     * Write the content into the given folder under the acting user's Files,
     * creating it on demand, and de-duplicating the name so an existing file
     * is never overwritten.
     *
     * @param string $userId  The acting Nextcloud user id (from `IUserSession`
     *                        only — never a request parameter).
     * @param string $folder  The already-sanitised destination folder path,
     *                        relative to the acting user's Files.
     * @param string $name    The requested (already basename-reduced) filename.
     * @param string $content The file content to write.
     *
     * @return array{path: string, name: string} The stored reference.
     *
     * @throws Throwable When the folder/file cannot be created or written
     *                   (quota, storage error, invalid path) — translated to a
     *                   500 by the caller.
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
     */
    private function store(string $userId, string $folder, string $name, string $content): array
    {
        $userFolder = $this->rootFolder->getUserFolder($userId);

        if ($userFolder->nodeExists($folder) === false) {
            $userFolder->newFolder($folder);
        }

        $attachmentsFolder = $userFolder->get($folder);
        if (($attachmentsFolder instanceof Folder) === false) {
            throw new RuntimeException($folder.' exists but is not a folder');
        }

        // Path-safety: verify the (already basename-reduced) name is a valid,
        // allowed path from this folder BEFORE writing. `Folder::verifyPath()` is
        // an OCP method only since Nextcloud 32 — this app's `info.xml` declares
        // `min-version="30"` and its pinned `nextcloud/ocp` dependency is v31.0.9,
        // so the method is UNCALLABLE (fatal `Error`) on an NC 30/31 install.
        // Guarded rather than called unconditionally: `basenameOf()` above is the
        // load-bearing anti-traversal control (it strips every path separator, so
        // `$name` can never contain `/` here) — `verifyPath()` is defense-in-depth
        // on NC 32+, not a control this endpoint depends on to be safe.
        if (method_exists($attachmentsFolder, 'verifyPath') === true) {
            $attachmentsFolder->verifyPath($name);
        }

        $storedName = $attachmentsFolder->getNonExistingName($name);
        $attachmentsFolder->newFile($storedName, $content);

        return [
            'path' => $folder.'/'.$storedName,
            'name' => $name,
        ];
    }//end store()
}//end class
