<?php

/**
 * Test stub for Doctrine\DBAL\ParameterType.
 *
 * Stands in for doctrine/dbal (not a Hermiq dependency) when the OCP
 * `IQueryBuilder` stub is class-loaded in standalone CI (php:8.3-cli + OCP
 * stubs): its PARAM_* class constants are initialised from these Doctrine
 * constants at load time, so mocking `OCP\IDBConnection` fatals without them.
 * Constant values mirror doctrine/dbal 3.x. The real class ships with the
 * Nextcloud server at runtime.
 *
 * @category Test
 * @package  Doctrine\DBAL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Doctrine\DBAL;

/**
 * Minimal ParameterType stub for standalone unit runs (doctrine/dbal 3.x values).
 */
final class ParameterType {

	/**
	 * NULL parameter type.
	 *
	 * @var int
	 */
	public const NULL = 0;

	/**
	 * Integer parameter type.
	 *
	 * @var int
	 */
	public const INTEGER = 1;

	/**
	 * String parameter type.
	 *
	 * @var int
	 */
	public const STRING = 2;

	/**
	 * Large object parameter type.
	 *
	 * @var int
	 */
	public const LARGE_OBJECT = 3;

	/**
	 * Boolean parameter type.
	 *
	 * @var int
	 */
	public const BOOLEAN = 5;

	/**
	 * Binary parameter type.
	 *
	 * @var int
	 */
	public const BINARY = 16;

	/**
	 * ASCII parameter type.
	 *
	 * @var int
	 */
	public const ASCII = 17;
}//end class
