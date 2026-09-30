<?php

/**
 * Hermiq repair step: seed the course Working with AI (compliance-ai-literacy).
 *
 * Seeds the six lessons in English and Dutch from `lib/Settings/literacy_lessons.json`,
 * once per (slug, locale). A lesson that already exists is left as it is, so an
 * admin's edit survives every upgrade.
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
 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the Working with AI lessons.
 *
 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
 */
class SeedLiteracyLessons implements IRepairStep {
	use RunsUnderSystemIdentity;

	private const REGISTER_SLUG = 'hermiq';

	private const SCHEMA_SLUG = 'literacylesson';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Lazy OpenRegister resolution.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function getName(): string {
		return 'Seed the course Working with AI (six lessons, English and Dutch)';
	}//end getName()

	/**
	 * Seed every lesson that does not exist yet.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get(ObjectService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available, skipping the Working with AI seed.');
			$this->logger->warning('[hermiq] Literacy lesson seed skipped: ' . $e->getMessage());
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->seed(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * Seed the lessons that are missing.
	 *
	 * @param ObjectService $objectService OpenRegister's object service.
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 */
	private function seed(ObjectService $objectService, IOutput $output): void {
		$existing = [];
		$stored = $objectService->setRegister(self::REGISTER_SLUG)->setSchema(self::SCHEMA_SLUG)
			->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
		foreach ($stored as $object) {
			if ($object instanceof ObjectEntity) {
				$data = $object->getObject();
				$existing[(string)($data['slug'] ?? '') . '/' . (string)($data['locale'] ?? '')] = true;
			}
		}

		foreach ($this->catalogue() as $lesson) {
			$key = $lesson['slug'] . '/' . $lesson['locale'];
			if (isset($existing[$key]) === true) {
				continue;
			}

			try {
				$objectService->saveObject(
					object: $lesson,
					register: self::REGISTER_SLUG,
					schema: self::SCHEMA_SLUG,
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$output->warning('Could not seed lesson "' . $key . '": ' . $e->getMessage());
				$this->logger->error('[hermiq] Literacy lesson seed failed for ' . $key . ': ' . $e->getMessage());
			}
		}
	}//end seed()

	/**
	 * The lessons shipped with the app.
	 *
	 * @return array<int, array<string, mixed>> The lessons.
	 */
	private function catalogue(): array {
		$decoded = json_decode((string)file_get_contents(__DIR__ . '/../Settings/literacy_lessons.json'), true);
		if (is_array($decoded) === false || is_array($decoded['lessons'] ?? null) === false) {
			return [];
		}

		return $decoded['lessons'];
	}//end catalogue()

}//end class
