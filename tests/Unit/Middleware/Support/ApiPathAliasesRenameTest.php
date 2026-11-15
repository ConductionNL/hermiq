<?php

/**
 * Unit tests for renamed and edge-case `/api/v1/` aliases of the API routes.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Middleware\Support;

use OCA\Hermiq\Middleware\Support\ApiPathAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The old `/api/conversations` paths succeed to `/api/v1/sessions`, an alias the
 * app already declares is not added twice, and the API fallback is recognised by
 * whether its requirement spans slashes.
 *
 * @covers \OCA\Hermiq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Hermiq\Middleware\Support\StandardProtocolPaths
 */
class ApiPathAliasesRenameTest extends TestCase {

	/**
	 * Renamed paths and their successor.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function renamedPaths(): array {
		return [
			'collection'    => ['/api/conversations', '/api/v1/sessions'],
			'item'          => ['/api/conversations/42', '/api/v1/sessions/42'],
			'messages'      => ['/api/conversations/42/messages', '/api/v1/sessions/42/messages'],
			'restore'       => ['/api/conversations/42/restore', '/api/v1/sessions/42/restore'],
			'permanent'     => ['/api/conversations/42/permanent', '/api/v1/sessions/42/permanent'],
			'other sub'     => ['/api/conversations/42/share', '/api/v1/conversations/42/share'],
			'lookalike'     => ['/api/conversationsx', '/api/v1/conversationsx'],
			'request path'  => ['/index.php/apps/hermiq/api/conversations/7', '/index.php/apps/hermiq/api/v1/sessions/7'],
		];
	}//end renamedPaths()

	/**
	 * Each renamed path succeeds to its new name under `/api/v1/`; others keep their name.
	 *
	 * @param string $path      The deprecated path.
	 * @param string $successor The expected successor.
	 *
	 * @return void
	 */
	#[DataProvider('renamedPaths')]
	public function testRenamedPathsSucceedToTheirNewName(string $path, string $successor): void {
		$this->assertSame(expected: $successor, actual: ApiPathAliases::successorOf(path: $path));
	}//end testRenamedPathsSucceedToTheirNewName()

	/**
	 * A renamed route gets its alias under the new name, and an alias the routes
	 * already declare (same verb and url) is not added again.
	 *
	 * @return void
	 */
	public function testAnAlreadyDeclaredAliasIsNotAddedTwice(): void {
		$routes = [
			'routes' => [
				['name' => 'sessions#index', 'url' => '/api/v1/sessions', 'verb' => 'GET'],
				['name' => 'conversations#index', 'url' => '/api/conversations', 'verb' => 'GET'],
				['name' => 'conversations#create', 'url' => '/api/conversations', 'verb' => 'post'],
			],
		];

		$declared = ApiPathAliases::withVersionedAliases(routes: $routes);

		$this->assertSame(
			expected: [
				$routes['routes'][0],
				['name' => 'conversations#create', 'url' => '/api/v1/sessions', 'verb' => 'post', 'postfix' => 'v1'],
				$routes['routes'][1],
				$routes['routes'][2],
			],
			actual: $declared['routes']
		);
	}//end testAnAlreadyDeclaredAliasIsNotAddedTwice()

	/**
	 * Without versioned routes the aliases come first; other keys are kept and a
	 * missing route list yields an empty one.
	 *
	 * @return void
	 */
	public function testAliasesWithoutDeclaredVersionedRoutes(): void {
		$routes = [
			'routes' => [
				['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
				['name' => 'things#index', 'url' => 'api/things'],
			],
			'ocs'    => [['name' => 'x#y', 'url' => '/x', 'verb' => 'GET']],
		];

		$declared = ApiPathAliases::withVersionedAliases(routes: $routes);

		$this->assertSame(expected: $routes['ocs'], actual: $declared['ocs']);
		$this->assertSame(
			expected: [
				['name' => 'things#index', 'url' => '/api/v1/things', 'postfix' => 'v1'],
				$routes['routes'][0],
				$routes['routes'][1],
			],
			actual: $declared['routes']
		);
		$this->assertSame(expected: ['ocs' => [], 'routes' => []], actual: ApiPathAliases::withVersionedAliases(routes: ['ocs' => []]));
	}//end testAliasesWithoutDeclaredVersionedRoutes()

	/**
	 * Routes and whether they are the catch-all API fallback.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public static function fallbacks(): array {
		return [
			'spanning requirement'   => [['url' => '/api/{path}', 'requirements' => ['path' => '.+']], true],
			'no leading slash'       => [['url' => 'api/{path}', 'requirements' => ['path' => '.*']], true],
			'single segment default' => [['url' => '/api/{path}'], false],
			'other parameter name'   => [['url' => '/api/{rest}', 'requirements' => ['path' => '.+']], false],
			'deeper path'            => [['url' => '/api/x/{path}', 'requirements' => ['path' => '.+']], false],
			'no url'                 => [[], false],
		];
	}//end fallbacks()

	/**
	 * Only a single-parameter `/api/{x}` route whose requirement spans slashes is the fallback.
	 *
	 * @param array<string,mixed> $route    The route.
	 * @param bool                $expected Whether it is the fallback.
	 *
	 * @return void
	 */
	#[DataProvider('fallbacks')]
	public function testIsApiFallback(array $route, bool $expected): void {
		$this->assertSame(expected: $expected, actual: ApiPathAliases::isApiFallback(route: $route));
	}//end testIsApiFallback()

	/**
	 * Paths outside `/api/` are never deprecated and have no successor.
	 *
	 * @return void
	 */
	public function testNonApiPathsAreNotDeprecated(): void {
		$this->assertFalse(condition: ApiPathAliases::isDeprecated(path: '/apps/hermiq/settings'));
		$this->assertFalse(condition: ApiPathAliases::isDeprecated(path: '/apix/things'));
		$this->assertFalse(condition: ApiPathAliases::isDeprecated(path: '/api'));
		$this->assertNull(actual: ApiPathAliases::successorOf(path: '/api/health'));
		$this->assertNull(actual: ApiPathAliases::successorOf(path: '/api/V2/things'));
	}//end testNonApiPathsAreNotDeprecated()
}//end class
