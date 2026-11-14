<?php

/**
 * Contract of ProviderDataUseRegistry
 *
 * The public surface of ProviderDataUseRegistry that other components call, so they depend
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
 * Contract of ProviderDataUseRegistry.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProviderDataUseRegistryInterface {

	/**
	 * The provider keeps nothing it is sent, and therefore trains on nothing.
	 */
	public const ZERO_RETENTION = 'zero-retention';

	/**
	 * The provider never trains on what it is sent.
	 */
	public const NO_TRAINING = 'no-training';

	/**
	 * The provider may train on what it is sent.
	 */
	public const MAY_TRAIN = 'may-train';

	/**
	 * No administrator has stated anything. Never a default that reads as safe.
	 */
	public const UNDECLARED = 'undeclared';

	/**
	 * The values an administrator may state.
	 *
	 * @var array<int, string>
	 */
	public const DECLARABLE = [self::ZERO_RETENTION, self::NO_TRAINING, self::MAY_TRAIN];

	/**
	 * Every declaration, keyed by provider id.
	 *
	 * @return array<string, array{provider: string, dataUse: string, termsReference: string, declaredBy: string, declaredAt: string}>
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function all(): array;

	/**
	 * State what a provider does with the data it is sent.
	 *
	 * @param string $provider The provider id, one of the supported chat providers.
	 * @param string $dataUse One of zero-retention, no-training, may-train.
	 * @param string $termsReference The terms or contract that says so.
	 * @param string $declaredBy The administrator's user id.
	 *
	 * @return array{provider: string, dataUse: string, termsReference: string, declaredBy: string, declaredAt: string}
	 *
	 * @throws InvalidArgumentException When the provider or the value is not supported.
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function declare(string $provider, string $dataUse, string $termsReference, string $declaredBy): array;

	/**
	 * What an administrator stated about one provider; `undeclared` when nothing.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return array{provider: string, dataUse: string, termsReference: string, declaredBy: string, declaredAt: string}
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function forProvider(string $provider): array;
}//end interface
