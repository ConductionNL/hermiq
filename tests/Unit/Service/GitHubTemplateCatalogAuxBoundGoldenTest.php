<?php

/**
 * Golden test for the auxiliary-file bound of GitHubTemplateCatalogService::fetchAuxFiles().
 *
 * The recorded-fixture golden (GitHubBundleFetchGoldenTest) covers the bundle
 * bound; this pins the skill-install one: past 128 auxiliary files the set is
 * cut, the files that made it are returned in tree order, no further file is
 * fetched, and the warning that the installed skill is incomplete is logged
 * once with the owner, repo and limit.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubArchiveExtractor;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The auxiliary-file bound of a skill install.
 */
final class GitHubTemplateCatalogAuxBoundGoldenTest extends TestCase {

	/**
	 * Fetch the auxiliary files of a skill whose tree holds $count of them (plus the package).
	 *
	 * @param int $count How many auxiliary blobs the tree lists.
	 *
	 * @return array{0: array<int, mixed>, 1: array<int, string>, 2: array<int, string>} Files, URLs, warnings.
	 */
	private function fetch(int $count): array {
		$tree = [['type' => 'blob', 'path' => 'hermiq-skill.md']];
		for ($i = 0; $i < $count; $i++) {
			$tree[] = ['type' => 'blob', 'path' => 'refs/f' . $i . '.md'];
		}

		$urls = [];
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($tree, &$urls): IResponse {
				$urls[] = $url;
				$body = (string)json_encode(['tree' => $tree]);
				if (str_contains($url, '/contents/') === true) {
					$body = (string)json_encode(['content' => base64_encode(basename(explode('?', $url)[0])), 'encoding' => 'base64']);
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willReturn($body);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$cache = $this->createMock(ICacheFactory::class);
		$cache->method('isAvailable')->willReturn(false);

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = $message . ' ' . json_encode($context);
			}
		);

		$service = new GitHubTemplateCatalogService(
			clientService: $clients,
			cacheFactory: $cache,
			logger: $logger,
			archiveExtractor: $this->createMock(GitHubArchiveExtractor::class),
		);
		$files = $service->fetchAuxFiles(kind: GitHubTemplateCatalogService::KIND_SKILL, owner: 'acme', repo: 'demo', ref: 'v1', actingUserId: 'alice');

		return [$files, $urls, $warnings];
	}//end fetch()

	/**
	 * At the bound nothing is cut; one past it the last file is dropped, unfetched, with one warning.
	 *
	 * @return void
	 */
	public function testTheAuxiliaryFileBound(): void {
		[$files, $urls, $warnings] = $this->fetch(count: 128);
		$this->assertCount(128, $files);
		$this->assertCount(129, $urls);
		$this->assertSame([], $warnings);
		$this->assertSame(['name' => 'refs/f0.md', 'content' => 'f0.md'], $files[0]);
		$this->assertSame('https://api.github.com/repos/acme/demo/git/trees/v1?recursive=1', $urls[0]);
		$this->assertSame('https://api.github.com/repos/acme/demo/contents/refs/f0.md?ref=v1', $urls[1]);

		[$files, $urls, $warnings] = $this->fetch(count: 130);
		$this->assertCount(128, $files);
		$this->assertSame('refs/f127.md', $files[127]['name']);
		$this->assertCount(129, $urls);
		$this->assertSame(
			['Hermiq skill install: auxiliary file set truncated — the installed skill is INCOMPLETE. {"owner":"acme","repo":"demo","limit":128}'],
			$warnings
		);
	}//end testTheAuxiliaryFileBound()
}//end class
