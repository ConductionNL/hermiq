<?php

/**
 * Unit tests for SkillBundleAnswers, the HTTP answers of the skill-bundle
 * publish and install endpoints, including how dropped entries are reported.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\BundleInstallRun;
use OCA\Hermiq\Controller\BundlePublishRun;
use OCA\Hermiq\Controller\SkillBundleAnswers;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SkillBundleAnswers.
 *
 * @covers \OCA\Hermiq\Controller\SkillBundleAnswers
 * @uses   \OCA\Hermiq\Controller\BundlePublishRun
 * @uses   \OCA\Hermiq\Controller\BundleInstallRun
 */
class SkillBundleAnswersTest extends TestCase {

	/**
	 * A publish run.
	 *
	 * @return BundlePublishRun
	 */
	private function publishRun(): BundlePublishRun {
		return new BundlePublishRun($this->createMock(IUser::class));
	}

	/**
	 * A bad request carries the code.
	 *
	 * @return void
	 */
	public function testBadRequest(): void {
		$response = (new SkillBundleAnswers())->badRequest('unknown_visibility');

		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'unknown_visibility'], $response->getData());
	}

	/**
	 * Nothing publishable is a 400 listing why each entry was left out.
	 *
	 * @return void
	 */
	public function testNothingToPublish(): void {
		$run = $this->publishRun();
		$run->skills = ['payloads' => [], 'outcomes' => [['name' => 's', 'outcome' => 'skipped']]];
		$run->agents = ['payloads' => [], 'outcomes' => [['name' => 'a', 'outcome' => 'skipped']]];

		$response = (new SkillBundleAnswers())->nothingToPublish($run);

		self::assertSame(400, $response->getStatus());
		self::assertSame([
			'error' => 'no_publishable_content',
			'skills' => [['name' => 's', 'outcome' => 'skipped']],
			'agents' => [['name' => 'a', 'outcome' => 'skipped']],
		], $response->getData());
	}

	/**
	 * Any skill or agent payload means there is something to publish.
	 *
	 * @return void
	 */
	public function testSomethingToPublish(): void {
		$answers = new SkillBundleAnswers();
		$skillsOnly = $this->publishRun();
		$skillsOnly->skills['payloads'] = [['name' => 's']];
		$agentsOnly = $this->publishRun();
		$agentsOnly->agents['payloads'] = [['name' => 'a']];

		self::assertNull($answers->nothingToPublish($skillsOnly));
		self::assertNull($answers->nothingToPublish($agentsOnly));
	}

	/**
	 * Published reports the commit and counts, marking dropped entries with their reason.
	 *
	 * @return void
	 */
	public function testPublishedReconcilesDroppedEntries(): void {
		$run = $this->publishRun();
		$run->skills['outcomes'] = [
			['name' => 'one', 'outcome' => 'published'],
			['name' => 'two', 'outcome' => 'published'],
			['name' => 'three', 'outcome' => 'skipped'],
		];
		$run->dropped = [['name' => 'two', 'reason' => 'too_large']];
		$run->agents['outcomes'] = [['name' => 'agent', 'outcome' => 'published'], ['name' => 'big', 'outcome' => 'published']];
		$run->droppedAgents = [['name' => 'big']];

		$response = (new SkillBundleAnswers())->published($run, ['repoUrl' => 'https://github.com/o/r', 'commitSha' => 'abc', 'created' => true, 'extra' => 'ignored']);

		self::assertSame(200, $response->getStatus());
		self::assertSame([
			'repoUrl' => 'https://github.com/o/r',
			'commitSha' => 'abc',
			'created' => true,
			'published' => 1,
			'dropped' => 1,
			'truncated' => true,
			'skills' => [
				['name' => 'one', 'outcome' => 'published'],
				['name' => 'two', 'outcome' => 'dropped', 'reason' => 'too_large'],
				['name' => 'three', 'outcome' => 'skipped'],
			],
			'agentsPublished' => 1,
			'agentsDropped' => 1,
			'agents' => [
				['name' => 'agent', 'outcome' => 'published'],
				['name' => 'big', 'outcome' => 'dropped', 'reason' => 'dropped'],
			],
		], $response->getData());
	}

	/**
	 * Nothing dropped is not truncated.
	 *
	 * @return void
	 */
	public function testPublishedWithoutDrops(): void {
		$run = $this->publishRun();
		$run->skills['outcomes'] = [['name' => 'one', 'outcome' => 'published']];

		$data = (new SkillBundleAnswers())->published($run, ['repoUrl' => 'u', 'commitSha' => 's', 'created' => false])->getData();

		self::assertFalse($data['truncated']);
		self::assertSame(1, $data['published']);
		self::assertSame(0, $data['dropped']);
		self::assertSame(0, $data['agentsPublished']);
		self::assertSame([], $data['agents']);
	}

	/**
	 * Installed reports the counts and outcomes of the install.
	 *
	 * @return void
	 */
	public function testInstalled(): void {
		$run = new BundleInstallRun($this->createMock(IUser::class));
		$run->bundle = ['truncated' => false];
		$run->skillResult = [
			'counts' => ['installed' => 2, 'updated' => 1, 'unchanged' => 3, 'skipped' => 0, 'failed' => 1],
			'outcomes' => [['name' => 's', 'outcome' => 'installed']],
		];
		$run->agentResult = ['outcomes' => [['name' => 'a', 'outcome' => 'updated']]];

		$response = (new SkillBundleAnswers())->installed($run);

		self::assertSame(200, $response->getStatus());
		self::assertSame([
			'installed' => 2,
			'updated' => 1,
			'unchanged' => 3,
			'skipped' => 0,
			'failed' => 1,
			'truncated' => false,
			'skills' => [['name' => 's', 'outcome' => 'installed']],
			'agents' => [['name' => 'a', 'outcome' => 'updated']],
		], $response->getData());
	}
}
