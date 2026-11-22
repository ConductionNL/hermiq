<?php

/**
 * Hermiq: where OpenRegister's vector facade is found, shared by every caller.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
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
 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Finds OpenRegister's `VectorSearchFacade` once, behind a `class_exists()`
 * probe and the container, and says whether it reports an embedding backend.
 *
 * One locator per caller: each keeps its own resolved facade and names itself
 * in the log line when the facade cannot be resolved. A server whose
 * OpenRegister predates the facade answers null here, never an error.
 *
 * @spec openspec/changes/vector-rag/specs/vector-rag/spec.md
 */
class VectorFacadeLocator {

	/**
	 * The resolved facade; false once resolution failed, null before it was tried.
	 *
	 * @var object|false|null
	 */
	private object|false|null $facade = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Resolves the facade at call time.
	 * @param LoggerInterface    $logger      Notes a facade that could not be resolved.
	 * @param string             $facadeClass The facade class; tests point it at a stub.
	 * @param string             $caller      The caller's name, for the log line.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $facadeClass,
		private readonly string $caller,
	) {
	}//end __construct()

	/**
	 * The facade, resolved once.
	 *
	 * @return object|null The facade, or null when OpenRegister has none.
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
	 */
	public function facade(): ?object {
		if ($this->facade === null) {
			$this->facade = $this->resolve();
		}

		if ($this->facade === false) {
			return null;
		}

		return $this->facade;
	}//end facade()

	/**
	 * Whether the facade reports an embedding backend; a facade that throws has none.
	 *
	 * @param object $facade The facade.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/vector-rag/tasks.md#task-1-1
	 */
	public function hasBackend(object $facade): bool {
		try {
			return method_exists($facade, 'isAvailable') === true && $facade->isAvailable() === true;
		} catch (\Throwable) {
			return false;
		}
	}//end hasBackend()

	/**
	 * Resolve the facade behind a class probe.
	 *
	 * @return object|false The facade, or false.
	 */
	private function resolve(): object|false {
		if (class_exists($this->facadeClass) === false) {
			return false;
		}

		try {
			$facade = $this->container->get($this->facadeClass);
		} catch (\Throwable $e) {
			$this->logger->info(
				message: '['.$this->caller.'] the OpenRegister vector facade could not be resolved',
				context: ['error' => $e->getMessage()]
			);
			return false;
		}

		if (is_object($facade) === false) {
			return false;
		}

		return $facade;
	}//end resolve()
}//end class
