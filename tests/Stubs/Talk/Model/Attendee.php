<?php

/**
 * Test stub for OCA\Talk\Model\Attendee (spreed).
 *
 * The attendee entity Hermiq builds for a bot participant when it edits the
 * bot's own message (instant-talk progressive replies). Only the two setters
 * Hermiq calls are declared.
 *
 * @category Stub
 * @package  OCA\Talk\Model
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Talk\Model;

/**
 * Minimal stub of the spreed Attendee entity.
 */
class Attendee {

	/**
	 * The actor type.
	 *
	 * @var string
	 */
	public string $actorType = '';

	/**
	 * The actor id.
	 *
	 * @var string
	 */
	public string $actorId = '';

	/**
	 * Set the actor type.
	 *
	 * @param string $actorType The actor type.
	 *
	 * @return void
	 */
	public function setActorType(string $actorType): void {
		$this->actorType = $actorType;
	}//end setActorType()

	/**
	 * Set the actor id.
	 *
	 * @param string $actorId The actor id.
	 *
	 * @return void
	 */
	public function setActorId(string $actorId): void {
		$this->actorId = $actorId;
	}//end setActorId()
}//end class
