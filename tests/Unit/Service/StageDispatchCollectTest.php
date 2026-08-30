<?php

/**
 * The artefacts a stage produces, on the way out and on the way back.
 *
 * THE VERDICT IS THE FILE, NOT THE EXIT CODE. A reviewer that crashed, ran out
 * of turns, or answered in prose exits 0 exactly like one that reviewed, so a
 * flow judging on the exit code judges nothing. `hydra-verdict.json` is the
 * only artefact that tells them apart.
 *
 * The runner has supported `collect` all along and nothing ever sent it. The
 * flow declared it, the node dropped it, `buildParams()` never wrote it, and
 * `mapResult()` would have discarded the answer anyway — four places, and a
 * gap at any one of them produces the same symptom: `stage.files` absent, the
 * verdict read as `missing`, and the review blocked. Correct refusals, for a
 * reason that was never true.
 *
 * Both directions are pinned here because the failure is silent in each.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\StageDispatchService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Exposes the two protected halves, so the payload and the answer can both be
 * asserted without a network round trip.
 */
class ExposedCollectStageDispatchService extends StageDispatchService {

	/**
	 * @param array $collect The artefacts to collect.
	 *
	 * @return array The built parameters.
	 */
	public function paramsFor(array $collect): array {
		return $this->buildParams(
			repo: 'https://github.com/ConductionNL/larpingapp',
			ref: 'main',
			command: ['claude', '-p', 'review'],
			uid: null,
			credentialId: '',
			ceiling: 1000,
			toolRepo: '',
			toolRef: '',
			push: [],
			pushCredentialId: '',
			llmCredentialId: '',
			collect: $collect
		);
	}//end paramsFor()

	/**
	 * @param string $body The runner's body.
	 *
	 * @return array The mapped result.
	 */
	public function mapResultPublic(string $body): array {
		return $this->mapResult(body: $body);
	}//end mapResultPublic()
}//end class

/**
 * @covers \OCA\Hermiq\Service\StageDispatchService
 *
 * @uses \OCA\Hermiq\Service\Llm\RunTokenService
 */
final class StageDispatchCollectTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var ExposedCollectStageDispatchService
	 */
	private ExposedCollectStageDispatchService $service;

	/**
	 * Build the service with stub collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(originalClassName: ICache::class));

		$this->service = new ExposedCollectStageDispatchService(
			$this->createMock(originalClassName: LoggerInterface::class),
			new RunTokenService(
				$cacheFactory,
				$this->createMock(originalClassName: ISecureRandom::class),
				$this->createMock(originalClassName: LoggerInterface::class)
			)
		);

	}//end setUp()

	/**
	 * A declared artefact reaches the runner.
	 *
	 * The ALIAS form is what a flow can actually use: an array keys each file
	 * by its own path, and a flow addresses data with a DOTTED path, so
	 * `files.hydra-verdict.json` splits in the wrong places and is unreachable.
	 * Collecting a file the consumer cannot address is the feature failing at
	 * its last step.
	 *
	 * @return void
	 */
	public function testADeclaredArtefactReachesTheRunner(): void {
		$params = $this->service->paramsFor(['verdict' => 'hydra-verdict.json']);

		$this->assertSame(
			expected: ['verdict' => 'hydra-verdict.json'],
			actual: ($params['collect'] ?? null),
			message: 'a collect the runner never receives is a stage whose artefact dies with the clone'
		);

	}//end testADeclaredArtefactReachesTheRunner()

	/**
	 * A stage that declares nothing sends no `collect` key at all.
	 *
	 * @return void
	 */
	public function testAStageThatDeclaresNothingSendsNoCollect(): void {
		$this->assertArrayNotHasKey(
			key: 'collect',
			array: $this->service->paramsFor([]),
			message: 'every stage that shipped before this key must be unchanged by it'
		);

	}//end testAStageThatDeclaresNothingSendsNoCollect()

	/**
	 * The collected artefacts survive the mapping back.
	 *
	 * Dropping them here would make `collect` a no-op that still looks like it
	 * works: the runner reads the files, returns them, and the mapper discards
	 * them one step before the only consumer.
	 *
	 * @return void
	 */
	public function testCollectedArtefactsSurviveTheMappingBack(): void {
		$result = $this->service->mapResultPublic(
			body: (string)json_encode(
				[
					'exitCode' => 0,
					'output' => 'reviewed',
					'files' => ['verdict' => ['verdict' => 'pass', 'checks_run' => ['hydra-gates']]],
				]
			)
		);

		$this->assertSame(
			expected: 'pass',
			actual: $result['files']['verdict']['verdict'],
			message: 'the artefact must reach the verdict step, or every review reads as missing'
		);
		$this->assertContains(
			needle: 'hydra-gates',
			haystack: $result['files']['verdict']['checks_run'],
			message: 'the gates declaration is what the reviewer guard is asked to check'
		);

	}//end testCollectedArtefactsSurviveTheMappingBack()

	/**
	 * A result with no artefacts still maps, and grows no empty `files` key.
	 *
	 * An empty `files` would read downstream as "collected nothing" rather than
	 * "collected nothing because none was asked for" — and the verdict step
	 * cannot tell those apart.
	 *
	 * @return void
	 */
	public function testAResultWithoutArtefactsGrowsNoFilesKey(): void {
		$result = $this->service->mapResultPublic(
			body: (string)json_encode(['exitCode' => 0, 'output' => 'ran'])
		);

		$this->assertSame(expected: 0, actual: $result['exitCode']);
		$this->assertArrayNotHasKey(key: 'files', array: $result);

	}//end testAResultWithoutArtefactsGrowsNoFilesKey()
}//end class
