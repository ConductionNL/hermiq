<?php

/**
 * Renders `{{dotted.path}}` placeholders against one item's json.
 *
 * The agent step and the workload step both template their configuration
 * from the item they run for, with the same substitution: a dotted path into
 * the item's json, an array value as its JSON encoding, and an unknown path as
 * the empty string.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Flow
 * @package  OCA\Hermiq\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

/**
 * The placeholder substitution of one flow item.
 *
 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */
final class ItemPlaceholders {

	/**
	 * Constructor.
	 *
	 * @param array $json The item's record.
	 */
	public function __construct(
		private readonly array $json,
	) {
	}//end __construct()

	/**
	 * Substitute `{{dotted.path}}` placeholders from the item's json.
	 *
	 * @param string $template The template.
	 *
	 * @return string The rendered text.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function render(string $template): string {
		return (string)preg_replace_callback(
			'/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/',
			fn (array $matches): string => $this->valueAt(path: $matches[1]),
			$template
		);

	}//end render()

	/**
	 * The text a dotted path resolves to: '' when absent, JSON for an array.
	 *
	 * @param string $path The dotted path.
	 *
	 * @return string The value as text.
	 */
	private function valueAt(string $path): string {
		$value = $this->json;
		foreach (explode('.', $path) as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return '';
			}

			$value = $value[$segment];
		}

		if (is_array($value) === true) {
			return (string)json_encode($value);
		}

		return (string)$value;

	}//end valueAt()
}//end class
