<?php

/**
 * Hermiq TokenMasker.
 *
 * Masks a matched credential for a log line: the first 6 and last 4
 * characters survive, anything shorter than 18 characters is fully masked.
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
 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#1-redaction
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Redaction;

/**
 * Masks one matched token (port of redact.py `_mask_token`).
 *
 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
 */
final class TokenMasker {

	/**
	 * Mask a log token — 18-char floor, preserves 6 prefix / 4 suffix.
	 *
	 * Port of redact.py `_mask_token` (empty → '***', never '').
	 *
	 * @param string $token The token to mask.
	 *
	 * @return string The masked token.
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-1-1
	 */
	public function mask(string $token): string {
		if ($token === '' || strlen($token) < 18) {
			return '***';
		}

		return substr($token, 0, 6) . '...' . substr($token, -4);
	}//end mask()
}//end class
