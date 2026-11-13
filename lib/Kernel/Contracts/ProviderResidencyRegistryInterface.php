<?php

/**
 * Contract of ProviderResidencyRegistry
 *
 * The public surface of ProviderResidencyRegistry that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of ProviderResidencyRegistry.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProviderResidencyRegistryInterface {

	/**
	 * The provider runs inside the organisation's own premises.
	 *
	 * @var string
	 */
	public const RESIDENCY_ON_PREMISE = 'on-premise';

	/**
	 * The provider runs in the European Union.
	 *
	 * @var string
	 */
	public const RESIDENCY_EU = 'eu';

	/**
	 * The provider runs outside the European Union.
	 *
	 * @var string
	 */
	public const RESIDENCY_OUTSIDE_EU = 'outside-eu';

	/**
	 * The residencies an administrator may state.
	 *
	 * @var array<int, string>
	 */
	public const DECLARABLE_RESIDENCIES = [
		self::RESIDENCY_ON_PREMISE,
		self::RESIDENCY_EU,
		self::RESIDENCY_OUTSIDE_EU,
	];

	/**
	 * Every declaration, keyed by provider id.
	 *
	 * @return array<string, array{provider: string, residency: string, location: string}> The declarations.
	 *
	 * @spec openspec/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
	 */
	public function all(): array;

	/**
	 * State where a provider runs.
	 *
	 * @param string $provider The provider id, one of the supported chat providers.
	 * @param string $residency One of `on-premise`, `eu`, `outside-eu`.
	 * @param string $location The free-text location detail, e.g. "Frankfurt, AWS eu-central-1".
	 *
	 * @return array{provider: string, residency: string, location: string} The stored declaration.
	 *
	 * @throws InvalidArgumentException When the provider or the residency is not a supported value.
	 *
	 * @spec openspec/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
	 */
	public function declareResidency(string $provider, string $residency, string $location = ''): array;
}//end interface
