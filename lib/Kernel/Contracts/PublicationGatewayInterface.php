<?php

/**
 * Contract of PublicationGateway
 *
 * The public surface of PublicationGateway that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of PublicationGateway.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicationGatewayInterface {

	/**
	 * Whether the fleet publication path (OpenCatalogi) is available at runtime.
	 *
	 * @return bool True when OpenCatalogi is installed; false otherwise.
	 *
	 * @spec openspec/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function isAvailable(): bool;

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
	 * @spec openspec/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function publish(array $publication): ?string;

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
	 * @spec openspec/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function withdraw(string $reference): bool;
}//end interface
