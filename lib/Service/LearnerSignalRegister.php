<?php

/**
 * Hermiq LearnerSignalRegister
 *
 * Answers one question for the course-recommendation engine: can the learner
 * signals be read on this instance, and if so with which register slug.
 *
 * It is one class because that question used to be asked as two half-questions
 * and only one half was ever answered. `getOrRegenerate()` checked that the APP
 * was installed, asking `IAppManager` through `FleetAppId`, and then read from a
 * hardcoded `scholiq` register. On an instance that has run learniq's
 * register-slug repair step those two facts disagree: the app answers to
 * `learniq` and so does the register row, so the app check passed and every
 * signal read went to a slug nothing answers to.
 *
 * The app id and the register slug are moved by two SEPARATE repair steps, and
 * either can run first. `IAppManager` is the authority for one,
 * `openregister_registers` for the other, and neither predicts the other. Asking
 * both here, together, is the only shape in which the disagreement cannot be
 * missed.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Whether the learner-signal register can be read here, and under which slug.
 *
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
 */
class LearnerSignalRegister {

	/**
	 * The peer app whose learner signals hermiq reads, by its CANONICAL name.
	 *
	 * The app renamed `scholiq` -> `learniq` and both are in the field.
	 * `isInstalled('scholiq')` against an instance running `learniq` does not
	 * error, it returns false, so a pinned id reports the app absent on exactly
	 * the instances where it is present. Resolved through {@see FleetAppId},
	 * never as a literal.
	 *
	 * @var string
	 */
	public const CANONICAL_APP = 'learniq';

	/**
	 * The CANONICAL slug of the register holding those signals, which is not
	 * necessarily the slug this instance answers to.
	 *
	 * Learniq ships a repair step, `lib/Repair/RenameRegisterSlug.php`, that
	 * renames the register from `scholiq`. Note the file name: eight fleet apps
	 * call theirs `MigrateRegisterSlug` and this is the one that does not. The step runs per instance,
	 * so both slugs are live across the estate at once and neither is safe to
	 * write as a literal. This is the name asked ABOUT; the name to read WITH
	 * comes back from {@see RegisterSlugResolverInterface}.
	 *
	 * @var string
	 */
	public const CANONICAL_REGISTER = 'learniq';

	/**
	 * Recorded when the learner-signal app is not installed under either id.
	 *
	 * @var string
	 */
	public const REASON_APP_ABSENT = 'learniq-app-absent';

	/**
	 * Recorded when the app is installed and its register is not here.
	 *
	 * Deliberately distinct from {@see REASON_APP_ABSENT}. That one is repaired
	 * by enabling an app, this one by provisioning a register, and before this
	 * class the second was reported as neither: the read went to a slug nothing
	 * answers to, came back with the empty list a register holding no matching
	 * objects returns, and the learner was served a complete, successful, empty
	 * recommendation set.
	 *
	 * @var string
	 */
	public const REASON_REGISTER_ABSENT = 'learniq-register-absent';

	/**
	 * Constructor.
	 *
	 * @param RegisterSlugResolverInterface $slugResolver Which slug the register answers to here.
	 * @param IAppManager                   $appManager   Optional-dependency detection.
	 * @param LoggerInterface               $logger       PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterSlugResolverInterface $slugResolver,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The slug to read learner signals with, or the reason there is none.
	 *
	 * Exactly one of the two is ever null. The reason is returned rather than
	 * merely logged because a caller that cannot tell "there is nothing to
	 * recommend" from "the course register is not on this instance" will report
	 * the second as the first, which is how this stayed invisible.
	 *
	 * @return array{slug: string|null, reason: string|null} The resolved slug, or
	 *         a REASON_* constant naming which of the two absences this is.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver
	 *  over a constant map, with no state to inject and nothing a test would
	 *  substitute. Injecting it would add a constructor argument to every
	 *  consumer to no end.
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md
	 */
	public function slugOrReason(): array {
		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: self::CANONICAL_APP) === false) {
			$this->logger->info(
				'[LearnerSignalRegister] The learner-signal app is not installed under any id it answers to; '
				. 'recommendations are unavailable on this instance.'
			);
			return ['slug' => null, 'reason' => self::REASON_APP_ABSENT];
		}

		// Branch on isResolved(), never on the returned value. Reading with a slug
		// this instance does not carry returns zero rows, and zero rows is what a
		// register with no matching objects returns too, so
		// `?->slug ?? self::CANONICAL_REGISTER` reinstates the defect this class
		// exists to remove.
		$resolution = $this->slugResolver->resolve(canonical: self::CANONICAL_REGISTER);
		if ($resolution->isResolved() === false) {
			$this->logger->warning(
				'[LearnerSignalRegister] The learner-signal register is not on this instance under any slug it has '
				. 'answered to (' . implode(', ', $resolution->candidates) . '). The app IS installed, so this is an '
				. 'unprovisioned register rather than a missing app.'
			);
			return ['slug' => null, 'reason' => self::REASON_REGISTER_ABSENT];
		}

		return ['slug' => $resolution->slug, 'reason' => null];
	}//end slugOrReason()
}//end class
