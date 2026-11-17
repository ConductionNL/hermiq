<?php

/**
 * Action Authorization Service
 *
 * Implements the ADR-023 action-level authorization pattern: each controller
 * method declares an action name (e.g. "item.publish") and delegates the
 * authorization decision to this service, which resolves the action against
 * an admin-configured matrix stored in IAppConfig.
 *
 * This service is the canonical place to enforce action RBAC. Per ADR-023:
 *   - Data RBAC (who can read/write which objects) is OpenRegister's job.
 *   - Action RBAC (who can invoke which controller method) is this service.
 *   - Admin-only operations (editing the matrix itself, app config, backup/
 *     restore, integrations, credentials) bypass this service and use
 *     #[AuthorizedAdminSetting(AppIdentity::APP_ID)] at the route layer.
 *
 * Controllers call `requireAction` which throws OCSForbiddenException when
 * the caller's groups don't intersect the matrix entry for the action.
 *
 * Admin users always pass. The matrix defaults to ["admin"] for every
 * declared action — first-install safe posture. The admin broadens via
 * the settings UI.
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
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Action-level authorization service.
 *
 * Enforces ADR-023 action RBAC: controllers call requireAction with a
 * dot-separated action name; this service checks the admin-configured
 * action-to-group mapping stored in IAppConfig.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
class ActionAuthService implements ActionAuthServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param ActionMatrix $actionMatrix The stored action matrix
	 * @param IGroupManager $groupManager Group manager for resolving user groups
	 */
	public function __construct(
		private ActionMatrix $actionMatrix,
		private IGroupManager $groupManager,
	) {
	}//end __construct()

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
	public function requireAction(IUser $user, string $action): void {
		// Admin always passes — break-glass for ops / debugging.
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return;
		}

		$allowedGroups = $this->actionMatrix->getAllowedGroups(action: $action);

		// An "admin"-only entry means non-admins never pass (admin already
		// returned above). Empty entry means nobody is allowed.
		if (count($allowedGroups) === 0 || $allowedGroups === ['admin']) {
			throw new OCSForbiddenException(
				"Action '{$action}' requires admin rights"
			);
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);

		// Exclude "admin" from matrix entry before intersection — admin was
		// already checked above; its presence in the entry is a display hint,
		// not a group membership check.
		$nonAdminAllowed = array_values(array_diff($allowedGroups, ['admin']));

		if (count(array_intersect($userGroups, $nonAdminAllowed)) === 0) {
			throw new OCSForbiddenException(
				"Action '{$action}' not allowed for your groups"
			);
		}

	}//end requireAction()

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
	public function can(IUser $user, string $action): bool {
		try {
			$this->requireAction(user: $user, action: $action);
			return true;
		} catch (OCSForbiddenException $e) {
			return false;
		}

	}//end can()

}//end class
