<?php

/**
 * Hermiq Action Matrix Controller.
 *
 * The administrator's read and write surface over the ADR-023 action matrix: which
 * groups may run each governance action. Admin-only by default: no method carries
 * `#[NoAdminRequired]`, so Nextcloud's middleware refuses anyone else before a method
 * runs. Editing who may do what stays with administrators; a group that could edit the
 * matrix could grant itself any action.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md#requirement-the-matrix-endpoints-are-administrator-only-and-validate-what-they-store-req-actmx-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ActionMatrixInterface;
use OCA\Hermiq\Service\ActionSeed;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Lists and changes the groups allowed per governance action.
 *
 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md#requirement-the-matrix-endpoints-are-administrator-only-and-validate-what-they-store-req-actmx-002
 */
class ActionMatrixController extends Controller {
	/**
	 * The group that always keeps every action.
	 */
	public const ADMIN = 'admin';

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request      The request.
	 * @param ActionMatrixInterface $matrix       The stored matrix.
	 * @param ActionSeed            $seed         The shipped defaults and labels.
	 * @param IGroupManager         $groupManager Which groups exist.
	 * @param IL10N                 $l10n         Translates the labels.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ActionMatrixInterface $matrix,
		private readonly ActionSeed $seed,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every action the seed or the stored matrix knows, with its label, its groups and whether it differs from the seed,
	 * and the groups that exist.
	 *
	 * @return JSONResponse `{actions: list<row>, groups: list<string>}`.
	 *
	 * @auth admin-only The action matrix decides who may run every governance action; only Nextcloud administrators see it.
	 *
	 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md#requirement-an-administrator-edits-the-action-matrix-on-the-tenant-management-page-req-actmx-001
	 */
	public function index(): JSONResponse {
		$stored = $this->matrix->getMatrix();
		$seeded = $this->seed->actions();
		$rows = [];
		foreach (array_unique([...array_keys($seeded), ...array_keys($stored)]) as $action) {
			$rows[] = $this->row(action: (string)$action, stored: $stored, seeded: $seeded);
		}

		return new JSONResponse(['actions' => $rows, 'groups' => $this->groupIds()]);
	}//end index()

	/**
	 * Replace the groups of one action. `admin` is always kept; saving the seeded groups is the reset.
	 *
	 * @param string $action The action.
	 *
	 * @return JSONResponse The stored row; 404 for an unknown action; 400 for a group that does not exist.
	 *
	 * @auth admin-only A group that could edit the matrix could grant itself any action.
	 *
	 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md#requirement-the-matrix-endpoints-are-administrator-only-and-validate-what-they-store-req-actmx-002
	 */
	public function update(string $action): JSONResponse {
		$stored = $this->matrix->getMatrix();
		$seeded = $this->seed->actions();
		if (array_key_exists($action, $stored) === false && array_key_exists($action, $seeded) === false) {
			return new JSONResponse(['message' => sprintf('Unknown action "%s".', $action)], Http::STATUS_NOT_FOUND);
		}

		$groups = $this->request->getParam('groups', []);
		if (is_array($groups) === false) {
			return new JSONResponse(['message' => 'groups must be a list of group ids.'], Http::STATUS_BAD_REQUEST);
		}

		$clean = [self::ADMIN];
		foreach ($groups as $group) {
			if (is_string($group) === false || $group === '') {
				return new JSONResponse(['message' => 'groups must be a list of group ids.'], Http::STATUS_BAD_REQUEST);
			}

			if ($group !== self::ADMIN && $this->groupManager->groupExists($group) === false) {
				return new JSONResponse(['message' => sprintf('Group "%s" does not exist.', $group), 'group' => $group], Http::STATUS_BAD_REQUEST);
			}

			$clean[] = $group;
		}

		// Stored on top of the seed, so an action that was never stored keeps
		// its neighbours' defaults rather than losing them.
		$stored = array_merge($seeded, $stored);
		$stored[$action] = array_values(array_unique($clean));
		$this->matrix->setMatrix($stored);

		return new JSONResponse($this->row(action: $action, stored: $this->matrix->getMatrix(), seeded: $seeded));
	}//end update()

	/**
	 * One action as the screen lists it.
	 *
	 * @param string                      $action The action.
	 * @param array<string, list<string>> $stored The stored matrix.
	 * @param array<string, list<string>> $seeded The seeded matrix.
	 *
	 * @return array{action: string, label: string, description: string, groups: list<string>, seeded: list<string>, changed: bool}
	 */
	private function row(string $action, array $stored, array $seeded): array {
		$label = $this->seed->label(action: $action);
		$groups = array_values($stored[$action] ?? $seeded[$action] ?? [self::ADMIN]);
		$defaults = array_values($seeded[$action] ?? [self::ADMIN]);
		$description = '';
		if ($label['description'] !== '') {
			$description = $this->l10n->t($label['description']);
		}

		return [
			'action' => $action,
			'label' => $this->l10n->t($label['label']),
			'description' => $description,
			'groups' => $groups,
			'seeded' => $defaults,
			'changed' => self::sorted(groups: $groups) !== self::sorted(groups: $defaults),
		];
	}//end row()

	/**
	 * The groups sorted, for comparison.
	 *
	 * @param list<string> $groups The groups.
	 *
	 * @return list<string>
	 */
	private static function sorted(array $groups): array {
		$groups = array_values(array_unique($groups));
		sort($groups);

		return $groups;
	}//end sorted()

	/**
	 * The id of every group on the instance, sorted.
	 *
	 * @return list<string>
	 */
	private function groupIds(): array {
		$ids = array_map(static fn (IGroup $group): string => $group->getGID(), $this->groupManager->search(''));
		sort($ids);

		return array_values(array_unique($ids));
	}//end groupIds()
}//end class
