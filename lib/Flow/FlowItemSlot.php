<?php

/**
 * One input item of a flow step and the output item it becomes.
 *
 * Every item-model step hands back, per input item, the item's json with its
 * answer added, the item's binary unchanged and a pairing to the input index.
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
 * An input item and its position, turned into the paired output item.
 *
 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */
final class FlowItemSlot {

	/**
	 * Constructor.
	 *
	 * @param array      $item  The input item.
	 * @param int|string $index Its key in the input list.
	 */
	public function __construct(
		private readonly array $item,
		private readonly int|string $index,
	) {
	}//end __construct()

	/**
	 * The input item's json.
	 *
	 * @return array The record.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function json(): array {
		return (array)($this->item['json'] ?? []);
	}//end json()

	/**
	 * The output item: the given json, the input's binary, paired to the input.
	 *
	 * @param array $json The record to put on the output item.
	 *
	 * @return array The output item.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function withJson(array $json): array {
		return [
			'json' => $json,
			'binary' => (array)($this->item['binary'] ?? []),
			'pairedItem' => ['item' => $this->index],
		];
	}//end withJson()
}//end class
