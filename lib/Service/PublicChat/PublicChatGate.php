<?php

/**
 * Hermiq PublicChatGate.
 *
 * The checks a public chat turn passes before the model is asked: the
 * channel is on, the `public-chat` AI feature is enabled, the organisation's
 * kill switch is not engaged, the agent exists, is switched on and holds no
 * tool grants, and the channel is under its daily question limit.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\PublicChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\PublicChat;

use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\TenantKillSwitch;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lets a public chat turn through, or refuses it.
 *
 * A stopped channel answers "This assistant is not available right now." and
 * never says why, so a visitor learns nothing about the organisation's
 * settings; the reason is logged.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
 */
class PublicChatGate {

	/**
	 * The AI feature every public channel hangs off.
	 */
	public const FEATURE_SLUG = 'public-chat';

	/**
	 * The daily limit of a channel that sets none.
	 */
	public const DEFAULT_DAILY_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param PublicChannelStore $store        Reads the channel's agent.
	 * @param AiFeatureService   $features     Whether `public-chat` is enabled.
	 * @param TenantKillSwitch   $killSwitch   The organisation's kill switch.
	 * @param AgentAvailability  $availability Whether the agent is switched on.
	 * @param ICacheFactory      $caches       Holds the daily question counts.
	 * @param ITimeFactory       $time         Today's date.
	 * @param LoggerInterface    $logger       Records why a turn was refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public function __construct(
		private readonly PublicChannelStore $store,
		private readonly AiFeatureService $features,
		private readonly TenantKillSwitch $killSwitch,
		private readonly AgentAvailability $availability,
		private readonly ICacheFactory $caches,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The channel's agent, when every gate before the model lets the turn through.
	 *
	 * @param array<string, mixed> $channel The channel.
	 *
	 * @return ObjectEntity
	 *
	 * @throws PublicChatRefusedException 503 when the channel, the feature, the kill switch or the agent stops it.
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public function runnableAgent(array $channel): ObjectEntity {
		$agent  = null;
		$reason = $this->channelRefusal(channel: $channel);
		if ($reason === null) {
			$agent  = $this->store->agent(agentId: (string)($channel['agentId'] ?? ''));
			$reason = $this->agentRefusal(agent: $agent);
		}

		if ($reason !== null || $agent === null) {
			$this->logger->info(
				message: '[PublicChatGate] A public chat turn was not answered: ' . ($reason ?? 'no agent'),
				context: ['file' => __FILE__, 'line' => __LINE__, 'channel' => (string)($channel['id'] ?? '')]
			);
			throw new PublicChatRefusedException();
		}

		return $agent;
	}//end runnableAgent()

	/**
	 * Count one question against the channel's daily limit.
	 *
	 * @param array<string, mixed> $channel The channel.
	 *
	 * @return void
	 *
	 * @throws PublicChatRefusedException 429 when the limit is reached.
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public function countQuestion(array $channel): void {
		$limit = (int)($channel['dailyQuestionLimit'] ?? self::DEFAULT_DAILY_LIMIT);
		if ($limit < 1) {
			$limit = self::DEFAULT_DAILY_LIMIT;
		}

		$cache = $this->counter();
		$key   = 'questions:' . (string)($channel['id'] ?? '') . ':' . $this->time->getDateTime()->format('Ymd');
		$count = (int)($cache?->get($key) ?? 0);
		if ($count >= $limit) {
			throw new PublicChatRefusedException(message: PublicChatRefusedException::LIMIT_REACHED, status: 429);
		}

		$cache?->set($key, ($count + 1), 90000);
	}//end countQuestion()

	/**
	 * Whether an agent holds any tool grant.
	 *
	 * An empty list grants nothing, and `__none__` is the explicit "no tools"
	 * sentinel; anything else is a grant.
	 *
	 * @param ObjectEntity|array<string, mixed>|null $agent The agent.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
	 */
	public function agentHasTools(ObjectEntity|array|null $agent): bool {
		if ($agent === null) {
			return false;
		}

		$data = $agent;
		if ($agent instanceof ObjectEntity) {
			$data = $agent->getObject();
		}

		$grants = array_filter(
			(array)($data['tools'] ?? []),
			static fn (mixed $tool): bool => is_string($tool) === true && trim($tool) !== '' && trim($tool) !== '__none__'
		);

		return $grants !== [];
	}//end agentHasTools()

	/**
	 * Why a channel may not answer, before its agent is read, or null when it may.
	 *
	 * @param array<string, mixed> $channel The channel.
	 *
	 * @return string|null
	 */
	private function channelRefusal(array $channel): ?string {
		if (($channel['enabled'] ?? false) !== true) {
			return 'channel off';
		}

		if ($this->featureEnabled() === false) {
			return 'feature off';
		}

		if ($this->killSwitch->isOrganisationEngaged(organisation: (string)($channel['tenantId'] ?? '')) === true) {
			return 'kill switch';
		}

		return null;
	}//end channelRefusal()

	/**
	 * Why an agent may not answer in public, or null when it may.
	 *
	 * @param ObjectEntity|null $agent The agent.
	 *
	 * @return string|null
	 */
	private function agentRefusal(?ObjectEntity $agent): ?string {
		if ($agent === null) {
			return 'no agent';
		}

		if ($this->availability->isOn(agent: $agent) === false) {
			return 'agent switched off';
		}

		if ($this->agentHasTools(agent: $agent) === true) {
			return 'agent has tools';
		}

		return null;
	}//end agentRefusal()

	/**
	 * Whether the `public-chat` AI feature is enabled.
	 *
	 * @return bool False also when the feature cannot be read.
	 */
	private function featureEnabled(): bool {
		try {
			$feature = $this->features->findBySlugForGate(slug: self::FEATURE_SLUG);
		} catch (Throwable $e) {
			return false;
		}

		return $feature !== null && (string)($feature->getObject()['lifecycle'] ?? '') === AiFeatureService::RESULT_ENABLED;
	}//end featureEnabled()

	/**
	 * The cache the daily counts live in, shared across the instance's servers when it can be.
	 *
	 * @return ICache|null
	 */
	private function counter(): ?ICache {
		try {
			return $this->caches->createDistributed('hermiq-public-chat');
		} catch (Throwable $e) {
			return null;
		}
	}//end counter()
}//end class
