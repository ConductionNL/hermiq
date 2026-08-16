<?php

/**
 * Hermiq manageable-organisation source (OpenRegister-backed)
 *
 * @category Tenant
 * @package  OCA\Hermiq\Tenant
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tenant;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Reads manageable organisations from OpenRegister, and degrades when it is absent.
 *
 * ## Shape (ADR-083 rule 1, optional-capability exception)
 *
 * This class reaches for OpenRegister ONLY after establishing that it is
 * installed, and resolves `OrganisationMapper` from the container rather than
 * declaring it as a constructor dependency. That is deliberate and it is the
 * documented exception to rule 1, not a violation of it: injecting the mapper
 * would make this service — and therefore the start-screen controller that
 * depends on it — unconstructable on an instance without OpenRegister, turning
 * a clean "install OpenRegister" message into a 500.
 *
 * The constructor takes core services only, so the container can always build
 * it. Every failure path answers "no manageable organisation" and logs nothing
 * louder than the caller already does; the kill-switch endpoints re-check
 * authorization server-side regardless, so an empty answer is safe.
 *
 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
 */
final class OpenRegisterManageableOrganisations implements ManageableOrganisations {

	/**
	 * Hard cap on organisations fetched for an instance admin.
	 *
	 * Mirrors the limit the dashboard controller used before this class existed.
	 */
	private const ADMIN_LIMIT = 500;

	/**
	 * The OpenRegister organisation mapper's fully-qualified name.
	 *
	 * A string rather than a `::class` constant on purpose: `::class` on an
	 * absent class is harmless, but naming the type anywhere in a file the start
	 * route can reach is what ADR-083 rule 3 is measuring. This class is not on
	 * that path — the interface breaks the chain — but the string keeps the
	 * dependency lazy in fact as well as in intent.
	 */
	private const ORGANISATION_MAPPER = 'OCA\\OpenRegister\\Db\\OrganisationMapper';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Runtime OpenRegister availability.
	 * @param ContainerInterface $container  Resolves the mapper once availability holds.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * List the organisations the given user may govern.
	 *
	 * @param string $userId  The Nextcloud user id.
	 * @param bool   $isAdmin Whether the user is an instance admin.
	 *
	 * @return array<int, array{id: string, label: string}> Organisation id/label pairs.
	 *
	 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
	 */
	public function forUser(string $userId, bool $isAdmin): array {
		if ($this->appManager->isInstalled('openregister') === false) {
			return [];
		}

		try {
			$mapper = $this->container->get(self::ORGANISATION_MAPPER);

			$organisations = [];
			if ($isAdmin === true) {
				$organisations = $mapper->findAll(limit: self::ADMIN_LIMIT);
			}

			if ($isAdmin === false) {
				$organisations = $mapper->findByUserId($userId);
			}
		} catch (Throwable) {
			// A read failure degrades to "no manageable organisation" — the
			// toggle endpoint remains the real authorization boundary.
			return [];
		}//end try

		$result = [];
		foreach ($organisations as $organisation) {
			if ($isAdmin === false && (string)($organisation->getOwner() ?? '') !== $userId) {
				continue;
			}

			$uuid = (string)$organisation->getUuid();
			$name = (string)($organisation->getName() ?? '');
			if ($name === '') {
				$name = $uuid;
			}

			$result[] = [
				'id' => $uuid,
				'label' => $name,
			];
		}//end foreach

		return $result;
	}//end forUser()
}//end class
