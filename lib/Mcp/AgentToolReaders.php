<?php

/**
 * Hermiq AgentToolReaders
 *
 * The services the agent tools read from: courses, memory and the web.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\Kernel\Contracts\CourseRecommendationEngineInterface;
use OCA\Hermiq\Kernel\Contracts\MemoryServiceInterface;
use OCA\Hermiq\Kernel\Contracts\WebFetchServiceInterface;
use OCA\Hermiq\Kernel\Contracts\WebSearchClientInterface;

/**
 * The services the agent tools read from: courses, memory and the web.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class AgentToolReaders {

	/**
	 * Constructor.
	 *
	 * @param CourseRecommendationEngineInterface $courseEngine Shared engine backing `recommendCourses`
	 *                                                          (ai-course-recommendations) — no
	 *                                                          duplicated gating/scoring/signal-read
	 *                                                          logic.
	 * @param MemoryServiceInterface $memoryService Shared service backing `rememberMemory`/
	 *                                              `recallMemory`/`forgetMemory`
	 *                                              (agent-memory-tools) — no duplicated
	 *                                              append/redact/recall/soft-delete logic.
	 * @param WebSearchClientInterface $webSearchClient Shared client backing `webSearch`
	 *                                                  (web-research-tool) —
	 *                                                  SSRF-guarded call to the
	 *                                                  admin-configured search backend.
	 * @param WebFetchServiceInterface $webFetchService Shared service backing `webFetch`
	 *                                                  (web-research-tool) —
	 *                                                  SSRF-guarded GET + readable-text
	 *                                                  extraction.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly CourseRecommendationEngineInterface $courseEngine,
		public readonly MemoryServiceInterface $memoryService,
		public readonly WebSearchClientInterface $webSearchClient,
		public readonly WebFetchServiceInterface $webFetchService,
	) {
	}//end __construct()
}//end class
