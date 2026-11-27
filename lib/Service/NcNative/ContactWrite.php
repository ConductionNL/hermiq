<?php

/**
 * Hermiq ContactWrite.
 *
 * One contact being saved: the own address book it goes to, its name, the tool's fields and the agent that authors it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use OCP\IAddressBook;

/**
 * One contact save in progress; it never leaves ContactWriteService.
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
final class ContactWrite {

	/**
	 * The tool arguments: email, phone, organization, uid.
	 *
	 * @var array<string, mixed>
	 */
	public array $arguments = [];

	/**
	 * The invoking agent id, for the ADR-088 mark.
	 *
	 * @var string
	 */
	public string $agentId = '';

	/**
	 * Start the save.
	 *
	 * @param IAddressBook $book The user's own address book.
	 * @param string       $name The contact's full name, trimmed.
	 */
	public function __construct(
		public readonly IAddressBook $book,
		public readonly string $name,
	) {
	}//end __construct()
}//end class
