<?php

/**
 * Contract of ConnectionReporter
 *
 * The public surface of ConnectionReporter that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of ConnectionReporter.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConnectionReporterInterface {

	/**
	 * Report a connection's status to integriq now.
	 *
	 * For a settings save or a test an admin ran. Never throws: this runs
	 * beside a request whose own answer is what the caller returns. Without
	 * integriq nothing is sent and nothing is logged, because a missing
	 * optional app is not a fault.
	 *
	 * @param string $key One of {@see self::KEYS}.
	 * @param string $status One of {@see self::STATUSES}.
	 * @param string $message What hermiq observed.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
	 */
	public function report(string $key, string $status, string $message = ''): bool;
}//end interface
