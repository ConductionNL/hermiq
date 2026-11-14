<?php

/**
 * One stage to run in the runner ExApp.
 *
 * What a caller asks the stage transport to run: the tree and ref the command runs
 * over, the command, who it runs for, the tool tree it comes from, whether it may
 * push, the credentials it may spend and what it reads back. Typed fields rather
 * than an array, because a field silently dropped at this boundary has already cost
 * a release once (`toolRepo`): a misspelt field here is a fatal at the call site.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One stage request, sync or async.
 *
 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
 */
final class StageRequest {

	/**
	 * The stage.
	 *
	 * @param string $repo Clone URL of the tree the command runs OVER.
	 * @param string $ref The ref to check out.
	 * @param array $command The command and its arguments.
	 * @param string|null $uid The acting user's UID.
	 * @param StageOptions $options The tool tree, push, credentials, ceiling and artefacts.
	 */
	public function __construct(
		public readonly string $repo,
		public readonly string $ref,
		public readonly array $command,
		public readonly ?string $uid = null,
		public readonly StageOptions $options = new StageOptions(),
	) {
	}//end __construct()
}//end class
