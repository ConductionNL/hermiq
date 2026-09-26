<?php

/**
 * Every declared route must survive registration.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec exclude Mechanical invariant of appinfo/routes.php, not a product requirement.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud names a route after its controller, its action and its `postfix`,
 * and after nothing else. `OC\AppFramework\Routing\RouteParser::processRoute()`
 * builds `strtolower($appName . '.' . $controller . '.' . $action . $postfix)`,
 * and `RouteCollection::add()` OVERWRITES an entry that already carries that
 * name.
 *
 * Neither the URL nor the verb is part of the name. So two entries that point
 * at the same controller action and declare no `postfix` are one route, and the
 * last one declared is the one that survives. Nothing warns, and `routes.php`
 * still reads as though both are there.
 *
 * 🔴 opencatalogi lost 27 of its 175 routes that way, all of them CORS
 * preflights, and found out from a live instance rather than from CI. This app
 * is clean today: measured with Nextcloud's own `RouteParser` over this file,
 * declared=187 registered=187 lost=0. This test is what keeps it that way.
 *
 * 🔑 No other test in this repo can see a lost route. Unit tests call the
 * controller action directly, and an action with a full test suite and no
 * reachable route answers exactly like one that works. So the assertion below
 * is on the registration KEY of each entry, not on the file parsing or on the
 * array being non-empty.
 *
 * Nine entries here already carry a `postfix` for exactly this reason: the
 * `legacy` conversation aliases and the `update` alias all reuse an action
 * another entry already names. Delete any one of those `postfix` keys and this
 * test names the pair.
 */
class RouteNameUniquenessTest extends TestCase {

	/**
	 * Read the declared route entries.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The route file.
	 */
	private function routeFile(): array {
		$routes = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$this->assertIsArray($routes, 'appinfo/routes.php must return an array');

		return $routes;
	}

	/**
	 * The key Nextcloud registers a route under, minus the app name.
	 *
	 * @param array<string, mixed> $route One entry from the route file.
	 *
	 * @return string The registration key.
	 */
	private function registrationKey(array $route): string {
		return strtolower($route['name'] . ($route['postfix'] ?? ''));
	}

	/**
	 * No two entries may register under the same key.
	 *
	 * @return void
	 */
	public function testEveryDeclaredRouteRegistersUnderItsOwnName(): void {
		$file = $this->routeFile();

		foreach (['routes', 'ocs'] as $section) {
			$seen = [];

			foreach (($file[$section] ?? []) as $entry) {
				$key = $this->registrationKey($entry);

				$this->assertArrayNotHasKey(
					$key,
					$seen,
					sprintf(
						"Two '%s' entries register as '%s', so Nextcloud keeps only the last one.\n"
						. "  kept:        %s %s\n"
						. "  OVERWRITTEN: %s %s\n"
						. "Give each entry its own 'postfix'.",
						$section,
						$key,
						$entry['verb'] ?? 'GET',
						$entry['url'] ?? '?',
						$seen[$key]['verb'] ?? 'GET',
						$seen[$key]['url'] ?? '?'
					)
				);

				$seen[$key] = $entry;
			}
		}

	}//end testEveryDeclaredRouteRegistersUnderItsOwnName()

	/**
	 * Every entry that registers is one Nextcloud can actually name.
	 *
	 * An entry whose `name` is not `controller#action` makes
	 * `RouteParser::processRoute()` throw `UnexpectedValueException` and takes
	 * the whole route file down with it, so the app loses every route at once.
	 *
	 * @return void
	 */
	public function testEveryEntryCarriesAControllerHashActionName(): void {
		$file = $this->routeFile();

		foreach (['routes', 'ocs'] as $section) {
			foreach (($file[$section] ?? []) as $entry) {
				$this->assertCount(
					2,
					explode('#', (string) ($entry['name'] ?? ''), 3),
					sprintf(
						"A '%s' entry for %s is named '%s'. RouteParser needs foo#bar and throws on anything else, which loses the whole file.",
						$section,
						$entry['url'] ?? '?',
						$entry['name'] ?? ''
					)
				);
			}
		}

	}//end testEveryEntryCarriesAControllerHashActionName()

}//end class
