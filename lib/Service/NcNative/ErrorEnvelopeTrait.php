<?php

/**
 * Hermiq NC-native error envelope helper.
 *
 * Every NC-native write service returns `['error' => ['code', 'message']]` rather
 * than throwing, because `HermiqToolProvider::invokeTool()` must never throw. The
 * shape is shared here so the three services cannot drift apart on it — a caller
 * that special-cases one service's error shape and not another's is a bug waiting
 * for the second service to be used.
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

/**
 * Shared structured-error envelope.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
trait ErrorEnvelopeTrait {

	/**
	 * Build a structured error envelope.
	 *
	 * @param string $code The machine-readable code.
	 * @param string $message The human-readable message.
	 *
	 * @return array<string, mixed> The error envelope.
	 */
	private function err(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];

	}//end err()

}//end trait
