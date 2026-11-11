<?php

/**
 * Hermiq PhoneRedactor.
 *
 * Masks E.164 phone numbers (personal data), keeping the country prefix and
 * the last digits so an operator can still tell numbers apart.
 *
 * Part of the PHP/PCRE port of Hermes' `agent/redact.py` (NousResearch
 * hermes-agent, MIT) that RedactionService runs before every audit write.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Redaction
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
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Redaction;

/**
 * The phone-number pass of the redaction pipeline.
 *
 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
 */
final class PhoneRedactor {

	/**
	 * Mask E.164 phone numbers (+<country><number>).
	 *
	 * Port of redact.py `_SIGNAL_PHONE_RE` + `_redact_phone`.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return string The text with phone numbers masked.
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	public function redact(string $text): string {
		if (str_contains($text, '+') === false) {
			return $text;
		}

		return preg_replace_callback(
			'~(\+[1-9]\d{6,14})(?![A-Za-z0-9])~',
			fn (array $matches): string => $this->maskPhone(phone: $matches[1]),
			$text
		);
	}//end redact()

	/**
	 * Mask one phone number: short numbers keep 2+2 characters, longer ones 4+4.
	 *
	 * @param string $phone The matched number, `+` included.
	 *
	 * @return string The masked number.
	 */
	private function maskPhone(string $phone): string {
		if (strlen($phone) <= 8) {
			return substr($phone, 0, 2) . '****' . substr($phone, -2);
		}

		return substr($phone, 0, 4) . '****' . substr($phone, -4);
	}//end maskPhone()
}//end class
