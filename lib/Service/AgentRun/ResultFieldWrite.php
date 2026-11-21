<?php

/**
 * Hermiq ResultFieldWrite
 *
 * The agent's answer and the field on the subject object it is written to.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AgentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AgentRun;

/**
 * The agent's answer and the field on the subject object it is written to.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ResultFieldWrite {

	/**
	 * Constructor.
	 *
	 * @param string $resultField The object field to write.
	 * @param string $value The agent's output.
	 * @param string $subjectUuid The triggering object's UUID.
	 * @param string $subjectRegister The triggering object's register.
	 * @param string $subjectSchema The triggering object's schema.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $resultField,
		public readonly string $value,
		public readonly string $subjectUuid,
		public readonly string $subjectRegister,
		public readonly string $subjectSchema,
	) {
	}//end __construct()
}//end class
