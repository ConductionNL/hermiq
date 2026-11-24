<?php

/**
 * Hermiq AgentFieldChanges.
 *
 * One field of an agent that a pre-save write changes. A guard on
 * OpenRegister's creating and updating events only has to judge the new
 * value: this reads the written object, merges the modified data over it and
 * compares the field with what was stored, so a guard added later does not
 * refuse an agent stored before it for an edit of another field.
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
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;

/**
 * Reads the new value of one agent field on a pending write, when the write changes it.
 *
 * @spec openspec/changes/spec-coverage-built-rows/specs/agent-management-ui/spec.md#REQ-SPCOV-002
 */
class AgentFieldChanges {

	/**
	 * Constructor.
	 *
	 * @param AgentObjectMatcherInterface $agents Tells a hermiq agent object apart.
	 */
	public function __construct(
		private readonly AgentObjectMatcherInterface $agents,
	) {
	}//end __construct()

	/**
	 * The change a write makes to an agent field, or null when there is none to judge.
	 *
	 * Null for another event, a write an earlier guard already refused, an object
	 * that is not an agent, a write without the field, and an unchanged value.
	 *
	 * @param Event  $event Any dispatched event.
	 * @param string $field The agent field.
	 *
	 * @return array{agentUuid: string, value: mixed}|null
	 *
	 * @spec openspec/changes/spec-coverage-built-rows/specs/agent-management-ui/spec.md#REQ-SPCOV-002
	 */
	public function changedValue(Event $event, string $field): ?array {
		if (($event instanceof ObjectUpdatingEvent) === false && ($event instanceof ObjectCreatingEvent) === false) {
			return null;
		}

		if ($event->isPropagationStopped() === true) {
			return null;
		}

		$object = $this->written(event: $event);
		$before = null;
		if ($event instanceof ObjectUpdatingEvent) {
			$before = ($event->getOldObject()?->getObject()[$field] ?? null);
		}

		if ($this->agents->isAgent(object: $object) === false) {
			return null;
		}

		$written = array_merge($object->getObject(), $event->getModifiedData());
		if (array_key_exists($field, $written) === false || $written[$field] === $before) {
			return null;
		}

		return ['agentUuid' => (string)$object->getUuid(), 'value' => $written[$field]];
	}//end changedValue()

	/**
	 * Refuse a pending write with a message for the field, so nothing is saved.
	 *
	 * @param Event  $event   The pending write.
	 * @param string $field   The agent field.
	 * @param string $message What the form shows under the field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/spec-coverage-built-rows/specs/agent-management-ui/spec.md#REQ-SPCOV-002
	 */
	public function refuse(Event $event, string $field, string $message): void {
		if (($event instanceof ObjectUpdatingEvent) === false && ($event instanceof ObjectCreatingEvent) === false) {
			return;
		}

		$event->setErrors([$field => $message]);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * The object a pending write saves: the new object on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pending write.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity
	 */
	private function written(ObjectCreatingEvent|ObjectUpdatingEvent $event): \OCA\OpenRegister\Db\ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end written()
}//end class
