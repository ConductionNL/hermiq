<?php

/**
 * Test stub for Doctrine\DBAL\Query\Expression\ExpressionBuilder.
 *
 * Stands in for doctrine/dbal (not a Hermiq dependency) when the OCP
 * `IExpressionBuilder` interface is class-loaded in standalone CI: that
 * interface initialises its comparison constants from this class. Constant
 * values mirror doctrine/dbal 3.x. The real class ships with the Nextcloud
 * server at runtime.
 *
 * @category Test
 * @package  Doctrine\DBAL\Query\Expression
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Query\Expression;

/**
 * Comparison-operator constants only.
 */
class ExpressionBuilder {
	public const EQ = '=';
	public const NEQ = '<>';
	public const LT = '<';
	public const LTE = '<=';
	public const GT = '>';
	public const GTE = '>=';
}//end class
