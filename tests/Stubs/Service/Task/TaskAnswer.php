<?php

/**
 * Stub of OpenRegister's TaskAnswer, for static analysis and unit tests.
 *
 * The real value object ships with OpenRegister; this mirrors its
 * constructor's first three parameters, the ones hermiq passes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The answer a completing verb carries.
 */
final class TaskAnswer {

	/**
	 * Constructor.
	 *
	 * @param string      $outcome    The outcome.
	 * @param string|null $resultText Free-text result.
	 * @param string|null $comment    The comment, mandatory on a rejecting outcome.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string|null $resultText = null,
		public readonly string|null $comment = null,
	) {
	}//end __construct()
}//end class
