<?php

/**
 * Unit tests for GitHubRepoTarget: name validation, the API path and the
 * repository URL.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubRepoTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A GitHub owner and repository a template is pushed to.
 *
 * @covers \OCA\Hermiq\Service\GitHubRepoTarget
 */
class GitHubRepoTargetTest extends TestCase {

	/**
	 * Owner and repository pairs and whether both names are valid.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function names(): array {
		return [
			'plain'             => ['ConductionNL', 'hermiq', true],
			'dots and dashes'   => ['a-b.c_d', 'repo.name-1', true],
			'100 characters'    => [str_repeat('a', 100), 'r', true],
			'101 characters'    => [str_repeat('a', 101), 'r', false],
			'empty owner'       => ['', 'hermiq', false],
			'empty repo'        => ['ConductionNL', '', false],
			'slash in repo'     => ['ConductionNL', 'a/b', false],
			'space in owner'    => ['Conduction NL', 'hermiq', false],
			'path traversal'    => ['..%2F', 'hermiq', false],
		];

	}//end names()

	/**
	 * Both names must be 1 to 100 letters, digits, dots, dashes or underscores.
	 *
	 * @param string $owner The owner.
	 * @param string $repo  The repository.
	 * @param bool   $valid Whether both are valid.
	 *
	 * @return void
	 */
	#[DataProvider('names')]
	public function testNamesAreValidated(string $owner, string $repo, bool $valid): void {
		self::assertSame($valid, (new GitHubRepoTarget(owner: $owner, repo: $repo))->hasValidNames());

	}//end testNamesAreValidated()

	/**
	 * The API path and URL name the owner and repository.
	 *
	 * @return void
	 */
	public function testTheApiPathAndUrl(): void {
		$target = new GitHubRepoTarget(owner: 'ConductionNL', repo: 'hermiq');

		self::assertSame('/repos/ConductionNL/hermiq', $target->apiPath());
		self::assertSame('https://github.com/ConductionNL/hermiq', $target->defaultUrl());

	}//end testTheApiPathAndUrl()

	/**
	 * The API path encodes each segment, so a name cannot add path segments.
	 *
	 * @return void
	 */
	public function testTheApiPathEncodesEachName(): void {
		self::assertSame('/repos/a%2Fb/c%20d', (new GitHubRepoTarget(owner: 'a/b', repo: 'c d'))->apiPath());

	}//end testTheApiPathEncodesEachName()
}//end class
