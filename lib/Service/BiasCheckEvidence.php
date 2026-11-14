<?php

/**
 * The latest bias check of an agent, and how recent every scheduled agent's is.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Kernel\Contracts\BiasCheckEvidenceInterface;
use OCA\Hermiq\Repair\SeedBiasEvalTemplate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * A bias check is a run of a dataset that carries the bias template's key: the
 * template itself or any copy of it (REQ-BIASEVAL-004). Only completed runs
 * count; a run a gate blocked or an infrastructure error ended says nothing
 * about bias. Reads are system reads: the callers (the factsheet and the
 * compliance dashboard) check access before they ask.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
 */
class BiasCheckEvidence implements BiasCheckEvidenceInterface {

	/**
	 * How many days a bias run counts as recent.
	 *
	 * @var int
	 */
	public const RECENT_DAYS = 45;

	/**
	 * Schema slug for EvalRun objects.
	 *
	 * @var string
	 */
	private const RUN_SCHEMA = 'evalrun';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads datasets, runs and schedules.
	 * @param ITimeFactory  $time          The clock.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The agent's latest completed run of a bias check dataset, or null when it has none.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return array{runId: string, datasetId: string, passRate: float, date: string}|null
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	public function latestFor(string $agentId): ?array {
		if ($agentId === '') {
			return null;
		}

		return $this->latestAmong(agentId: $agentId, datasetIds: $this->biasDatasetIds());
	}//end latestFor()

	/**
	 * The bias-eval-recency control status.
	 *
	 * @return array{status: string, detail: string}
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004
	 */
	public function recency(): array {
		$datasetIds = $this->biasDatasetIds();
		$agentIds = [];
		foreach ($this->objects(schema: EvalScheduleService::SCHEMA, filters: ['enabled' => true]) as $schedule) {
			$data = $schedule->getObject();
			$agentId = (string)($data['agentId'] ?? '');
			if (($data['enabled'] ?? false) === true && $agentId !== '' && in_array((string)($data['datasetId'] ?? ''), $datasetIds, true) === true) {
				$agentIds[$agentId] = true;
			}
		}

		if ($agentIds === []) {
			return ['status' => 'unevidenced', 'detail' => 'No agent has a scheduled bias check.'];
		}

		$cutoff = $this->now()->modify('-' . self::RECENT_DAYS . ' days');
		$recent = 0;
		foreach (array_keys($agentIds) as $agentId) {
			$latest = $this->latestAmong(agentId: (string)$agentId, datasetIds: $datasetIds);
			if ($latest !== null && new DateTimeImmutable($latest['date']) >= $cutoff) {
				$recent++;
			}
		}

		$total = count($agentIds);
		$detail = sprintf('%d of %d agent(s) with a scheduled bias check had one in the last %d days.', $recent, $total, self::RECENT_DAYS);
		if ($recent === $total) {
			return ['status' => 'satisfied', 'detail' => $detail];
		}

		if ($recent > 0) {
			return ['status' => 'partial', 'detail' => $detail];
		}

		return ['status' => 'unevidenced', 'detail' => $detail];
	}//end recency()

	/**
	 * The uuids of every dataset that carries the bias template's key.
	 *
	 * @return list<string>
	 */
	private function biasDatasetIds(): array {
		$ids = [];
		foreach ($this->objects(schema: EvalOwnership::DATASET_SCHEMA, filters: ['templateKey' => SeedBiasEvalTemplate::TEMPLATE_KEY]) as $dataset) {
			if (($dataset->getObject()['templateKey'] ?? '') === SeedBiasEvalTemplate::TEMPLATE_KEY) {
				$ids[] = (string)$dataset->getUuid();
			}
		}

		return $ids;
	}//end biasDatasetIds()

	/**
	 * The agent's latest completed run of one of the datasets, or null.
	 *
	 * @param string       $agentId    The agent.
	 * @param list<string> $datasetIds The bias datasets.
	 *
	 * @return array{runId: string, datasetId: string, passRate: float, date: string}|null
	 */
	private function latestAmong(string $agentId, array $datasetIds): ?array {
		if ($datasetIds === []) {
			return null;
		}

		$latest = null;
		$latestAt = null;
		foreach ($this->objects(schema: self::RUN_SCHEMA, filters: ['agentId' => $agentId]) as $run) {
			$data = $run->getObject();
			$startedAt = self::moment(value: ($data['startedAt'] ?? null));
			if (($data['agentId'] ?? '') !== $agentId
				|| ($data['status'] ?? '') !== 'completed'
				|| in_array((string)($data['datasetId'] ?? ''), $datasetIds, true) === false
				|| $startedAt === null
			) {
				continue;
			}

			if ($latestAt === null || $startedAt > $latestAt) {
				$latestAt = $startedAt;
				$latest = [
					'runId' => (string)$run->getUuid(),
					'datasetId' => (string)($data['datasetId'] ?? ''),
					'passRate' => (float)($data['passRate'] ?? 0),
					'date' => $startedAt->format('Y-m-d\TH:i:sP'),
				];
			}
		}

		return $latest;
	}//end latestAmong()

	/**
	 * The objects of a schema matching the filters, read without RBAC.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return list<ObjectEntity>
	 */
	private function objects(string $schema, array $filters): array {
		$objects = $this->objectService
			->setRegister(EvalOwnership::REGISTER)
			->setSchema($schema)
			->findAll(config: ['filters' => $filters], _rbac: false, _multitenancy: false);

		return array_values(array_filter($objects, static fn ($object): bool => $object instanceof ObjectEntity));
	}//end objects()

	/**
	 * Now, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
	}//end now()

	/**
	 * A stored date-time, or null when there is none or it cannot be read.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
		} catch (Throwable) {
			return null;
		}
	}//end moment()
}//end class
