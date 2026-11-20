<?php

/**
 * Hermiq BrowserRefusedException.
 *
 * A browser action the browser service refused: an address the egress policy
 * does not allow, a password field, an unknown element reference.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Browser
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
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Browser;

use RuntimeException;

/**
 * A refusal answered by the browser service, with its machine-readable code.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md
 */
class BrowserRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $refusal The refusal code, e.g. `password_field`.
	 * @param string $message The text the agent reads.
	 */
	public function __construct(public readonly string $refusal, string $message) {
		parent::__construct(message: $message);

	}//end __construct()
}//end class
