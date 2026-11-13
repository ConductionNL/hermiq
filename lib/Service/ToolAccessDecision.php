<?php

/**
 * Hermiq ToolAccessDecision
 *
 * Whether a stored tool-access request may be decided now.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Only a pending request is decided, and only for the tool its owner was shown.
 *
 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */
final class ToolAccessDecision {
	/**
	 * Why a request may not be decided now, or null when it may.
	 *
	 * @param array<string, mixed> $stored      The stored request.
	 * @param string               $shownToolId The tool the decision names.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
	 */
	public function conflict(array $stored, string $shownToolId): ?string {
		if (($stored['status'] ?? '') !== 'pending') {
			return 'This request is already decided.';
		}

		if ($shownToolId === '' || $shownToolId !== (string)($stored['toolId'] ?? '')) {
			return 'This request changed since it was shown. Reload it before deciding.';
		}

		return null;
	}//end conflict()
}//end class
