<?php

/**
 * Hermiq AgentTemperatureGuardListener.
 *
 * Every turn of an agent sends its temperature to the model provider, and
 * providers take 0 to 2. Agents are saved through OpenRegister's objects API
 * by the agent form, so the range is enforced on the pre-save events, where
 * every write path passes. Only a changed temperature is judged.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/spec-coverage-built-rows/specs/agent-management-ui/spec.md#REQ-SPCOV-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Refuses a write that sets an agent's temperature outside 0 to 2.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/spec-coverage-built-rows/specs/agent-management-ui/spec.md#REQ-SPCOV-002
 */
class AgentTemperatureGuardListener implements IEventListener {

	/**
	 * The agent field that holds the temperature.
	 *
	 * @var string
	 */
	public const FIELD = 'temperature';

	/**
	 * The highest temperature a provider takes; the lowest is 0.
	 *
	 * @var float
	 */
	public const MAXIMUM = 2.0;

	/**
	 * The refusal the agent form shows.
	 *
	 * @var string
	 */
	public const OUT_OF_RANGE = 'The temperature must be between 0 and 2.';

	/**
	 * Constructor.
	 *
	 * @param AgentObjectMatcherInterface $agents Tells a hermiq agent object apart.
	 * @param LoggerInterface             $logger Logger.
	 */
	public function __construct(
		private readonly AgentObjectMatcherInterface $agents,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the write when it changes the temperature to something other than a number from 0 to 2.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/spec-coverage-built-rows/specs/agent-management-ui/spec.md#REQ-SPCOV-002
	 */
	public function handle(Event $event): void {
		$change = AgentFieldChange::of(event: $event, agents: $this->agents, field: self::FIELD);
		if ($change === null || self::withinRange(value: $change->value) === true) {
			return;
		}

		$change->refuse(field: self::FIELD, message: self::OUT_OF_RANGE);
		$this->logger->info(
			'[AgentTemperatureGuardListener] Refused a temperature outside 0 to 2 on agent {agent}',
			['agent' => $change->agentUuid]
		);
	}//end handle()

	/**
	 * Whether a temperature may be stored: unset, or a number from 0 to 2.
	 *
	 * @param mixed $value The written value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/spec-coverage-built-rows/specs/agent-management-ui/spec.md#REQ-SPCOV-002
	 */
	public static function withinRange(mixed $value): bool {
		if ($value === null || $value === '') {
			return true;
		}

		$numeric = is_int($value) === true || is_float($value) === true || (is_string($value) === true && is_numeric($value) === true);

		return $numeric === true && (float)$value >= 0.0 && (float)$value <= self::MAXIMUM;
	}//end withinRange()
}//end class
