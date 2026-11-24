<?php

/**
 * Hermiq FederatedEngineLocator.
 *
 * Finds OpenRegister's shared federated config engine on this instance and
 * builds the broker identity its calls name. The engine is another app's class,
 * resolved by class-string from the container, so hermiq installs and runs
 * without it: no engine, an engine without the asked method, or a container
 * that cannot build it all answer null, and the caller keeps its own GitHub path.
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
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Locate OpenRegister's federated config engine.
 *
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
 */
final class FederatedEngineLocator {

	/**
	 * OpenRegister's engine, resolved by class-string so hermiq installs without it.
	 */
	public const ENGINE_CLASS = 'OCA\\OpenRegister\\Service\\Config\\FederatedConfigService';

	/**
	 * The engine's broker identity value object.
	 */
	public const IDENTITY_CLASS = 'OCA\\OpenRegister\\Service\\Config\\BrokerIdentity';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface|null $container The container the engine is resolved from; null means no engine.
	 * @param string                  $appId     The app id a brokered call names.
	 */
	public function __construct(
		private readonly ?ContainerInterface $container,
		private readonly string $appId,
	) {
	}//end __construct()

	/**
	 * The engine, when this instance has one that offers the method.
	 *
	 * @param string $method The engine method the caller needs.
	 *
	 * @return object|null The engine, or null.
	 *
	 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
	 */
	public function engineWith(string $method): ?object {
		if ($this->container === null
			|| class_exists(self::ENGINE_CLASS) === false
			|| class_exists(self::IDENTITY_CLASS) === false
			|| $this->container->has(self::ENGINE_CLASS) === false
		) {
			return null;
		}

		try {
			$engine = $this->container->get(self::ENGINE_CLASS);
		} catch (Throwable $e) {
			return null;
		}

		// An older OpenRegister has the engine without the newer methods.
		if (is_object($engine) === false || is_callable([$engine, $method]) === false) {
			return null;
		}

		return $engine;
	}//end engineWith()

	/**
	 * The broker identity a call for this user names: this app and the user.
	 *
	 * @param string|null $actingUserId The acting user, or null.
	 *
	 * @return object The engine's BrokerIdentity.
	 *
	 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
	 */
	public function identityFor(?string $actingUserId): object {
		return call_user_func([self::IDENTITY_CLASS, 'of'], $this->appId, $actingUserId);
	}//end identityFor()
}//end class
