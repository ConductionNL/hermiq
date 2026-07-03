<?php

/**
 * Hermiq ScheduleService.
 *
 * The dispatch engine behind ScheduleTask. On each tick it selects due, enabled
 * Schedule objects from OpenRegister (register `hermiq`, schema `schedule`),
 * advances their run-state BEFORE firing (at-most-once crash safety), impersonates
 * the schedule owner, invokes OpenRegister's agent runtime (ChatService) with the
 * bound agent and prompt, calls a delivery hook, and manages repeat accounting and
 * lifecycle. Hermiq owns no LLM/tool engine — all execution and persistence go
 * through OpenRegister's single write-path (ADR-001, ADR-002, ADR-004).
 *
 * This is the recognised ADR-031 imperative exception: scheduled bulk work that
 * fires agents, not a derived value or declarative lifecycle transition.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Cron\CronExpression;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Selects and dispatches due Hermiq schedules against OpenRegister agents.
 *
 * The register slug is `hermiq` and the schema slug is `schedule` (owned upstream
 * by the agent-schedule-schema change). Every state mutation flows through
 * OpenRegister's ObjectService so tenancy and the ADR-004 audit-trail are inherited.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Dispatcher coordinates OR services.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Sum of many small single-purpose
 *   defensive helpers (date/repeat sanitisers, per-kind next-run, impersonation);
 *   each method stays simple, but a poll-dispatch job legitimately has many of them.
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
 */
class ScheduleService
{

    /**
     * OpenRegister register slug that holds Hermiq schedule objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for schedule objects.
     *
     * @var string
     */
    private const SCHEMA_SLUG = 'schedule';

    /**
     * Schedule properties declared as `date-time` in the schema.
     *
     * OpenRegister's getObject() returns stored date-times as `Y-m-d H:i:s`
     * (space, no `T`), but saveObject re-validates the WHOLE object against the
     * schema's `date-time` format, which requires ISO-8601 with `T`. Every one of
     * these fields must be re-normalised to `format('c')` before a full-object
     * save, or the write is rejected.
     *
     * @var array<int, string>
     */
    private const DATE_TIME_FIELDS = ['nextRun', 'runAt'];

    /**
     * Constructor.
     *
     * @param ObjectService      $objectService      OpenRegister object read/write (single write-path).
     * @param AgentMapper        $agentMapper        Resolves an agent UUID to an Agent entity.
     * @param ConversationMapper $conversationMapper Creates the conversation row the agent runs against.
     * @param ChatService        $chatService        OpenRegister agent runtime (processMessage).
     * @param IUserSession       $userSession        Session used to impersonate the schedule owner.
     * @param IUserManager       $userManager        Resolves the owner UID to an IUser.
     * @param IConfig            $config             Reads owner/instance timezone.
     * @param LoggerInterface    $logger             PSR-3 logger (delivery seam + diagnostics).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AgentMapper $agentMapper,
        private readonly ConversationMapper $conversationMapper,
        private readonly ChatService $chatService,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Dispatch every due schedule for this tick.
     *
     * Selects enabled Schedule objects whose `nextRun` is at or before now, then
     * processes each in isolation: a failure on one schedule is recorded and does
     * not abort the rest of the tick.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-1
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-7
     */
    public function run(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $due = $this->findDueSchedules(now: $now);
        } catch (Throwable $e) {
            $this->logger->error(
                'Hermiq dispatcher could not load due schedules: '.$e->getMessage(),
                ['exception' => $e]
            );
            return;
        }

        foreach ($due as $schedule) {
            // Per-schedule isolation: one bad schedule must not block the tick.
            try {
                $this->dispatch(schedule: $schedule, now: $now);
            } catch (Throwable $e) {
                $this->recordFailure(schedule: $schedule, error: $e);
            }
        }

    }//end run()

    /**
     * Find enabled schedules that are due at or before the given moment.
     *
     * Enabled schedules are fetched register/schema-wide (RBAC/multi-tenancy off —
     * the tick runs system-wide, then impersonates each owner before firing) and the
     * `nextRun <= now` cut is applied in PHP for operator-independent correctness.
     *
     * @param DateTimeImmutable $now The current UTC moment.
     *
     * @return array<int, ObjectEntity> The due, enabled schedule objects.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-1
     */
    private function findDueSchedules(DateTimeImmutable $now): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEMA_SLUG)
            ->findAll(
                config: ['filters' => ['enabled' => true]],
                _rbac: false,
                _multitenancy: false
            );

        $due = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            if (($data['enabled'] ?? false) !== true) {
                continue;
            }

            $nextRun = $this->parseDate(value: (string) ($data['nextRun'] ?? ''));
            if ($nextRun === null || $nextRun <= $now) {
                $due[] = $object;
            }
        }

        return $due;

    }//end findDueSchedules()

    /**
     * Process one due schedule end-to-end.
     *
     * Order matters for at-most-once safety: run-state (`nextRun`, `lastStatus`,
     * `repeat.completed`) is committed BEFORE the agent turn, so a crash during the
     * (long) agent run cannot re-fire the same occurrence.
     *
     * @param ObjectEntity      $schedule The schedule object to fire.
     * @param DateTimeImmutable $now      The current UTC moment.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-4
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
     */
    private function dispatch(ObjectEntity $schedule, DateTimeImmutable $now): void
    {
        $data  = $schedule->getObject();
        $owner = (string) ($schedule->getOwner() ?? '');
        $kind  = (string) ($data['kind'] ?? '');

        $repeat = $this->normaliseRepeat(repeat: ($data['repeat'] ?? []));
        $repeat['completed'] += 1;
        $limitReached         = ($repeat['times'] > 0 && $repeat['completed'] >= $repeat['times']);
        $isOnce = ($kind === 'once');

        // COMMIT-BEFORE-RUN (at-most-once). Advance nextRun and mark running before
        // the agent is ever invoked. One-shots and finished finite repeats disable
        // themselves so they are not re-selected next tick.
        $nextRun            = $this->computeNextRun(kind: $kind, data: $data, owner: $owner, now: $now);
        $data['nextRun']    = $nextRun?->format('c');
        $data['lastStatus'] = 'running';
        $data['lastError']  = null;
        $data['repeat']     = $repeat;
        if ($isOnce === true || $limitReached === true) {
            $data['enabled'] = false;
        }

        $this->persist(schedule: $schedule, data: $data);

        // Run the agent + deliver + finalise inside a try/catch that operates on the
        // SAME $data that already carries the committed advance (nextRun / disabled
        // one-shot / bumped repeat). CRASH-SAFETY INVARIANT (task 4.2): whether the
        // turn succeeds or fails, the failure branch must NOT re-read the stale
        // pre-commit entity nor recompute nextRun — reverting the advance would make
        // a failing schedule stay perpetually due and re-fire every tick, defeating
        // commit-before-run. On failure the advance stays; only lastStatus/lastError
        // change.
        try {
            $output = $this->runAgentAsOwner(
                owner: $owner,
                agentId: (string) ($data['agentId'] ?? ''),
                prompt: (string) ($data['prompt'] ?? '')
            );

            $this->deliver(
                channel: (string) ($data['deliver'] ?? 'none'),
                output: $output,
                schedule: $schedule
            );

            // Finalise success state on the advanced $data.
            $data['lastStatus'] = 'ok';
            $data['lastError']  = null;
        } catch (Throwable $e) {
            // Record the failure on the advanced $data — the advance is preserved.
            $this->logger->warning(
                sprintf('Hermiq schedule %s failed: %s', (string) $schedule->getUuid(), $e->getMessage()),
                ['exception' => $e]
            );
            $data['lastStatus'] = 'error';
            $data['lastError']  = $e->getMessage();
        }//end try

        if ($limitReached === true) {
            $this->deleteSchedule(schedule: $schedule);
            return;
        }

        $this->persist(schedule: $schedule, data: $data);

    }//end dispatch()

    /**
     * Compute the next run time for a schedule, anchored to the owner's timezone.
     *
     * Cron uses dragonmantank/cron-expression; interval adds `intervalMinutes`;
     * once returns null (one-shots do not recur). The owner's configured timezone is
     * used, falling back to the instance default timezone when the owner has none.
     *
     * @param string              $kind  Schedule kind: once|interval|cron.
     * @param array<string,mixed> $data  The schedule payload.
     * @param string              $owner The owner UID (for timezone resolution).
     * @param DateTimeImmutable   $now   The current UTC moment.
     *
     * @return DateTimeImmutable|null The next run (UTC), or null for one-shots.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-2
     */
    private function computeNextRun(string $kind, array $data, string $owner, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $timezone = $this->resolveTimezone(owner: $owner);

        if ($kind === 'cron') {
            $cronExpr = (string) ($data['cronExpr'] ?? '');
            if ($cronExpr === '') {
                return null;
            }

            $cron = new CronExpression($cronExpr);
            $next = $cron->getNextRunDate(
                currentTime: $now->setTimezone($timezone),
                nth: 0,
                allowCurrentDate: false,
                timeZone: $timezone->getName()
            );

            return DateTimeImmutable::createFromMutable($next)->setTimezone(new DateTimeZone('UTC'));
        }

        if ($kind === 'interval') {
            $minutes = (int) ($data['intervalMinutes'] ?? 0);
            if ($minutes <= 0) {
                return null;
            }

            return $now->add(new DateInterval('PT'.$minutes.'M'));
        }

        // Once: one-shot, does not recur.
        return null;

    }//end computeNextRun()

    /**
     * Invoke the OpenRegister agent as the schedule owner and return its output.
     *
     * Impersonates the owner (IUserSession/IUserManager, mirroring OpenConnector's
     * JobService), resolves the agent UUID, opens a conversation bound to that agent,
     * and runs ChatService::processMessage with the prompt. The prior session user is
     * always restored so identity never bleeds across schedules in the same tick.
     *
     * @param string $owner   The schedule owner UID.
     * @param string $agentId The bound agent UUID.
     * @param string $prompt  The prompt to run.
     *
     * @return string The agent's response text.
     *
     * @throws RuntimeException When the owner or agent cannot be resolved.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-4
     */
    private function runAgentAsOwner(string $owner, string $agentId, string $prompt): string
    {
        $user = $this->userManager->get($owner);
        if ($user === null) {
            throw new RuntimeException("Schedule owner '{$owner}' does not exist");
        }

        $priorUser = $this->userSession->getUser();
        $this->userSession->setUser($user);

        try {
            $agent        = $this->agentMapper->findByUuid($agentId);
            $conversation = new Conversation();
            $conversation->setUserId($owner);
            $conversation->setOwner($owner);
            $conversation->setAgentId($agent->getId());
            $conversation->setTitle('Hermiq scheduled run');
            $conversation = $this->conversationMapper->insert($conversation);

            $result = $this->chatService->processMessage(
                conversationId: (int) $conversation->getId(),
                userId: $owner,
                userMessage: $prompt
            );

            return (string) ($result['message'] ?? '');
        } finally {
            // Restore the pre-impersonation identity (OpenConnector #1006 pattern).
            $this->userSession->setUser($priorUser);
        }

    }//end runAgentAsOwner()

    /**
     * Delivery seam.
     *
     * Logs the intended delivery for `talk`/`notification` and skips `none`. The real
     * Talk/notification adapter is the separate `talk-delivery` change; this seam keeps
     * the dispatcher testable in isolation without being an empty no-op.
     *
     * @param string       $channel  Delivery channel: talk|notification|none.
     * @param string       $output   The agent output to deliver.
     * @param ObjectEntity $schedule The schedule the output belongs to.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-5
     */
    private function deliver(string $channel, string $output, ObjectEntity $schedule): void
    {
        if ($channel === 'none' || $channel === '') {
            return;
        }

        $this->logger->info(
            sprintf(
                'Hermiq schedule %s would deliver via %s (%d chars) — talk-delivery seam',
                (string) $schedule->getUuid(),
                $channel,
                strlen($output)
            )
        );

    }//end deliver()

    /**
     * Last-resort failure recorder for exceptions that escape dispatch()'s own
     * try/catch (e.g. a failure during the commit-before-run write itself).
     *
     * The agent-turn failure is handled inside dispatch() on the post-commit $data,
     * so this path should essentially never fire. When it does, it MUST NOT revert
     * the committed run-state: it re-fetches the FRESH object (which already carries
     * any advanced nextRun / disabled one-shot / bumped repeat that a prior persist
     * committed) and overwrites only `lastStatus`/`lastError` — it never operates on
     * the stale pre-commit in-memory entity (BUG 4). If the object cannot be
     * re-fetched, it logs and skips rather than clobbering with stale data.
     * Best-effort: a persistence failure here is logged, not re-thrown.
     *
     * @param ObjectEntity $schedule The schedule that failed.
     * @param Throwable    $error    The captured failure.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-7
     */
    private function recordFailure(ObjectEntity $schedule, Throwable $error): void
    {
        $this->logger->warning(
            sprintf('Hermiq schedule %s failed: %s', (string) $schedule->getUuid(), $error->getMessage()),
            ['exception' => $error]
        );

        try {
            $uuid  = (string) $schedule->getUuid();
            $fresh = $this->objectService->find(
                id: $uuid,
                register: self::REGISTER_SLUG,
                schema: self::SCHEMA_SLUG,
                _rbac: false,
                _multitenancy: false
            );

            if ($fresh === null) {
                // Do not clobber committed run-state with a stale pre-commit copy.
                $this->logger->error(
                    sprintf('Hermiq could not re-fetch schedule %s to record failure; skipping.', $uuid)
                );
                return;
            }

            $data = $fresh->getObject();
            $data['lastStatus'] = 'error';
            $data['lastError']  = $error->getMessage();
            $this->persist(schedule: $fresh, data: $data);
        } catch (Throwable $e) {
            $this->logger->error(
                'Hermiq could not record schedule failure: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try

    }//end recordFailure()

    /**
     * Persist a schedule payload through OpenRegister's single write-path.
     *
     * Every date-time field is re-normalised to ISO-8601 (`format('c')`) first, so
     * a full-object save always passes the schema's `date-time` format — OR's
     * getObject() hands date-times back as `Y-m-d H:i:s` (space, no `T`), which the
     * schema rejects. Applied here so ALL callers (success + failure) are covered.
     *
     * @param ObjectEntity        $schedule The schedule being updated.
     * @param array<string,mixed> $data     The new object payload.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
     */
    private function persist(ObjectEntity $schedule, array $data): void
    {
        $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $data),
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            uuid: (string) $schedule->getUuid(),
            _rbac: false,
            _multitenancy: false
        );

    }//end persist()

    /**
     * Neutralise OpenRegister read-modify-write round-trip artifacts before a save.
     *
     * The dispatcher reads a whole object via getObject(), edits a few fields, and
     * saves the whole array back — so OR re-validates the ENTIRE payload against the
     * schema, including fields OR itself materialised on read in a shape the schema
     * rejects. This single seam repairs the artifacts on the fields this change owns
     * (`nextRun`/`runAt` date-time format, and the nullable `repeat` object) so a
     * full-object save passes OR's own validation. It repairs OR's own read-side
     * shape only — it does not mask genuinely user-supplied invalid data.
     *
     * @param array<string,mixed> $data The payload about to be saved.
     *
     * @return array<string,mixed> The sanitised payload.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
     */
    private function sanitizeForSave(array $data): array
    {
        $data = $this->normaliseDates(data: $data);
        $data = $this->sanitizeRepeat(data: $data);
        return $data;

    }//end sanitizeForSave()

    /**
     * Normalise all date-time fields in a schedule payload to ISO-8601 with `T`.
     *
     * Reparses each present `date-time` field (from any parseable form, notably OR's
     * `Y-m-d H:i:s`) and rewrites it with `format('c')`. Null/empty values are left
     * as-is; genuinely unparseable values are left untouched so validation surfaces
     * them rather than this method silently masking a bad payload.
     *
     * @param array<string,mixed> $data The payload about to be saved.
     *
     * @return array<string,mixed> The payload with normalised date-time fields.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
     */
    private function normaliseDates(array $data): array
    {
        foreach (self::DATE_TIME_FIELDS as $field) {
            if (array_key_exists($field, $data) === false) {
                continue;
            }

            $value = $data[$field];
            if ($value === null || $value === '') {
                continue;
            }

            $parsed = $this->parseDate(value: (string) $value);
            if ($parsed !== null) {
                $data[$field] = $parsed->format('c');
            }
        }

        return $data;

    }//end normaliseDates()

    /**
     * Sanitise the nullable `repeat` object against its schema constraints.
     *
     * `repeat` is optional: a schedule with no valid finite-repeat is INFINITE and
     * must serialise as `repeat = null`. OR's getObject() materialises the nullable
     * object as `{"times": 0, "completed": 0}` on read, which then fails the schema's
     * `repeat.times` `minimum: 1` when the whole object is saved back. This collapses
     * any non-finite repeat (missing, non-array, or `times < 1`) to `null`, and keeps
     * a genuine finite repeat only when `times >= 1` (with `completed` coerced to an
     * int >= 0). It repairs OR's round-trip artifact, not user intent: a user who
     * wants a finite repeat supplies `times >= 1`, which is preserved verbatim.
     *
     * @param array<string,mixed> $data The payload about to be saved.
     *
     * @return array<string,mixed> The payload with a schema-valid `repeat`.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
     */
    private function sanitizeRepeat(array $data): array
    {
        if (array_key_exists('repeat', $data) === false) {
            return $data;
        }

        $repeat = $data['repeat'];
        if (is_array($repeat) === false || isset($repeat['times']) === false || is_numeric($repeat['times']) === false) {
            $data['repeat'] = null;
            return $data;
        }

        $times = (int) $repeat['times'];
        if ($times < 1) {
            // A times value below 1 means an infinite schedule; drop the artifact.
            $data['repeat'] = null;
            return $data;
        }

        $completed = 0;
        if (isset($repeat['completed']) === true && is_numeric($repeat['completed']) === true) {
            $completed = max(0, (int) $repeat['completed']);
        }

        $data['repeat'] = [
            'times'     => $times,
            'completed' => $completed,
        ];

        return $data;

    }//end sanitizeRepeat()

    /**
     * Delete a schedule that has reached its repeat limit.
     *
     * @param ObjectEntity $schedule The schedule to delete.
     *
     * @return void
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
     */
    private function deleteSchedule(ObjectEntity $schedule): void
    {
        $this->objectService->deleteObject(
            uuid: (string) $schedule->getUuid(),
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            _rbac: false,
            _multitenancy: false
        );

    }//end deleteSchedule()

    /**
     * Resolve the timezone to anchor next-run computation to.
     *
     * Uses the owner's configured Nextcloud timezone; falls back to the instance
     * default timezone, and finally UTC, when the owner has none (decision locked).
     *
     * @param string $owner The owner UID.
     *
     * @return DateTimeZone The resolved timezone.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-2
     */
    private function resolveTimezone(string $owner): DateTimeZone
    {
        $tz = '';
        if ($owner !== '') {
            $tz = (string) $this->config->getUserValue($owner, 'core', 'timezone', '');
        }

        if ($tz === '') {
            $tz = (string) $this->config->getSystemValueString('default_timezone', 'UTC');
        }

        try {
            return new DateTimeZone($tz);
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }

    }//end resolveTimezone()

    /**
     * Normalise the `repeat` payload to a `{times:int, completed:int}` shape.
     *
     * @param mixed $repeat The raw repeat value from the schedule.
     *
     * @return array{times:int, completed:int}
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
     */
    private function normaliseRepeat(mixed $repeat): array
    {
        if (is_array($repeat) === false) {
            $repeat = [];
        }

        return [
            'times'     => (int) ($repeat['times'] ?? 0),
            'completed' => (int) ($repeat['completed'] ?? 0),
        ];

    }//end normaliseRepeat()

    /**
     * Parse a stored ISO-8601 timestamp into a UTC DateTimeImmutable.
     *
     * @param string $value The stored timestamp (may be empty).
     *
     * @return DateTimeImmutable|null The parsed UTC moment, or null when unparseable.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return null;
        }

    }//end parseDate()
}//end class
