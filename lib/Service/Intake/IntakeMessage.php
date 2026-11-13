<?php

/**
 * Hermiq IntakeMessage.
 *
 * One message a person sends into their conversation about a subject: who, about
 * what, on which channel, what they wrote and when. Built by IntakeService from its
 * receive() call and never handed out of it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Intake
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
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-starting-by-e-mail-and-continuing-in-the-portal
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Intake;

use DateTimeImmutable;

/**
 * One received intake message.
 *
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-starting-by-e-mail-and-continuing-in-the-portal
 */
final class IntakeMessage {

	/**
	 * Which channel the message arrived on (recorded, decides nothing).
	 *
	 * @var string
	 */
	public string $channel = '';

	/**
	 * What the person wrote.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * When the message arrived.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $at;

	/**
	 * Address the message.
	 *
	 * @param string $person  The person the conversation belongs to.
	 * @param string $subject What it is about, as the owning app names it.
	 */
	public function __construct(
		public readonly string $person,
		public readonly string $subject,
	) {
	}//end __construct()
}//end class
