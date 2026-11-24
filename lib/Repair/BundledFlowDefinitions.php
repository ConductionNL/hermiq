<?php

/**
 * Hermiq BundledFlowDefinitions.
 *
 * Reads the flow definitions shipped in `lib/Settings/flows/*.flow.json`, the copies
 * of hydra's flows that SeedBundledFlows seeds. A file that does not decode to a
 * definition with a name is reported, not skipped: it is a packaging defect, and a
 * seeder that silently drops it would report a clean install with a flow missing.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use JsonException;

/**
 * The bundled flow definitions, by file name.
 *
 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
 */
class BundledFlowDefinitions {

	/**
	 * The shipped definitions' directory, relative to this file.
	 *
	 * @var string
	 */
	public const DIRECTORY = __DIR__ . '/../Settings/flows';

	/**
	 * Constructor.
	 *
	 * @param string $directory Where the `*.flow.json` files are; the shipped directory by default.
	 *
	 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
	 */
	public function __construct(
		private readonly string $directory=self::DIRECTORY,
	) {
	}//end __construct()

	/**
	 * Every definition, by file name, in file-name order; a file that cannot be read
	 * as a named definition carries an `error` instead of its `data`.
	 *
	 * @return array<string, array{data: array<string, mixed>|null, error: string|null}> The definitions.
	 *
	 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
	 */
	public function all(): array {
		$files = glob($this->directory . '/*.flow.json');
		if ($files === false) {
			return [];
		}

		sort($files);
		$definitions = [];
		foreach ($files as $file) {
			$definitions[basename($file)] = self::read(file: $file);
		}

		return $definitions;
	}//end all()

	/**
	 * One file as a definition, or the reason it is not one.
	 *
	 * @param string $file The file.
	 *
	 * @return array{data: array<string, mixed>|null, error: string|null} The definition or its error.
	 *
	 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
	 */
	private static function read(string $file): array {
		$raw = file_get_contents($file);
		if ($raw === false) {
			return ['data' => null, 'error' => 'unreadable'];
		}

		try {
			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			return ['data' => null, 'error' => 'invalid JSON: ' . $e->getMessage()];
		}

		if (is_array($data) === false || trim((string)($data['name'] ?? '')) === '') {
			return ['data' => null, 'error' => 'not a flow definition with a name'];
		}

		return ['data' => $data, 'error' => null];
	}//end read()
}//end class
