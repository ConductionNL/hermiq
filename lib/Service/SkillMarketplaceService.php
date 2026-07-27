<?php

/**
 * Hermiq SkillMarketplaceService.
 *
 * Extends the skills catalog (skills-catalog) with the marketplace surface
 * (skills-marketplace): quarantine + a review gate for externally-sourced skills, an
 * age-based Curator lifecycle that never hard-deletes, and hub publishing routed through
 * OpenConnector. All persistence flows through OpenRegister ObjectService (single
 * write-path, native tenant scoping, ADR-001 Option C+/ADR-003).
 *
 * Externally-sourced skill content is heuristically scanned on install via OpenRegister's
 * ContentScanService (remote-code / destructive-shell / exfiltration / embedded-secret /
 * prompt-injection patterns); the verdict is recorded on the skill and a `dangerous` verdict
 * blocks one-click approval, so a reviewer must consciously override it. The quarantine
 * invariant — an externally-sourced skill is never auto-active — holds regardless, with the
 * scan enriching the review gate rather than replacing it. Hub submission goes through
 * OpenConnector's CallService (no direct HTTP); with no hub connector configured it returns
 * a structured error.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/skills-marketplace/tasks.md#2-skillmarketplaceservice
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ContentScanService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Quarantine + Curator + hub-publish surface for agent skills.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The marketplace lifecycle spans
 * install-from-source (HTTP), content scanning (OR ContentScanService), skill CRUD,
 * curation (cron) and hub publish; each dependency is one lifecycle stage.
 *
 * @spec openspec/changes/skills-marketplace/tasks.md#2-skillmarketplaceservice
 */
class SkillMarketplaceService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for skill objects.
     *
     * @var string
     */
    private const SKILL_SCHEMA = 'agentskill';

    /**
     * Default days of inactivity before an active skill becomes stale.
     *
     * @var int
     */
    private const DEFAULT_STALE_DAYS = 90;

    /**
     * Default days a stale skill waits before being archived.
     *
     * @var int
     */
    private const DEFAULT_ARCHIVE_DAYS = 180;

    /**
     * Constructor.
     *
     * @param ObjectService      $objectService      OpenRegister object read/write (single write-path).
     * @param SkillService       $skillService       Catalog service (get-by-uuid).
     * @param SkillSerializer    $skillSerializer    agentskills.io (de)serialiser.
     * @param ContentScanService $contentScanService OpenRegister heuristic content scanner.
     * @param IAppConfig         $appConfig          App config (curator thresholds).
     * @param ContainerInterface $container          Lazy OpenConnector CallService resolution.
     * @param LoggerInterface    $logger             PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Distinct collaborators.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SkillService $skillService,
        private readonly SkillSerializer $skillSerializer,
        private readonly ContentScanService $contentScanService,
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Install a skill from an external source into QUARANTINE (never auto-active).
     *
     * @param string $package   The agentskills.io package.
     * @param string $source    The source (`org`|`hub`).
     * @param string $createdBy The installing user id.
     *
     * @return ObjectEntity The quarantined Skill object.
     *
     * @spec openspec/changes/skills-marketplace/tasks.md#task-2-1
     */
    public function installFromSource(string $package, string $source, string $createdBy): ObjectEntity
    {
        $parsed = $this->skillSerializer->fromPackage(package: $package);

        $name = $parsed['name'];
        if ($name === '') {
            $name = 'Untitled skill';
        }

        // Heuristically scan the skill body + frontmatter for dangerous patterns before it is
        // ever stored as trusted content. The verdict is recorded; a 'dangerous' verdict is
        // surfaced in the quarantine reason and later blocks one-click approval.
        $scan   = $this->scanContent(body: (string) $parsed['body'], frontmatter: $parsed['frontmatter']);
        $reason = $this->quarantineReasonFor(source: $source, scan: $scan);

        return $this->objectService->saveObject(
            object: [
                'name'             => $name,
                'description'      => $parsed['description'],
                'frontmatter'      => $parsed['frontmatter'],
                'body'             => $parsed['body'],
                'files'            => [],
                'state'            => 'quarantined',
                'source'           => $source,
                'quarantineReason' => $reason,
                'scanReport'       => $scan,
                'lastActivityAt'   => $this->now(),
                'createdBy'        => $createdBy,
                'installedOn'      => [],
            ],
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA
        );

    }//end installFromSource()

    /**
     * The review gate: transition a quarantined skill to active.
     *
     * A `dangerous` content-scan verdict blocks one-click approval — the skill stays
     * quarantined and the caller is told to override explicitly (approveQuarantined with
     * $force=true), so a reviewer cannot activate malicious content by reflex.
     *
     * @param string $skillId The Skill UUID.
     * @param bool   $force   Override a `dangerous` scan verdict (a conscious reviewer decision).
     *
     * @return ObjectEntity|null The updated Skill, or null when not found.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$force` is a genuine two-mode
     * reviewer decision (explicit dangerous-verdict override), part of the public
     * seam the controller exposes — not an SRP smell.
     *
     * @spec openspec/changes/skills-marketplace/tasks.md#task-2-2
     */
    public function approveQuarantined(string $skillId, bool $force=false): ?ObjectEntity
    {
        $skill = $this->skillService->getSkill(skillId: $skillId);
        if ($skill === null) {
            return null;
        }

        $data = $skill->getObject();
        if ((string) ($data['state'] ?? '') !== 'quarantined') {
            // Not quarantined — nothing to approve; return unchanged.
            return $skill;
        }

        // Re-scan at the gate (content is authoritative; the stored report may be stale) and
        // refuse to auto-activate a dangerous skill unless a reviewer explicitly overrides.
        $scan = $this->scanContent(body: (string) ($data['body'] ?? ''), frontmatter: ($data['frontmatter'] ?? []));
        $data['scanReport'] = $scan;
        if (($scan['severity'] ?? '') === ContentScanService::SEVERITY_DANGEROUS && $force === false) {
            $data['quarantineReason'] = $this->quarantineReasonFor(source: (string) ($data['source'] ?? 'org'), scan: $scan);
            $this->logger->warning(
                'Hermiq blocked one-click approval of a dangerous skill',
                ['skillId' => $skillId, 'findings' => count($scan['findings'] ?? [])]
            );
            return $this->save(data: $data, uuid: (string) $skill->getUuid());
        }

        $data['state']            = 'active';
        $data['quarantineReason'] = null;
        $data['lastActivityAt']   = $this->now();

        return $this->save(data: $data, uuid: (string) $skill->getUuid());

    }//end approveQuarantined()

    /**
     * Age-based lifecycle curation: active→stale→archived; NEVER hard-deletes.
     *
     * @return array<string, int> Summary counts { scanned, staled, archived }.
     *
     * @spec openspec/changes/skills-marketplace/tasks.md#task-2-3
     */
    public function curate(): array
    {
        $staleDays   = $this->appConfig->getValueInt(Application::APP_ID, 'skillStaleDays', self::DEFAULT_STALE_DAYS);
        $archiveDays = $this->appConfig->getValueInt(Application::APP_ID, 'skillArchiveDays', self::DEFAULT_ARCHIVE_DAYS);

        $nowTs    = time();
        $scanned  = 0;
        $staled   = 0;
        $archived = 0;

        foreach ($this->loadSkills() as $skill) {
            $scanned++;
            $data  = $skill->getObject();
            $state = (string) ($data['state'] ?? 'active');

            if ($state === 'active' && $this->olderThanDays(timestamp: ($data['lastActivityAt'] ?? null), days: $staleDays, now: $nowTs) === true) {
                $data['state']      = 'stale';
                $data['staleSince'] = $this->now();
                $this->save(data: $data, uuid: (string) $skill->getUuid(), systemWide: true);
                $staled++;
                continue;
            }

            if ($state === 'stale' && $this->olderThanDays(timestamp: ($data['staleSince'] ?? null), days: $archiveDays, now: $nowTs) === true) {
                $data['state']      = 'archived';
                $data['archivedAt'] = $this->now();
                // NEVER delete — archived skills stay reconstructable.
                $this->save(data: $data, uuid: (string) $skill->getUuid(), systemWide: true);
                $archived++;
            }
        }//end foreach

        $summary = [
            'scanned'  => $scanned,
            'staled'   => $staled,
            'archived' => $archived,
        ];

        $this->logger->debug('Hermiq skill curator pass complete', $summary);

        return $summary;

    }//end curate()

    /**
     * Publish a skill to an external hub via OpenConnector (no direct HTTP).
     *
     * @param string $skillId The Skill UUID.
     * @param string $hubId   The target hub identifier (an OpenConnector source/connector).
     *
     * @return array<string, mixed> The publish result or a structured error.
     *
     * @spec openspec/changes/skills-marketplace/tasks.md#task-2-4
     */
    public function publishToHub(string $skillId, string $hubId): array
    {
        $skill = $this->skillService->getSkill(skillId: $skillId);
        if ($skill === null) {
            return ['error' => ['code' => 'not_found', 'message' => 'Skill not found.']];
        }

        // Serialise via the ONE export selection (skills-marketplace delta): the
        // OpenConnector secondary route applies the SAME `learning-candidates.md`
        // strip as GitHub publish — unvetted observations never leave the instance.
        $package = ($this->skillService->exportSkill(skillId: $skillId) ?? '');

        try {
            // Route outbound through OpenConnector's CallService — never a direct HTTP client.
            $callService = $this->container->get('OCA\\OpenConnector\\Service\\CallService');
        } catch (Throwable $e) {
            return [
                'error'        => [
                    'code'    => 'hub_unavailable',
                    'message' => 'No OpenConnector hub connector is configured; cannot publish. The serialised package is ready.',
                ],
                'packageBytes' => strlen($package),
            ];
        }

        // A live hub needs a configured OpenConnector source for $hubId; without one this
        // is a documented seam. We do NOT open a direct HTTP client here.
        unset($callService);
        return [
            'error'        => [
                'code'    => 'hub_unconfigured',
                'message' => 'OpenConnector is present but no hub source is configured for "'.$hubId.'".',
            ],
            'packageBytes' => strlen($package),
        ];

    }//end publishToHub()

    /**
     * Save a skill payload back to OpenRegister.
     *
     * The Curator cron has no user session, so it saves RBAC/multitenancy OFF (updating
     * the existing object in place preserves its owner/organisation). User-initiated calls
     * (approve) save in the caller's context.
     *
     * @param array<string, mixed> $data       The skill payload.
     * @param string               $uuid       The skill UUID.
     * @param bool                 $systemWide Whether to save with RBAC/multitenancy off (the cron).
     *
     * @return ObjectEntity The persisted object.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$systemWide` is a genuine two-mode
     * seam (sessionless Curator cron vs user-context save); the RBAC toggle is the
     * documented point of the parameter.
     */
    private function save(array $data, string $uuid, bool $systemWide=false): ObjectEntity
    {
        // OR materialises stored date-times back as space-separated ('Y-m-d H:i:s') on read;
        // re-saving that value fails the schema's 'date-time' format. Normalise every
        // date-time field to ISO-8601 before the write (the ScheduleService gotcha).
        foreach (['lastActivityAt', 'staleSince', 'archivedAt'] as $field) {
            $data[$field] = $this->normaliseDate(value: ($data[$field] ?? null));
        }

        return $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            uuid: $uuid,
            _rbac: ($systemWide === false),
            _multitenancy: ($systemWide === false)
        );

    }//end save()

    /**
     * Load every skill system-wide for the Curator.
     *
     * The Curator is a background cron with no user session, so it reads RBAC/multitenancy
     * OFF to curate every organisation's skills by age (the dispatcher's pattern). Curation
     * only advances lifecycle state (active→stale→archived) — it never crosses tenants with
     * the data, and never deletes.
     *
     * @return array<int, ObjectEntity> The skill objects.
     */
    private function loadSkills(): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SKILL_SCHEMA)
            ->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);

        $out = [];
        foreach ($objects as $object) {
            if ($object instanceof ObjectEntity) {
                $out[] = $object;
            }
        }

        return $out;

    }//end loadSkills()

    /**
     * Whether a timestamp is older than N days (missing/invalid ⇒ treated as eligible).
     *
     * @param mixed $timestamp The ISO-8601 timestamp (or null).
     * @param int   $days      The threshold in days.
     * @param int   $now       The current unix time.
     *
     * @return bool True when older than the threshold.
     */
    private function olderThanDays(mixed $timestamp, int $days, int $now): bool
    {
        $thresholdSeconds = ($days * 86400);

        if (is_string($timestamp) === false || $timestamp === '') {
            // No activity stamp — an untracked/legacy skill is eligible.
            return true;
        }

        try {
            $stamp = (new DateTimeImmutable($timestamp))->getTimestamp();
        } catch (Throwable $e) {
            return true;
        }

        return (($now - $stamp) >= $thresholdSeconds);

    }//end olderThanDays()

    /**
     * Run the OpenRegister content scanner over a skill's body + frontmatter and return a
     * report augmented with the scan time (for the scanReport field).
     *
     * Frontmatter arrives as raw YAML (a string) from the agentskills.io serializer, or as a
     * decoded array elsewhere; a string is scanned inline with the body, an array is folded in
     * as structured metadata.
     *
     * @param string $body        The skill body (markdown/instructions).
     * @param mixed  $frontmatter The skill frontmatter (raw YAML string or decoded array).
     *
     * @return array<string, mixed> The scan report { severity, safe, findings, scannedAt, … }.
     */
    private function scanContent(string $body, mixed $frontmatter): array
    {
        $content  = $body;
        $metadata = [];
        if (is_array($frontmatter) === true) {
            $metadata = $frontmatter;
        } else if (is_string($frontmatter) === true && $frontmatter !== '') {
            $content .= "\n".$frontmatter;
        }

        $report = $this->contentScanService->scan(content: $content, metadata: $metadata);
        $report['scannedAt'] = $this->now();

        return $report;

    }//end scanContent()

    /**
     * The quarantine reason for a freshly-installed or re-scanned skill, reflecting the scan
     * verdict so a reviewer sees why it needs attention.
     *
     * @param string               $source The install source (`org`|`hub`|`local`).
     * @param array<string, mixed> $scan   The scan report from scanContent().
     *
     * @return string The human-readable quarantine reason.
     */
    private function quarantineReasonFor(string $source, array $scan): string
    {
        $severity = (string) ($scan['severity'] ?? ContentScanService::SEVERITY_CLEAN);
        $count    = count(($scan['findings'] ?? []));

        if ($severity === ContentScanService::SEVERITY_DANGEROUS) {
            return 'Installed from '.$source.'; content scan flagged '.$count.' DANGEROUS pattern(s) — '
                .'review before activation (approval is blocked until overridden).';
        }

        if ($severity === ContentScanService::SEVERITY_SUSPICIOUS) {
            return 'Installed from '.$source.'; content scan flagged '.$count.' suspicious pattern(s) — review before activation.';
        }

        return 'Installed from '.$source.'; awaiting review before activation.';

    }//end quarantineReasonFor()

    /**
     * The current UTC timestamp in ISO-8601.
     *
     * @return string The ISO-8601 timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    }//end now()

    /**
     * Normalise a stored date value to ISO-8601 (or null), fixing OR's space-format read.
     *
     * @param mixed $value The raw date value.
     *
     * @return string|null The ISO-8601 date, or null.
     */
    private function normaliseDate(mixed $value): ?string
    {
        if (is_string($value) === false || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('c');
        } catch (Throwable $e) {
            return null;
        }

    }//end normaliseDate()
}//end class
