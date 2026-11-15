<?php

/**
 * Hermiq SkillBundleAnswers.
 *
 * The answers SkillController's bundle endpoints give once their work is done
 * or refused: the 400s, the "nothing publishable" report, and the publish and
 * install reports, built from the run each endpoint carries. Holds no state
 * and no collaborators.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The responses of the skill-bundle endpoints.
 *
 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */
final class SkillBundleAnswers {

	/**
	 * The 400 for a bundle-publish request that cannot be served.
	 *
	 * @param string $code The error code.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function badRequest(string $code): JSONResponse {
		return new JSONResponse(['error' => $code], Http::STATUS_BAD_REQUEST);
	}//end badRequest()

	/**
	 * The 400 that reports every outcome when none of the requested skills or agents resolved.
	 *
	 * @param BundlePublishRun $run The publish, with its skills and agents resolved.
	 *
	 * @return JSONResponse|null The refusal, or null when there is something to publish.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function nothingToPublish(BundlePublishRun $run): ?JSONResponse {
		if ($run->skills['payloads'] === [] && $run->agents['payloads'] === []) {
			return new JSONResponse(
				['error' => 'no_publishable_content', 'skills' => $run->skills['outcomes'], 'agents' => $run->agents['outcomes']],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end nothingToPublish()

	/**
	 * The publish report: where it went, and what was published and dropped per
	 * skill and per agent after serialisation.
	 *
	 * @param BundlePublishRun $run The publish, serialised.
	 * @param array{repoUrl:string,commitSha:string,created:bool} $result The push outcome.
	 *
	 * @return JSONResponse The report.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function published(BundlePublishRun $run, array $result): JSONResponse {
		return new JSONResponse(
			['repoUrl' => $result['repoUrl'], 'commitSha' => $result['commitSha'], 'created' => $result['created']] + $this->bundleReport(run: $run),
			Http::STATUS_OK
		);
	}//end published()

	/**
	 * What was published and dropped, per skill and per agent, after serialisation.
	 *
	 * @param BundlePublishRun $run The publish, serialised.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function bundleReport(BundlePublishRun $run): array {
		$reconciled = $this->reconcileOutcomes(outcomes: $run->skills['outcomes'], dropped: $run->dropped);
		$reconciledAgents = $this->reconcileOutcomes(outcomes: $run->agents['outcomes'], dropped: $run->droppedAgents);

		return [
			'published' => $reconciled['published'],
			'dropped' => count($run->dropped),
			'truncated' => ($run->dropped !== [] || $run->droppedAgents !== []),
			'skills' => $reconciled['outcomes'],
			'agentsPublished' => $reconciledAgents['published'],
			'agentsDropped' => count($run->droppedAgents),
			'agents' => $reconciledAgents['outcomes'],
		];
	}//end bundleReport()

	/**
	 * The install report: per-state counts, truncation, and the skill and agent outcomes.
	 *
	 * @param BundleInstallRun $run The install, done.
	 *
	 * @return JSONResponse The report.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function installed(BundleInstallRun $run): JSONResponse {
		$counts = $run->skillResult['counts'];

		return new JSONResponse(
			[
				'installed' => $counts['installed'],
				'updated' => $counts['updated'],
				'unchanged' => $counts['unchanged'],
				'skipped' => $counts['skipped'],
				'failed' => $counts['failed'],
				'truncated' => $run->bundle['truncated'],
				'skills' => $run->skillResult['outcomes'],
				'agents' => $run->agentResult['outcomes'],
			],
			Http::STATUS_OK
		);
	}//end installed()

	/**
	 * Reconcile the per-skill outcomes against what the serialiser actually
	 * bundled, so the response describes the ARTEFACT rather than the request.
	 *
	 * A skill the serialiser discarded is re-marked `dropped` with its reason, and
	 * only skills that reached the tree count as published. Without this the API
	 * reports success for content it never shipped — observed on the first real
	 * bundle, where 94 skills were requested, 64 were bundled, and all 94 came back
	 * as `published`.
	 *
	 * @param array<int, array<string, mixed>> $outcomes The per-skill outcomes built from the request.
	 * @param array<int, array<string, mixed>> $dropped The serialiser's dropped list.
	 *
	 * @return array{outcomes:array<int,array<string,mixed>>,published:int}
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	private function reconcileOutcomes(array $outcomes, array $dropped): array {
		$byName = [];
		foreach ($dropped as $entry) {
			$byName[(string)($entry['name'] ?? '')] = (string)($entry['reason'] ?? 'dropped');
		}

		$published = 0;
		$out = [];
		foreach ($outcomes as $outcome) {
			$name = (string)($outcome['name'] ?? '');
			if (isset($byName[$name]) === true) {
				$outcome['outcome'] = 'dropped';
				$outcome['reason'] = $byName[$name];
			}

			if (($outcome['outcome'] ?? '') === 'published') {
				$published++;
			}

			$out[] = $outcome;
		}

		return ['outcomes' => $out, 'published' => $published];
	}//end reconcileOutcomes()
}//end class
