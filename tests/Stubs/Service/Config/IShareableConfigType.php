<?php

/**
 * Minimal OpenRegister IShareableConfigType stub for standalone unit runs
 * and static analysis.
 *
 * Signatures mirrored from openregister lib/Service/Config/IShareableConfigType.php.
 * Registered at TEST TIME only by tests/bootstrap.php and scanned (never
 * executed) by phpstan/psalm; the real interface wins whenever OpenRegister is
 * enabled.
 *
 * Hermiq *implements* this contract (HermiqSkillShareableConfigType), and phpstan
 * refuses to ignoreErrors the "implements unknown interface" category — reflection
 * fails before the ignore list applies — so the contract has to be resolvable
 * rather than suppressed. Same "share the contract" pattern as the Flow stubs
 * alongside this directory.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

/**
 * Minimal IShareableConfigType stub.
 */
interface IShareableConfigType {
	/**
	 * The stable type id (e.g. `openregister.flows`).
	 *
	 * @return string The id.
	 */
	public function getId(): string;

	/**
	 * The human name shown when sharing or browsing.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string;

	/**
	 * The GitHub topic a published config of this type is tagged with.
	 *
	 * @return string The discovery topic.
	 */
	public function getTopic(): string;

	/**
	 * Package a selection of this type's configuration into a portable bundle.
	 *
	 * @param array $selection What to share, in the type's own terms.
	 *
	 * @return array The portable bundle: `{type, version, ...content}`.
	 */
	public function serialise(array $selection): array;

	/**
	 * Apply a bundle of this type to this instance.
	 *
	 * @param array $bundle A bundle previously produced by a type of this id.
	 *
	 * @return array The install result (e.g. `{installed: [...]}`).
	 */
	public function deserialise(array $bundle): array;
}//end interface
