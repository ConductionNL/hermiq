<?php

/**
 * Hermiq PublishableSkills
 *
 * Resolves the skills a bundle publish names into their publishable payloads,
 * with one outcome per requested skill. The step behind
 * `SkillController::collectPublishablePayloads()`.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The publishable payloads and per-skill outcomes of one bundle publish.
 *
 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */
final class PublishableSkills {

	/**
	 * Build the step.
	 *
	 * @param SkillService    $skillService Reads skills and their publish-time file selection.
	 * @param LoggerInterface $logger       Logs a skill that could not be resolved.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function __construct(
		private readonly SkillService $skillService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every requested skill's payload, with one outcome per skill in request order.
	 *
	 * @param array<int, mixed> $skillIds The requested skill ids.
	 *
	 * @return array{payloads:array<int,array<string,mixed>>,outcomes:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function collect(array $skillIds): array {
		$payloads = [];
		$outcomes = [];
		foreach ($skillIds as $skillId) {
			$id = (string)$skillId;
			$object = $this->publishable(id: $id);
			if (is_string($object) === true) {
				$outcomes[] = ['name' => $id, 'outcome' => $object];
				continue;
			}

			$payloads[] = $object;
			$outcomes[] = ['name' => (string)($object['name'] ?? ''), 'files' => count($object['files']), 'outcome' => 'published'];
		}

		return ['payloads' => $payloads, 'outcomes' => $outcomes];
	}//end collect()

	/**
	 * A skill's payload with its publish-time file selection, or why it is not published.
	 *
	 * @param string $id The skill id.
	 *
	 * @return array<string, mixed>|string The payload, or `not_found` / `failed`.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	private function publishable(string $id): array|string {
		$skill = $this->resolve(id: $id);
		if (is_string($skill) === true) {
			return $skill;
		}

		$object = $skill->getObject();
		$object['files'] = ($this->skillService->publishFileSelection(skillId: $id) ?? []);

		return $object;
	}//end publishable()

	/**
	 * The skill, or `not_found` when there is none and `failed` (logged) when reading it fails.
	 *
	 * @param string $id The skill id.
	 *
	 * @return ObjectEntity|string
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	private function resolve(string $id): ObjectEntity|string {
		try {
			$skill = $this->skillService->getSkill(skillId: $id);
		} catch (DoesNotExistException $e) {
			return 'not_found';
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq bundle publish: resolving skill "' . $id . '" failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return 'failed';
		}

		return ($skill ?? 'not_found');
	}//end resolve()
}//end class
