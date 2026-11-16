<?php

/**
 * Unit tests for reading the engine wire format in GitHubTemplateCatalogService
 * (store-through-federated-config, G7).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-1
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
use Psr\Log\NullLogger;

/**
 * During the cutover the store reads a repository the engine published as it
 * reads its own: the skill's `hermiq-skill.json` envelope when `hermiq-skill.md`
 * is absent, and an envelope inside `hermiq-agent-template.json`.
 *
 * @covers \OCA\Hermiq\Service\GitHubTemplateCatalogService
 * @uses   \OCA\Hermiq\Service\EnginePackageEnvelope
 * @uses   \OCA\Hermiq\Service\GitHubCatalogPayload
 * @uses   \OCA\Hermiq\Service\CatalogFetch
 * @uses   \OCA\Hermiq\Service\CatalogCaller
 * @uses   \OCA\Hermiq\Service\CatalogSearch
 * @uses   \OCA\Hermiq\Service\SkillSerializer
 *
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-1
 */
final class GitHubTemplateCatalogEngineFormatTest extends TestCase {

	private const SKILL_MD = "---\nname: meeting-notes\ndescription: Writes the notes of a meeting.\n---\n\nTake notes.";

	/**
	 * The URLs the fake GitHub was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $urls = [];

	/**
	 * A skill repository with only the engine envelope installs its package.
	 *
	 * @return void
	 */
	public function testASkillPublishedByTheEngineReadsItsEnvelope(): void {
		$service = $this->service(files: ['hermiq-skill.json' => $this->skillEnvelope()]);

		$package = $service->fetchPackageFile(kind: GitHubTemplateCatalogService::KIND_SKILL, owner: 'acme', repo: 'notes', ref: 'v2', actingUserId: null);

		$this->assertSame(self::SKILL_MD, $package);
		$this->assertSame(
			[
				'https://api.github.com/repos/acme/notes/contents/hermiq-skill.md?ref=v2',
				'https://api.github.com/repos/acme/notes/contents/hermiq-skill.json?ref=v2',
			],
			$this->urls
		);
	}//end testASkillPublishedByTheEngineReadsItsEnvelope()

	/**
	 * A skill repository in the store's own format is read once, as before.
	 *
	 * @return void
	 */
	public function testANativeSkillIsReadWithoutTheEngineFile(): void {
		$service = $this->service(files: ['hermiq-skill.md' => self::SKILL_MD, 'hermiq-skill.json' => $this->skillEnvelope(package: 'other')]);

		$this->assertSame(self::SKILL_MD, $service->fetchPackageFile(kind: GitHubTemplateCatalogService::KIND_SKILL, owner: 'acme', repo: 'notes', ref: null, actingUserId: null));
		$this->assertSame(['https://api.github.com/repos/acme/notes/contents/hermiq-skill.md'], $this->urls);
	}//end testANativeSkillIsReadWithoutTheEngineFile()

	/**
	 * An engine file that is no skill envelope gives no package.
	 *
	 * @return void
	 */
	public function testAnEngineFileOfAnotherShapeGivesNothing(): void {
		$service = $this->service(files: ['hermiq-skill.json' => '{"type":"hermiq.agent-template","templates":[{"package":"x"}]}']);

		$this->assertNull($service->fetchPackageFile(kind: GitHubTemplateCatalogService::KIND_SKILL, owner: 'acme', repo: 'notes', ref: null, actingUserId: null));
	}//end testAnEngineFileOfAnotherShapeGivesNothing()

	/**
	 * An agent template is never looked for under a second file name.
	 *
	 * @return void
	 */
	public function testAMissingTemplateIsNotLookedForElsewhere(): void {
		$service = $this->service(files: ['hermiq-skill.json' => $this->skillEnvelope()]);

		$this->assertNull($service->fetchPackageFile(kind: GitHubTemplateCatalogService::KIND_AGENT_TEMPLATE, owner: 'acme', repo: 'notes', ref: null, actingUserId: null));
		$this->assertSame(['https://api.github.com/repos/acme/notes/contents/hermiq-agent-template.json'], $this->urls);
	}//end testAMissingTemplateIsNotLookedForElsewhere()

	/**
	 * A template file holding the engine envelope gives the template inside it; a native one is kept.
	 *
	 * @return void
	 */
	public function testATemplateEnvelopeIsUnwrappedAndANativeTemplateKept(): void {
		$envelope = (string)json_encode(['type' => 'hermiq.agent-template', 'version' => '1.0', 'templates' => [['name' => 'Demo', 'package' => '{"name":"Demo"}']]]);
		$service = $this->service(files: ['hermiq-agent-template.json' => $envelope]);
		$this->assertSame('{"name":"Demo"}', $service->fetchTemplateFile(owner: 'acme', repo: 'demo', ref: null, actingUserId: null));

		$native = '{"name":"Native","description":"Kept as it is"}';
		$service = $this->service(files: ['hermiq-agent-template.json' => $native]);
		$this->assertSame($native, $service->fetchTemplateFile(owner: 'acme', repo: 'demo', ref: null, actingUserId: null));
	}//end testATemplateEnvelopeIsUnwrappedAndANativeTemplateKept()

	/**
	 * A search hit published by the engine is an installable card, not unparseable.
	 *
	 * @return void
	 */
	public function testASearchHitPublishedByTheEngineIsInstallable(): void {
		$service = $this->service(
			files: ['hermiq-skill.json' => $this->skillEnvelope()],
			search: ['items' => [['full_name' => 'acme/notes', 'name' => 'notes', 'owner' => ['login' => 'acme'], 'stargazers_count' => 2]]]
		);

		$result = $service->search(query: null, actingUserId: null, kind: GitHubTemplateCatalogService::KIND_SKILL);

		$this->assertCount(1, $result['cards']);
		$this->assertTrue($result['cards'][0]['installable']);
		$this->assertFalse($result['cards'][0]['unparseable']);
		$this->assertSame('meeting-notes', $result['cards'][0]['name']);
	}//end testASearchHitPublishedByTheEngineIsInstallable()

	/**
	 * The engine file is the package, never one of the skill's auxiliary files.
	 *
	 * @return void
	 */
	public function testTheEngineFileIsNoAuxiliaryFile(): void {
		$service = $this->service(
			files: ['hermiq-skill.json' => $this->skillEnvelope(), 'references/steps.md' => 'Step one.'],
			tree: ['hermiq-skill.json', 'references/steps.md']
		);

		$files = $service->fetchAuxFiles(kind: GitHubTemplateCatalogService::KIND_SKILL, owner: 'acme', repo: 'notes', ref: null, actingUserId: null);

		$this->assertSame([['name' => 'references/steps.md', 'content' => 'Step one.']], $files);
	}//end testTheEngineFileIsNoAuxiliaryFile()

	/**
	 * An engine envelope holding one skill package.
	 *
	 * @param string $package The package text.
	 *
	 * @return string
	 */
	private function skillEnvelope(string $package=self::SKILL_MD): string {
		return (string)json_encode(['type' => 'hermiq.skill', 'version' => '1.0', 'skills' => [['name' => 'meeting-notes', 'package' => $package]]]);
	}//end skillEnvelope()

	/**
	 * A catalogue over a fake anonymous GitHub serving these files.
	 *
	 * @param array<string, string>     $files  Repo path to file text.
	 * @param array<string, mixed>|null $search The search answer, if any.
	 * @param array<int, string>        $tree   The repository tree's blob paths.
	 *
	 * @return GitHubTemplateCatalogService
	 */
	private function service(array $files, ?array $search=null, array $tree=[]): GitHubTemplateCatalogService {
		$this->urls = [];
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($files, $search, $tree): IResponse {
				$this->urls[] = $url;
				$status = 404;
				$body = '';
				if (str_contains($url, '/search/repositories') === true && $search !== null) {
					[$status, $body] = [200, (string)json_encode($search)];
				} elseif (str_contains($url, '/git/trees/') === true) {
					$blobs = array_map(static fn (string $path): array => ['type' => 'blob', 'path' => $path], $tree);
					[$status, $body] = [200, (string)json_encode(['tree' => $blobs])];
				} elseif (preg_match('#/contents/([^?]+)#', $url, $match) === 1 && isset($files[rawurldecode($match[1])]) === true) {
					$content = $files[rawurldecode($match[1])];
					[$status, $body] = [200, (string)json_encode(['content' => base64_encode($content), 'encoding' => 'base64'])];
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				$response->method('getBody')->willReturn($body);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$cache = $this->createMock(ICacheFactory::class);
		$cache->method('isAvailable')->willReturn(false);

		return new GitHubTemplateCatalogService(
			clientService: $clients,
			cacheFactory: $cache,
			logger: new NullLogger(),
			archiveExtractor: $this->createMock(GitHubArchiveExtractor::class),
		);
	}//end service()
}//end class
