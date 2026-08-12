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
 * agent-tool-governance-and-disclosure additionally registers `hermiq.searchTools`
 * here (design.md §2) — Hermiq's progressive-disclosure meta-tool. It flows through
 * the SAME registration mechanism as the six tools above (so it enumerates in
 * `ToolRegistryFacade::listTools()` and is subject to the SAME grant/whitelist
 * rules as any other tool), but its INVOCATION never reaches `invokeTool()` below:
 * `Engine\FacadeToolInvoker::__call()` short-circuits it directly against
 * `ToolSearchService` (Hermiq-internal, no facade round-trip — see that class).
 * The `unknown_tool` branch here is a defensive fallback only, for the
 * (non-Hermiq-engine) case where something else calls the facade directly.
 *
 * agent-memory-tools additionally registers `hermiq.rememberMemory`/
 * `hermiq.recallMemory`/`hermiq.forgetMemory` here — the MemGPT/Letta
 * "self-editing memory" tools that let an agent decide, mid-run, to write/search/
 * retract its own durable memory (`MemoryService`'s `Memory`/`UserProfile` objects),
 * instead of memory only ever being written by operator/app-driven code. These three
 * flow through the exact same `invokeTool()`/governance path as every other tool
 * here (tracing, tenant scoping, `Agent.tools` allowlist) — no new mechanism.
 *
 * web-research-tool additionally registers `hermiq.webSearch`/`hermiq.webFetch` here
 * — the ONE explicit, narrowly-scoped exception to this class's own "remote systems
 * route through OpenConnector's CallService" rule stated above (see the
 * `nc-native-tools` MODIFIED requirement and `discovery.md`): `CallService` is built
 * around an admin-pre-registered `Source.location`, structurally incapable of
 * fetching a URL an LLM only learns of at call time (typically from a `web.search`
 * result). Both tools instead call out directly via `OCP\Http\Client\IClientService`,
 * behind their own dedicated SSRF/allowlist/denylist egress guard
 * (`Service\WebResearch\WebResearchEgressGuard`) that runs before every request —
 * see that class's docblock for the full security rationale. Both are `scope: 'read'`
 * / `readOnlyHint: true`, so they classify as read-only under
 * `ToolGrantResolver::isWriteOrDestructive()` (auto-allowed under default-deny,
 * invoked for real — not neutralised — under `run-replay-and-dry-run`'s dry-run
 * preview) exactly like every other read tool here; an org's `agent-guardrails`
 * policy MAY still classify either tool id `confirm`/`deny` explicitly, which
 * `FacadeToolInvoker` enforces before either ever dispatches.
 *
 * sub-agent-delegation additionally registers `hermiq.delegateAgent` here — lets one
 * Hermiq agent invoke another, in the same organisation and on its own explicit
 * `Agent.delegationAllowlist`, as a bounded sequential sub-task in an isolated
 * conversation. `scope: 'create'` / `destructiveHint: true` classifies it as
 * write/destructive under `ToolGrantResolver::isWriteOrDestructive()` (so it is
 * default-denied like any other write tool — an agent needs it BOTH in its `tools`
 * grant AND its `delegationAllowlist`) and as side-effecting under
 * `ToolClassificationService::isSideEffecting()` (so a dry-run neutralises it like
 * any other side-effecting tool, never actually delegating). All governance —
 * self/cycle refusal, the allowlist, depth/fan-out bounds, cross-organisation
 * refusal, tenant-model-policy, the kill-switch, the budget hard cap, and the
 * target-requires-approval refusal — lives in `DelegationService::delegate()`; this
 * method only validates the tool's own input shape and forwards to it.
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
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\DelegationService;
use OCA\Hermiq\Service\Engine\ToolReachResolver;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MCP tool provider exposing Files/Contacts/Calendar/Deck/email as agent tools.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   One provider aggregates six distinct
 *   Nextcloud/Hermiq capabilities; each needs its own collaborator.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complexity is the sum of one
 *   dispatch branch + one small, single-responsibility handler per registered tool
 *   (agent-memory-tools added three) — each handler stays independently simple and
 *   testable; the total tracks the catalogue size, not incidental complexity.
 *
 * 🔴 Implements IMcpToolProvider DIRECTLY. This class used to also extend
 * `OCA\OpenRegister\Mcp\AbstractToolHandler`, which OpenRegister has since
 * removed — leaving a parent that could not be autoloaded. Every request then
 * failed to resolve this provider ("[Application] Resolve failed … Class
 * OCA\OpenRegister\Mcp\AbstractToolHandler not found"), so hermiq contributed
 * ZERO tools to the catalogue: agent tool grants resolved to nothing and
 * scheduled runs died with "This agent's tool grants resolve to no tools".
 *
 * The inheritance was vestigial — no `parent::` call anywhere — and all three
 * interface methods (getAppId/getTools/invokeTool) are implemented here. Do
 * not reintroduce a base class from OpenRegister without checking it still
 * exists there: a missing parent fails at autoload, not at compile, so the
 * damage shows up as an empty tool catalogue rather than a fatal.
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
 */
class HermiqToolProvider implements IMcpToolProvider
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
            'id'              => Application::APP_ID.'.listFiles',
            // The acting user's own files — nothing they could not already list.
            'reach'           => ToolReachResolver::REACH_USER,
            'name'            => 'List files',
            'description'     => 'List the files and folders in the acting user\'s Nextcloud folder at an optional path.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => ['path' => ['type' => 'string', 'description' => 'Folder path relative to the user root (default: root).']],
                'required'   => [],
            ],
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'scope'           => 'read',
        ],
        [
            'id'              => Application::APP_ID.'.readFile',
            'reach'           => ToolReachResolver::REACH_USER,
            'name'            => 'Read file',
            'description'     => 'Read the text content of a file in the acting user\'s Nextcloud folder (size-capped).',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => ['path' => ['type' => 'string', 'description' => 'File path relative to the user root.']],
                'required'   => ['path'],
            ],
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'scope'           => 'read',
        ],
        [
            'id'              => Application::APP_ID.'.searchContacts',
            // 🔴 `user`, NOT `instance`, even though the system addressbook
            // surfaces other users' cards. Reach measures blast radius of EFFECT
            // and DISCLOSURE, not the provenance of bytes read — a lookup here
            // changes nothing and tells nobody. This is the rule that keeps the
            // entire OpenRegister read catalogue out of the gate; classify it
            // `instance` and every empty-`tools` agent loses its reads.
            'reach'           => ToolReachResolver::REACH_USER,
            'name'            => 'Search contacts',
            'description'     => 'Search the acting user\'s address books by name or email.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => ['query' => ['type' => 'string', 'description' => 'The search term.']],
                'required'   => ['query'],
            ],
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'scope'           => 'read',
        ],
        [
            'id'              => Application::APP_ID.'.listCalendarEvents',
            'reach'           => ToolReachResolver::REACH_USER,
            'name'            => 'List calendar events',
            'description'     => 'List upcoming events from the acting user\'s calendars within the next N days.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => ['days' => ['type' => 'integer', 'description' => 'Look-ahead window in days (default 7).']],
                'required'   => [],
            ],
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'scope'           => 'read',
        ],
        [
            'id'              => Application::APP_ID.'.sendMail',
            // 🔴 The tool this whole axis exists for. Its `scope` is `create`,
            // which reads as harmless — "makes a thing" — while the effect is
            // irreversible and lands in a third party's inbox. `external` is the
            // honest label; scope alone was actively misleading here.
            'reach'           => ToolReachResolver::REACH_EXTERNAL,
            'name'            => 'Send email',
            'description'     => 'Send an email from the acting user to a recipient.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => [
                    'to'      => ['type' => 'string', 'description' => 'Recipient email address.'],
                    'subject' => ['type' => 'string', 'description' => 'Email subject.'],
                    'body'    => ['type' => 'string', 'description' => 'Plain-text email body.'],
                ],
                'required'   => ['to', 'subject', 'body'],
            ],
            // Sends externally-visible email as the acting user; the send cannot be
            // recalled once accepted by the mail transport — irreversible + externally
            // visible, so destructiveHint is true even though nothing is deleted.
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'scope'           => 'create',
        ],
        [
            'id'              => Application::APP_ID.'.listDeckBoards',
            'reach'           => ToolReachResolver::REACH_USER,
            'name'            => 'List Deck boards',
            'description'     => 'List the acting user\'s Deck boards (requires the Deck app).',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => [],
                'required'   => [],
            ],
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'scope'           => 'read',
        ],
        [
            'id'              => Application::APP_ID.'.searchTools',
            // Reads the agent's own catalogue; touches nothing outside itself.
            'reach'           => ToolReachResolver::REACH_SELF,
            'name'            => 'Search tools',
            'description'     => 'Search this agent\'s available tool catalogue by keyword when the full list was not '
                .'shown (progressive disclosure); returns matching tool descriptors you can then call directly.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => ['query' => ['type' => 'string', 'description' => 'Keyword(s) describing the capability you need.']],
                'required'   => ['query'],
            ],
            // In-memory substring match over this run's already-resolved descriptor
            // set (ToolSearchService) — no I/O, no writes.
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'scope'           => 'read',
        ],
        [
            'id'              => Application::APP_ID.'.recommendCourses',
            'reach'           => ToolReachResolver::REACH_USER,
            'name'            => 'Recommend courses',
            'description'     => 'Get the acting learner\'s current ranked, explained next-best-course recommendations '
                .'(ai-course-recommendations). Advisory only, self-scoped to the caller; ranking is a deterministic '
                .'weighted-signal score, not an LLM judgement — the explanation text may be LLM-phrased.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => [],
                'required'   => [],
            ],
            // NOT read-only: CourseRecommendationEngine::getOrRegenerate() persists a
            // fresh CourseRecommendation object via ObjectService::saveObject() (create
            // or update) whenever the cached set is absent or past its 24h staleAt TTL
            // — only an unexpired cache hit avoids the write. Re-running after
            // staleness can also change output (LLM-phrased explanation text, a new
            // staleAt timestamp), so it is not idempotent either.
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'scope'           => 'update',
        ],
        [
            'id'              => Application::APP_ID.'.rememberMemory',
            'reach'           => ToolReachResolver::REACH_SELF,
            'name'            => 'Remember a fact',
            'description'     => 'Append a durable fact to your own memory (scope: agent) or to what you know about the '
                .'person you are talking with (scope: user), so you can recall it in a future turn or session.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => [
                    'content' => ['type' => 'string', 'description' => 'The fact to remember.'],
                    'scope'   => [
                        'type'        => 'string',
                        'enum'        => ['agent', 'user'],
                        'description' => 'Whose memory to append to: your own (agent) or the acting user\'s profile (user).',
                    ],
                ],
                'required'   => ['content', 'scope'],
            ],
            // Appends a new entry every call — never idempotent, never destructive
            // (soft-delete-only forgetting lives in forgetMemory, not here); content
            // is redacted before persist (MemoryService::appendEntry()).
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'scope'           => 'create',
        ],
        [
            'id'              => Application::APP_ID.'.recallMemory',
            'reach'           => ToolReachResolver::REACH_SELF,
            'name'            => 'Recall memory',
            'description'     => 'Search your own remembered facts, what you know about the acting user, and past '
                .'conversation turns for a query — so you can decide what is relevant to this turn instead of '
                .'everything being silently injected.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'Keyword(s) or a natural-language query to search memory and history for.',
                    ],
                ],
                'required'   => ['query'],
            ],
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'scope'           => 'read',
        ],
        [
            'id'              => Application::APP_ID.'.forgetMemory',
            // `delete` scope but `self` reach — reversible and private to the
            // agent. It stays gated regardless, because gating is a UNION:
            // reach only ever ADDS tools to the gate, it never removes one.
            'reach'           => ToolReachResolver::REACH_SELF,
            'name'            => 'Forget a fact',
            'description'     => 'Retract one previously-remembered fact you no longer believe, by its entry id (from a '
                .'prior rememberMemory or recallMemory result). This is a soft delete — the fact stops being recalled '
                .'but is never erased from the audit history.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'string', 'description' => 'The entry id to forget (from rememberMemory or recallMemory).']],
                'required'   => ['id'],
            ],
            // A soft delete: excludes the entry from future recall/budget counting.
            // Never a hard delete (the entry stays in the stored array and AuditTrail),
            // but the effect is real and irreversible via this tool — destructiveHint
            // true, mirroring sendMail's "irreversible effect, nothing physically
            // erased" reasoning. Repeating the same id is a no-op (idempotent).
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'scope'           => 'delete',
        ],
        [
            'id'              => Application::APP_ID.'.webSearch',
            // Egress. `scope: read` made this look inert; the query itself leaves
            // the instance, so the model's wording reaches a third party.
            'reach'           => ToolReachResolver::REACH_EXTERNAL,
            'name'            => 'Web search',
            'description'     => 'Search the open web via the admin-configured search backend and return ranked '
                .'title/url/snippet results. Reports itself unavailable (never a fabricated result) if no backend '
                .'is configured.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => ['query' => ['type' => 'string', 'description' => 'The search query.']],
                'required'   => ['query'],
            ],
            // GET-only against an admin-configured backend; no write, no side effect.
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'scope'           => 'read',
        ],
        [
            'id'              => Application::APP_ID.'.webFetch',
            // Fetches a CALLER-SUPPLIED url — the model chooses where the
            // request goes, which is egress under model control.
            'reach'           => ToolReachResolver::REACH_EXTERNAL,
            'name'            => 'Web fetch',
            'description'     => 'Fetch a URL via HTTP GET and return extracted readable text (text/html, '
                .'text/plain, or text/markdown only; size-capped; delimited as untrusted external content). '
                .'Blocks private/loopback/link-local/cloud-metadata addresses (checked against the resolved IP) '
                .'and never follows redirects automatically.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => ['url' => ['type' => 'string', 'description' => 'The URL to fetch (https:// by default).']],
                'required'   => ['url'],
            ],
            // GET-only, read-only by construction — see WebResearchEgressGuard for the
            // egress governance that makes an agent-chosen target safe to invoke.
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'scope'           => 'read',
        ],
        [
            'id'              => Application::APP_ID.'.delegateAgent',
            // `instance` as its OWN reach — but a delegation composes: the
            // effective reach of a delegated run is the MAX of this and the
            // delegate's, so handing work to an agent that can send mail is an
            // external act. A delegation cannot launder reach.
            'reach'           => ToolReachResolver::REACH_INSTANCE,
            'name'            => 'Delegate to another agent',
            'description'     => 'Delegate a bounded sub-task to another agent explicitly named on your delegation '
                .'allowlist, in the same organisation. Runs the target agent in a fresh, isolated conversation and '
                .'returns its final text result. Sequential only — this call blocks until the sub-agent finishes.',
            'inputSchema'     => [
                'type'       => 'object',
                'properties' => [
                    'targetAgentId' => [
                        'type'        => 'string',
                        'format'      => 'uuid',
                        'description' => 'UUID of the agent to delegate to (must be on your delegation allowlist).',
                    ],
                    'task'          => [
                        'type'        => 'string',
                        'description' => 'The bounded task/prompt to hand to the target agent.',
                    ],
                ],
                'required'   => ['targetAgentId', 'task'],
            ],
            // A real, irreversible sub-agent run (its own budget/audit entry,
            // possibly its own side effects) — never read-only, never idempotent
            // (each call is a fresh sub-run), destructiveHint true so it is
            // default-denied like any other write tool and neutralised under a
            // dry-run preview (see class docblock).
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => false,
            'scope'           => 'create',
        ],
    ];

    /**
     * Constructor.
     *
     * @param IUserSession               $userSession       The current user session (auth + scoping).
     * @param IRootFolder                $rootFolder        Files root (scoped per user).
     * @param IContactsManager           $contactsManager   Contacts search (acting user's books).
     * @param ICalendarManager           $calendarManager   Calendar query (acting user's calendars).
     * @param IMailer                    $mailer            Outbound email.
     * @param IAppManager                $appManager        App availability (Deck) + describe.
     * @param ContainerInterface         $container         Lazy Deck BoardService resolution.
     * @param CourseRecommendationEngine $courseEngine      Shared engine backing `recommendCourses`
     *                                                      (ai-course-recommendations) — no
     *                                                      duplicated gating/scoring/signal-read
     *                                                      logic.
     * @param MemoryService              $memoryService     Shared service backing `rememberMemory`/
     *                                                      `recallMemory`/`forgetMemory`
     *                                                      (agent-memory-tools) — no duplicated
     *                                                      append/redact/recall/soft-delete logic.
     * @param WebSearchClient            $webSearchClient   Shared client backing `webSearch`
     *                                                      (web-research-tool) —
     *                                                      SSRF-guarded call to the
     *                                                      admin-configured search backend.
     * @param WebFetchService            $webFetchService   Shared service backing `webFetch`
     *                                                      (web-research-tool) —
     *                                                      SSRF-guarded GET + readable-text
     *                                                      extraction.
     * @param DelegationService          $delegationService Shared governed dispatcher backing
     *                                                      `delegateAgent` (sub-agent-delegation) —
     *                                                      all self/cycle/allowlist/depth/fan-out/
     *                                                      organisation/model-policy/kill-switch/
     *                                                      budget/approval gating lives there.
     * @param LoggerInterface            $logger            PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) DI of ten distinct capabilities.
     */
    public function __construct(
        // Promoted like every other dependency. These two were previously plain
        // params assigned onto properties DECLARED BY the removed
        // `AbstractToolHandler` base — with the base gone they silently became
        // PHP dynamic properties (deprecated in 8.2), which works at runtime and
        // is why a live tool call still succeeded, but phpstan/psalm correctly
        // flagged the reads and writes as undefined.
        private readonly IUserSession $userSession,
        private readonly IRootFolder $rootFolder,
        private readonly IContactsManager $contactsManager,
        private readonly ICalendarManager $calendarManager,
        private readonly IMailer $mailer,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly CourseRecommendationEngine $courseEngine,
        private readonly MemoryService $memoryService,
        private readonly WebSearchClient $webSearchClient,
        private readonly WebFetchService $webFetchService,
        private readonly DelegationService $delegationService,
        private readonly LoggerInterface $logger,
    ) {
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) A single dispatch switch over the tool
     *   catalogue — the branch count tracks the number of registered tools, not incidental
     *   complexity; each case delegates to its own single-responsibility handler.
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
                case Application::APP_ID.'.recommendCourses':
                    return $this->courseEngine->getOrRegenerate(learnerUid: $uid);
                case Application::APP_ID.'.rememberMemory':
                    return $this->rememberMemory(uid: $uid, arguments: $arguments);
                case Application::APP_ID.'.recallMemory':
                    return $this->recallMemory(uid: $uid, arguments: $arguments);
                case Application::APP_ID.'.forgetMemory':
                    return $this->forgetMemory(uid: $uid, arguments: $arguments);
                case Application::APP_ID.'.webSearch':
                    return $this->webSearch(uid: $uid, query: (string) ($arguments['query'] ?? ''));
                case Application::APP_ID.'.webFetch':
                    return $this->webFetch(url: (string) ($arguments['url'] ?? ''));
                case Application::APP_ID.'.delegateAgent':
                    return $this->delegateAgent(arguments: $arguments);
                case Application::APP_ID.'.searchTools':
                    // Defensive fallback only — the Hermiq engine short-circuits this
                    // call directly to ToolSearchService before it ever reaches the
                    // facade (see class docblock). A caller that bypasses the engine
                    // gets a clear error instead of a silent no-op.
                    return $this->error(
                        code: 'internal_only',
                        message: 'hermiq.searchTools is handled internally by the Hermiq engine and is not invocable via the facade.'
                    );
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
     * Append a durable fact to the agent's own Memory (`scope: agent`) or to the
     * acting user's UserProfile for this agent (`scope: user`) — agent-memory-tools'
     * self-service write. Delegates verbatim to `MemoryService::appendMemoryEntry()`/
     * `appendUserProfileEntry()`, which redacts `content` before persist
     * (`MemoryService::appendEntry()`).
     *
     * @param string               $uid       The acting user id (IDOR: `scope: user`
     *                                        only ever writes to THIS user's own
     *                                        UserProfile, never a caller-supplied
     *                                        `subjectUid`).
     * @param array<string, mixed> $arguments The tool arguments (`content`, `scope`,
     *                                        plus the run-injected `agentId` — see
     *                                        `FacadeToolInvoker::withAgentId()`).
     *
     * @return array<string, mixed> The result, or an error envelope.
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool
     */
    private function rememberMemory(string $uid, array $arguments): array
    {
        $agentId = trim((string) ($arguments['agentId'] ?? ''));
        if ($agentId === '') {
            return $this->noAgentContextError();
        }

        $content = trim((string) ($arguments['content'] ?? ''));
        if ($content === '') {
            return $this->error(code: 'invalid_argument', message: 'A non-empty content is required.');
        }

        $scope = (string) ($arguments['scope'] ?? '');
        if (in_array($scope, ['agent', 'user'], true) === false) {
            return $this->error(code: 'invalid_argument', message: 'scope must be "agent" or "user".');
        }

        if ($scope === 'user') {
            $memory = $this->memoryService->appendUserProfileEntry(agentId: $agentId, subjectUid: $uid, text: $content);
            return $this->rememberedResult(scope: $scope, memory: $memory);
        }

        $memory = $this->memoryService->appendMemoryEntry(agentId: $agentId, text: $content);
        return $this->rememberedResult(scope: $scope, memory: $memory);

    }//end rememberMemory()

    /**
     * Shape a persisted Memory/UserProfile object into `rememberMemory`'s result
     * payload, surfacing the newly-appended entry's id.
     *
     * @param string       $scope  The scope the entry was appended to (`agent`|`user`).
     * @param ObjectEntity $memory The persisted Memory/UserProfile object.
     *
     * @return array<string, mixed> The result payload.
     */
    private function rememberedResult(string $scope, ObjectEntity $memory): array
    {
        $data    = $memory->getObject();
        $entries = (array) ($data['entries'] ?? []);
        $newest  = end($entries);

        $entryId = null;
        if (is_array($newest) === true) {
            $entryId = ($newest['id'] ?? null);
        }

        return [
            'remembered'         => true,
            'scope'              => $scope,
            'entryId'            => $entryId,
            'needsConsolidation' => (bool) ($data['needsConsolidation'] ?? false),
        ];

    }//end rememberedResult()

    /**
     * Search the agent's own Memory/UserProfile entries and past SessionTurns for a
     * query — agent-memory-tools' self-service recall. Reuses the SAME OpenRegister
     * search substrate `recallSessions()` already uses (`MemoryService::recallEntries()`
     * + `recallSessions()`, merged into one result) — no second search index.
     *
     * @param string               $uid       The acting user id (whose own UserProfile
     *                                        is also searched).
     * @param array<string, mixed> $arguments The tool arguments (`query`, plus the
     *                                        run-injected `agentId`).
     *
     * @return array<string, mixed> The combined result, or an error envelope.
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-recall-tool
     */
    private function recallMemory(string $uid, array $arguments): array
    {
        $agentId = trim((string) ($arguments['agentId'] ?? ''));
        if ($agentId === '') {
            return $this->noAgentContextError();
        }

        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return $this->error(code: 'invalid_argument', message: 'A non-empty query is required.');
        }

        $entries = $this->memoryService->recallEntries(agentId: $agentId, subjectUid: $uid, query: $query);
        $turns   = $this->memoryService->recallSessions(agentId: $agentId, query: $query);

        $sessionTurns = [];
        foreach ($turns as $turn) {
            $sessionTurns[] = $this->shapeSessionTurn(turn: $turn);
        }

        return [
            'query'              => $query,
            'memoryEntries'      => $entries['memoryEntries'],
            'userProfileEntries' => $entries['userProfileEntries'],
            'sessionTurns'       => $sessionTurns,
        ];

    }//end recallMemory()

    /**
     * Soft-delete one memory entry by id — agent-memory-tools' self-service forget.
     * Scoped to the agent's own Memory and the ACTING user's own UserProfile only
     * (IDOR: never a caller-supplied `subjectUid`). Never a hard delete
     * (`MemoryService::forgetEntry()`); an unknown id is a structured not-found
     * result, never a thrown error.
     *
     * @param string               $uid       The acting user id.
     * @param array<string, mixed> $arguments The tool arguments (`id`, plus the
     *                                        run-injected `agentId`).
     *
     * @return array<string, mixed> The result, or an error envelope.
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only
     */
    private function forgetMemory(string $uid, array $arguments): array
    {
        $agentId = trim((string) ($arguments['agentId'] ?? ''));
        if ($agentId === '') {
            return $this->noAgentContextError();
        }

        $entryId = trim((string) ($arguments['id'] ?? ''));
        if ($entryId === '') {
            return $this->error(code: 'invalid_argument', message: 'A non-empty id is required.');
        }

        $result = $this->memoryService->forgetEntry(agentId: $agentId, subjectUid: $uid, entryId: $entryId);

        if ($result['found'] === false) {
            return ['found' => false, 'message' => 'No memory entry with that id was found.'];
        }

        return ['found' => true, 'scope' => $result['scope']];

    }//end forgetMemory()

    /**
     * Shape a SessionTurn ObjectEntity into the recall result's turn payload.
     *
     * @param ObjectEntity $turn The SessionTurn object.
     *
     * @return array<string, string> The shaped turn.
     */
    private function shapeSessionTurn(ObjectEntity $turn): array
    {
        $data = $turn->getObject();

        return [
            'role'      => (string) ($data['role'] ?? ''),
            'content'   => (string) ($data['content'] ?? ''),
            'createdAt' => (string) ($data['createdAt'] ?? ''),
        ];

    }//end shapeSessionTurn()

    /**
     * The error envelope for a memory tool called with no agent context
     * (agent-less chat — `FacadeToolInvoker::withAgentId()` never injects an
     * `agentId` when the run has none).
     *
     * @return array<string, mixed> The error envelope.
     */
    private function noAgentContextError(): array
    {
        return $this->error(
            code: 'no_agent_context',
            message: 'This tool requires an agent context and cannot be called outside an agent run.'
        );

    }//end noAgentContextError()

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
            // 🔴 Was `method_exists($board, 'getTitle')`, which is false for every getter
            // reached through `Entity::__call()`, so this listed NOTHING for its whole
            // life. `property_exists()` is what `Entity::getter()` itself consults;
            // `is_callable()` would only invert the silence. See DeckBoardMagicAccessorTest.
            if (is_object($board) === false || property_exists($board, 'title') === false) {
                continue;
            }

            try {
                // Dynamic because it IS dynamic — `__call()` materialises the method.
                $title = call_user_func([$board, 'getTitle']);
            } catch (Throwable $e) {
                $this->logger->warning('Hermiq skipped an unreadable Deck board: '.$e->getMessage());
                continue;
            }

            if (is_scalar($title) === true) {
                $results[] = ['title' => (string) $title];
            }
        }//end foreach

        return ['boards' => $results];

    }//end listDeckBoards()

    /**
     * Search the configured web-research backend (web-research-tool). The acting
     * user id is forwarded to `WebSearchClient` for the credential broker's
     * sessionless-caller path (scheduled/background runs have no session).
     *
     * @param string $uid   The acting user id.
     * @param string $query The search query.
     *
     * @return array<string, mixed> The result, or an error envelope.
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
     */
    private function webSearch(string $uid, string $query): array
    {
        if (trim($query) === '') {
            return $this->error(code: 'invalid_argument', message: 'A search query is required.');
        }

        return $this->webSearchClient->search(query: $query, actingUserId: $uid);

    }//end webSearch()

    /**
     * Fetch a URL via `WebFetchService` (web-research-tool). The URL is untrusted by
     * construction — the SSRF/allowlist/denylist guard runs inside the service before
     * any request is issued.
     *
     * @param string $url The URL to fetch.
     *
     * @return array<string, mixed> The result, or an error envelope.
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-webfetch-extracts-readable-text-with-a-content-type-gate
     */
    private function webFetch(string $url): array
    {
        if (trim($url) === '') {
            return $this->error(code: 'invalid_argument', message: 'A URL is required.');
        }

        return $this->webFetchService->fetch(url: $url);

    }//end webFetch()

    /**
     * Delegate a bounded sub-task to another agent (sub-agent-delegation).
     * Validates the tool's own input shape, then forwards to
     * `DelegationService::delegate()` — every governance gate (self/cycle,
     * allowlist, depth/fan-out, organisation, model-policy, kill-switch,
     * budget, approval) lives there, never here.
     *
     * @param array<string, mixed> $arguments The tool arguments (`targetAgentId`, `task`,
     *                                        plus the run-injected `agentId` — see
     *                                        `Engine\FacadeToolInvoker::withAgentId()`).
     *
     * @return array<string, mixed> The result, or an error envelope.
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-a-delegated-sub-agent-runs-in-an-isolated-conversation
     */
    private function delegateAgent(array $arguments): array
    {
        $callerAgentId = trim((string) ($arguments['agentId'] ?? ''));
        if ($callerAgentId === '') {
            return $this->noAgentContextError();
        }

        $targetAgentId = trim((string) ($arguments['targetAgentId'] ?? ''));
        if ($targetAgentId === '') {
            return $this->error(code: 'invalid_argument', message: 'targetAgentId is required.');
        }

        $task = trim((string) ($arguments['task'] ?? ''));
        if ($task === '') {
            return $this->error(code: 'invalid_argument', message: 'A non-empty task is required.');
        }

        return $this->delegationService->delegate(callerAgentId: $callerAgentId, targetAgentId: $targetAgentId, task: $task);

    }//end delegateAgent()

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
