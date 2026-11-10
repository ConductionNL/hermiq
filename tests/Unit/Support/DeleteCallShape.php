<?php

/**
 * Flat views of the arguments an ObjectService::deleteObject() double receives.
 *
 * deleteObject() takes its uuid, register and schema inside an ObjectDeletion.
 * Doubles and recordings that describe a delete keep describing it by uuid,
 * register and schema, so a recording records the delete, not the call shape.
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

use OCA\OpenRegister\Service\Object\ObjectDeletion;

/**
 * Flat views of a deleteObject() call.
 */
final class DeleteCallShape {

	/**
	 * The deleteObject() arguments as uuid, register, schema, rbac, multitenancy.
	 *
	 * @param array<int, mixed> $args The arguments the double received.
	 *
	 * @return array<int, mixed> The flat list.
	 */
	public static function flat(array $args): array {
		$deletion = $args[0];
		assert($deletion instanceof ObjectDeletion);

		return [
			$deletion->uuid,
			$deletion->target->register,
			$deletion->target->schema,
			($args[1] ?? true),
			($args[2] ?? true),
		];
	}//end flat()

	/**
	 * The deleteObject() arguments by name, leaving out access flags at their default.
	 *
	 * @param array<int, mixed> $args The arguments the double received.
	 *
	 * @return array<string, mixed> The uuid, register, schema and every non-default flag.
	 */
	public static function named(array $args): array {
		[$uuid, $register, $schema, $rbac, $multitenancy] = self::flat(args: $args);
		$out = ['uuid' => $uuid];
		if ($register !== null) {
			$out['register'] = $register;
		}

		if ($schema !== null) {
			$out['schema'] = $schema;
		}

		if ($rbac !== true) {
			$out['_rbac'] = $rbac;
		}

		if ($multitenancy !== true) {
			$out['_multitenancy'] = $multitenancy;
		}

		return $out;
	}//end named()
}//end class
