<?php

/**
 * Hermiq GraphExtractionService.
 *
 * Turns a batch of source records into graph entities and relations for the user
 * who enqueued the batch. The whole run holds that user's identity
 * (ActingUserScope), so a record that user cannot read yields nothing: no model
 * call, no write. For each readable record the configured model (under the record's
 * organisation's model policy) proposes entities and relations as JSON; labels and
 * aliases are redacted; every write goes through GraphService, so it is an audited
 * ObjectService write carrying the record as sourceRef, this extractor's identity
 * and version in extractedBy, and a confidence. Upserts are idempotent, so running a
 * batch again adds nothing new.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Graph
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
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\RedactionService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Acting-user graph extraction.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */
class GraphExtractionService {

	/**
	 * This extractor's identity and version, stored on every entry it writes.
	 */
	public const EXTRACTOR = 'hermiq-graph-extractor@1';

	/**
	 * The most entities and relations taken from one record.
	 */
	private const MAX_PER_RECORD = 40;

	/**
	 * The instruction the model gets before the record text.
	 */
	private const PROMPT = 'Extract the named entities and the relations between them from the record below. '
		. 'Answer with JSON only, in this shape: {"entities": [{"label": "...", "type": "person|organisation|case|document|topic", '
		. '"aliases": ["..."], "confidence": 0.0}], "relations": [{"from": "<entity label>", "predicate": '
		. '"worksFor|partOf|relatesTo|mentions|authoredBy|about", "to": "<entity label>", "confidence": 0.0}]}. '
		. 'Use only names that appear in the record. Record:' . "\n\n";

	/**
	 * Constructor.
	 *
	 * @param GraphService $graph The graph (the only write path).
	 * @param GraphSourceReader $reader User-scoped record reads.
	 * @param ConversationManagementHandler $llm The configured model.
	 * @param RedactionService $redaction Secret redaction.
	 * @param ActingUserScope $actingUser Impersonate-and-restore.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly GraphService $graph,
		private readonly GraphSourceReader $reader,
		private readonly ConversationManagementHandler $llm,
		private readonly RedactionService $redaction,
		private readonly ActingUserScope $actingUser,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Extract a batch as the user who enqueued it.
	 *
	 * @param string $uid The enqueueing user.
	 * @param array<int, array<string, mixed>> $sources Each {sourceType, sourceRef}.
	 *
	 * @return array{entities: int, relations: int, skipped: int} What was written, and how many records could not be read.
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#scenario-extraction-cannot-read-beyond-its-user
	 */
	public function extract(string $uid, array $sources): array {
		try {
			return $this->actingUser->run(uid: $uid, work: fn (): array => $this->extractAll(uid: $uid, sources: $sources));
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq graph: an extraction batch failed', ['uid' => $uid, 'exception' => $e]);

			return ['entities' => 0, 'relations' => 0, 'skipped' => count($sources)];
		}

	}//end extract()

	/**
	 * Extract every source of the batch; the session already holds the user.
	 *
	 * @param string $uid The acting user.
	 * @param array<int, array<string, mixed>> $sources Each {sourceType, sourceRef}.
	 *
	 * @return array{entities: int, relations: int, skipped: int}
	 */
	private function extractAll(string $uid, array $sources): array {
		$counts = ['entities' => 0, 'relations' => 0, 'skipped' => 0];
		foreach ($sources as $source) {
			$sourceType = (string)($source['sourceType'] ?? '');
			$sourceRef = (array)($source['sourceRef'] ?? []);
			$record = $this->reader->read(sourceType: $sourceType, ref: $sourceRef, uid: $uid);
			if ($record === null) {
				$counts['skipped']++;
				continue;
			}

			$proposal = $this->propose(text: $record['text'], organisation: $record['organisation']);
			$written = $this->persist(sourceType: $sourceType, sourceRef: $sourceRef, proposal: $proposal);
			$counts['entities'] += $written['entities'];
			$counts['relations'] += $written['relations'];
		}

		return $counts;

	}//end extractAll()

	/**
	 * Ask the model for entities and relations; an unusable answer is an empty proposal.
	 *
	 * @param string $text The record text.
	 * @param string|null $organisation The record's organisation (model policy).
	 *
	 * @return array{entities: array<int, mixed>, relations: array<int, mixed>}
	 */
	private function propose(string $text, ?string $organisation): array {
		$empty = ['entities' => [], 'relations' => []];
		try {
			$answer = $this->llm->generateText(prompt: self::PROMPT . $text, organisation: $organisation);
		} catch (Throwable $e) {
			$this->logger->info('Hermiq graph: the model could not be asked', ['exception' => $e]);

			return $empty;
		}

		$start = strpos($answer, '{');
		$end = strrpos($answer, '}');
		if ($start === false || $end === false || $end < $start) {
			return $empty;
		}

		$decoded = json_decode(substr($answer, $start, ($end - $start + 1)), true);
		if (is_array($decoded) === false) {
			return $empty;
		}

		return [
			'entities' => array_slice(array_values((array)($decoded['entities'] ?? [])), 0, self::MAX_PER_RECORD),
			'relations' => array_slice(array_values((array)($decoded['relations'] ?? [])), 0, self::MAX_PER_RECORD),
		];

	}//end propose()

	/**
	 * Write one record's proposal through GraphService.
	 *
	 * @param string $sourceType The record's source type.
	 * @param array<string, mixed> $sourceRef The record's pointer.
	 * @param array{entities: array<int, mixed>, relations: array<int, mixed>} $proposal The proposal.
	 *
	 * @return array{entities: int, relations: int}
	 */
	private function persist(string $sourceType, array $sourceRef, array $proposal): array {
		$byLabel = [];
		foreach ($proposal['entities'] as $entity) {
			$label = $this->clean(value: ($entity['label'] ?? null));
			if (is_array($entity) === false || $label === '') {
				continue;
			}

			$aliases = array_values(array_filter(array_map(fn (mixed $alias): string => $this->clean(value: $alias), (array)($entity['aliases'] ?? []))));
			$byLabel[mb_strtolower($label)] = $this->graph->upsertEntity(
				[
					'label' => $label,
					'entityType' => (string)($entity['type'] ?? 'topic'),
					'sourceType' => $sourceType,
					'sourceRef' => $sourceRef,
					'aliases' => $aliases,
					'confidence' => ($entity['confidence'] ?? null),
					'extractedBy' => self::EXTRACTOR,
				]
			);
		}

		$relations = 0;
		foreach ($proposal['relations'] as $relation) {
			$from = ($byLabel[mb_strtolower($this->clean(value: ($relation['from'] ?? null)))] ?? null);
			$to = ($byLabel[mb_strtolower($this->clean(value: ($relation['to'] ?? null)))] ?? null);
			if ($from === null || $to === null || is_string($relation['predicate'] ?? null) === false) {
				continue;
			}

			$this->graph->upsertRelation(
				[
					'fromEntity' => $from,
					'predicate' => $relation['predicate'],
					'toEntity' => $to,
					'sourceType' => $sourceType,
					'sourceRef' => $sourceRef,
					'confidence' => ($relation['confidence'] ?? null),
					'extractedBy' => self::EXTRACTOR,
				]
			);
			$relations++;
		}

		return ['entities' => count($byLabel), 'relations' => $relations];

	}//end persist()

	/**
	 * A redacted, trimmed label; empty for anything that is not a string.
	 *
	 * @param mixed $value The proposed label.
	 *
	 * @return string The label.
	 */
	private function clean(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($this->redaction->redact($value));

	}//end clean()
}//end class
