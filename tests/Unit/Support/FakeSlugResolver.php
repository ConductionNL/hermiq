<?php

/**
 * A register-slug resolver bound to a fixed instance state.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Support;

use OCA\OpenRegister\Contract\RegisterSlugResolution;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;

/**
 * Answers as an instance that carries exactly the given register slugs.
 *
 * Hand written rather than `createMock()`, and the reason is the whole point of
 * the tests that use it. What is being checked is the DIFFERENCE between a
 * migrated and an unmigrated instance, and that difference is the resolver's
 * entire behaviour. A mock stubbed to return one slug behaves identically on
 * both, so every test built on one passes whether or not the code under test
 * uses the answer it was given.
 *
 * The candidate list is stated here rather than read from `RegisterSlugAliases`,
 * which lives in openregister's `lib/Support/` and is not published to consumers.
 * Only the register this app actually reads is listed: a double carrying the
 * whole fleet map would be a second copy of OpenRegister's truth, kept in a
 * repository that does not own it, which is what the published resolver exists
 * to prevent.
 */
final class FakeSlugResolver implements RegisterSlugResolverInterface {

	/**
	 * The slug history of the one renamed register hermiq reads.
	 *
	 * Newest first, transcribed from learniq's own repair step
	 * (`scholiq/lib/Repair/RenameRegisterSlug.php`). Note the file name: eight
	 * fleet apps call that step `MigrateRegisterSlug` and learniq is the one that
	 * does not, which is why a sweep searching the single file name once reported
	 * learniq as never migrated.
	 *
	 * @var array<string, list<string>>
	 */
	private const CANDIDATES = ['learniq' => ['learniq', 'scholiq']];

	/**
	 * Constructor.
	 *
	 * @param list<string> $present The register slugs this instance carries.
	 */
	public function __construct(private readonly array $present = []) {
	}//end __construct()

	/**
	 * Resolve against the fixed instance state.
	 *
	 * @param string       $canonical  The canonical register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return RegisterSlugResolution The resolution.
	 */
	public function resolve(string $canonical, array $candidates=[]): RegisterSlugResolution {
		$probe = $candidates;
		if ($probe === []) {
			$probe = (self::CANDIDATES[$canonical] ?? [$canonical]);
		}

		$matched = array_values(
			array_filter($probe, fn (string $slug): bool => in_array($slug, $this->present, true))
		);

		$state = RegisterSlugResolution::RESOLVED;
		if ($matched === []) {
			$state = RegisterSlugResolution::ABSENT;
		} else if (count($matched) > 1) {
			$state = RegisterSlugResolution::AMBIGUOUS;
		}

		return new RegisterSlugResolution(
			canonical: $canonical,
			slug: ($matched[0] ?? null),
			state: $state,
			candidates: $probe,
			matched: $matched
		);
	}//end resolve()

	/**
	 * The slug to read with, or null when this instance carries none of them.
	 *
	 * @param string       $canonical  The canonical register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return string|null The slug, or null.
	 */
	public function slugOrNull(string $canonical, array $candidates=[]): ?string {
		return $this->resolve(canonical: $canonical, candidates: $candidates)->slug;
	}//end slugOrNull()
}//end class
