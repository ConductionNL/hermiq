<?php

/**
 * Hermiq AppIdentity
 *
 * The app id as one constant, so components name the Kernel instead of the
 * AppInfo bootstrap.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel
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
 * @spec exclude Structural constant holder, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel;

/**
 * The app id as one constant.
 *
 * @spec exclude Structural constant holder, no functional requirement.
 */
final class AppIdentity {

	/**
	 * The Nextcloud app id.
	 *
	 * @var string
	 */
	public const APP_ID = 'hermiq';
}//end class
