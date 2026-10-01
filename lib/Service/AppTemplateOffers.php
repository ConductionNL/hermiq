<?php

/**
 * Hermiq AppTemplateOffers.
 *
 * Asks every installed app for the agent templates it offers for itself
 * (CollectAgentTemplatesEvent) and imports each offer quarantined and scanned,
 * with the offering app in `offeredBy`. An offer whose package has not changed
 * since the last collect is skipped by its hash; a changed one replaces the
 * template and sends it back to review.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Event\CollectAgentTemplatesEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects and imports the agent templates installed apps offer.
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */
class AppTemplateOffers {

	/**
	 * What a Nextcloud app id looks like.
	 *
	 * @var string
	 */
	private const APP_ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Carries the collect event to the offering apps.
	 * @param AgentTemplateService $templates Imports each offer quarantined and scanned.
	 * @param IAppManager $appManager Tells whether the offering app is installed.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly AgentTemplateService $templates,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ask the installed apps for their templates and import what they offer.
	 *
	 * A listener that throws ends the dispatch, but the offers made before it are
	 * still imported; a refused offer is counted and logged, never fatal.
	 *
	 * @return array{imported: int, updated: int, unchanged: int, refused: int} What happened to the offers.
	 *
	 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
	 */
	public function collect(): array {
		$event = new CollectAgentTemplatesEvent();
		try {
			$this->dispatcher->dispatchTyped(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning('[hermiq] An app failed while offering agent templates: ' . $e->getMessage());
		}

		$counts = ['imported' => 0, 'updated' => 0, 'unchanged' => 0, 'refused' => 0];
		$known = $this->knownOffers();
		foreach ($event->getOffers() as $offer) {
			$outcome = $this->importOne(appId: $offer['appId'], package: $offer['package'], known: $known);
			$counts[$outcome]++;
		}

		return $counts;
	}//end collect()

	/**
	 * Import one offer.
	 *
	 * @param string $appId The offering app.
	 * @param string $package The offered package.
	 * @param array<string, array{uuid: string, hash: string}> $known The earlier offers by "app/name", updated in place.
	 *
	 * @return string One of imported, updated, unchanged, refused.
	 */
	private function importOne(string $appId, string $package, array &$known): string {
		$parsed = $this->acceptable(appId: $appId, package: $package);
		if ($parsed === null) {
			return 'refused';
		}

		$hash = hash('sha256', $package);
		$key = $appId . '/' . $parsed['name'];
		$earlier = ($known[$key] ?? null);
		if ($earlier !== null && $earlier['hash'] === $hash) {
			return 'unchanged';
		}

		try {
			$saved = $this->templates->importAppOffer(
				parsed: $parsed,
				appId: $appId,
				offerHash: $hash,
				replaceUuid: ($earlier['uuid'] ?? null)
			);
		} catch (Throwable $e) {
			$this->logger->error('[hermiq] Could not import the agent template "' . $key . '": ' . $e->getMessage());
			return 'refused';
		}

		$known[$key] = ['uuid' => (string)$saved->getUuid(), 'hash' => $hash];

		if ($earlier !== null) {
			return 'updated';
		}

		return 'imported';
	}//end importOne()

	/**
	 * The parsed package when the offer can be imported, else null (logged).
	 *
	 * @param string $appId The offering app.
	 * @param string $package The offered package.
	 *
	 * @return array<string, mixed>|null The parsed package, or null.
	 */
	private function acceptable(string $appId, string $package): ?array {
		if (preg_match(self::APP_ID_PATTERN, $appId) !== 1 || $this->appManager->isInstalled($appId) === false) {
			$this->logger->warning('[hermiq] Refused an agent template offered for an app that is not installed: ' . $appId);
			return null;
		}

		$decoded = json_decode($package, true);
		if (is_array($decoded) === false) {
			$this->logger->warning('[hermiq] Refused an agent template from ' . $appId . ': the package is not a JSON object.');
			return null;
		}

		$parsed = $this->templates->parsePackage(package: $package);
		if (trim((string)$parsed['name']) === '') {
			$this->logger->warning('[hermiq] Refused an agent template from ' . $appId . ': the package has no name.');
			return null;
		}

		return $parsed;
	}//end acceptable()

	/**
	 * The templates earlier collects imported, by "app/name".
	 *
	 * @return array<string, array{uuid: string, hash: string}>
	 */
	private function knownOffers(): array {
		$known = [];
		foreach ($this->templates->appOffers() as $template) {
			$data = $template->getObject();
			$key = (string)($data['offeredBy'] ?? '') . '/' . (string)($data['name'] ?? '');
			$known[$key] = ['uuid' => (string)$template->getUuid(), 'hash' => (string)($data['offerHash'] ?? '')];
		}

		return $known;
	}//end knownOffers()
}//end class
