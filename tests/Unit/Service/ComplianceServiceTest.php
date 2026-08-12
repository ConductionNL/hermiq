<?php

/**
 * Unit tests for ComplianceService (compliance-control-packs).
 *
 * Covers the six evidenceSource dispatch branches (each reads only its one named
 * seam and returns satisfied/partial/unevidenced), the dashboard's per-framework
 * coverage/gap aggregation, the auditor's-pack export passing `exportAuditTrail()`
 * through unmodified, and the factsheet assembling Agent/AiFeature/Approval/Incident
 * data live with no new persisted object.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-4-complianceservice-dashboard-export-and-factsheet-aggregation
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\ComplianceService;
use OCA\Hermiq\Service\TenantControlService;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\Hermiq\Service\TenantOpsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the compliance-control-packs ComplianceService.
 *
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
 */
class ComplianceServiceTest extends TestCase {

	/**
	 * An ObjectEntity carrying the given payload/uuid/owner (schema-agnostic fixture).
	 *
	 * @param string $uuid The object uuid.
	 * @param array<string, mixed> $payload The object payload.
	 * @param string $owner The object owner uid.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $payload, string $owner = ''): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid($uuid);
		$e->setObject($payload);
		if ($owner !== '') {
			$e->setOwner($owner);
		}

		return $e;
	}//end object()

	/**
	 * A stateful ObjectService test double keyed by schema (mirrors TenantOpsServiceTest).
	 *
	 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $bySchema): ObjectService {
		return new class($bySchema) extends ObjectService {
			private ?string $schema = null;

			/**
			 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
			 */
			public function __construct(
				private array $bySchema,
			) {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->bySchema[$this->schema] ?? []);
			}

			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true,
			): ?ObjectEntity {
				foreach (($this->bySchema[(string)$schema] ?? []) as $object) {
					if ($object->getUuid() === $id) {
						return $object;
					}
				}

				return null;
			}
		};

	}//end objectService()

	/**
	 * A single control fixture referencing the given evidenceSource, seeded
	 * alongside its one EU AI Act ControlFramework so `dashboard()` can compute it.
	 *
	 * @param string $evidenceSource The evidenceSource enum value.
	 *
	 * @return ObjectService An ObjectService double carrying exactly this one
	 *                       framework + control (no agent/incident schema data).
	 */
	private function catalogueWith(string $evidenceSource): ObjectService {
		return $this->objectService(
			[
				'agentcontrolframework' => [$this->object('fw1', ['slug' => 'eu-ai-act', 'name' => 'EU AI Act'])],
				'agentcompliancecontrol' => [
					$this->object(
						'c1',
						[
							'frameworkSlug' => 'eu-ai-act',
							'controlId' => 'art.x',
							'title' => 'Test control',
							'sourceUrl' => 'https://example.test/',
							'evidenceSource' => $evidenceSource,
						]
					),
				],
			]
		);

	}//end catalogueWith()

	/**
	 * Build a ComplianceService with the given ObjectService double and
	 * evidence-seam service mocks (any omitted mock defaults to a bare createMock).
	 *
	 * @param ObjectService $objectService The catalogue/agent/incident double.
	 * @param TenantOpsService|null $tenantOpsService Optional custom mock.
	 * @param ApprovalService|null $approvalService Optional custom mock.
	 * @param TenantControlService|null $tenantControlService Optional custom mock.
	 * @param TenantModelPolicyService|null $modelPolicyService Optional custom mock.
	 * @param AiFeatureService|null $aiFeatureService Optional custom mock.
	 *
	 * @return ComplianceService
	 */
	private function service(
		ObjectService $objectService,
		?TenantOpsService $tenantOpsService = null,
		?ApprovalService $approvalService = null,
		?TenantControlService $tenantControlService = null,
		?TenantModelPolicyService $modelPolicyService = null,
		?AiFeatureService $aiFeatureService = null,
	): ComplianceService {
		return new ComplianceService(
			objectService: $objectService,
			tenantOpsService: ($tenantOpsService ?? $this->createMock(TenantOpsService::class)),
			approvalService: ($approvalService ?? $this->createMock(ApprovalService::class)),
			tenantControlService: ($tenantControlService ?? $this->createMock(TenantControlService::class)),
			modelPolicyService: ($modelPolicyService ?? $this->createMock(TenantModelPolicyService::class)),
			aiFeatureService: ($aiFeatureService ?? $this->createMock(AiFeatureService::class)),
		);

	}//end service()

	/**
	 * Compute the single control row for a given evidenceSource by loading a
	 * one-control-one-framework dashboard.
	 *
	 * @param string $evidenceSource The evidenceSource to exercise.
	 * @param TenantOpsService|null $tenantOpsService Optional custom mock.
	 * @param ApprovalService|null $approvalService Optional custom mock.
	 * @param TenantControlService|null $tenantControlService Optional custom mock.
	 * @param TenantModelPolicyService|null $modelPolicyService Optional custom mock.
	 * @param AiFeatureService|null $aiFeatureService Optional custom mock.
	 *
	 * @return array<string, mixed> The computed control row (status/detail).
	 */
	private function statusFor(
		string $evidenceSource,
		?TenantOpsService $tenantOpsService = null,
		?ApprovalService $approvalService = null,
		?TenantControlService $tenantControlService = null,
		?TenantModelPolicyService $modelPolicyService = null,
		?AiFeatureService $aiFeatureService = null,
	): array {
		$service = $this->service(
			objectService: $this->catalogueWith(evidenceSource: $evidenceSource),
			tenantOpsService: $tenantOpsService,
			approvalService: $approvalService,
			tenantControlService: $tenantControlService,
			modelPolicyService: $modelPolicyService,
			aiFeatureService: $aiFeatureService,
		);

		$dashboard = $service->dashboard(organisation: 'org-a');
		$this->assertNotEmpty($dashboard['frameworks'][0]['controls'], 'Fixture control must appear under its framework.');

		return $dashboard['frameworks'][0]['controls'][0];
	}//end statusFor()

	/**
	 * The audit-trail-recordkeeping evidence branch reads exportAuditTrail() and
	 * reports satisfied/unevidenced by recordCount only — never partial.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
	 */
	public function testAuditTrailEvidenceSatisfiedAndUnevidenced(): void {
		$withRecords = $this->createMock(TenantOpsService::class);
		$withRecords->method('exportAuditTrail')->willReturn(['recordCount' => 3]);
		$this->assertSame(
			'satisfied',
			$this->statusFor(evidenceSource: 'audit-trail-recordkeeping', tenantOpsService: $withRecords)['status']
		);

		$withoutRecords = $this->createMock(TenantOpsService::class);
		$withoutRecords->method('exportAuditTrail')->willReturn(['recordCount' => 0]);
		$this->assertSame(
			'unevidenced',
			$this->statusFor(evidenceSource: 'audit-trail-recordkeeping', tenantOpsService: $withoutRecords)['status']
		);

	}//end testAuditTrailEvidenceSatisfiedAndUnevidenced()

	/**
	 * The approval-gate-oversight branch: satisfied when at least one decided
	 * approval exists; partial when approvals exist but are all pending;
	 * unevidenced when none exist.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-each-evidence-source-maps-to-exactly-one-existing-hermiq-seam
	 */
	public function testApprovalEvidenceThreeStates(): void {
		$none = $this->createMock(ApprovalService::class);
		$none->method('listForOrganisation')->willReturn([]);
		$this->assertSame(
			'unevidenced',
			$this->statusFor(evidenceSource: 'approval-gate-oversight', approvalService: $none)['status']
		);

		$allPending = $this->createMock(ApprovalService::class);
		$allPending->method('listForOrganisation')->willReturn([$this->object('a1', ['status' => 'pending'])]);
		$this->assertSame(
			'partial',
			$this->statusFor(evidenceSource: 'approval-gate-oversight', approvalService: $allPending)['status']
		);

		$decided = $this->createMock(ApprovalService::class);
		$decided->method('listForOrganisation')->willReturn(
			[$this->object('a2', ['status' => 'approved', 'decidedBy' => 'alice', 'decidedAt' => '2026-01-01T00:00:00+00:00'])]
		);
		$this->assertSame(
			'satisfied',
			$this->statusFor(evidenceSource: 'approval-gate-oversight', approvalService: $decided)['status']
		);

	}//end testApprovalEvidenceThreeStates()

	/**
	 * The kill-switch-stop-mechanism branch: satisfied when a TenantControl object
	 * exists for the organisation (regardless of engaged state), unevidenced otherwise.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
	 */
	public function testKillSwitchEvidence(): void {
		$provisioned = $this->createMock(TenantControlService::class);
		$provisioned->method('getForOrganisation')->willReturn($this->object('tc1', ['engaged' => false]));
		$this->assertSame(
			'satisfied',
			$this->statusFor(evidenceSource: 'kill-switch-stop-mechanism', tenantControlService: $provisioned)['status']
		);

		$missing = $this->createMock(TenantControlService::class);
		$missing->method('getForOrganisation')->willReturn(null);
		$this->assertSame(
			'unevidenced',
			$this->statusFor(evidenceSource: 'kill-switch-stop-mechanism', tenantControlService: $missing)['status']
		);

	}//end testKillSwitchEvidence()

	/**
	 * The model-policy-risk-control branch: satisfied for an org policy with a
	 * non-empty allowlist, partial for the instance default (or an empty org
	 * allowlist), unevidenced for the fail-closed fallback.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
	 */
	public function testModelPolicyEvidenceThreeStates(): void {
		$own = $this->createMock(TenantModelPolicyService::class);
		$own->method('effectivePolicyFor')->willReturn(['source' => 'organisation', 'allowed' => [['provider' => 'openai', 'models' => []]]]);
		$this->assertSame(
			'satisfied',
			$this->statusFor(evidenceSource: 'model-policy-risk-control', modelPolicyService: $own)['status']
		);

		$instance = $this->createMock(TenantModelPolicyService::class);
		$instance->method('effectivePolicyFor')->willReturn(['source' => 'instance', 'allowed' => [['provider' => 'openai', 'models' => []]]]);
		$this->assertSame(
			'partial',
			$this->statusFor(evidenceSource: 'model-policy-risk-control', modelPolicyService: $instance)['status']
		);

		$fallback = $this->createMock(TenantModelPolicyService::class);
		$fallback->method('effectivePolicyFor')->willReturn(['source' => 'fallback', 'allowed' => []]);
		$this->assertSame(
			'unevidenced',
			$this->statusFor(evidenceSource: 'model-policy-risk-control', modelPolicyService: $fallback)['status']
		);

	}//end testModelPolicyEvidenceThreeStates()

	/**
	 * The capability-review-least-privilege branch: satisfied when every agent has
	 * a current review, partial for a mix, unevidenced with no agents or none reviewed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
	 */
	public function testAccessReviewEvidenceStates(): void {
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

		$allReviewed = $this->createMock(TenantOpsService::class);
		$allReviewed->method('accessReviewList')->willReturn(['agents' => [['reviewedAt' => $now], ['reviewedAt' => $now]]]);
		$allReviewed->method('getRetentionMonths')->willReturn(6);
		$this->assertSame(
			'satisfied',
			$this->statusFor(evidenceSource: 'capability-review-least-privilege', tenantOpsService: $allReviewed)['status']
		);

		$mixed = $this->createMock(TenantOpsService::class);
		$mixed->method('accessReviewList')->willReturn(['agents' => [['reviewedAt' => $now], ['reviewedAt' => null]]]);
		$mixed->method('getRetentionMonths')->willReturn(6);
		$this->assertSame(
			'partial',
			$this->statusFor(evidenceSource: 'capability-review-least-privilege', tenantOpsService: $mixed)['status']
		);

		$none = $this->createMock(TenantOpsService::class);
		$none->method('accessReviewList')->willReturn(['agents' => []]);
		$none->method('getRetentionMonths')->willReturn(6);
		$this->assertSame(
			'unevidenced',
			$this->statusFor(evidenceSource: 'capability-review-least-privilege', tenantOpsService: $none)['status']
		);

	}//end testAccessReviewEvidenceStates()

	/**
	 * The dpo-ack-design-time-gate branch: satisfied when at least one AiFeature is
	 * enabled, partial when features exist but none enabled, unevidenced when none
	 * are registered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
	 */
	public function testAiFeatureEvidenceStates(): void {
		$enabled = $this->createMock(AiFeatureService::class);
		$enabled->method('listFeatures')->willReturn([$this->object('f1', ['lifecycle' => 'enabled'])]);
		$this->assertSame(
			'satisfied',
			$this->statusFor(evidenceSource: 'dpo-ack-design-time-gate', aiFeatureService: $enabled)['status']
		);

		$disabledOnly = $this->createMock(AiFeatureService::class);
		$disabledOnly->method('listFeatures')->willReturn([$this->object('f2', ['lifecycle' => 'disabled'])]);
		$this->assertSame(
			'partial',
			$this->statusFor(evidenceSource: 'dpo-ack-design-time-gate', aiFeatureService: $disabledOnly)['status']
		);

		$none = $this->createMock(AiFeatureService::class);
		$none->method('listFeatures')->willReturn([]);
		$this->assertSame(
			'unevidenced',
			$this->statusFor(evidenceSource: 'dpo-ack-design-time-gate', aiFeatureService: $none)['status']
		);

	}//end testAiFeatureEvidenceStates()

	/**
	 * The dashboard aggregates per-framework coverage and lists every non-satisfied
	 * control in the gap list.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-a-compliance-dashboard-shows-per-framework-coverage-and-the-gap-list
	 */
	public function testDashboardAggregatesCoverageAndGaps(): void {
		$frameworks = [$this->object('fw1', ['slug' => 'eu-ai-act', 'name' => 'EU AI Act'])];
		$controls = [
			$this->object(
				'c1',
				[
					'frameworkSlug' => 'eu-ai-act',
					'controlId' => 'art.12',
					'title' => 'Record-keeping',
					'sourceUrl' => 'https://example.test/12',
					'evidenceSource' => 'audit-trail-recordkeeping',
				]
			),
			$this->object(
				'c2',
				[
					'frameworkSlug' => 'eu-ai-act',
					'controlId' => 'art.26',
					'title' => 'Deployer duties',
					'sourceUrl' => 'https://example.test/26',
					'evidenceSource' => 'kill-switch-stop-mechanism',
				]
			),
		];

		$tenantOps = $this->createMock(TenantOpsService::class);
		$tenantOps->method('exportAuditTrail')->willReturn(['recordCount' => 5]);

		$tenantControl = $this->createMock(TenantControlService::class);
		$tenantControl->method('getForOrganisation')->willReturn(null);

		$service = $this->service(
			objectService: $this->objectService(['agentcontrolframework' => $frameworks, 'agentcompliancecontrol' => $controls]),
			tenantOpsService: $tenantOps,
			tenantControlService: $tenantControl,
		);

		$dashboard = $service->dashboard(organisation: 'org-a');

		$this->assertCount(1, $dashboard['frameworks']);
		$this->assertSame('eu-ai-act', $dashboard['frameworks'][0]['slug']);
		$this->assertSame(50, $dashboard['frameworks'][0]['coveragePercent']);
		$this->assertCount(1, $dashboard['gaps'], 'Only the unevidenced kill-switch control is a gap.');
		$this->assertSame('art.26', $dashboard['gaps'][0]['controlId']);

	}//end testDashboardAggregatesCoverageAndGaps()

	/**
	 * auditorPack() passes exportAuditTrail()'s return value through unmodified,
	 * nested under `auditTrail`, alongside `complianceCoverage`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-the-auditors-pack-export-extends-the-existing-art-12-export
	 */
	public function testAuditorPackWrapsAuditTrailUnmodified(): void {
		$rawExport = ['export' => 'eu-ai-act-audit', 'recordCount' => 2, 'records' => [['action' => 'run']]];
		$tenantOps = $this->createMock(TenantOpsService::class);
		$tenantOps->method('exportAuditTrail')->willReturn($rawExport);

		$service = $this->service(objectService: $this->objectService([]), tenantOpsService: $tenantOps);

		$pack = $service->auditorPack(organisation: 'org-a');

		$this->assertSame($rawExport, $pack['auditTrail']);
		$this->assertArrayHasKey('complianceCoverage', $pack);
		$this->assertArrayHasKey('generatedAt', $pack);

	}//end testAuditorPackWrapsAuditTrailUnmodified()

	/**
	 * factsheet() assembles Agent/AiFeature/Approval/Incident data live and returns
	 * null for a non-existent agent (no fabricated envelope).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-ai-factsheet-summarises-an-agents-governance-lifecycle
	 */
	public function testFactsheetAssemblesAllFourSourcesAndNullsWhenAgentMissing(): void {
		$agent = $this->object(
			'agent-1',
			[
				'name' => 'Permit assistant',
				'provider' => 'openai',
				'model' => 'gpt-4o-mini',
				'tools' => ['a.b.get'],
				'type' => 'chat-companion',
				'actingUser' => 'bob',
				'reviewedAt' => '2026-01-01T00:00:00+00:00',
			],
			'alice'
		);
		$incident = $this->object(
			'inc-1',
			['description' => 'Oops', 'impact' => 'Minor', 'linkedAgentId' => 'agent-1', 'createdAt' => '2026-01-02T00:00:00+00:00']
		);

		$aiFeatureService = $this->createMock(AiFeatureService::class);
		$aiFeatureService->method('findBySlug')->willReturn(
			$this->object('af-1', ['riskCategory' => 'high', 'lifecycle' => 'enabled', 'dpoAckBy' => 'dpo', 'dpoAckAt' => '2026-01-01T00:00:00+00:00'])
		);

		$approvalService = $this->createMock(ApprovalService::class);
		$approvalService->method('listForAgent')->willReturn(
			[
				$this->object(
					'appr-1',
					['status' => 'approved', 'decidedBy' => 'dpo', 'decidedAt' => '2026-01-01T00:00:00+00:00', 'requestedAt' => '2025-12-31T00:00:00+00:00']
				),
			]
		);

		$service = $this->service(
			objectService: $this->objectService(['agent' => [$agent], 'incident' => [$incident]]),
			approvalService: $approvalService,
			aiFeatureService: $aiFeatureService,
		);

		$factsheet = $service->factsheet(agentId: 'agent-1');

		$this->assertNotNull($factsheet);
		$this->assertSame('Permit assistant', $factsheet['agent']['name']);
		$this->assertSame('alice', $factsheet['agent']['owner']);
		$this->assertSame('high', $factsheet['aiFeature']['riskCategory']);
		$this->assertCount(1, $factsheet['approvals']);
		$this->assertSame('approved', $factsheet['approvals'][0]['status']);
		$this->assertCount(1, $factsheet['incidents']);
		$this->assertSame('Oops', $factsheet['incidents'][0]['description']);
		$this->assertSame('2026-01-01T00:00:00+00:00', $factsheet['lastReviewedAt']);

		$this->assertNull($service->factsheet(agentId: 'does-not-exist'));

	}//end testFactsheetAssemblesAllFourSourcesAndNullsWhenAgentMissing()
}//end class
