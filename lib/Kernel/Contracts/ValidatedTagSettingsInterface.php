<?php

/**
 * Contract of ValidatedTagSettings
 *
 * The public surface of ValidatedTagSettings that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of ValidatedTagSettings.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ValidatedTagSettingsInterface {

	/**
	 * The validated tag's id, or null when none is chosen or the chosen one no longer exists.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	public function tagId(): ?string;

	/**
	 * The setting as the admin page shows it: `tagId`, `name` and the `groups` that may assign it.
	 *
	 * @return array{tagId: ?string, name: ?string, groups: list<string>}
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	public function all(): array;

	/**
	 * Name an existing system tag as the validated tag, and optionally set who may assign it.
	 *
	 * @param string            $tagId  The tag.
	 * @param list<string>|null $groups The groups that may assign it; null leaves the tag's groups as they are.
	 *
	 * @return array{tagId: ?string, name: ?string, groups: list<string>}
	 *
	 * @throws InvalidArgumentException When the tag does not exist.
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	public function choose(string $tagId, ?array $groups): array;

	/**
	 * Create a restricted tag (visible, not freely assignable), assignable by the given groups, and name it.
	 *
	 * An existing restricted tag with the same name is taken instead of a second one.
	 *
	 * @param string       $name   The tag name.
	 * @param list<string> $groups The groups that may assign it.
	 *
	 * @return array{tagId: ?string, name: ?string, groups: list<string>}
	 *
	 * @throws InvalidArgumentException When the name is empty.
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	public function create(string $name, array $groups): array;
}//end interface
