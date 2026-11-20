<?php

/**
 * Hermiq BiasCheckEvidence.
 *
 * The latest bias check as evidence (reuse-scheduled-bias-evals, REQ-BIASEVAL-004).
 * A bias check is an `EvalRun` of a dataset carrying the bias template's key
 * (`SeedBiasEvalTemplate::TEMPLATE_KEY`): a copy made with "Use for this agent".
 *
 * - `latestFor()` gives the agent factsheet its line: the date, pass rate and run of
 *   the agent's latest completed bias check, or null for "No bias check run".
 * - `evidence()` is the `bias-eval-recency` evidence source of the compliance
 *   dashboard: `satisfied` when every agent with an enabled bias schedule had a
 *   completed bias check in the last 45 days, `partial` when some did, and
 *   `unevidenced` when none did or no agent has a bias schedule.
 *
 * Computed on read from the stored runs, never a stored status, like every other
 * evidence source of `ComplianceService`. A passed check shows how an agent behaved
 * on the pairs, not that it is free of bias; the wording says no more than that.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Eval
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Eval;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Repair\SeedBiasEvalTemplate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Reads the latest bias check per agent and the bias-testing recency status.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */
class BiasCheckEvidence {

	/**
	 * How many days a bias check counts as recent.
	 *
	 * @var int
	 */
	public const RECENT_DAYS = 45;

	/**
	 * The register that holds hermiq's objects.
	 *
	 * @var string
	 */
	private const REGISTER = 'hermiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads datasets, runs and schedules as the system.
	 * @param ITimeFactory  $time          The clock the 45-day window is measured from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The agent's latest completed bias check.
	 *
	 * @param string $agentId The agent uuid.
	 *
	 * @return array{runId: string, datasetId: string, passRate: float, date: string, startedAt: string}|null
	 *         The check, or null when the agent has none.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	public function latestFor(string $agentId): ?array {
		if ($agentId === '') {
			return null;
		}

		return $this->latestAmong(runs: $this->runsOf(agentId: $agentId), datasetIds: $this->biasDatasetIds());
	}//end latestFor()

	/**
	 * The `bias-eval-recency` evidence: how many agents with an enabled bias
	 * schedule had a completed bias check in the last 45 days.
	 *
	 * @return array{status: string, detail: string}
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	public function evidence(): array {
		$datasetIds = $this->biasDatasetIds();
		$agentIds   = $this->scheduledAgentIds(datasetIds: $datasetIds);
		$total      = count($agentIds);
		if ($total === 0) {
			return [
				'status' => 'unevidenced',
				'detail' => 'No agent has a scheduled bias check yet.',
			];
		}

		$cutoff = $this->now()->modify('-' . self::RECENT_DAYS . ' days');
		$recent = 0;
		foreach ($agentIds as $agentId) {
			$latest = $this->latestAmong(runs: $this->runsOf(agentId: $agentId), datasetIds: $datasetIds);
			if ($latest !== null && new DateTimeImmutable($latest['startedAt']) >= $cutoff) {
				$recent++;
			}
		}

		return self::statusOf(recent: $recent, total: $total);
	}//end evidence()

	/**
	 * The status and sentence for $recent of $total scheduled agents with a recent check.
	 *
	 * @param int $recent How many had a recent check.
	 * @param int $total  How many agents have a bias schedule.
	 *
	 * @return array{status: string, detail: string}
	 */
	private static function statusOf(int $recent, int $total): array {
		if ($recent === $total) {
			return [
				'status' => 'satisfied',
				'detail' => sprintf('All %d agent(s) with a scheduled bias check had one in the last %d days.', $total, self::RECENT_DAYS),
			];
		}

		if ($recent > 0) {
			return [
				'status' => 'partial',
				'detail' => sprintf('%d of %d agent(s) with a scheduled bias check had one in the last %d days.', $recent, $total, self::RECENT_DAYS),
			];
		}

		return [
			'status' => 'unevidenced',
			'detail' => sprintf('None of the %d agent(s) with a scheduled bias check had one in the last %d days.', $total, self::RECENT_DAYS),
		];
	}//end statusOf()

	/**
	 * The latest completed, dated run among $runs whose dataset is a bias dataset.
	 *
	 * @param array<int, ObjectEntity>  $runs       The agent's runs.
	 * @param array<string, true>       $datasetIds The bias dataset uuids.
	 *
	 * @return array{runId: string, datasetId: string, passRate: float, date: string, startedAt: string}|null
	 */
	private function latestAmong(array $runs, array $datasetIds): ?array {
		$latest = null;
		foreach ($runs as $run) {
			$data      = $run->getObject();
			$datasetId = (string)($data['datasetId'] ?? '');
			$startedAt = (string)($data['startedAt'] ?? '');
			if (isset($datasetIds[$datasetId]) === false || ($data['status'] ?? '') !== 'completed' || $startedAt === '') {
				continue;
			}

			$started = $this->parse(value: $startedAt);
			if ($started === null || ($latest !== null && $started <= $latest['at'])) {
				continue;
			}

			$latest = ['at' => $started, 'run' => $run, 'data' => $data];
		}

		if ($latest === null) {
			return null;
		}

		return [
			'runId'     => (string)$latest['run']->getUuid(),
			'datasetId' => (string)($latest['data']['datasetId'] ?? ''),
			'passRate'  => (float)($latest['data']['passRate'] ?? 0),
			'date'      => $latest['at']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
			'startedAt' => $latest['at']->format(DATE_ATOM),
		];
	}//end latestAmong()

	/**
	 * The uuids of every dataset made from the bias template (and the template itself).
	 *
	 * @return array<string, true> Uuid → true.
	 */
	private function biasDatasetIds(): array {
		$ids = [];
		foreach ($this->rows(schema: 'evaldataset', filters: ['templateKey' => SeedBiasEvalTemplate::TEMPLATE_KEY]) as $dataset) {
			if (($dataset->getObject()['templateKey'] ?? '') === SeedBiasEvalTemplate::TEMPLATE_KEY) {
				$ids[(string)$dataset->getUuid()] = true;
			}
		}

		return $ids;
	}//end biasDatasetIds()

	/**
	 * The agents with an enabled schedule on a bias dataset.
	 *
	 * @param array<string, true> $datasetIds The bias dataset uuids.
	 *
	 * @return array<int, string> The agent uuids, each once.
	 */
	private function scheduledAgentIds(array $datasetIds): array {
		$agentIds = [];
		foreach ($this->rows(schema: 'evalschedule', filters: ['enabled' => true]) as $schedule) {
			$data    = $schedule->getObject();
			$agentId = (string)($data['agentId'] ?? '');
			if (($data['enabled'] ?? false) === true && $agentId !== '' && isset($datasetIds[(string)($data['datasetId'] ?? '')]) === true) {
				$agentIds[$agentId] = true;
			}
		}

		return array_keys($agentIds);
	}//end scheduledAgentIds()

	/**
	 * The eval runs of one agent.
	 *
	 * @param string $agentId The agent uuid.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function runsOf(string $agentId): array {
		return array_values(
			array_filter(
				$this->rows(schema: 'evalrun', filters: ['agentId' => $agentId]),
				static fn (ObjectEntity $run): bool => ($run->getObject()['agentId'] ?? '') === $agentId
			)
		);
	}//end runsOf()

	/**
	 * Rows of one hermiq schema, read as the system; empty when the read fails.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function rows(string $schema, array $filters): array {
		try {
			$found = $this->objectService->setRegister(self::REGISTER)->setSchema($schema)->findAll(
				config: ['filters' => $filters, 'limit' => 1000],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return [];
		}

		return array_values(array_filter($found, static fn (mixed $row): bool => $row instanceof ObjectEntity));
	}//end rows()

	/**
	 * A stored timestamp, or null when it cannot be read.
	 *
	 * @param string $value The timestamp.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parse(string $value): ?DateTimeImmutable {
		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end parse()

	/**
	 * Now, from the clock.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
	}//end now()
}//end class
