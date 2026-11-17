<?php

/**
 * Contract of ActionAuthService
 *
 * The public surface of ActionAuthService that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;

/**
 * Contract of ActionAuthService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActionAuthServiceInterface {

	/**
	 * Check whether the user may perform the named action (non-throwing).
	 *
	 * @param IUser $user The authenticated user.
	 * @param string $action Dot-separated action name.
	 *
	 * @return bool True if the user may perform the action.
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function can(IUser $user, string $action): bool;

	/**
	 * Require that the user may perform the named action.
	 *
	 * Admin users always pass (break-glass). Non-admins pass only when any
	 * of their groups intersects the matrix entry for the action.
	 *
	 * @param IUser $user The authenticated user.
	 * @param string $action Dot-separated action name (e.g. "item.publish").
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When the user's groups don't match the action's allowed groups.
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	public function requireAction(IUser $user, string $action): void;
}//end interface
