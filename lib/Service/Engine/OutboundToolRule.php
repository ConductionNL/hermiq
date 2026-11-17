<?php

/**
 * Hermiq: which tools send something on a person's behalf (personal-preferences).
 *
 * A person who switched on "Vragen voordat een agent namens mij mailt of iets
 * verstuurt" is asked before such a tool runs. The rule reads the tool id's
 * own name: a send, reply, forward, post, publish, deliver, notify, share or
 * invite verb, or a mail tool that is not a read. It only ever adds a question,
 * so a tool it misses is never less guarded than before.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * Classifies a tool id as outbound or not.
 *
 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */
final class OutboundToolRule {

	/**
	 * Verbs that send something to someone.
	 *
	 * @var string
	 */
	private const SEND_VERBS = '/^(send|reply|forward|post|publish|deliver|notify|share|invite)/i';

	/**
	 * Verbs that only read.
	 *
	 * @var string
	 */
	private const READ_VERBS = '/^(list|read|search|get|find|count|recall)/i';

	/**
	 * Whether a tool sends something on the person's behalf.
	 *
	 * @param string $toolId The registry id, e.g. `hermiq.sendMail`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public static function isOutbound(string $toolId): bool {
		$segments = explode('.', trim($toolId));
		$name     = (string)end($segments);
		if ($name === '') {
			return false;
		}

		if (preg_match(self::SEND_VERBS, $name) === 1) {
			return true;
		}

		return stripos($name, 'mail') !== false && preg_match(self::READ_VERBS, $name) !== 1;
	}//end isOutbound()

	/**
	 * The outbound tool ids among these.
	 *
	 * @param array<int|string, string> $toolIds Registry ids.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public static function outboundAmong(array $toolIds): array {
		return array_values(array_unique(array_filter($toolIds, static fn (string $id): bool => self::isOutbound(toolId: $id))));
	}//end outboundAmong()
}//end class
