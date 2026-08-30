<?php

/**
 * Test stub for Doctrine\DBAL\Types\Types.
 *
 * Stands in for doctrine/dbal (not a Hermiq dependency) when the OCP
 * `IQueryBuilder` stub is class-loaded in standalone CI — see
 * ParameterType.php for the rationale. Constant values mirror
 * doctrine/dbal 3.x. The real class ships with the Nextcloud server at
 * runtime.
 *
 * @category Test
 * @package  Doctrine\DBAL\Types
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

/**
 * Minimal Types stub for standalone unit runs (doctrine/dbal 3.x values).
 */
final class Types {

	/**
	 * Boolean column type.
	 *
	 * @var string
	 */
	public const BOOLEAN = 'boolean';

	/**
	 * Mutable datetime column type.
	 *
	 * @var string
	 */
	public const DATETIME_MUTABLE = 'datetime';

	/**
	 * Immutable datetime column type.
	 *
	 * @var string
	 */
	public const DATETIME_IMMUTABLE = 'datetime_immutable';

	/**
	 * Mutable datetime-with-timezone column type.
	 *
	 * @var string
	 */
	public const DATETIMETZ_MUTABLE = 'datetimetz';

	/**
	 * Immutable datetime-with-timezone column type.
	 *
	 * @var string
	 */
	public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';

	/**
	 * Mutable date column type.
	 *
	 * @var string
	 */
	public const DATE_MUTABLE = 'date';

	/**
	 * Immutable date column type.
	 *
	 * @var string
	 */
	public const DATE_IMMUTABLE = 'date_immutable';

	/**
	 * Mutable time column type.
	 *
	 * @var string
	 */
	public const TIME_MUTABLE = 'time';

	/**
	 * Immutable time column type.
	 *
	 * @var string
	 */
	public const TIME_IMMUTABLE = 'time_immutable';
}//end class
