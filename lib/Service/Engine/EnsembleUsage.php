<?php

/**
 * Hermiq EnsembleUsage.
 *
 * The usage of an ensemble turn (models-several-models-per-turn D8): the run's
 * `usage` is the sum of every model call of the turn, the reference calls and
 * the agent's own, and `usageByModel` keeps each call's own usage. Because the
 * budget sums `usage` from run entries, the whole ensemble counts against it.
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
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-ensemble-counts-every-call-against-the-budget-req-mens-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * Sum the usage of several model calls.
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-ensemble-counts-every-call-against-the-budget-req-mens-004
 */
final class EnsembleUsage {

	/**
	 * The usage of a turn of several calls.
	 *
	 * Every numeric key is summed across the calls (tokens as integers, seconds
	 * rounded to hundredths); a call that reported nothing adds nothing.
	 *
	 * @param array<int, array{provider: string, model: string, usage: array<string, mixed>}> $calls The calls, in order.
	 *
	 * @return array<string, mixed> The summed usage, with `usageByModel` listing each call.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-ensemble-counts-every-call-against-the-budget-req-mens-004
	 */
	public static function sum(array $calls): array {
		$total = [];
		$byModel = [];
		foreach ($calls as $call) {
			$usage = $call['usage'];
			foreach ($usage as $key => $value) {
				if (is_int($value) === true || is_float($value) === true) {
					$total[$key] = (($total[$key] ?? 0) + $value);
				}
			}

			$byModel[] = ['provider' => $call['provider'], 'model' => $call['model'], 'usage' => $usage];
		}

		foreach ($total as $key => $value) {
			if (is_float($value) === true) {
				$total[$key] = round($value, 2);
			}
		}

		$total['usageByModel'] = $byModel;
		return $total;
	}//end sum()
}//end class
