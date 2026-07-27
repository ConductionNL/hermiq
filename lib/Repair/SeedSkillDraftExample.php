<?php

/**
 * Hermiq SeedSkillDraftExample repair step.
 *
 * Seeds ONE pending consolidation draft (skill-self-improvement) on the seeded
 * `tender-summary` skill — `awaiting-approval`, threshold trigger, dated learnings
 * refs, nil-UUID run ids, a small body improvement, a clean scan verdict and the
 * honest `noEvalEvidence: true` flag — PLUS its linked pending `Approval` carrying
 * the REQUIRED decision-evidence payload, so a fresh install renders the full review
 * surface and deciding the seed (from SkillDetail OR the generic approval inbox)
 * exercises the real accept/reject path.
 *
 * Idempotent: matched by the tender-summary skill + the existence of ANY draft for
 * it — a re-run never duplicates, INCLUDING after the draft is decided (a decided
 * draft still exists, so no second draft or Approval is ever created). Seeded in
 * system context WITHOUT dispatching a notification (the ping requirement applies to
 * runtime creation, not repair-step seeding). Placeholders are nil UUIDs only.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\SkillConsolidationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed one decidable pending SkillDraft + payload-carrying Approval on the
 * tender-summary seed skill.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
 */
class SeedSkillDraftExample implements IRepairStep
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
     * Schema slug for skill draft objects.
     *
     * @var string
     */
    private const DRAFT_SCHEMA = 'agentskilldraft';

    /**
     * Schema slug for approval objects.
     *
     * @var string
     */
    private const APPROVAL_SCHEMA = 'approval';

    /**
     * The seed skill this draft rides on (SeedMaturityExampleSkills, a declared
     * dependency of this change).
     *
     * @var string
     */
    private const SEED_SKILL_NAME = 'tender-summary';

    /**
     * The nil-UUID placeholder for seeded run ids.
     *
     * @var string
     */
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container    Lazy OpenRegister/consolidation resolution
     *                                         (OpenRegister may be absent).
     * @param IURLGenerator      $urlGenerator Builds the Approval payload's SkillDetail deep link.
     * @param LoggerInterface    $logger       PSR-3 logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     *
     * @spec exclude Trivial IRepairStep display-name accessor; no behavioural spec.
     */
    public function getName(): string
    {
        return 'Seed a pending skill consolidation draft example (skill-self-improvement)';

    }//end getName()

    /**
     * Seed the pending draft + Approval when the tender-summary skill exists and no
     * draft (in ANY state) exists for it yet.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
     */
    public function run(IOutput $output): void
    {
        try {
            $objectService = $this->container->get(ObjectService::class);
        } catch (Throwable $e) {
            $output->warning('OpenRegister not available — skipping skill draft example seed.');
            $this->logger->warning('[hermiq] skill draft example seed skipped: '.$e->getMessage());
            return;
        }

        try {
            $skill = $this->findSkillByName(objectService: $objectService, name: self::SEED_SKILL_NAME);
            if ($skill === null) {
                $output->info(self::SEED_SKILL_NAME.' seed skill absent — skipping draft example seed.');
                return;
            }

            $skillId = (string) $skill->getUuid();

            if ($this->draftExistsForSkill(objectService: $objectService, skillId: $skillId) === true) {
                // Idempotency: the seed exists in SOME state (pending or already
                // decided) — never a second draft or Approval.
                $output->info('Skill draft example already present — skipped.');
                return;
            }

            $draft = $objectService->saveObject(
                object: $this->draftPayload(skill: $skill),
                register: self::REGISTER_SLUG,
                schema: self::DRAFT_SCHEMA,
                _rbac: false,
                _multitenancy: false
            );

            // The linked pending Approval is seeded DIRECTLY (system context, no
            // notification dispatch) but with the full REQUIRED decision-evidence
            // payload — inbox-approving it exercises the real apply path.
            $approval = $objectService->saveObject(
                object: $this->approvalPayload(draft: $draft, skillId: $skillId),
                register: self::REGISTER_SLUG,
                schema: self::APPROVAL_SCHEMA,
                _rbac: false,
                _multitenancy: false
            );

            $draftData = $draft->getObject();
            $draftData['approvalId'] = (string) $approval->getUuid();
            unset($draftData['id'], $draftData['uuid'], $draftData['@self']);

            $objectService->saveObject(
                object: $draftData,
                register: self::REGISTER_SLUG,
                schema: self::DRAFT_SCHEMA,
                uuid: (string) $draft->getUuid(),
                _rbac: false,
                _multitenancy: false
            );

            $output->info('Skill draft example seed complete.');
        } catch (Throwable $e) {
            $output->warning('Could not seed the skill draft example: '.$e->getMessage());
            $this->logger->error('[hermiq] skill draft example seed failed: '.$e->getMessage());
        }//end try

    }//end run()

    /**
     * The seeded draft payload: `awaiting-approval`, threshold trigger, provenance
     * from the ACTUAL seeded learnings entries, and a small body improvement (one
     * sharpened step + one added exemption note) over the current body.
     *
     * @param ObjectEntity $skill The tender-summary skill.
     *
     * @return array<string, mixed> The draft payload.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
     */
    private function draftPayload(ObjectEntity $skill): array
    {
        $data = $skill->getObject();

        $learningRefs = $this->seedLearningRefs(data: $data);

        return [
            'skillId'             => (string) $skill->getUuid(),
            'baseVersionId'       => $this->currentVersionId(skillUuid: (string) $skill->getUuid()),
            'trigger'             => 'threshold',
            'status'              => SkillConsolidationService::STATUS_AWAITING_APPROVAL,
            'proposedFrontmatter' => (string) ($data['frontmatter'] ?? ''),
            'proposedBody'        => $this->improvedBody(currentBody: (string) ($data['body'] ?? '')),
            'proposedFiles'       => ($data['files'] ?? []),
            'provenance'          => [
                'learningRefs'     => $learningRefs,
                'runIds'           => [
                    self::NIL_UUID,
                    '00000000-0000-0000-0000-000000000001',
                ],
                'triggerEvalRunId' => '',
            ],
            'scanVerdict'         => 'clean',
            'scanReport'          => [
                'severity'  => 'clean',
                'safe'      => true,
                'findings'  => [],
                'scannedAt' => $this->now(),
            ],
            'noEvalEvidence'      => true,
            'approvalId'          => '',
            'auditNote'           => 'Seeded example draft (skill-self-improvement) — deciding it exercises the real accept/reject path.',
        ];

    }//end draftPayload()

    /**
     * The seeded pending Approval payload — sourceType `skill-draft` with the FULL
     * required decision-evidence payload (deep link, scan verdict, `noEvalEvidence`
     * flag, learnings summary); routed to the admin group as reviewer, its prompt
     * clearly marked as an example.
     *
     * @param ObjectEntity $draft   The seeded draft.
     * @param string       $skillId The tender-summary skill UUID.
     *
     * @return array<string, mixed> The Approval payload.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
     */
    private function approvalPayload(ObjectEntity $draft, string $skillId): array
    {
        // NOTE: `agentId` is OMITTED — a skill-draft Approval gates a skill version,
        // not an agent run, and OR validates `format: uuid` on any PRESENT value
        // (an empty string fails validation and aborted this whole seed silently).
        // The decided-* fields are omitted for the same reason: the decision
        // endpoint writes them; null values are not schema-typed.
        return [
            'status'       => 'pending',
            'sourceType'   => 'skill-draft',
            'draftId'      => (string) $draft->getUuid(),
            'skillId'      => $skillId,
            'draftPayload' => [
                'deepLink'         => $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/skills/'.$skillId),
                'scanVerdict'      => 'clean',
                'noEvalEvidence'   => true,
                'learningsSummary' => 'Consolidates 3 promoted learnings entries into the skill body (seeded example).',
            ],
            'prompt'       => 'Seeded example: review the proposed tender-summary improvement.',
            'requestedAt'  => $this->now(),
            'reviewer'     => 'admin',
            'reviewerType' => 'group',
        ];

    }//end approvalPayload()

    /**
     * Derive three dated learnings refs from the ACTUAL seeded `learnings.md` via
     * the consolidation service's own entry parser, so the review surface's
     * provenance list resolves against real entries. Falls back to three nil-hash
     * refs when parsing is unavailable.
     *
     * @param array<string, mixed> $data The skill payload.
     *
     * @return array<int, string> The learnings refs.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
     */
    private function seedLearningRefs(array $data): array
    {
        try {
            $consolidation = $this->container->get(SkillConsolidationService::class);
            $entries       = $consolidation->drivingEntries(data: $data);
            $refs          = array_map(static fn (array $entry): string => (string) $entry['ref'], $entries);
            $refs          = array_slice($refs, 0, 3);
            if ($refs !== []) {
                return $refs;
            }
        } catch (Throwable $e) {
            $this->logger->info('[hermiq] draft seed: falling back to placeholder learnings refs: '.$e->getMessage());
        }

        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

        return [
            $today.'-00000000',
            $today.'-00000001',
            $today.'-00000002',
        ];

    }//end seedLearningRefs()

    /**
     * The seeded body improvement: sharpen the award-criteria step and add one
     * exemption note — a small, reviewable diff against the current body.
     *
     * @param string $currentBody The skill's current body.
     *
     * @return string The proposed body.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
     */
    private function improvedBody(string $currentBody): string
    {
        $improved = str_replace(
            '1. Extract the essentials: contracting authority, CPV codes, lot structure,
   estimated value, submission deadline, award criteria and their weights.',
            '1. Extract the essentials: contracting authority, CPV codes, lot structure,
   estimated value, submission deadline, award criteria and their weights —
   quote the award-criteria weights verbatim (the bid team re-checks any
   paraphrase).',
            $currentBody
        );

        return $improved."\n\n## Exemption note\n\n"
            .'- When a self-cleaning claim is present, record it verbatim under the '
            .'exclusion check — note it, never judge it.'."\n";

    }//end improvedBody()

    /**
     * The skill's current version id (newest create/update AuditTrail entry), best
     * effort — '' when unresolvable (a fresh seed may predate any update entry).
     *
     * @param string $skillUuid The skill UUID.
     *
     * @return string The version id, or ''.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
     */
    private function currentVersionId(string $skillUuid): string
    {
        try {
            $versionService = $this->container->get(\OCA\Hermiq\Service\SkillVersionService::class);
            return (string) ($versionService->currentVersionId(skillUuid: $skillUuid) ?? '');
        } catch (Throwable $e) {
            return '';
        }

    }//end currentVersionId()

    /**
     * Whether ANY draft exists for the skill (idempotency key: skill + draft
     * presence, decided drafts included).
     *
     * @param ObjectService $objectService The OpenRegister object service.
     * @param string        $skillId       The skill UUID.
     *
     * @return bool True when a draft exists.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
     */
    private function draftExistsForSkill(ObjectService $objectService, string $skillId): bool
    {
        $objects = $objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::DRAFT_SCHEMA)
            ->findAll(
                config: ['filters' => ['skillId' => $skillId], 'limit' => 50],
                _rbac: false,
                _multitenancy: false
            );

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getObject()['skillId'] ?? '') === $skillId) {
                return true;
            }
        }

        return false;

    }//end draftExistsForSkill()

    /**
     * Find the seed skill by name (system context, no RBAC — the
     * SeedMaturityExampleSkills lookup).
     *
     * @param ObjectService $objectService The OpenRegister object service.
     * @param string        $name          The seed skill name.
     *
     * @return ObjectEntity|null The matching skill, or null when absent.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
     */
    private function findSkillByName(ObjectService $objectService, string $name): ?ObjectEntity
    {
        $objects = $objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SKILL_SCHEMA)
            ->findAll(
                config: ['filters' => ['name' => $name], 'limit' => 50],
                _rbac: false,
                _multitenancy: false
            );

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getObject()['name'] ?? '') === $name) {
                return $object;
            }
        }

        return null;

    }//end findSkillByName()

    /**
     * The current UTC timestamp in ISO-8601.
     *
     * @return string The timestamp.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-seeded-pending-draft-demonstrates-the-review-surface
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    }//end now()
}//end class
