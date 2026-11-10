<?php

/**
 * Flat views of the arguments an ObjectService::saveObject() double receives.
 *
 * The saveObject() call takes its register, schema, uuid, extend, policy and
 * actor inside an ObjectSaveOptions. Doubles and recordings that describe a save keep
 * describing it by those fields, so a recording records the save, not the call
 * shape.
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

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Flat views of a saveObject() call.
 */
final class SaveCallShape {

	/**
	 * The saveObject() parameters before openregister #9123, in order, with their defaults.
	 *
	 * @var array<string, mixed>
	 */
	private const OLD_DEFAULTS = [
		'object' => null,
		'extend' => [],
		'register' => null,
		'schema' => null,
		'uuid' => null,
		'_rbac' => true,
		'_multitenancy' => true,
		'silent' => false,
		'_validation' => true,
		'uploadedFiles' => null,
		'currentUser' => null,
		'failIfExists' => false,
		'_unowned' => false,
		'_dedupOverride' => false,
		'_owner' => null,
	];

	/**
	 * The saveObject() parameter names before openregister #9123, in order.
	 *
	 * @return list<string>
	 */
	public static function oldNames(): array {
		return array_keys(self::OLD_DEFAULTS);
	}//end oldNames()

	/**
	 * The saveObject() arguments by their names before openregister #9123.
	 *
	 * @param array<int, mixed> $args The arguments the double received: object, options, rbac, multitenancy.
	 *
	 * @return array<string, mixed> object, extend, register, schema, uuid, _rbac, _multitenancy, silent,
	 *                              _validation, uploadedFiles, currentUser, failIfExists, _unowned,
	 *                              _dedupOverride and _owner.
	 */
	public static function named(array $args): array {
		$options = ($args[1] ?? new ObjectSaveOptions());
		assert($options instanceof ObjectSaveOptions);

		return [
			'object' => $args[0],
			'extend' => $options->extend,
			'register' => $options->target->register,
			'schema' => $options->target->schema,
			'uuid' => $options->uuid,
			'_rbac' => ($args[2] ?? true),
			'_multitenancy' => ($args[3] ?? true),
			'silent' => $options->policy->silent,
			'_validation' => $options->policy->validation,
			'uploadedFiles' => $options->uploadedFiles,
			'currentUser' => $options->actor->currentUser,
			'failIfExists' => $options->policy->failIfExists,
			'_unowned' => $options->actor->unowned,
			'_dedupOverride' => $options->policy->dedupOverride,
			'_owner' => $options->actor->owner,
		];
	}//end named()

	/**
	 * The saveObject() arguments by their old names, leaving out the ones at their old default.
	 *
	 * @param array<int, mixed> $args The arguments the double received: object, options, rbac, multitenancy.
	 *
	 * @return array<string, mixed> The object and every argument that differs from its old default.
	 */
	public static function passed(array $args): array {
		$out = [];
		foreach (self::named(args: $args) as $name => $value) {
			if ($name !== 'object' && self::OLD_DEFAULTS[$name] === $value) {
				continue;
			}

			$out[$name] = $value;
		}

		return $out;
	}//end passed()

	/**
	 * The saveObject() arguments as the positional list before openregister #9123.
	 *
	 * @param array<int, mixed> $args The arguments the double received: object, options, rbac, multitenancy.
	 *
	 * @return array<int, mixed> The values of named(), in the old parameter order.
	 */
	public static function flat(array $args): array {
		return array_values(self::named(args: $args));
	}//end flat()
}//end class
