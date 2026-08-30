<?php

/**
 * Hermiq PublicationGateway.
 *
 * The runtime integration seam that hands an Algoritmekader publication to the fleet
 * publication path **without** a hard hermiq→OpenCatalogi coupling. OpenCatalogi is the
 * fleet's publication leaf: it renders and harvests whatever lives, published, in its
 * OpenRegister publication register. This gateway therefore delegates through the *shared
 * OpenRegister data layer* (ADR-022) — it deposits the mapped publication object into
 * OpenCatalogi's publication register via the same `ObjectService` write-path hermiq
 * already uses, and OpenCatalogi surfaces it outward. It resolves availability at runtime
 * via `IAppManager` (`isInstalled('opencatalogi')`); when OpenCatalogi is not installed the
 * gateway is unavailable and every method fails closed (null / false) so the AI-feature
 * register stays fully governable internally.
 *
 * Deliberately NOT built on the OpenRegister integration registry (`getLeaf` / `call`) or a
 * server-to-server HTTP call — those are the phantom cross-app patterns banned by ADR-041
 * (gate-27). hermiq opens NO connection to algoritmes.overheid.nl itself; the national
 * portal is OpenCatalogi's DCAT/harvest domain.
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
 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;

/**
 * Runtime seam that delegates Algoritmekader publication to OpenCatalogi via OpenRegister.
 *
 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
 */
class PublicationGateway {

	/**
	 * The fleet publication leaf whose presence makes the publish action available.
	 *
	 * @var string
	 */
	public const PUBLICATION_APP = 'opencatalogi';

	/**
	 * The OpenRegister register slug OpenCatalogi's publications live in.
	 *
	 * @var string
	 */
	private const PUBLICATION_REGISTER = 'opencatalogi';

	/**
	 * The OpenRegister schema slug of a publication.
	 *
	 * @var string
	 */
	private const PUBLICATION_SCHEMA = 'publication';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Resolves whether the publication leaf is installed (runtime seam).
	 * @param ObjectService $objectService OpenRegister shared write-path (published-predicate seam).
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Whether the fleet publication path (OpenCatalogi) is available at runtime.
	 *
	 * @return bool True when OpenCatalogi is installed; false otherwise.
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function isAvailable(): bool {
		return $this->appManager->isInstalled(self::PUBLICATION_APP);
	}//end isAvailable()

	/**
	 * Hand a mapped Algoritmekader publication to OpenCatalogi's publication register.
	 *
	 * Writes the publication object through the shared OpenRegister write-path into
	 * OpenCatalogi's publication register (published-predicate seam). Returns the created
	 * object's UUID — the external register reference stored back on the AiFeature. When
	 * OpenCatalogi is absent it fails closed and returns null (the caller then reports the
	 * action as unavailable and leaves the feature internally governable).
	 *
	 * @param array<string, mixed> $publication The Algoritmekader-conformant publication.
	 *
	 * @return string|null The external register reference (UUID), or null when unavailable.
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function publish(array $publication): ?string {
		if ($this->isAvailable() === false) {
			return null;
		}

		$publication['publicatiedatum'] = $this->now();

		$saved = $this->objectService->saveObject(
			object: $publication,
			register: self::PUBLICATION_REGISTER,
			schema: self::PUBLICATION_SCHEMA
		);

		return (string)$saved->getUuid();
	}//end publish()

	/**
	 * Request unpublication of a previously published entry (withdrawal).
	 *
	 * Stamps the OpenCatalogi publication with a depublication date through the shared
	 * write-path, which removes it from the outward feed. Fails closed (false) when
	 * OpenCatalogi is absent or no reference was recorded.
	 *
	 * @param string $reference The external register reference stored at publish time.
	 *
	 * @return bool True when the withdrawal was requested; false when unavailable.
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function withdraw(string $reference): bool {
		if ($this->isAvailable() === false || trim($reference) === '') {
			return false;
		}

		$existing = $this->objectService->find(
			id: $reference,
			register: self::PUBLICATION_REGISTER,
			schema: self::PUBLICATION_SCHEMA
		);

		$data = [];
		if ($existing instanceof ObjectEntity) {
			$data = $existing->getObject();
		}

		$data['depublicatiedatum'] = $this->now();
		$data['status'] = 'withdrawn';

		$this->objectService->saveObject(
			object: $data,
			register: self::PUBLICATION_REGISTER,
			schema: self::PUBLICATION_SCHEMA,
			uuid: $reference
		);

		return true;
	}//end withdraw()

	/**
	 * The current UTC timestamp in ISO-8601.
	 *
	 * @return string The ISO-8601 timestamp.
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	private function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
	}//end now()
}//end class
