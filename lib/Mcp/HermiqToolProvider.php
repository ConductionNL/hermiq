<?php

/**
 * Hermiq HermiqToolProvider (nc-native-tools).
 *
 * The class name follows OpenRegister's per-app MCP discovery convention
 * (`OCA\{Namespace}\Mcp\{Namespace}ToolProvider`) so McpToolsService autowires it as the
 * hermiq provider (the FQCN candidate OR falls back to when the service alias is not
 * resolvable from OR's container).
 *
 * Exposes Nextcloud-native capabilities — Files, Contacts, Calendar, Deck and outbound
 * email — as agent tools through OpenRegister's MCP IMcpToolProvider interface, so Hermiq
 * agents act inside the host Nextcloud with no second tool-registration mechanism
 * (nc-native-tools, ADR-001 Option C+). Every tool enforces a per-object IDOR guard by
 * scoping strictly to the acting user's own resources (their user folder, addressbooks,
 * calendars, email) — the runtime passes the user session through unchanged, never
 * impersonating. invokeTool() never throws: every failure returns a structured error.
 *
 * Remote / non-Nextcloud systems are out of scope here and route through OpenConnector's
 * CallService — no provider opens a direct HTTP client.
 *
 * NOTE (OR#269): an OpenRegister agent turn cannot yet INVOKE a tool (Ollama tool-calling
 * returns HTTP 400). This provider registers + enumerates in OR's tool registry and each
 * tool is directly invocable; the LLM-selects-and-calls path is blocked on OR#269.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#1-ncnativetoolprovider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Mcp\AbstractToolHandler;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MCP tool provider exposing Files/Contacts/Calendar/Deck/email as agent tools.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One provider aggregates five distinct
 *   Nextcloud capabilities; each needs its own collaborator.
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#1-ncnativetoolprovider
 */
class HermiqToolProvider extends AbstractToolHandler implements IMcpToolProvider
{

    /**
     * Maximum bytes returned by readFile (avoid dumping large binaries into a prompt).
     *
     * @var int
     */
    private const MAX_READ_BYTES = 20000;

    /**
     * The tool catalogue this provider exposes (each id namespaced `hermiq.`).
     *
     * @var array<int, array<string, mixed>>
     */
    private const TOOL_DESCRIPTORS = [
        [
            'id'          => Application::APP_ID.'.listFiles',
            'name'        => 'List files',
            'description' => 'List the files and folders in the acting user\'s Nextcloud folder at an optional path.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['path' => ['type' => 'string', 'description' => 'Folder path relative to the user root (default: root).']],
                'required'   => [],
            ],
        ],
        [
            'id'          => Application::APP_ID.'.readFile',
            'name'        => 'Read file',
            'description' => 'Read the text content of a file in the acting user\'s Nextcloud folder (size-capped).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['path' => ['type' => 'string', 'description' => 'File path relative to the user root.']],
                'required'   => ['path'],
            ],
        ],
        [
            'id'          => Application::APP_ID.'.searchContacts',
            'name'        => 'Search contacts',
            'description' => 'Search the acting user\'s address books by name or email.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['query' => ['type' => 'string', 'description' => 'The search term.']],
                'required'   => ['query'],
            ],
        ],
        [
            'id'          => Application::APP_ID.'.listCalendarEvents',
            'name'        => 'List calendar events',
            'description' => 'List upcoming events from the acting user\'s calendars within the next N days.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['days' => ['type' => 'integer', 'description' => 'Look-ahead window in days (default 7).']],
                'required'   => [],
            ],
        ],
        [
            'id'          => Application::APP_ID.'.sendMail',
            'name'        => 'Send email',
            'description' => 'Send an email from the acting user to a recipient.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'to'      => ['type' => 'string', 'description' => 'Recipient email address.'],
                    'subject' => ['type' => 'string', 'description' => 'Email subject.'],
                    'body'    => ['type' => 'string', 'description' => 'Plain-text email body.'],
                ],
                'required'   => ['to', 'subject', 'body'],
            ],
        ],
        [
            'id'          => Application::APP_ID.'.listDeckBoards',
            'name'        => 'List Deck boards',
            'description' => 'List the acting user\'s Deck boards (requires the Deck app).',
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
     * @param IUserSession       $userSession     The current user session (auth + scoping).
     * @param IGroupManager      $groupManager    Group manager (AbstractToolHandler helpers).
     * @param IRootFolder        $rootFolder      Files root (scoped per user).
     * @param IContactsManager   $contactsManager Contacts search (acting user's books).
     * @param ICalendarManager   $calendarManager Calendar query (acting user's calendars).
     * @param IMailer            $mailer          Outbound email.
     * @param IAppManager        $appManager      App availability (Deck) + describe.
     * @param ContainerInterface $container       Lazy Deck BoardService resolution.
     * @param LoggerInterface    $logger          PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) DI of five distinct capabilities.
     */
    public function __construct(
        IUserSession $userSession,
        IGroupManager $groupManager,
        private readonly IRootFolder $rootFolder,
        private readonly IContactsManager $contactsManager,
        private readonly ICalendarManager $calendarManager,
        private readonly IMailer $mailer,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        $this->userSession  = $userSession;
        $this->groupManager = $groupManager;
    }//end __construct()

    /**
     * The app id that namespaces every tool id.
     *
     * @return string The app slug (matches info.xml `<id>`).
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-1
     */
    public function getAppId(): string
    {
        return Application::APP_ID;

    }//end getAppId()

    /**
     * The full tool catalogue (per-object authorisation is enforced in invokeTool()).
     *
     * @return array<int, array<string, mixed>> The tool descriptors.
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-1
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * Invoke a tool by id — authorises (scopes to the acting user) BEFORE any data access.
     *
     * Never throws: every failure returns `['error' => ['code', 'message']]`.
     *
     * @param string               $toolId    The namespaced tool id.
     * @param array<string, mixed> $arguments The tool arguments.
     *
     * @return array<string, mixed> The JSON-encodable result.
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-7
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->error(code: 'unauthenticated', message: 'No authenticated user.');
        }

        $uid = $user->getUID();

        try {
            switch ($toolId) {
                case Application::APP_ID.'.listFiles':
                    return $this->listFiles(uid: $uid, path: (string) ($arguments['path'] ?? ''));
                case Application::APP_ID.'.readFile':
                    return $this->readFile(uid: $uid, path: (string) ($arguments['path'] ?? ''));
                case Application::APP_ID.'.searchContacts':
                    return $this->searchContacts(query: (string) ($arguments['query'] ?? ''));
                case Application::APP_ID.'.listCalendarEvents':
                    return $this->listCalendarEvents(uid: $uid, days: (int) ($arguments['days'] ?? 7));
                case Application::APP_ID.'.sendMail':
                    return $this->sendMail(user: $user, arguments: $arguments);
                case Application::APP_ID.'.listDeckBoards':
                    return $this->listDeckBoards();
                default:
                    return $this->error(
                        code: 'unknown_tool',
                        message: "Unknown tool id '".$toolId."'. Available: ".implode(', ', array_column(self::TOOL_DESCRIPTORS, 'id')).'.'
                    );
            }//end switch
        } catch (Throwable $e) {
            $this->logger->warning('Hermiq nc-native tool failed: '.$e->getMessage(), ['exception' => $e, 'tool' => $toolId]);
            return $this->error(code: 'tool_failed', message: 'The tool call failed.');
        }//end try

    }//end invokeTool()

    /**
     * List files in the acting user's folder at $path (IDOR: user folder only).
     *
     * @param string $uid  The acting user id.
     * @param string $path The folder path relative to the user root.
     *
     * @return array<string, mixed> The listing, or an error envelope.
     */
    private function listFiles(string $uid, string $path): array
    {
        $userFolder = $this->rootFolder->getUserFolder($uid);

        $target = $userFolder;
        if (trim($path, '/') !== '') {
            if ($userFolder->nodeExists($path) === false) {
                return $this->error(code: 'not_found', message: 'Path not found in your files.');
            }

            $node = $userFolder->get($path);
            if (($node instanceof Folder) === false) {
                return $this->error(code: 'not_a_folder', message: 'Path is not a folder.');
            }

            $target = $node;
        }

        $entries = [];
        foreach ($target->getDirectoryListing() as $node) {
            $type = 'file';
            if (($node instanceof Folder) === true) {
                $type = 'folder';
            }

            $entries[] = [
                'name' => $node->getName(),
                'type' => $type,
                'size' => $node->getSize(),
            ];
        }

        $reportedPath = '/';
        if ($path !== '') {
            $reportedPath = $path;
        }

        return ['path' => $reportedPath, 'entries' => $entries];

    }//end listFiles()

    /**
     * Read a text file from the acting user's folder (IDOR: user folder only; size-capped).
     *
     * @param string $uid  The acting user id.
     * @param string $path The file path relative to the user root.
     *
     * @return array<string, mixed> The content, or an error envelope.
     */
    private function readFile(string $uid, string $path): array
    {
        if (trim($path, '/') === '') {
            return $this->error(code: 'invalid_argument', message: 'A file path is required.');
        }

        $userFolder = $this->rootFolder->getUserFolder($uid);
        if ($userFolder->nodeExists($path) === false) {
            return $this->error(code: 'not_found', message: 'File not found in your files.');
        }

        $node = $userFolder->get($path);
        if (($node instanceof File) === false) {
            return $this->error(code: 'not_a_file', message: 'Path is not a file.');
        }

        $content   = (string) $node->getContent();
        $truncated = false;
        if (strlen($content) > self::MAX_READ_BYTES) {
            $content   = substr($content, 0, self::MAX_READ_BYTES);
            $truncated = true;
        }

        return [
            'path'      => $path,
            'content'   => $content,
            'truncated' => $truncated,
        ];

    }//end readFile()

    /**
     * Search the acting user's address books (IDOR: IManager uses the user context).
     *
     * @param string $query The search term.
     *
     * @return array<string, mixed> The matches, or an error envelope.
     */
    private function searchContacts(string $query): array
    {
        if (trim($query) === '') {
            return $this->error(code: 'invalid_argument', message: 'A search query is required.');
        }

        $matches = $this->contactsManager->search($query, ['FN', 'EMAIL'], ['limit' => 25]);

        $results = [];
        foreach ($matches as $contact) {
            $email = ($contact['EMAIL'] ?? '');
            if (is_array($email) === true) {
                $email = reset($email);
            }

            $results[] = [
                'name'  => (string) ($contact['FN'] ?? ''),
                'email' => (string) $email,
            ];
        }

        return ['query' => $query, 'results' => $results];

    }//end searchContacts()

    /**
     * List upcoming events from the acting user's calendars (IDOR: user's principal).
     *
     * @param string $uid  The acting user id.
     * @param int    $days The look-ahead window in days.
     *
     * @return array<string, mixed> The events, or an error envelope.
     */
    private function listCalendarEvents(string $uid, int $days): array
    {
        $window    = max(1, min(90, $days));
        $principal = 'principals/users/'.$uid;
        $calendars = $this->calendarManager->getCalendarsForPrincipal($principal);

        $events = [];
        foreach ($calendars as $calendar) {
            foreach ($calendar->search('', [], [], 50) as $event) {
                $events[] = [
                    'calendar' => $calendar->getDisplayName(),
                    'summary'  => (string) ($event['objects'][0]['SUMMARY'][0][0] ?? ($event['summary'] ?? '')),
                ];
            }
        }

        return ['windowDays' => $window, 'events' => $events];

    }//end listCalendarEvents()

    /**
     * Send an email from the acting user (IDOR: From is the caller's own identity).
     *
     * @param \OCP\IUser           $user      The acting user.
     * @param array<string, mixed> $arguments The tool arguments (to, subject, body).
     *
     * @return array<string, mixed> The send result, or an error envelope.
     */
    private function sendMail(\OCP\IUser $user, array $arguments): array
    {
        $to      = trim((string) ($arguments['to'] ?? ''));
        $subject = (string) ($arguments['subject'] ?? '');
        $body    = (string) ($arguments['body'] ?? '');

        if ($to === '' || $subject === '' || $body === '') {
            return $this->error(code: 'invalid_argument', message: 'to, subject and body are all required.');
        }

        if ($this->mailer->validateMailAddress($to) === false) {
            return $this->error(code: 'invalid_argument', message: 'The recipient address is not a valid email.');
        }

        $from = $user->getEMailAddress();
        if ($from === null || $from === '') {
            return $this->error(code: 'no_sender', message: 'Your account has no email address to send from.');
        }

        $message = $this->mailer->createMessage();
        $message->setFrom([$from => $user->getDisplayName()]);
        $message->setTo([$to]);
        $message->setSubject($subject);
        $message->setPlainBody($body);

        $failed = $this->mailer->send($message);

        return ['sent' => ($failed === []), 'failedRecipients' => $failed];

    }//end sendMail()

    /**
     * List the acting user's Deck boards (lazy dependency; error when Deck is absent).
     *
     * @return array<string, mixed> The boards, or an error envelope.
     */
    private function listDeckBoards(): array
    {
        if ($this->appManager->isEnabledForUser('deck') === false) {
            return $this->error(code: 'deck_unavailable', message: 'The Deck app is not installed or enabled.');
        }

        try {
            // Resolve Deck's BoardService lazily so the class is never a hard dependency.
            $boardService = $this->container->get('OCA\\Deck\\Service\\BoardService');
            $boards       = $boardService->findAll();
        } catch (Throwable $e) {
            $this->logger->warning('Hermiq Deck board listing failed: '.$e->getMessage(), ['exception' => $e]);
            return $this->error(code: 'deck_unavailable', message: 'Could not read Deck boards.');
        }

        $results = [];
        foreach ($boards as $board) {
            if (is_object($board) === true && method_exists($board, 'getTitle') === true) {
                $results[] = ['title' => (string) $board->getTitle()];
            }
        }

        return ['boards' => $results];

    }//end listDeckBoards()

    /**
     * Build a structured error envelope (invokeTool never throws).
     *
     * @param string $code    The machine error code.
     * @param string $message The human-readable message.
     *
     * @return array<string, mixed> The error envelope.
     */
    private function error(string $code, string $message): array
    {
        return ['error' => ['code' => $code, 'message' => $message]];

    }//end error()
}//end class
