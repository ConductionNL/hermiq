<?php

/**
 * Flat views of the arguments an ObjectService::find() double receives.
 *
 * find() takes its register, schema, extend, files, render and audit inside
 * an ObjectFindOptions. Doubles and recordings that describe a read keep
 * describing it in the flat parameter list, so a recording records the read,
 * not the call shape.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Support;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * Flat views of a find() call.
 */
final class FindCallShape {

	/**
	 * The flat parameter names, in order, with their defaults.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = [
		'_extend' => [],
		'files' => false,
		'register' => null,
		'schema' => null,
		'_rbac' => true,
		'_multitenancy' => true,
		'_render' => true,
		'_audit' => true,
	];

	/**
	 * The find() arguments as id, extend, files, register, schema, rbac, multitenancy, render, audit.
	 *
	 * @param array<int, mixed> $args The arguments the double received.
	 *
	 * @return array<int, mixed> The flat list.
	 */
	public static function flat(array $args): array {
		$options = ($args[1] ?? null);
		if ($options instanceof ObjectFindOptions === false) {
			$options = new ObjectFindOptions();
		}

		return [
			$args[0],
			$options->extend,
			$options->files,
			$options->register,
			$options->schema,
			($args[2] ?? true),
			($args[3] ?? true),
			$options->render,
			$options->audit,
		];
	}//end flat()

	/**
	 * The find() arguments by flat parameter name, leaving out the ones at their default.
	 *
	 * @param array<int, mixed> $args The arguments the double received.
	 *
	 * @return array<string, mixed> The id and every non-default flat argument.
	 */
	public static function named(array $args): array {
		$flat = self::flat(args: $args);
		$out  = ['id' => $flat[0]];
		foreach (array_keys(self::DEFAULTS) as $index => $name) {
			if ($flat[($index + 1)] !== self::DEFAULTS[$name]) {
				$out[$name] = $flat[($index + 1)];
			}
		}

		return $out;
	}//end named()
}//end class
