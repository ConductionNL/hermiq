<?php

/**
 * Hermiq: the answer cache of an agent's turns without tools.
 *
 * A turn that repeats an earlier question to the same agent version, in the same
 * organisation and with the same assembled input, gets the stored answer without
 * a model call. Opt-in per agent (`Agent.answerCache.enabled`), never for a turn
 * that offers tools, a dry run, or a message with attachments.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AnswerCache
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
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AnswerCache;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\Engine\ResponseTurnRun;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Looks up and stores the answers of eligible turns.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-an-exact-repeat-within-one-organisation-gets-the-stored-answer-req-acache-002
 */
class AnswerCacheService {

	public const REGISTER = 'hermiq';

	public const SCHEMA = 'agentanswercache';

	public const DEFAULT_TTL_MINUTES = 1440;

	public const MAX_TTL_MINUTES = 43200;

	private const PURGE_PAGE = 500;

	/**
	 * The lowest similarity a similar question is served at.
	 */
	public const MIN_SIMILARITY = 0.95;

	/**
	 * How many nearest questions the similarity layer asks the facade for.
	 */
	private const SIMILAR_LIMIT = 5;

	/**
	 * Constructor.
	 *
	 * @param ObjectService                  $objects  OpenRegister's single read and write path.
	 * @param AgentVersionService            $versions The agent's current version.
	 * @param OrganisationModelPolicyService $policy   The effective model policy.
	 * @param IUserSession                   $session  The person asking.
	 * @param ITimeFactory                   $time     The clock.
	 * @param LoggerInterface                $logger   The logger.
	 * @param SimilarQuestionSearch|null     $similar  OpenRegister's vector search, for the similarity layer.
	 * @param RedactionService|null          $redactor Redacts the question stored for the similarity layer.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly AgentVersionService $versions,
		private readonly OrganisationModelPolicyService $policy,
		private readonly IUserSession $session,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly ?SimilarQuestionSearch $similar=null,
		private readonly ?RedactionService $redactor=null,
	) {
	}//end __construct()

	/**
	 * Whether the turn may read or write the cache.
	 *
	 * Only when the agent switched it on, the turn offers no tools, it is not a
	 * dry run (a replay runs as one) and the message carries no attachment.
	 *
	 * @param ResponseTurnRun $run The turn, after its tools and driver are resolved.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-the-answer-cache-is-opt-in-per-agent-and-only-for-turns-without-tools-req-acache-001
	 */
	public function isEligible(ResponseTurnRun $run): bool {
		if ($run->agent === null || $run->dryRun === true || $run->answerCacheBypass === true) {
			return false;
		}

		if (empty($run->progress->functions) === false || $run->attachments !== []) {
			return false;
		}

		return ($this->setting(run: $run)['enabled'] ?? false) === true;
	}//end isEligible()

	/**
	 * The entry key: SHA-256 over the organisation, agent, agent version, provider,
	 * model, temperature and the full assembled input.
	 *
	 * @param ResponseTurnRun $run       The turn, with its messages built.
	 * @param string|null     $versionId The agent's current version id.
	 *
	 * @return string The hex digest.
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-an-exact-repeat-within-one-organisation-gets-the-stored-answer-req-acache-002
	 */
	public function key(ResponseTurnRun $run, ?string $versionId): string {
		$input = [];
		foreach ($run->messageHistory as $message) {
			$input[] = [(string)$message->role->value, (string)$message->content];
		}

		$agentData = $run->agentData();

		return hash(
			'sha256',
			(string)json_encode(
				[
					(string)($run->progress->organisation ?? ''),
					(string)$run->agent?->getUuid(),
					(string)$versionId,
					$run->progress->driver->provider,
					$run->progress->driver->model,
					($agentData['temperature'] ?? null),
					$input,
				],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}//end key()

	/**
	 * The stored answer for an eligible turn, or null to call the model.
	 *
	 * Every hit is re-checked here: same organisation, same agent version, not
	 * expired, and its provider and model still allowed by the effective model
	 * policy. An entry the policy no longer allows is deleted. Any failure reads
	 * as a miss, so the model is called.
	 *
	 * @param ResponseTurnRun $run The turn, with its messages built.
	 *
	 * When the exact layer misses, the similarity layer may serve the answer to a
	 * differently worded first question (REQ-ACACHE-003).
	 *
	 * @return array{answer: string, entryId: string, layer: string, similarity?: float}|null
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-an-exact-repeat-within-one-organisation-gets-the-stored-answer-req-acache-002
	 */
	public function lookup(ResponseTurnRun $run): ?array {
		if ($this->isEligible(run: $run) === false) {
			return null;
		}

		try {
			$versionId = $this->versions->currentVersionId(agentUuid: (string)$run->agent?->getUuid());
			$entry     = $this->find(run: $run, keyHash: $this->key(run: $run, versionId: $versionId), versionId: $versionId);
			if ($entry !== null) {
				return $this->serve(run: $run, entry: $entry, layer: 'exact');
			}

			$similar = $this->findSimilar(run: $run, versionId: $versionId);
			if ($similar === null) {
				return null;
			}

			return $this->serve(run: $run, entry: $similar['entry'], layer: 'similar', similarity: $similar['similarity']);
		} catch (Throwable $error) {
			$this->logger->warning('[AnswerCache] lookup failed, calling the model', ['exception' => $error->getMessage()]);
			return null;
		}
	}//end lookup()

	/**
	 * Store the model's answer of an eligible turn.
	 *
	 * @param ResponseTurnRun $run    The turn, with its messages built.
	 * @param string          $answer The model's answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-an-exact-repeat-within-one-organisation-gets-the-stored-answer-req-acache-002
	 */
	public function store(ResponseTurnRun $run, string $answer): void {
		if (trim($answer) === '' || $this->isEligible(run: $run) === false) {
			return;
		}

		try {
			$versionId = $this->versions->currentVersionId(agentUuid: (string)$run->agent?->getUuid());
			$now       = $this->now();
			$minutes   = $this->ttlMinutes(run: $run);
			$entry     = [
				'agentId' => (string)$run->agent?->getUuid(),
				'agentVersionId' => (string)$versionId,
				'keyHash' => $this->key(run: $run, versionId: $versionId),
				'answer' => $answer,
				'provider' => $run->progress->driver->provider,
				'model' => $run->progress->driver->model,
				'userId' => (string)$this->session->getUser()?->getUID(),
				'createdAt' => $now->format(DateTimeInterface::ATOM),
				'expiresAt' => $now->modify('+' . $minutes . ' minutes')->format(DateTimeInterface::ATOM),
				'hitCount' => 0,
			];
			// The question is kept, redacted, only for the similarity layer.
			$question = $this->storedQuestion(run: $run);
			if ($question !== null) {
				$entry['question'] = $question;
			}

			$this->objects->saveObject(
				object: $entry,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA)),
				_rbac: false
			);
		} catch (Throwable $error) {
			$this->logger->warning('[AnswerCache] could not store the answer', ['exception' => $error->getMessage()]);
		}
	}//end store()

	/**
	 * Delete one entry; false when it could not be deleted.
	 *
	 * @param string $entryId The entry uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
	 */
	public function remove(string $entryId): bool {
		if ($entryId === '') {
			return false;
		}

		try {
			return $this->objects->deleteObject(deletion: $this->deletion(entryId: $entryId), _rbac: false);
		} catch (Throwable $error) {
			$this->logger->warning('[AnswerCache] could not delete entry ' . $entryId, ['exception' => $error->getMessage()]);
			return false;
		}
	}//end remove()

	/**
	 * Delete every expired entry, of every organisation; returns how many went.
	 *
	 * Run by the daily AnswerCachePurgeJob. Pages through the entries, so no
	 * fixed cap leaves expired answers behind.
	 *
	 * @return int The entries deleted.
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
	 */
	public function purgeExpired(): int {
		$now     = $this->now();
		$expired = [];
		$offset  = 0;
		do {
			$page = $this->objects->setRegister(self::REGISTER)->setSchema(self::SCHEMA)->findAll(
				config: ['limit' => self::PURGE_PAGE, 'offset' => $offset],
				_rbac: false,
				_multitenancy: false
			);
			foreach ($page as $entry) {
				if ($entry instanceof ObjectEntity === true && $this->expired(data: $entry->getObject(), now: $now) === true) {
					$expired[] = (string)$entry->getUuid();
				}
			}

			$offset += self::PURGE_PAGE;
		} while (count($page) === self::PURGE_PAGE);

		$removed = 0;
		foreach ($expired as $entryId) {
			if ($this->objects->deleteObject(deletion: $this->deletion(entryId: $entryId), _rbac: false, _multitenancy: false) === true) {
				$removed++;
			}
		}

		return $removed;
	}//end purgeExpired()

	/**
	 * Remove every cached answer of one agent ("Clear cached answers").
	 *
	 * Reads the entries by `agentId` across organisations, as the purge job
	 * does: the caller has already been checked as the agent's owner, and an
	 * entry of this agent is this agent's whichever organisation stored it.
	 *
	 * @param string $agentId The agent's UUID.
	 *
	 * @return int How many entries were removed.
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
	 */
	public function clearForAgent(string $agentId): int {
		if (trim($agentId) === '') {
			return 0;
		}

		$entryIds = [];
		$offset   = 0;
		do {
			$page = $this->objects->setRegister(self::REGISTER)->setSchema(self::SCHEMA)->findAll(
				config: ['filters' => ['agentId' => $agentId], 'limit' => self::PURGE_PAGE, 'offset' => $offset],
				_rbac: false,
				_multitenancy: false
			);
			foreach ($page as $entry) {
				if ($entry instanceof ObjectEntity === true && (string)(($entry->getObject())['agentId'] ?? '') === $agentId) {
					$entryIds[] = (string)$entry->getUuid();
				}
			}

			$offset += self::PURGE_PAGE;
		} while (count($page) === self::PURGE_PAGE);

		$removed = 0;
		foreach ($entryIds as $entryId) {
			if ($this->objects->deleteObject(deletion: $this->deletion(entryId: $entryId), _rbac: false, _multitenancy: false) === true) {
				$removed++;
			}
		}

		return $removed;
	}//end clearForAgent()

	/**
	 * The similarity layer's scope for this turn: `user`, `organisation`, or null when off.
	 *
	 * `organisation` is refused, and the layer off, while the agent searches data
	 * (enableRag), since retrieved context may differ per person.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-similar-questions-match-only-through-openregisters-vector-facade-req-acache-003
	 */
	public function similarityScope(ResponseTurnRun $run): ?string {
		$scope = ($this->setting(run: $run)['similarity'] ?? 'off');
		if ($scope === 'user') {
			return 'user';
		}

		if ($scope === 'organisation' && ($run->agentData()['enableRag'] ?? false) !== true) {
			return 'organisation';
		}

		return null;
	}//end similarityScope()

	/**
	 * Serve a found entry: re-check the model policy, count the hit, return the answer.
	 *
	 * An entry whose provider or model the effective policy no longer allows is
	 * deleted and the model is called.
	 *
	 * @param ResponseTurnRun $run        The turn.
	 * @param ObjectEntity    $entry      The entry.
	 * @param string          $layer      `exact` or `similar`.
	 * @param float|null      $similarity The similarity score, for the similar layer.
	 *
	 * @return array{answer: string, entryId: string, layer: string, similarity?: float}|null
	 */
	private function serve(ResponseTurnRun $run, ObjectEntity $entry, string $layer, ?float $similarity=null): ?array {
		$data    = $entry->getObject();
		$allowed = $this->policy->isAllowed(
			organisation: (string)($run->progress->organisation ?? ''),
			provider: (string)($data['provider'] ?? ''),
			model: (string)($data['model'] ?? '')
		);
		if ($allowed === false) {
			$this->remove(entryId: (string)$entry->getUuid());
			return null;
		}

		$this->countHit(entry: $entry);

		$hit = ['answer' => (string)($data['answer'] ?? ''), 'entryId' => (string)$entry->getUuid(), 'layer' => $layer];
		if ($similarity !== null) {
			$hit['similarity'] = $similarity;
		}

		return $hit;
	}//end serve()

	/**
	 * The stored question for the similarity layer: redacted, and only when the layer is on.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string|null
	 */
	private function storedQuestion(ResponseTurnRun $run): ?string {
		if ($this->similarityScope(run: $run) === null || $this->isFirstQuestion(run: $run) === false) {
			return null;
		}

		return $this->redacted(question: $run->userMessage);
	}//end storedQuestion()

	/**
	 * The question with sensitive values removed; null when there is no redactor or no question.
	 *
	 * Without a redactor nothing is stored or searched, so an unredacted question
	 * never reaches the index.
	 *
	 * @param string $question The question.
	 *
	 * @return string|null
	 */
	private function redacted(string $question): ?string {
		if ($this->redactor === null || trim($question) === '') {
			return null;
		}

		return $this->redactor->redact(text: $question);
	}//end redacted()

	/**
	 * Whether this turn is the first question of its conversation.
	 *
	 * @param ResponseTurnRun $run The turn, with its messages built.
	 *
	 * @return bool
	 */
	private function isFirstQuestion(ResponseTurnRun $run): bool {
		$questions = 0;
		foreach ($run->messageHistory as $message) {
			if ((string)$message->role->value === 'user') {
				$questions++;
			}
		}

		return $questions <= 1;
	}//end isFirstQuestion()

	/**
	 * The stored entry of a similar question, re-checked here, or null.
	 *
	 * Only for the first question of a conversation, with the layer on and the
	 * facade available. Every row the facade returns is re-checked: an object of
	 * this agent's cache, same organisation, same agent version, in scope (`user`:
	 * stored by this person), not expired, and a similarity of at least
	 * MIN_SIMILARITY. A row that fails any check is ignored.
	 *
	 * @param ResponseTurnRun $run       The turn.
	 * @param string|null     $versionId The agent's current version id.
	 *
	 * @return array{entry: ObjectEntity, similarity: float}|null
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-similar-questions-match-only-through-openregisters-vector-facade-req-acache-003
	 */
	private function findSimilar(ResponseTurnRun $run, ?string $versionId): ?array {
		$scope = $this->similarityScope(run: $run);
		if ($scope === null || $this->similar === null || $this->isFirstQuestion(run: $run) === false) {
			return null;
		}

		$question = $this->redacted(question: $run->userMessage);
		if ($question === null || $this->similar->isAvailable() === false) {
			return null;
		}

		$best = null;
		foreach ($this->similar->nearest(question: $question, limit: self::SIMILAR_LIMIT) as $row) {
			$similarity = (float)($row['similarity'] ?? 0);
			if (($row['entity_type'] ?? null) !== 'object' || $similarity < self::MIN_SIMILARITY || ($best !== null && $similarity <= $best['similarity'])) {
				continue;
			}

			$entry = $this->similarEntry(run: $run, entityId: ($row['entity_id'] ?? null), versionId: $versionId, scope: $scope);
			if ($entry !== null) {
				$best = ['entry' => $entry, 'similarity' => $similarity];
			}
		}

		return $best;
	}//end findSimilar()

	/**
	 * The cache entry a facade row points at, when it passes every re-check; else null.
	 *
	 * @param ResponseTurnRun $run       The turn.
	 * @param mixed           $entityId  The row's object id.
	 * @param string|null     $versionId The agent's current version id.
	 * @param string          $scope     `user` or `organisation`.
	 *
	 * @return ObjectEntity|null
	 */
	private function similarEntry(ResponseTurnRun $run, mixed $entityId, ?string $versionId, string $scope): ?ObjectEntity {
		if ((is_string($entityId) === false || $entityId === '') && is_int($entityId) === false) {
			return null;
		}

		$entry = $this->objects->find(
			id: $entityId,
			options: new ObjectFindOptions(register: self::REGISTER, schema: self::SCHEMA),
			_rbac: false
		);
		if ($entry instanceof ObjectEntity === false) {
			return null;
		}

		$data = $entry->getObject();
		if ((string)($data['agentId'] ?? '') !== (string)$run->agent?->getUuid() || (string)($data['question'] ?? '') === '') {
			return null;
		}

		if ((string)($entry->getOrganisation() ?? '') !== (string)($run->progress->organisation ?? '')) {
			return null;
		}

		if ((string)($data['agentVersionId'] ?? '') !== (string)$versionId || $this->expired(data: $data, now: $this->now()) === true) {
			return null;
		}

		$userId = (string)$this->session->getUser()?->getUID();
		if ($scope === 'user' && ($userId === '' || (string)($data['userId'] ?? '') !== $userId)) {
			return null;
		}

		return $entry;
	}//end similarEntry()

	/**
	 * The deletion of one cache entry.
	 *
	 * @param string $entryId The entry uuid.
	 *
	 * @return ObjectDeletion
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
	 */
	private function deletion(string $entryId): ObjectDeletion {
		return new ObjectDeletion(uuid: $entryId, target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA));
	}//end deletion()

	/**
	 * The live entry for this key in this organisation and agent version, or null.
	 *
	 * @param ResponseTurnRun $run       The turn.
	 * @param string          $keyHash   The key.
	 * @param string|null     $versionId The agent's current version id.
	 *
	 * @return ObjectEntity|null
	 */
	private function find(ResponseTurnRun $run, string $keyHash, ?string $versionId): ?ObjectEntity {
		$agentId = (string)$run->agent?->getUuid();
		$found   = $this->objects->setRegister(self::REGISTER)->setSchema(self::SCHEMA)->findAll(
			config: ['filters' => ['agentId' => $agentId, 'keyHash' => $keyHash], 'limit' => 10],
			_rbac: false
		);

		$now = $this->now();
		foreach ($found as $entry) {
			if ($entry instanceof ObjectEntity === false) {
				continue;
			}

			$data = $entry->getObject();
			if (($data['keyHash'] ?? null) !== $keyHash || ($data['agentId'] ?? null) !== $agentId) {
				continue;
			}

			// The organisation is in the key, and the stored row must say the same.
			if ((string)($entry->getOrganisation() ?? '') !== (string)($run->progress->organisation ?? '')) {
				continue;
			}

			if ((string)($data['agentVersionId'] ?? '') !== (string)$versionId || $this->expired(data: $data, now: $now) === true) {
				continue;
			}

			return $entry;
		}

		return null;
	}//end find()

	/**
	 * Whether an entry's expiry has passed or cannot be read.
	 *
	 * @param array<string, mixed> $data The entry.
	 * @param DateTimeImmutable    $now  The moment.
	 *
	 * @return bool
	 */
	private function expired(array $data, DateTimeImmutable $now): bool {
		$expires = date_create_immutable((string)($data['expiresAt'] ?? ''));

		return $expires === false || $expires <= $now;
	}//end expired()

	/**
	 * Count a hit on the entry, best effort.
	 *
	 * @param ObjectEntity $entry The entry.
	 *
	 * @return void
	 */
	private function countHit(ObjectEntity $entry): void {
		$data = $entry->getObject();
		$data['hitCount']  = ((int)($data['hitCount'] ?? 0) + 1);
		$data['lastHitAt'] = $this->now()->format(DateTimeInterface::ATOM);
		try {
			$this->objects->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA),
					uuid: (string)$entry->getUuid()
				),
				_rbac: false
			);
		} catch (Throwable $error) {
			$this->logger->info('[AnswerCache] hit not counted', ['exception' => $error->getMessage()]);
		}
	}//end countHit()

	/**
	 * The agent's answer cache setting.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return array<string, mixed>
	 */
	private function setting(ResponseTurnRun $run): array {
		$setting = ($run->agentData()['answerCache'] ?? []);
		if (is_array($setting) === false) {
			return [];
		}

		return $setting;
	}//end setting()

	/**
	 * The agent's lifetime in minutes, within 1 and the cap.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return int
	 */
	private function ttlMinutes(ResponseTurnRun $run): int {
		$minutes = ($this->setting(run: $run)['ttlMinutes'] ?? self::DEFAULT_TTL_MINUTES);
		if (is_int($minutes) === false || $minutes < 1) {
			return self::DEFAULT_TTL_MINUTES;
		}

		return min($minutes, self::MAX_TTL_MINUTES);
	}//end ttlMinutes()

	/**
	 * Now, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
	}//end now()
}//end class
