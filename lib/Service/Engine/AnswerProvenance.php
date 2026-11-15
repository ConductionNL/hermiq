<?php

/**
 * Hermiq AnswerProvenance.
 *
 * Builds the provenance record stored on each assistant turn: which feature,
 * provider, model, residency and location served it, which sources it used,
 * and the type, name and outcome of each tool and guardrail step.
 *
 * The record carries identities and outcomes, never content: no tool
 * arguments, no tool results, no document text and no reasoning. A step's
 * trace extras are therefore NOT copied, with one exception: the identity of
 * an artefact a write tool produced (its type and id). A refused call's
 * argument, a dry run's arguments and a web tool's target stay on the trace.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
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
 * @spec openspec/changes/chat-how-an-answer-came-about/tasks.md#task-1-the-provenance-record-on-both-transports
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * The provenance record of one answer.
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-every-answer-records-how-it-came-about-req-atrn-001
 */
final class AnswerProvenance {

	/**
	 * The trace step types the record keeps; context, history and llm timing steps are left out.
	 *
	 * @var list<string>
	 */
	public const STEP_TYPES = ['tool', 'guardrail', 'guard'];

	/**
	 * Build the record from the turn's trace and its sources.
	 *
	 * @param RunTraceCollector|null $trace   The turn's trace, or null when none was kept.
	 * @param array                  $sources The turn's RAG sources.
	 *
	 * @return array{model: array{feature: string, provider: string, model: string, residency: string, location: string}|null,
	 *     sources: list<array{id: string, type: string, name: string}>,
	 *     steps: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-every-answer-records-how-it-came-about-req-atrn-001
	 */
	public function build(?RunTraceCollector $trace, array $sources): array {
		return [
			'model' => $this->model(disclosure: $trace?->providerDisclosure()),
			'sources' => $this->sources(sources: $sources),
			'steps' => $this->steps(steps: ($trace?->toArray() ?? [])),
		];
	}//end build()

	/**
	 * The model that served the answer, copied so relabelling a provider later does not rewrite it.
	 *
	 * @param array|null $disclosure The trace's provider disclosure.
	 *
	 * @return array{feature: string, provider: string, model: string, residency: string, location: string}|null
	 */
	private function model(?array $disclosure): ?array {
		if ($disclosure === null) {
			return null;
		}

		return [
			'feature' => (string)($disclosure['feature'] ?? ''),
			'provider' => (string)($disclosure['provider'] ?? ''),
			'model' => (string)($disclosure['model'] ?? ''),
			'residency' => (string)($disclosure['residency'] ?? ''),
			'location' => (string)($disclosure['location'] ?? ''),
		];
	}//end model()

	/**
	 * The sources by identity and name only, never their excerpts.
	 *
	 * @param array $sources The RAG sources.
	 *
	 * @return list<array{id: string, type: string, name: string}>
	 */
	private function sources(array $sources): array {
		$kept = [];
		foreach ($sources as $source) {
			if (is_array($source) === false) {
				continue;
			}

			$kept[] = [
				'id' => (string)($source['id'] ?? ($source['file_id'] ?? '')),
				'type' => (string)($source['type'] ?? ''),
				'name' => (string)($source['name'] ?? ($source['title'] ?? '')),
			];
		}

		return $kept;
	}//end sources()

	/**
	 * The tool and guardrail steps by type, name and outcome, plus a produced artefact's identity.
	 *
	 * @param array $steps The trace steps.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function steps(array $steps): array {
		$kept = [];
		foreach ($steps as $step) {
			if (is_array($step) === false || in_array(($step['type'] ?? ''), self::STEP_TYPES, true) === false) {
				continue;
			}

			$entry = [
				'type' => (string)$step['type'],
				'name' => (string)($step['name'] ?? ''),
				'outcome' => (string)($step['outcome'] ?? ''),
			];

			$artefact = ($step['artefact'] ?? null);
			if (is_array($artefact) === true && isset($artefact['type'], $artefact['id']) === true) {
				$entry['artefact'] = ['type' => (string)$artefact['type'], 'id' => (string)$artefact['id']];
			}

			$kept[] = $entry;
		}//end foreach

		return $kept;
	}//end steps()
}//end class
