<?php

/**
 * No code under lib/ pins a superseded register slug.
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

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The case that has never once been caught here.
 *
 * A consumer pinned to a superseded register slug on a MIGRATED instance does
 * not raise. `openregister_registers` has no row with that slug, so the read
 * matches nothing and returns an empty result set, which is byte-for-byte what a
 * healthy, empty register returns. There is no exception, no 404, no log line
 * separating the two. Every other guard in this repository watches behaviour,
 * and this defect has no behaviour to watch: it is a feature that quietly stops
 * happening. For a recommendation engine that means it recommends nothing and
 * reports success.
 *
 * So the guard is static, and it is repo-wide rather than diff-scoped. Diff
 * scope is right for debt a PR could reasonably be asked to carry; it is wrong
 * here, because every one of these references was written BEFORE the slug was
 * renamed and will therefore never appear in a diff. A diff-scoped version of
 * this test passes on a repository full of the defect.
 *
 * ## What it does NOT catch
 *
 * It reads lines, not data flow. A superseded slug arriving from app config,
 * from a manifest, or through more than one assignment is invisible to it, as is
 * a `match` arm built at run time. That is why
 * {@see \OCA\Hermiq\Tests\Unit\Service\CourseRegisterResolutionTest} exists
 * beside it: this guard stops the literal being TYPED, and that one stops the
 * resolved slug being IGNORED.
 *
 * Measured on the mutation that reinstated `scholiq` in `LearnerSignalReader`:
 * the unmigrated-instance test still passed,
 * because on an unmigrated instance the pinned literal happens to be the right
 * answer. Only the migrated case failed, and only the migrated case has ever
 * mattered.
 */
class RegisterSlugPinTest extends TestCase {

	/**
	 * Superseded register slug => the canonical slug replacing it.
	 *
	 * Only the register this app actually reads. openregister owns the full fleet
	 * map in `lib/Support/RegisterSlugAliases.php`, which is not published to
	 * consumers, and copying all ten here would put a second copy of that truth in
	 * a repository that does not own it. What this guard needs is narrower anyway:
	 * the slugs THIS app could plausibly type.
	 *
	 * @var array<string, string>
	 */
	private const SUPERSEDED = ['scholiq' => 'learniq'];

	/**
	 * Files allowed to name a superseded slug, and why.
	 *
	 * Each entry must be a genuine exception, a file that exists in order to name
	 * the old name, rather than a deferral. Anything else belongs in a resolver
	 * call.
	 *
	 * `FleetAppId` is the standing example, and it is the distinction this whole
	 * mechanism turns on: it maps APP IDS, whose source of truth is
	 * `IAppManager`, not register slugs, whose source of truth is
	 * `openregister_registers`. Two different repair steps move them and either
	 * can run first, so one cannot be used to predict the other.
	 *
	 * Measured, so that the next reader is not misled: at the time of writing
	 * `FleetAppId` trips none of the patterns below, because its `scholiq` sits in
	 * an app-id map and in prose rather than in register position. The entry is
	 * kept because the file is the one place in this repo where naming the old
	 * name is correct, and a future edit could easily put it in a shape a pattern
	 * does match.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = [
		'lib/Support/FleetAppId.php' => 'app ids, not register slugs; a different question with a different source of truth',
	];

	/**
	 * Source patterns that put a string literal in REGISTER position.
	 *
	 * Deliberately narrow. A slug is only a defect where it identifies a
	 * register; the same word in a log message, a skip reason, an app id or the
	 * frozen `sourceApp` stamp is not this defect, and a guard that flagged those
	 * would be turned off.
	 *
	 * @var list<string>
	 */
	private const REGISTER_POSITION = [
		'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/',
		'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
	];

	/**
	 * No file under lib/ names a superseded register slug in register position.
	 *
	 * @return void
	 */
	public function testNoSourceFilePinsASupersededRegisterSlug(): void {
		$findings = [];
		foreach ($this->sourceFiles() as $relative => $absolute) {
			if (isset(self::ALLOWED[$relative]) === true) {
				continue;
			}

			// NOT FILE_SKIP_EMPTY_LINES. Skipping blank lines renumbers every
			// line after the first one, so `$index + 1` stops being the line
			// number and becomes the count of non-blank lines. Measured on
			// openregister's reconciler: a pin on line 590 was reported as line
			// 528, because 62 blank lines preceded it. A guard that names the
			// wrong line is a guard whose next reader concludes it is broken.
			$lines = file($absolute, FILE_IGNORE_NEW_LINES);
			if ($lines === false) {
				continue;
			}

			foreach ($lines as $index => $line) {
				foreach (self::REGISTER_POSITION as $pattern) {
					if (preg_match($pattern, $line, $matches) !== 1) {
						continue;
					}

					$slug = strtolower($matches[1]);
					if (isset(self::SUPERSEDED[$slug]) === false) {
						continue;
					}

					$findings[] = sprintf(
						'%s:%d pins the superseded register slug \'%s\'. Resolve \'%s\' through '
						. 'RegisterSlugResolverInterface::resolve() instead, and branch on isResolved(), '
						. 'because reading with a slug this instance does not carry returns zero rows, '
						. 'not an error.',
						$relative,
						($index + 1),
						$slug,
						self::SUPERSEDED[$slug]
					);
				}
			}
		}

		$this->assertSame([], $findings, "Superseded register slugs are pinned:\n" . implode("\n", $findings));
	}//end testNoSourceFilePinsASupersededRegisterSlug()

	/**
	 * The guard actually looks at something.
	 *
	 * A file walker that silently finds no files is the classic hollow green: the
	 * assertion above would pass on an empty list forever. This pins the walker to
	 * a floor well below the real count, so a broken path fails here rather than
	 * passing there. It also checks that every allowed path is a real file, so an
	 * exemption cannot outlive the file it exempts.
	 *
	 * @return void
	 */
	public function testTheGuardScansTheSourceTree(): void {
		$files = $this->sourceFiles();

		$this->assertGreaterThan(100, count($files), 'The walker must see lib/, or the guard above cannot fail.');
		$this->assertArrayHasKey(
			'lib/Service/LearnerSignalReader.php',
			$files,
			'The signal reader is the file this guard was written for; the walker must reach it.'
		);

		foreach (array_keys(self::ALLOWED) as $allowed) {
			$this->assertArrayHasKey(
				$allowed,
				$files,
				'An exemption must name a file that exists: ' . $allowed
			);
		}
	}//end testTheGuardScansTheSourceTree()

	/**
	 * The patterns match a pinned slug when one is present.
	 *
	 * Watched failing is not enough on its own once the tree is clean: from then
	 * on the guard passes whether or not its regexes still work. This feeds each
	 * register-position form a known-bad line and requires a match, so a regex
	 * that stops matching reddens immediately instead of going quiet.
	 *
	 * @return void
	 */
	public function testEachRegisterPositionPatternStillMatches(): void {
		$samples = [
			'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/' => "\$objectService->setRegister('scholiq');",
			'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/'                    => "\$svc->readSignal(register: 'scholiq', schema: 'course');",
			'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/'              => "'filters' => ['register' => 'scholiq', 'schema' => 'course'],",
			'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\tprivate const SCHOLIQ_REGISTER = 'scholiq';",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$registerSlug = 'scholiq';",
		];

		foreach (self::REGISTER_POSITION as $pattern) {
			$this->assertArrayHasKey($pattern, $samples, 'Every register-position pattern needs a known-bad sample.');
			$this->assertSame(
				1,
				preg_match($pattern, $samples[$pattern], $matches),
				'Pattern must match its known-bad sample: ' . $pattern
			);
			$this->assertArrayHasKey(
				strtolower($matches[1]),
				self::SUPERSEDED,
				'The sample must capture a slug this guard calls superseded: ' . $pattern
			);
		}
	}//end testEachRegisterPositionPatternStillMatches()

	/**
	 * Every PHP file under lib/, keyed by repository-relative path.
	 *
	 * @return array<string, string> Relative path => absolute path.
	 */
	private function sourceFiles(): array {
		$root = dirname(__DIR__, 3);
		$lib = $root . '/lib';

		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if (($file instanceof SplFileInfo) === false || $file->isFile() === false) {
				continue;
			}

			if ($file->getExtension() !== 'php') {
				continue;
			}

			$path = $file->getPathname();
			$files[ltrim(str_replace($root, '', $path), '/')] = $path;
		}

		return $files;
	}//end sourceFiles()
}//end class
