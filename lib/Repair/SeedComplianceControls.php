<?php

/**
 * Hermiq Seed Compliance Controls Repair Step.
 *
 * Idempotently seeds the compliance-control-packs catalogue on install/upgrade: 3
 * `ControlFramework` rows (EU AI Act, ISO/IEC 42001, NIST AI RMF) and their ~10
 * `Control` rows, each written through OpenRegister's ObjectService single write-path
 * (ADR-001, ADR-004) — never a bespoke insert. Re-running is safe: a framework/control
 * whose `slug`/`controlId` already exists is skipped. Runs after InitializeSettings
 * (which imports the `agentcontrolframework`/`agentcompliancecontrol` schemas);
 * OpenRegister is resolved lazily so the step no-ops gracefully when OpenRegister is
 * not installed, mirroring SeedAiFeatures.
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
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-2-seed-the-catalogue-idempotently
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the compliance-control-packs catalogue via ObjectService (idempotent).
 *
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-2-seed-the-catalogue-idempotently
 */
class SeedComplianceControls implements IRepairStep
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for ControlFramework objects.
     *
     * @var string
     */
    private const FRAMEWORK_SCHEMA = 'agentcontrolframework';

    /**
     * Schema slug for Control objects.
     *
     * @var string
     */
    private const CONTROL_SCHEMA = 'agentcompliancecontrol';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container Server container for lazy ObjectService resolution.
     * @param LoggerInterface    $logger    PSR-3 logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-2-seed-the-catalogue-idempotently
     */
    public function getName(): string
    {
        return 'Seed compliance-control-packs catalogue (EU AI Act, ISO/IEC 42001, NIST AI RMF)';

    }//end getName()

    /**
     * Seed the ControlFramework and Control objects that do not yet exist.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-2-seed-the-catalogue-idempotently
     */
    public function run(IOutput $output): void
    {
        try {
            $objectService = $this->container->get(ObjectService::class);
        } catch (Throwable $e) {
            $output->warning('OpenRegister not available — skipping compliance-control-packs seed.');
            $this->logger->warning('[hermiq] Compliance control seed skipped: '.$e->getMessage());
            return;
        }

        $frameworksSeeded = 0;
        foreach ($this->seedFrameworks() as $framework) {
            try {
                if ($this->frameworkSlugExists(objectService: $objectService, slug: $framework['slug']) === true) {
                    continue;
                }

                $objectService->saveObject(
                    object: $framework,
                    register: self::REGISTER_SLUG,
                    schema: self::FRAMEWORK_SCHEMA,
                    _rbac: false,
                    _multitenancy: false
                );
                $frameworksSeeded++;
            } catch (Throwable $e) {
                $output->warning('Could not seed control framework "'.$framework['slug'].'": '.$e->getMessage());
                $this->logger->error('[hermiq] ControlFramework seed failed for '.$framework['slug'].': '.$e->getMessage());
            }//end try
        }//end foreach

        $controlsSeeded = 0;
        foreach ($this->seedControls() as $control) {
            try {
                $exists = $this->controlExists(
                    objectService: $objectService,
                    frameworkSlug: $control['frameworkSlug'],
                    controlId: $control['controlId']
                );
                if ($exists === true) {
                    continue;
                }

                $objectService->saveObject(
                    object: $control,
                    register: self::REGISTER_SLUG,
                    schema: self::CONTROL_SCHEMA,
                    _rbac: false,
                    _multitenancy: false
                );
                $controlsSeeded++;
            } catch (Throwable $e) {
                $output->warning('Could not seed control "'.$control['frameworkSlug'].'/'.$control['controlId'].'": '.$e->getMessage());
                $this->logger->error(
                    '[hermiq] Control seed failed for '.$control['frameworkSlug'].'/'.$control['controlId'].': '.$e->getMessage()
                );
            }//end try
        }//end foreach

        $output->info(
            'Compliance-control-packs seed complete ('.$frameworksSeeded.' framework(s), '.$controlsSeeded.' control(s) new).'
        );

    }//end run()

    /**
     * The seed ControlFramework rows (design.md Seed Data).
     *
     * @return array<int, array<string, mixed>> The seed objects.
     */
    private function seedFrameworks(): array
    {
        return [
            [
                'slug'        => 'eu-ai-act',
                'name'        => 'EU AI Act',
                'edition'     => 'Regulation (EU) 2024/1689',
                'sourceUrl'   => 'https://artificialintelligenceact.eu/',
                'description' => 'EU regulation on AI, risk-tiered obligations.',
            ],
            [
                'slug'        => 'iso-42001',
                'name'        => 'ISO/IEC 42001',
                'edition'     => 'ISO/IEC 42001:2023',
                'sourceUrl'   => 'https://www.iso.org/standard/81230.html',
                'description' => 'AI management system (AIMS) standard.',
            ],
            [
                'slug'        => 'nist-ai-rmf',
                'name'        => 'NIST AI RMF',
                'edition'     => 'AI RMF 1.0 (Jan 2023)',
                'sourceUrl'   => 'https://www.nist.gov/itl/ai-risk-management-framework',
                'description' => 'Voluntary US risk-management framework.',
            ],
        ];

    }//end seedFrameworks()

    /**
     * The seed Control rows (design.md Seed Data): 3 EU AI Act + 3 ISO/IEC 42001 + 4
     * NIST AI RMF, each mapped to exactly one of the six evidenceSource seams.
     *
     * @return array<int, array<string, mixed>> The seed objects.
     */
    private function seedControls(): array
    {
        return [
            [
                'frameworkSlug'       => 'eu-ai-act',
                'controlId'           => 'art.12',
                'title'               => 'Record-keeping',
                'description'         => 'High-risk AI systems must automatically log events over their '
                    .'lifetime to enable an appropriate level of traceability.',
                'sourceUrl'           => 'https://artificialintelligenceact.eu/article/12/',
                'evidenceSource'      => 'audit-trail-recordkeeping',
                'evidenceDescription' => 'Satisfied when at least one audited run record exists for the '
                    .'organisation in the hash-chained AuditTrail.',
            ],
            [
                'frameworkSlug'       => 'eu-ai-act',
                'controlId'           => 'art.14',
                'title'               => 'Human oversight',
                'description'         => 'High-risk AI systems must be designed so natural persons can '
                    .'effectively oversee their operation, including the ability to decide not to act on an output.',
                'sourceUrl'           => 'https://artificialintelligenceact.eu/article/14/',
                'evidenceSource'      => 'approval-gate-oversight',
                'evidenceDescription' => 'Satisfied when at least one human-approval-gate decision '
                    .'(approved or denied) has been recorded for the organisation.',
            ],
            [
                'frameworkSlug'       => 'eu-ai-act',
                'controlId'           => 'art.26',
                'title'               => 'Deployer duties',
                'description'         => 'Deployers of high-risk AI systems must take appropriate technical '
                    .'and organisational measures, including the ability to suspend use.',
                'sourceUrl'           => 'https://artificialintelligenceact.eu/article/26/',
                'evidenceSource'      => 'kill-switch-stop-mechanism',
                'evidenceDescription' => 'Satisfied when the organisation has a provisioned stop mechanism '
                    .'(kill-switch), regardless of whether it is currently engaged.',
            ],
            [
                'frameworkSlug'       => 'iso-42001',
                'controlId'           => '9.1',
                'title'               => 'Monitoring, measurement, analysis and evaluation',
                'description'         => 'The organisation shall determine what needs to be monitored and '
                    .'evaluated, including AI system performance and impacts.',
                'sourceUrl'           => 'https://www.iso.org/standard/81230.html',
                'evidenceSource'      => 'capability-review-least-privilege',
                'evidenceDescription' => 'Satisfied when every agent in the organisation has a current '
                    .'(non-stale) periodic access review.',
            ],
            [
                'frameworkSlug'       => 'iso-42001',
                'controlId'           => '6.1.2',
                'title'               => 'AI risk assessment',
                'description'         => 'The organisation shall define and apply an AI risk assessment '
                    .'process, addressed at design time before a feature is enabled.',
                'sourceUrl'           => 'https://www.iso.org/standard/81230.html',
                'evidenceSource'      => 'dpo-ack-design-time-gate',
                'evidenceDescription' => 'Satisfied when at least one registered AI feature has been '
                    .'enabled after its required DPO acknowledgement.',
            ],
            [
                'frameworkSlug'       => 'iso-42001',
                'controlId'           => '8.2',
                'title'               => 'AI system impact assessment',
                'description'         => 'The organisation shall perform AI system impact assessments, '
                    .'evaluating potential consequences to individuals and groups.',
                'sourceUrl'           => 'https://www.iso.org/standard/81230.html',
                'evidenceSource'      => 'capability-review-least-privilege',
                'evidenceDescription' => 'Satisfied when every agent in the organisation has a current '
                    .'(non-stale) periodic access review.',
            ],
            [
                'frameworkSlug'       => 'nist-ai-rmf',
                'controlId'           => 'GOVERN-1.1',
                'title'               => 'Governance policies and processes',
                'description'         => 'Legal and regulatory requirements involving AI are understood, '
                    .'managed, and documented, including a design-time review gate.',
                'sourceUrl'           => 'https://www.nist.gov/itl/ai-risk-management-framework',
                'evidenceSource'      => 'dpo-ack-design-time-gate',
                'evidenceDescription' => 'Satisfied when at least one registered AI feature has been '
                    .'enabled after its required DPO acknowledgement.',
            ],
            [
                'frameworkSlug'       => 'nist-ai-rmf',
                'controlId'           => 'MAP-1.1',
                'title'               => 'Context mapping',
                'description'         => 'Context and risk of the AI system are established and understood '
                    .'before the system is put into operation.',
                'sourceUrl'           => 'https://www.nist.gov/itl/ai-risk-management-framework',
                'evidenceSource'      => 'dpo-ack-design-time-gate',
                'evidenceDescription' => 'Satisfied when at least one registered AI feature has been '
                    .'enabled after its required DPO acknowledgement.',
            ],
            [
                'frameworkSlug'       => 'nist-ai-rmf',
                'controlId'           => 'MEASURE-2.1',
                'title'               => 'Risk controls tested',
                'description'         => 'Test sets, metrics, and details about the tools used during AI '
                    .'system risk measurement are documented, including model/provider restrictions.',
                'sourceUrl'           => 'https://www.nist.gov/itl/ai-risk-management-framework',
                'evidenceSource'      => 'model-policy-risk-control',
                'evidenceDescription' => 'Satisfied when the organisation has its own model-provider '
                    .'allowlist (ModelPolicy) with at least one allowed provider.',
            ],
            [
                'frameworkSlug'       => 'nist-ai-rmf',
                'controlId'           => 'MANAGE-4.1',
                'title'               => 'Risk treatment and monitoring',
                'description'         => 'Risk treatments, including response and recovery, are documented, '
                    .'including the ability to halt an AI system\'s operation.',
                'sourceUrl'           => 'https://www.nist.gov/itl/ai-risk-management-framework',
                'evidenceSource'      => 'kill-switch-stop-mechanism',
                'evidenceDescription' => 'Satisfied when the organisation has a provisioned stop mechanism '
                    .'(kill-switch), regardless of whether it is currently engaged.',
            ],
        ];

    }//end seedControls()

    /**
     * Whether a ControlFramework with the given slug already exists (system context,
     * no RBAC).
     *
     * @param ObjectService $objectService The OpenRegister object service.
     * @param string        $slug          The framework slug.
     *
     * @return bool True when a matching object exists.
     */
    private function frameworkSlugExists(ObjectService $objectService, string $slug): bool
    {
        $objects = $objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::FRAMEWORK_SCHEMA)
            ->findAll(
                config: ['filters' => ['slug' => $slug], 'limit' => 50],
                _rbac: false,
                _multitenancy: false
            );

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getObject()['slug'] ?? '') === $slug) {
                return true;
            }
        }

        return false;

    }//end frameworkSlugExists()

    /**
     * Whether a Control with the given (frameworkSlug, controlId) pair already
     * exists (system context, no RBAC).
     *
     * @param ObjectService $objectService The OpenRegister object service.
     * @param string        $frameworkSlug The owning framework slug.
     * @param string        $controlId     The control's own identifier within its framework.
     *
     * @return bool True when a matching object exists.
     */
    private function controlExists(ObjectService $objectService, string $frameworkSlug, string $controlId): bool
    {
        $objects = $objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::CONTROL_SCHEMA)
            ->findAll(
                config: ['filters' => ['frameworkSlug' => $frameworkSlug, 'controlId' => $controlId], 'limit' => 200],
                _rbac: false,
                _multitenancy: false
            );

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            if ((string) ($data['frameworkSlug'] ?? '') === $frameworkSlug
                && (string) ($data['controlId'] ?? '') === $controlId
            ) {
                return true;
            }
        }

        return false;

    }//end controlExists()
}//end class
