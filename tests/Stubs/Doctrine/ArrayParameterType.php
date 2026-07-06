<?php

/**
 * Test stub for Doctrine\DBAL\ArrayParameterType.
 *
 * Stands in for doctrine/dbal (not a Hermiq dependency) when the OCP
 * `IQueryBuilder` stub is class-loaded in standalone CI — see
 * ParameterType.php for the rationale. Constant values mirror
 * doctrine/dbal 3.x. The real class ships with the Nextcloud server at
 * runtime.
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
 * Minimal ArrayParameterType stub for standalone unit runs (doctrine/dbal 3.x values).
 */
final class ArrayParameterType
{

    /**
     * Integer array parameter type.
     *
     * @var int
     */
    public const INTEGER = 101;

    /**
     * String array parameter type.
     *
     * @var int
     */
    public const STRING = 102;

    /**
     * Binary array parameter type.
     *
     * @var int
     */
    public const BINARY = 116;

    /**
     * ASCII array parameter type.
     *
     * @var int
     */
    public const ASCII = 117;
}//end class
