<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Hermiq\Service\Handoff
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Handoff;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Hermiq\BackgroundJob\HandoffResultJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Opens and closes handoffs: work an agent started and did not wait for.
 *
 * A handoff is `waiting` until the work ends, then exactly one of `done`,
 * `failed` or `expired`, and it stays there: a second close is ignored, so a
 * late or repeated answer can never overwrite the first. The deadline is 24
 * hours unless the caller asks for another, and never more than 7 days.
 *
 * This class keeps the record. Starting the work belongs to the callers;
 * when this class closes or expires a handoff it queues HandoffResultJob,
 * which runs the follow-up turn (HandoffDelivery), so the request that
 * closed it never waits on an agent.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */
class HandoffService {

	/**
	 * OpenRegister register slug the Handoff schema lives in.
	 */
	public const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for handoffs.
	 */
	public const SCHEMA_SLUG = 'agenthandoff';

	/**
	 * What an agent can wait on.
	 */
	public const KINDS = ['flow', 'webhook', 'a2a'];

	/**
	 * The one open status.
	 */
	public const WAITING = 'waiting';

	/**
	 * The statuses a close may set. `expired` is set by expireIfDue() only.
	 */
	public const CLOSE_STATUSES = ['done', 'failed'];

	/**
	 * The deadline when the caller sets none.
	 */
	public const DEFAULT_DEADLINE_HOURS = 24;

	/**
	 * The longest deadline a handoff may have: 7 days.
	 */
	public const MAX_DEADLINE_HOURS = 168;

	/**
	 * Fields a handoff cannot be opened without.
	 */
	private const REQUIRED = ['kind', 'agentId', 'conversationId', 'requestedBy', 'label', 'target'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object service.
	 * @param ITimeFactory    $timeFactory   The clock deadlines are set and judged by.
	 * @param LoggerInterface $logger        The logger.
	 * @param IJobList        $jobList       Queues the delivery of a closed handoff.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly IJobList $jobList,
	) {
	}//end __construct()

	/**
	 * Open a handoff for work an agent just started.
	 *
	 * @param array<string, mixed> $handoff kind, agentId, conversationId, requestedBy, label and target;
	 *                                      optionally externalRef, deadlineHours and resultTokenHash.
	 *
	 * @return array<string, mixed> The stored handoff, with its `id`.
	 *
	 * @throws InvalidArgumentException When a required field is missing or the kind is unknown.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
	 */
	public function open(array $handoff): array {
		foreach (self::REQUIRED as $field) {
			if (is_string($handoff[$field] ?? null) === false || trim($handoff[$field]) === '') {
				throw new InvalidArgumentException(sprintf('A handoff needs a %s.', $field));
			}
		}

		if (in_array($handoff['kind'], self::KINDS, true) === false) {
			throw new InvalidArgumentException(sprintf('A handoff cannot wait on "%s".', $handoff['kind']));
		}

		$now = $this->now();
		$hours = $this->deadlineHours(requested: ($handoff['deadlineHours'] ?? null));

		$data = [
			'kind'           => $handoff['kind'],
			'agentId'        => $handoff['agentId'],
			'conversationId' => $handoff['conversationId'],
			'requestedBy'    => $handoff['requestedBy'],
			'label'          => $handoff['label'],
			'target'         => $handoff['target'],
			'externalRef'    => (string) ($handoff['externalRef'] ?? ''),
			'status'         => self::WAITING,
			'result'         => null,
			'requestedAt'    => $now->format(DateTimeInterface::ATOM),
			'deadline'       => $now->add(new DateInterval('PT' . $hours . 'H'))->format(DateTimeInterface::ATOM),
			'closedAt'       => null,
		];
		if (is_string($handoff['resultTokenHash'] ?? null) === true && $handoff['resultTokenHash'] !== '') {
			$data['resultTokenHash'] = $handoff['resultTokenHash'];
		}

		$saved = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			_rbac: false,
			_multitenancy: false
		);

		return $this->toArray(object: $saved);
	}//end open()

	/**
	 * Close a waiting handoff with the work's answer.
	 *
	 * A handoff that is already closed is left as it is and the call answers
	 * false: the first answer stands.
	 *
	 * @param string $handoffId The handoff's uuid.
	 * @param string $status    `done` (the work answered) or `failed` (it could not be carried out).
	 * @param mixed  $result    What the work returned, kept as it arrived.
	 *
	 * @return bool True when this call closed the handoff.
	 *
	 * @throws InvalidArgumentException When the status is not one a close may set.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
	 */
	public function close(string $handoffId, string $status, mixed $result): bool {
		if (in_array($status, self::CLOSE_STATUSES, true) === false) {
			throw new InvalidArgumentException(sprintf('A handoff cannot be closed as "%s".', $status));
		}

		$handoff = $this->waiting(handoffId: $handoffId);
		if ($handoff === null) {
			return false;
		}

		$handoff['status'] = $status;
		$handoff['result'] = $this->asObject(result: $result);
		$handoff['closedAt'] = $this->now()->format(DateTimeInterface::ATOM);
		$this->store(handoffId: $handoffId, handoff: $handoff);
		$this->queueDelivery(handoffId: $handoffId);

		return true;
	}//end close()

	/**
	 * The result in the shape the schema stores: a JSON object.
	 *
	 * A list or a single value is kept under `value`, so it is stored as it
	 * arrived instead of being refused by the object type.
	 *
	 * @param mixed $result What the work returned.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
	 */
	private function asObject(mixed $result): ?array {
		if ($result === null) {
			return null;
		}

		if (is_array($result) === true && ($result === [] || array_is_list($result) === false)) {
			return $result;
		}

		return ['value' => $result];
	}//end asObject()

	/**
	 * A stored handoff in any state, or null when it does not exist.
	 *
	 * @param string $handoffId The handoff's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
	 */
	public function find(string $handoffId): ?array {
		if ($handoffId === '') {
			return null;
		}

		$object = $this->objectService->find(
			id: $handoffId,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			_rbac: false,
			_multitenancy: false
		);
		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		return $object->getObject();
	}//end find()

	/**
	 * The id of the waiting handoff of this kind whose work has this external reference, or null.
	 *
	 * @param string $kind        `flow`, `webhook` or `a2a`.
	 * @param string $externalRef The flow run uuid or remote task id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
	 */
	public function waitingFor(string $kind, string $externalRef): ?string {
		if ($externalRef === '') {
			return null;
		}

		$filters = ['kind' => $kind, 'externalRef' => $externalRef, 'status' => self::WAITING];
		$found   = $this->objectService->setRegister(self::REGISTER_SLUG)->setSchema(self::SCHEMA_SLUG)->findAll(
			config: ['filters' => $filters, 'limit' => 10],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($found as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $object->getObject();
			if (array_intersect_assoc(array_map('strval', array_intersect_key($data, $filters)), $filters) === $filters) {
				return (string) $object->getUuid();
			}
		}

		return null;
	}//end waitingFor()

	/**
	 * Expire a waiting handoff whose deadline has passed.
	 *
	 * @param string $handoffId The handoff's uuid.
	 *
	 * @return bool True when this call expired the handoff.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
	 */
	public function expireIfDue(string $handoffId): bool {
		$handoff = $this->waiting(handoffId: $handoffId);
		if ($handoff === null) {
			return false;
		}

		$now = $this->now();
		$deadline = $this->deadlineOf(handoff: $handoff);
		if ($deadline !== null && $deadline > $now) {
			return false;
		}

		$handoff['status'] = 'expired';
		$handoff['closedAt'] = $now->format(DateTimeInterface::ATOM);
		$this->store(handoffId: $handoffId, handoff: $handoff);
		$this->queueDelivery(handoffId: $handoffId);

		return true;
	}//end expireIfDue()

	/**
	 * Queue the delivery of a handoff this class just closed or expired.
	 *
	 * @param string $handoffId The handoff's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-finished-handoff-returns-its-result-as-a-new-turn-in-the-same-conversation-req-handoff-002
	 */
	private function queueDelivery(string $handoffId): void {
		$this->jobList->add(HandoffResultJob::class, ['handoffId' => $handoffId]);
	}//end queueDelivery()

	/**
	 * A stored handoff's deadline, or null when it has none that reads as a date.
	 *
	 * A handoff without a readable deadline counts as past it: it can expire,
	 * so it never waits forever.
	 *
	 * @param array<string, mixed> $handoff The stored handoff.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function deadlineOf(array $handoff): ?DateTimeImmutable {
		$deadline = (string) ($handoff['deadline'] ?? '');
		if ($deadline === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($deadline);
		} catch (\Exception $e) {
			return null;
		}
	}//end deadlineOf()

	/**
	 * The deadline in hours: the default when none or nonsense is asked, never more than the cap.
	 *
	 * @param mixed $requested The hours the caller asked for.
	 *
	 * @return int
	 */
	private function deadlineHours(mixed $requested): int {
		if (is_int($requested) === false || $requested < 1) {
			return self::DEFAULT_DEADLINE_HOURS;
		}

		return min($requested, self::MAX_DEADLINE_HOURS);
	}//end deadlineHours()

	/**
	 * A handoff that is still waiting, or null when it is gone or already closed.
	 *
	 * @param string $handoffId The handoff's uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function waiting(string $handoffId): ?array {
		if ($handoffId === '') {
			return null;
		}

		$object = $this->objectService->find(
			id: $handoffId,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			_rbac: false,
			_multitenancy: false
		);
		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		$handoff = $object->getObject();
		if (($handoff['status'] ?? null) !== self::WAITING) {
			$this->logger->info(
				'[HandoffService] A handoff that is no longer waiting was left as it is',
				['handoffId' => $handoffId, 'status' => (string) ($handoff['status'] ?? '')]
			);
			return null;
		}

		return $handoff;
	}//end waiting()

	/**
	 * Save a handoff with every field carried forward.
	 *
	 * @param string               $handoffId The handoff's uuid.
	 * @param array<string, mixed> $handoff   The whole handoff.
	 *
	 * @return void
	 */
	private function store(string $handoffId, array $handoff): void {
		$this->objectService->saveObject(
			object: $handoff,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			uuid: $handoffId,
			_rbac: false,
			_multitenancy: false
		);
	}//end store()

	/**
	 * The stored handoff as an array with its id.
	 *
	 * @param ObjectEntity $object The saved object.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(ObjectEntity $object): array {
		$data = $object->getObject();
		$data['id'] = (string) $object->getUuid();

		return $data;
	}//end toArray()

	/**
	 * Now, from the injected clock.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable($this->timeFactory->now()->format(DateTimeInterface::ATOM));
	}//end now()
}//end class
