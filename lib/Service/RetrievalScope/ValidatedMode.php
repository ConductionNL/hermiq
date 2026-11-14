<?php

/**
 * Hermiq Validated Mode.
 *
 * The rules of an agent's "Answer only from validated documents"
 * (memory-retrieval-scope, D3 and D4): whether it is on, the similarity floor,
 * the abstention message, the session settings it narrows, which retrieved
 * rows pass the floor, and the scope record each answer keeps (D5). Pure: no
 * search, no storage, so every rule is testable on its own.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\RetrievalScope
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\RetrievalScope;

/**
 * The validated mode's rules.
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */
final class ValidatedMode {

	/**
	 * The floor when the agent sets none.
	 *
	 * @var float
	 */
	public const DEFAULT_FLOOR = 0.5;

	/**
	 * The abstention when the agent sets none (design D3).
	 *
	 * @var string
	 */
	public const DEFAULT_ABSTENTION = 'I cannot answer this from the validated documents. Contact the Klantcontactcentrum for help.';

	/**
	 * Whether the agent answers only from validated documents.
	 *
	 * @param array<string, mixed> $agentData The agent.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
	 */
	public static function isOn(array $agentData): bool {
		return ($agentData['answerOnlyFromValidated'] ?? false) === true;
	}//end isOn()

	/**
	 * The similarity a passage needs: the agent's floor between 0 and 1, else the default.
	 *
	 * @param array<string, mixed> $agentData The agent.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
	 */
	public static function floor(array $agentData): float {
		$floor = ($agentData['ragMinSimilarity'] ?? null);
		if (is_int($floor) === false && is_float($floor) === false) {
			return self::DEFAULT_FLOOR;
		}

		if ($floor < 0 || $floor > 1) {
			return self::DEFAULT_FLOOR;
		}

		return (float)$floor;
	}//end floor()

	/**
	 * The answer stored when nothing validated matches.
	 *
	 * @param array<string, mixed> $agentData The agent.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
	 */
	public static function abstention(array $agentData): string {
		$message = ($agentData['abstentionMessage'] ?? null);
		if (is_string($message) === false || trim($message) === '') {
			return self::DEFAULT_ABSTENTION;
		}

		return trim($message);
	}//end abstention()

	/**
	 * The session's RAG settings with everything that would widen the scope
	 * taken out (D4): object search stays off, and no file or folder scope of
	 * the session replaces the validated set.
	 *
	 * @param array<string, mixed> $ragSettings The session's settings.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
	 */
	public static function narrowed(array $ragSettings): array {
		unset($ragSettings['scope']);
		$ragSettings['includeObjects'] = false;

		return $ragSettings;
	}//end narrowed()

	/**
	 * The files a validated turn may search: the tagged files, within the
	 * agent's own file set when it has one.
	 *
	 * @param list<int>|null $fileIds The agent's file set, or null when unscoped.
	 * @param list<int>      $tagged  The files carrying the validated tag.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
	 */
	public static function fileSet(?array $fileIds, array $tagged): array {
		if ($fileIds === null) {
			return array_values(array_unique($tagged));
		}

		return array_values(array_intersect($tagged, $fileIds));
	}//end fileSet()

	/**
	 * A retrieved row's similarity, 0 when it reports none.
	 *
	 * @param mixed $row The row.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
	 */
	public static function similarityOf(mixed $row): float {
		if (is_array($row) === false) {
			return 0.0;
		}

		$similarity = ($row['similarity'] ?? ($row['score'] ?? ($row['_score'] ?? null)));
		if (is_int($similarity) === false && is_float($similarity) === false) {
			return 0.0;
		}

		return (float)$similarity;
	}//end similarityOf()

	/**
	 * The rows at or above the floor, with how many passed and the best similarity found.
	 *
	 * @param mixed $rows  The retrieved rows.
	 * @param float $floor The floor.
	 *
	 * @return array{rows: list<mixed>, passed: int, best: float|null}
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
	 */
	public static function aboveFloor(mixed $rows, float $floor): array {
		$kept = [];
		$best = null;
		if (is_array($rows) === false) {
			$rows = [];
		}

		foreach ($rows as $row) {
			$similarity = self::similarityOf(row: $row);
			if ($best === null || $similarity > $best) {
				$best = $similarity;
			}

			if ($similarity >= $floor) {
				$kept[] = $row;
			}
		}

		return ['rows' => $kept, 'passed' => count($kept), 'best' => $best];
	}//end aboveFloor()

	/**
	 * The scope an answer records (D5).
	 *
	 * @param mixed      $fileFilters The agent's file filters.
	 * @param bool       $validated   Whether validated mode was on.
	 * @param float|null $floor       The floor, when validated mode was on.
	 * @param int        $passed      How many passages passed the floor.
	 * @param float|null $best        The best similarity found.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-each-answer-records-the-scope-it-was-given-req-rscope-004
	 */
	public static function record(mixed $fileFilters, bool $validated, ?float $floor, int $passed, ?float $best): array {
		if (is_array($fileFilters) === false) {
			$fileFilters = null;
		}

		if ($best !== null) {
			$best = round($best, 4);
		}

		return [
			'fileFilters' => $fileFilters,
			'validated' => $validated,
			'floor' => $floor,
			'passed' => $passed,
			'bestSimilarity' => $best,
			'abstained' => ($validated === true && $passed === 0),
		];
	}//end record()
}//end class
