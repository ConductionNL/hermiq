<?php

/**
 * Hermiq: the audit event for adding or removing an approval waiver.
 *
 * Waiving approval (a grant ending in `#noapproval`) is the one grant edit that
 * removes a human from the loop, and it is invisible in a diff of the tool list:
 * `hermiq.sendMail` and `hermiq.sendMail#noapproval` are one string apart. A
 * distinct action token makes "when did anyone turn off approval, and who"
 * answerable without re-parsing every historical grant list. Both directions are
 * recorded, so a trail can show that a waiver was temporary.
 *
 * Two paths write an agent's `tools`: Hermiq's tool-grants endpoint, and
 * OpenRegister's generic objects API (the owner or an admin). The endpoint
 * records its own write and claims the agent for the duration, so the object
 * event of that same write is not recorded twice; every other write is recorded
 * from the object event.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
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
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Capability\ToolGrantSet;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the waiver audit event, once per write.
 *
 * @psalm-api
 *
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
 */
class GrantWaiverAudit {

	/**
	 * AuditTrail action recorded when an approval waiver is added or removed.
	 *
	 * @var string
	 */
	public const ACTION = 'tool-grant-approval-waiver';

	/**
	 * The actor recorded for a write without a signed-in user (cron, occ).
	 *
	 * @var string
	 */
	public const SYSTEM_ACTOR = 'system';

	/**
	 * Agent uuids whose write the caller records itself.
	 *
	 * @var array<string, true>
	 */
	private array $claimed = [];

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper OpenRegister's audit write.
	 * @param IUserSession $userSession Resolves the acting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The grant entries in a stored `tools` value that carry a `#noapproval` fragment.
	 *
	 * Read through ToolGrantSet, so both stored shapes (a list, or the app,
	 * subject, action structure) are seen. Iterating a structure directly would
	 * yield app names rather than grants, and every waiver would go unseen.
	 *
	 * @param mixed $tools The stored `tools` value.
	 *
	 * @return array<int, string> The waived entries, verbatim.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	public function waivers(mixed $tools): array {
		if (is_array($tools) === false) {
			return [];
		}

		$waived = [];
		foreach (ToolGrantSet::fromStored(stored: $tools)->toGrantStrings() as $grant) {
			if (str_ends_with($grant, ToolGrantResolver::WAIVER_FRAGMENT) === true) {
				$waived[] = $grant;
			}
		}

		return $waived;
	}//end waivers()

	/**
	 * Record the waivers an agent's write added or removed. Writes nothing when none moved.
	 *
	 * Never throws: an audit write failing must not turn a successful write into
	 * an error. The failure is logged with the agent it concerns.
	 *
	 * @param ObjectEntity $agent The agent as stored after the write.
	 * @param array<int, string> $before The waived entries before the write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	public function record(ObjectEntity $agent, array $before): void {
		$after = $this->waivers(tools: ($agent->getObject()['tools'] ?? []));
		$added = array_values(array_diff($after, $before));
		$removed = array_values(array_diff($before, $after));

		if ($added === [] && $removed === []) {
			return;
		}

		try {
			$this->auditTrailMapper->createAuditTrailEntry(
				object: $agent,
				action: self::ACTION,
				context: [
					'added' => $added,
					'removed' => $removed,
					'actor' => $this->actor(),
					'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not write approval-waiver audit for ' . ((string)$agent->getUuid()) . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end record()

	/**
	 * Mark an agent's write as recorded by the caller, until released.
	 *
	 * @param string $agentId The agent uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	public function claim(string $agentId): void {
		$this->claimed[$agentId] = true;
	}//end claim()

	/**
	 * Release a claim made with claim().
	 *
	 * @param string $agentId The agent uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	public function release(string $agentId): void {
		unset($this->claimed[$agentId]);
	}//end release()

	/**
	 * Whether the caller records this agent's current write itself.
	 *
	 * @param string $agentId The agent uuid.
	 *
	 * @return bool True while claimed.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	public function isClaimed(string $agentId): bool {
		return isset($this->claimed[$agentId]);
	}//end isClaimed()

	/**
	 * The acting user's id, or the system actor when nobody is signed in.
	 *
	 * @return string The actor.
	 */
	private function actor(): string {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return self::SYSTEM_ACTOR;
		}

		return $uid;
	}//end actor()
}//end class
