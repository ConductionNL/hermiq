<?php

/**
 * Hermiq Seed FormFill AiFeature Repair Step (forms-smart-paste-fill).
 *
 * Idempotently seeds the `form-fill` `AiFeature` governance object in
 * `lifecycle: disabled`, at EU AI Act limited risk: it proposes values a person
 * checks before saving and decides nothing. Mirrors
 * `SeedLessonAuthoringFeature`: written
 * through OpenRegister's ObjectService single write-path (ADR-001, ADR-004),
 * system-scoped (`_rbac: false, _multitenancy: false`, `tenantId: ''`) so any DPO
 * can acknowledge it, and skipped on re-run once the slug exists. An admin must
 * enable it after the DPO acknowledgement before `FormFillEngine` calls
 * any provider.
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
 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-form-fill-is-off-until-an-admin-acknowledges-it-req-ffill-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Repair\Support\AiFeatureRows;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the `form-fill` AiFeature via ObjectService (idempotent).
 *
 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-form-fill-is-off-until-an-admin-acknowledges-it-req-ffill-001
 */
class SeedFormFillFeature implements IRepairStep {
	use \OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for AiFeature objects.
	 *
	 * @var string
	 */
	private const AIFEATURE_SCHEMA = 'agentaifeature';

	/**
	 * The feature slug this step seeds.
	 *
	 * @var string
	 */
	private const FEATURE_SLUG = 'form-fill';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for lazy ObjectService resolution.
	 * @param LoggerInterface $logger PSR-3 logger.
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
	 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-form-fill-is-off-until-an-admin-acknowledges-it-req-ffill-001
	 */
	public function getName(): string {
		return 'Seed the form-fill AI feature (forms-smart-paste-fill)';
	}//end getName()

	/**
	 * Seed the `form-fill` AiFeature when it does not yet exist.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-smart-paste-fill/specs/form-fill/spec.md#requirement-form-fill-is-off-until-an-admin-acknowledges-it-req-ffill-001
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available, skipping the form-fill AI feature seed.');
			$this->logger->warning('[hermiq] form-fill AiFeature seed skipped: ' . $e->getMessage());
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous' further down the call chain even when
		// the write itself passes `_rbac: false`.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->seedFeature(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * Seed the form-fill feature when it is not present yet.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 */
	private function seedFeature(object $objectService, IOutput $output): void {
		try {
			if ($this->slugExists(objectService: $objectService) === true) {
				$output->info('form-fill AI feature already exists, skipping.');
				return;
			}

			$objectService->saveObject(
				object: $this->featurePayload(),
				register: self::REGISTER_SLUG,
				schema: self::AIFEATURE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			$output->info('Seeded the form-fill AI feature (disabled, pending DPO acknowledgement).');
		} catch (Throwable $e) {
			$output->warning('Could not seed the form-fill AI feature: ' . $e->getMessage());
			$this->logger->error('[hermiq] form-fill AiFeature seed failed: ' . $e->getMessage());
		}//end try

	}//end seedFeature()

	/**
	 * The governance row this step seeds; the description says what leaves the form.
	 *
	 * @return array<string, string> The AiFeature payload.
	 */
	private function featurePayload(): array {
		return [
			'slug' => self::FEATURE_SLUG,
			'name' => 'Paste to fill',
			'description' => 'Proposes values for a form\'s fields from a text the user pastes, such as an email '
				. 'signature. The pasted text and the allowed fields\' key, label, type and options leave the form '
				. 'for the configured model; nothing else does. Every value is a proposal the user checks before '
				. 'saving. Limited risk under the EU AI Act: it proposes and decides nothing.',
			'riskCategory' => 'limited',
			'lifecycle' => 'disabled',
			'tenantId' => '',
			'doel' => 'Save typing: fill a form from text the user already has.',
			'dataBronnen' => 'The text the user pastes and the form\'s field keys, labels, types and options.',
			'humanIntervention' => 'Every value is a proposal in the form. The user checks, edits or clears it '
				. 'before saving.',
		];
	}//end featurePayload()

	/**
	 * Whether the `form-fill` AiFeature already exists (system context, no RBAC).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 *
	 * @return bool True when the feature already exists.
	 */
	private function slugExists(ObjectService $objectService): bool {
		return (new AiFeatureRows(objectService: $objectService))->exists(slug: self::FEATURE_SLUG);
	}//end slugExists()
}//end class
