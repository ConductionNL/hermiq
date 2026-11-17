<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Controller
 * @package   OCA\Hermiq\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

/**
 * One page of the advisory Approvals about one subject, as the AI oversight routes
 * ask for it: the origin app, the subject and its type, and the page.
 *
 * @spec openspec/specs/ai-oversight/spec.md
 */
final class SubjectListing {

	/**
	 * The listing.
	 *
	 * @param string $originApp   The origin app id.
	 * @param string $subjectId   The subject's id in that app.
	 * @param string $subjectType The subject type, optional.
	 * @param int    $limit       Page size.
	 * @param int    $offset      Paging offset.
	 */
	public function __construct(
		public readonly string $originApp,
		public readonly string $subjectId,
		public readonly string $subjectType='',
		public readonly int $limit=25,
		public readonly int $offset=0,
	) {
	}//end __construct()

	/**
	 * Whether both the origin app and the subject id are given.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/ai-oversight/spec.md
	 */
	public function isComplete(): bool {
		return $this->originApp !== '' && $this->subjectId !== '';
	}//end isComplete()

	/**
	 * The same subject, at another page.
	 *
	 * @param int $offset Paging offset.
	 * @param int $limit  Page size.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/ai-oversight-subject-export/specs/ai-oversight/spec.md
	 */
	public function atPage(int $offset, int $limit): self {
		return new self(
			originApp: $this->originApp,
			subjectId: $this->subjectId,
			subjectType: $this->subjectType,
			limit: $limit,
			offset: $offset
		);
	}//end atPage()
}//end class
