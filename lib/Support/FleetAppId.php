<?php

/**
 * Hermiq FleetAppId.
 *
 * Resolves a Conduction fleet app's installed identity across the rename.
 *
 * The fleet renamed: `scholiq` became `learniq`, `openconnector` became
 * `integriq`, and so on. The new names have reached `development`, but an
 * instance pinned to an older release still answers only to the old one, and
 * both are in the field at once.
 *
 * That matters because every cross-app reference is a DUCK-TYPED RUNTIME
 * LOOKUP. `IAppManager::isInstalled('scholiq')` against an instance running
 * `learniq` does not error — it returns false, and the integration silently
 * does nothing. A hard swap to the new id has the same failure in the other
 * direction against an older deployment. So neither name alone is correct:
 * this resolver takes a LIST, newest first, and returns whichever the
 * instance actually has.
 *
 * The rename moved TWO things, and each breaks its own set of call sites. A
 * stale id makes `isInstalled()` answer false; a stale namespace makes
 * `class_exists()` answer false and `ContainerInterface::get()` throw, into a
 * catch that exists so hermiq stays installable without its optional peers.
 * Both fail into the same silent no-op. {@see self::isInstalled()} covers the
 * id half and {@see self::getService()} the namespace half; fixing one and not
 * the other leaves the integration just as dark.
 *
 * WHAT THIS DELIBERATELY DOES NOT COVER: stored data. An OpenRegister register
 * slug, and any app id already written into a persisted object, are frozen on
 * purpose — renaming one in code does not rename the rows, it orphans them.
 * `CourseRecommendationEngine::SCHOLIQ_REGISTER` and the `sourceApp` written
 * onto a CourseRecommendation are both in that category and neither goes
 * through this class.
 *
 * @category Support
 * @package  OCA\Hermiq\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Hermiq\Support;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Resolves fleet app ids and namespaces across the rename.
 */
final class FleetAppId {
	/**
	 * Candidate ids per canonical app, NEWEST FIRST.
	 *
	 * Order is the contract: the first entry that is installed wins, so a
	 * migrated instance resolves to the new id and an older one falls back.
	 * Adding a rename means prepending, never replacing — dropping the old id
	 * is what silently breaks the integrations this class exists to protect.
	 *
	 * @var array<string, list<string>>
	 */
	private const CANDIDATES = [
		'integriq' => ['integriq', 'openconnector'],
		'filinq' => ['filinq', 'docudesk'],
		'thematiq' => ['thematiq', 'nldesign'],
		'stackiq' => ['stackiq', 'softwarecatalog'],
		'larpinq' => ['larpinq', 'larpingapp'],
		'dossiq' => ['dossiq', 'procest'],
		'learniq' => ['learniq', 'scholiq'],
		'decidiq' => ['decidiq', 'decidesk'],
		'buildiq' => ['buildiq', 'openbuild'],
		'keepiq' => ['keepiq', 'doriath'],
	];

	/**
	 * Candidate PHP namespaces per canonical app, NEWEST FIRST.
	 *
	 * Every pair was read out of that app's own composer.json history, never
	 * inferred from its id — `openbuild` shipped `OCA\OpenBuilt`, which no
	 * naming rule would have produced, so guessing writes a name that has never
	 * existed. Order is the contract here too.
	 *
	 * @var array<string, list<string>>
	 */
	private const NAMESPACES = [
		'integriq' => ['OCA\Integriq', 'OCA\OpenConnector'],
		'filinq' => ['OCA\Filinq', 'OCA\DocuDesk'],
		'thematiq' => ['OCA\Thematiq', 'OCA\NLDesign'],
		'stackiq' => ['OCA\Stackiq', 'OCA\SoftwareCatalog'],
		'larpinq' => ['OCA\Larpinq', 'OCA\LarpingApp'],
		'dossiq' => ['OCA\Dossiq', 'OCA\Procest'],
		'learniq' => ['OCA\Learniq', 'OCA\Scholiq'],
		'decidiq' => ['OCA\Decidiq', 'OCA\Decidesk'],
		'buildiq' => ['OCA\Buildiq', 'OCA\OpenBuilt'],
		'keepiq' => ['OCA\Keepiq', 'OCA\Doriath'],
	];

	/**
	 * The id this instance actually has installed, or null if none is.
	 *
	 * @param IAppManager $appManager The Nextcloud app manager.
	 * @param string $canonical Canonical (new) app name, e.g. 'learniq'.
	 *
	 * @return string|null The installed id, or null when the app is absent.
	 */
	public static function resolve(IAppManager $appManager, string $canonical): ?string {
		foreach ((self::CANDIDATES[$canonical] ?? [$canonical]) as $candidate) {
			try {
				if ($appManager->isInstalled($candidate) === true) {
					return $candidate;
				}
			} catch (Throwable $e) {
				// An app manager that cannot answer for one candidate must not
				// abort the search — the next candidate may still resolve.
				continue;
			}
		}

		return null;
	}//end resolve()

	/**
	 * Whether any candidate id for this app is installed.
	 *
	 * @param IAppManager $appManager The Nextcloud app manager.
	 * @param string $canonical Canonical (new) app name, e.g. 'learniq'.
	 *
	 * @return bool True when the app is present under some id.
	 */
	public static function isInstalled(IAppManager $appManager, string $canonical): bool {
		return self::resolve(appManager: $appManager, canonical: $canonical) !== null;
	}//end isInstalled()

	/**
	 * Every fully-qualified name a class could have, newest namespace first.
	 *
	 * @param string $canonical Canonical (new) app name, e.g. 'integriq'.
	 * @param string $relative Class name below the app root, e.g. 'Service\CallService'.
	 *
	 * @return list<string> Candidate FQCNs, newest first; empty when unknown.
	 */
	public static function classCandidates(string $canonical, string $relative): array {
		$relative = ltrim($relative, '\\');
		$candidates = [];

		foreach ((self::NAMESPACES[$canonical] ?? []) as $namespace) {
			$candidates[] = $namespace . '\\' . $relative;
		}

		return $candidates;
	}//end classCandidates()

	/**
	 * Fetch a service from another fleet app, whatever namespace it ships under.
	 *
	 * Replaces `$container->get('OCA\SomeOldName\Service\Thing')`, which throws
	 * when that app has renamed and — because every call site of that shape is
	 * wrapped in a try/catch that degrades gracefully — turns a rename into a
	 * feature that quietly stops working rather than an error anybody sees.
	 *
	 * @param ContainerInterface $container The service container.
	 * @param string $canonical Canonical (new) app name, e.g. 'integriq'.
	 * @param string $relative Class name below the app root, e.g. 'Service\CallService'.
	 *
	 * @return object|null The service, or null when no candidate resolves.
	 */
	public static function getService(ContainerInterface $container, string $canonical, string $relative): ?object {
		foreach (self::classCandidates(canonical: $canonical, relative: $relative) as $fqcn) {
			try {
				$service = $container->get($fqcn);
				if (is_object($service) === true) {
					return $service;
				}
			} catch (Throwable $e) {
				// This candidate is not registered — try the next name before
				// concluding the app is absent.
				continue;
			}
		}

		return null;
	}//end getService()
}//end class
