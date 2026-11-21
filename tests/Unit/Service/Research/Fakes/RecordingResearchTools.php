<?php

/**
 * A research toolbox double that records every call (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research\Fakes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research\Fakes;

use Closure;
use OCA\Hermiq\Service\Research\ResearchToolbox;
use OCA\Hermiq\Service\Research\ResearchTools;
use OCA\Hermiq\Service\WebResearch\WebFetchService;

/**
 * Opens itself as the toolbox when the agent was granted the tools, and answers scripted pages.
 */
class RecordingResearchTools implements ResearchTools, ResearchToolbox {

	/**
	 * Whether the agent may search and read the web.
	 *
	 * @var boolean
	 */
	public bool $granted = true;

	/**
	 * What every search finds.
	 *
	 * @var list<array{title: string, url: string}>
	 */
	public array $results = [
		['title' => 'Register Amsterdam', 'url' => 'https://a.example/1'],
		['title' => 'Register Utrecht', 'url' => 'https://a.example/2'],
	];

	/**
	 * Fetch answers per URL; a URL not listed reads as a page.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $fetches = [];

	/**
	 * Called after every fetch with the number of fetches so far.
	 *
	 * @var Closure|null
	 */
	public ?Closure $afterFetch = null;

	/**
	 * The agent the toolbox was opened for.
	 *
	 * @var string
	 */
	public string $openedFor = '';

	/**
	 * Constructor.
	 *
	 * @param EventLog $log Where every search and fetch is noted.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EventLog $log,
	) {
	}//end __construct()

	/**
	 * This toolbox, or null when the agent was not granted the tools.
	 *
	 * @param string $agentId        The agent.
	 * @param string $organisation   The organisation.
	 * @param string $conversationId The conversation.
	 *
	 * @return ResearchToolbox|null
	 */
	public function open(string $agentId, string $organisation, string $conversationId): ?ResearchToolbox {
		$this->openedFor = $agentId;
		if ($this->granted === false) {
			return null;
		}

		return $this;
	}//end open()

	/**
	 * Note the search and answer the results.
	 *
	 * @param string $query The query.
	 *
	 * @return list<array{title: string, url: string}>
	 */
	public function search(string $query): array {
		$this->log->note(event: 'search:' . $query);

		return $this->results;
	}//end search()

	/**
	 * Note the fetch, run the hook, and answer the page.
	 *
	 * @param string $url The URL.
	 *
	 * @return array<string, mixed>
	 */
	public function fetch(string $url): array {
		$this->log->note(event: 'fetch:' . $url);
		if ($this->afterFetch !== null) {
			($this->afterFetch)(count($this->log->starting(prefix: 'fetch:')));
		}

		if (isset($this->fetches[$url]) === true) {
			return $this->fetches[$url];
		}

		$text = 'page two lists Utrecht';
		if ($url === 'https://a.example/1') {
			$text = 'page one says the register is public';
		}

		return ['url' => $url, 'content' => WebFetchService::UNTRUSTED_BEGIN . "\nSource: {$url}\n\n{$text}\n" . WebFetchService::UNTRUSTED_END];
	}//end fetch()
}//end class
