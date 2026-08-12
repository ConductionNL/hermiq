<?php

/**
 * Minimal OpenRegister IFlowNode stub for standalone unit runs and static analysis.
 *
 * Signatures mirrored verbatim from openregister lib/Service/Flow/IFlowNode.php.
 * Registered at TEST TIME only by tests/bootstrap.php and scanned (never
 * executed) by phpstan/psalm — see the note there on why these mappings must
 * not live in composer.json `autoload-dev`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Minimal IFlowNode stub.
 */
interface IFlowNode {
	/**
	 * The step `type` this node answers to, unique across the fleet.
	 *
	 * @return string The type identifier.
	 */
	public function getId(): string;

	/**
	 * Human-readable name for the palette.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string;

	/**
	 * What this node does, in one sentence.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string;

	/**
	 * Absolute URL of the palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string;

	/**
	 * Whether this node is offered in the given scope.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool;

	/**
	 * Reject a configuration the author cannot have meant.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws \UnexpectedValueException When the configuration is unusable.
	 */
	public function validateConfig(array $config): void;

	/**
	 * Do the work: items in, items out.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata — NOT the data channel.
	 *
	 * @return array The output items.
	 */
	public function execute(array $items, array $config, array $context): array;
}//end interface
