<?php

/**
 * Test stub for OpenRegister FlowRunTerminalEvent.
 *
 * Stands in for OCA\OpenRegister\Event\FlowRunTerminalEvent when OpenRegister
 * is not installed (standalone CI). Mirrors the real event's constructor and
 * accessors exactly (openregister development, lib/Event/FlowRunTerminalEvent.php):
 * the run's public uuid and the terminal status it was persisted with.
 * hermiq's FlowRunTerminalListener routes on the FQN string, so it consumes
 * this stub the same way it would the real class. The real event ships with
 * OpenRegister.
 *
 * @category Test
 * @package  OCA\OpenRegister\Event
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

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * A flow run was persisted in a terminal status.
 */
class FlowRunTerminalEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $runUuid The run's public uuid.
	 * @param string $status  The terminal status it was persisted with.
	 */
	public function __construct(
		private readonly string $runUuid,
		private readonly string $status,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The run's public uuid.
	 *
	 * @return string
	 */
	public function getRunUuid(): string {
		return $this->runUuid;
	}//end getRunUuid()

	/**
	 * The terminal status.
	 *
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()
}//end class
