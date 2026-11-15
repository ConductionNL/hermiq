<?php

/**
 * Hermiq ConnectionStatus
 *
 * One connection report: the connection, its status and what hermiq observed.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Connection
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
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Connection;

/**
 * A connection report.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
 */
final class ConnectionStatus {

	/**
	 * Build the report.
	 *
	 * @param string $key     The connection key.
	 * @param string $status  The status.
	 * @param string $message What hermiq observed.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $status,
		public readonly string $message,
	) {
	}//end __construct()
}//end class
