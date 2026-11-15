<?php

/**
 * Contract of AiFeatureBindingService
 *
 * The public surface of AiFeatureBindingService that other components call, so they depend
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
use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of AiFeatureBindingService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiFeatureBindingServiceInterface {

	/**
	 * Bind a provider and model to a feature, and set the residency it requires.
	 *
	 * Passing an empty provider and model clears the binding, which returns the
	 * feature to the organisation's effective `ModelPolicy` default: the behaviour
	 * every feature had before this change.
	 *
	 * @param string $id The AiFeature UUID.
	 * @param string|null $provider The provider to bind, '' or null to clear.
	 * @param string|null $model The model to bind, '' or null to clear.
	 * @param string|null $requiredResidency The residency the feature demands, '' or null to clear.
	 *
	 * @return ObjectEntity|null The stored feature, or null when there is no such feature.
	 *
	 * @throws InvalidArgumentException When the pair is outside the effective policy, when only
	 *                                  one half of the pair is given, or when the residency is
	 *                                  not one an administrator may state.
	 *
	 * @spec openspec/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
	 */
	public function bind(string $id, ?string $provider, ?string $model, ?string $requiredResidency = null): ?ObjectEntity;

	/**
	 * What the feature register shows about where each feature's runs will go: the
	 * provider it will use, where it was resolved from, and the residency of that
	 * provider. What leaves the building is then readable in one place.
	 *
	 * @param FeatureProviderResolver $resolver Resolves each feature's effective binding.
	 *
	 * @return array<int, array<string, mixed>> One row per feature.
	 *
	 * @spec openspec/specs/ai-feature-governance/spec.md#requirement-a-split-deployment-must-be-expressible-as-configuration
	 */
	public function overview(FeatureProviderResolver $resolver): array;
}//end interface
