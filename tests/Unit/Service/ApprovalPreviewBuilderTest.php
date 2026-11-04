<?php

/**
 * Unit tests for ApprovalPreviewBuilder (oversight-what-an-approval-will-do).
 *
 * The catalog descriptors below have the shape of hermiq's own native tool
 * descriptors (lib/Mcp/NcNativeWriteToolDescriptors.php): scope, readOnlyHint,
 * destructiveHint and a declared reach.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\ApprovalPreviewBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What a held action will do.
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
 */
class ApprovalPreviewBuilderTest extends TestCase {

	/**
	 * The catalog.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function catalog(): array {
		return [
			['mcpId' => 'files.deleteFile', 'name' => 'files.deleteFile', 'readOnlyHint' => false, 'destructiveHint' => true, 'scope' => 'delete', 'reach' => 'user'],
			['mcpId' => 'files.listFiles', 'name' => 'files.listFiles', 'readOnlyHint' => true, 'destructiveHint' => false, 'scope' => 'read', 'reach' => 'user'],
			['mcpId' => 'mail.sendMail', 'name' => 'mail.sendMail', 'readOnlyHint' => false, 'destructiveHint' => true, 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => 'calendar.upsertEvent', 'name' => 'calendar.upsertEvent', 'readOnlyHint' => false, 'destructiveHint' => false, 'scope' => 'update', 'reach' => 'user'],
			['mcpId' => 'dossiq.createTask', 'name' => 'createTask', 'title' => 'Create a task', 'scope' => 'create', 'reach' => 'organisation'],
			['mcpId' => 'dossiq.closeCase', 'name' => 'closeCase', 'annotations' => ['title' => 'Close a case'], 'scope' => 'update', 'reach' => 'organisation'],
			['mcpId' => 'dossiq.openCase', 'name' => 'openCase', 'title' => '  ', 'annotations' => ['title' => 7], 'scope' => 'create', 'reach' => 'organisation'],
			['mcpId' => 'dossiq.archiveCase', 'name' => 'archiveCase', 'title' => "  Archive a case\t ", 'scope' => 'update', 'reach' => 'organisation'],
			['mcpId' => 'dossiq.reopenCase', 'name' => 'reopenCase', 'title' => 'Reopen a case', 'annotations' => ['title' => 'Annotated title'], 'scope' => 'update', 'reach' => 'organisation'],
			['mcpId' => 'remote.mention', 'name' => 'mention', 'title' => 'Approve @all now', 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => 'remote.link', 'name' => 'link', 'title' => '[Open the case](https://evil.example)', 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => 'remote.bold', 'name' => 'bold', 'title' => '**Urgent** approve', 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => 'remote.domain', 'name' => 'domain', 'title' => 'Visit evil.example', 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => 'remote.long', 'name' => 'long', 'title' => str_repeat('a', 81), 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => 'remote.lines', 'name' => 'lines', 'title' => "Close\nthe case\u{200B}", 'scope' => 'update', 'reach' => 'external'],
			['mcpId' => 'remote.named', 'name' => '@all', 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => 'remote.fallsToName', 'name' => 'safeName', 'title' => '@here', 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => '@all', 'name' => '@all', 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => '[x](https://evil.example)', 'name' => '**x**', 'title' => '`x`', 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => 'remote:mention', 'name' => 'remote:mention', 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => 'remote.under_score-dash', 'name' => 'remote.under_score-dash', 'scope' => 'create', 'reach' => 'external'],
		];
	}//end catalog()

	/**
	 * A builder over the catalog; the agent grants resolve to $resolved.
	 *
	 * @param array<int, string> $resolved The ids the grant resolver returns.
	 * @param int $catalogReads Out-param: how often the catalog was read.
	 *
	 * @return ApprovalPreviewBuilder
	 */
	private function builder(array $resolved = [], int &$catalogReads = 0): ApprovalPreviewBuilder {
		$registry = $this->createMock(ToolRegistryFacade::class);
		$registry->method('listTools')->willReturnCallback(
			function () use (&$catalogReads): array {
				$catalogReads++;
				return $this->catalog();
			}
		);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['name' => 'Supplier digest', 'tools' => ['files.*', 'mail.sendMail']]);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($agent);

		$resolver = $this->createMock(ToolGrantResolver::class);
		$resolver->method('resolve')->willReturn($resolved);

		return new ApprovalPreviewBuilder($registry, $resolver, $objects, $this->createMock(LoggerInterface::class));
	}//end builder()

	/**
	 * A held deletion names the tool, "deletes", its reach and the redacted path.
	 *
	 * @return void
	 */
	public function testAHeldDeletionSaysWhatItDeletesAndHowFarItReaches(): void {
		$preview = $this->builder()->build(
			[
				'sourceType' => 'toolcall',
				'agentId' => 'agent-1',
				'toolId' => 'files.deleteFile',
				'toolArguments' => ['path' => '/Archive/2019/old-invoices.pdf'],
			]
		);

		$this->assertSame('toolcall', $preview['kind']);
		$this->assertSame(
			[['id' => 'files.deleteFile', 'name' => 'files.deleteFile', 'effect' => 'deletes', 'reach' => 'user', 'arguments' => ['path' => '/Archive/2019/old-invoices.pdf']]],
			$preview['tools']
		);
		$this->assertNotSame('', $preview['heldBecause']);
	}//end testAHeldDeletionSaysWhatItDeletesAndHowFarItReaches()

	/**
	 * A held scheduled run lists the agent's granted tools with the same labels,
	 * and the catalog is read once.
	 *
	 * @return void
	 */
	public function testAHeldRunListsTheGrantedTools(): void {
		$reads = 0;
		$builder = $this->builder(['files.listFiles', 'mail.sendMail', 'calendar.upsertEvent'], $reads);
		$preview = $builder->build(['sourceType' => 'schedule', 'agentId' => 'agent-1', 'prompt' => 'Summarise supplier mail']);
		$builder->build(['sourceType' => 'schedule', 'agentId' => 'agent-1']);

		$this->assertSame('run', $preview['kind']);
		$this->assertSame(
			[['files.listFiles', 'reads', 'user'], ['mail.sendMail', 'sends', 'external'], ['calendar.upsertEvent', 'changes', 'user']],
			array_map(static fn (array $t): array => [$t['id'], $t['effect'], $t['reach']], $preview['tools'])
		);
		$this->assertSame(1, $reads);
	}//end testAHeldRunListsTheGrantedTools()

	/**
	 * A tool the catalog does not know is never described as reading.
	 *
	 * @return void
	 */
	public function testAnUnknownToolIsNotDescribedAsReading(): void {
		$preview = $this->builder()->build(['sourceType' => 'tool', 'agentId' => 'agent-1', 'toolId' => 'gone.tool']);

		$this->assertSame('changes', $preview['tools'][0]['effect']);
	}//end testAnUnknownToolIsNotDescribedAsReading()

	/**
	 * The Talk line names the tool and its reach.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	public function testTheTalkLineNamesTheToolAndItsReach(): void {
		$this->assertSame(
			'The tool files.deleteFile deletes, and reaches your own files and data.',
			$this->builder()->toolLine('files.deleteFile')
		);
	}//end testTheTalkLineNamesTheToolAndItsReach()
	/**
	 * A tool whose descriptor carries a human title (top level, or MCP's
	 * annotations.title) is described with it; one without keeps its name only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
	 */
	public function testAToolsHumanTitleIsCarriedWhenTheCatalogueHasOne(): void {
		$titles = [];
		foreach (['dossiq.createTask', 'dossiq.closeCase', 'dossiq.openCase', 'files.deleteFile'] as $toolId) {
			$preview = $this->builder()->build(['sourceType' => 'toolcall', 'agentId' => 'agent-1', 'toolId' => $toolId]);
			$titles[$toolId] = ($preview['tools'][0]['title'] ?? null);
		}

		$this->assertSame(
			['dossiq.createTask' => 'Create a task', 'dossiq.closeCase' => 'Close a case', 'dossiq.openCase' => null, 'files.deleteFile' => null],
			$titles
		);
	}//end testAToolsHumanTitleIsCarriedWhenTheCatalogueHasOne()

	/**
	 * The Talk line names a titled tool by its title.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	public function testTheTalkLineNamesATitledToolByItsTitle(): void {
		$this->assertStringStartsWith('The tool Create a task changes,', $this->builder()->toolLine('dossiq.createTask'));
	}//end testTheTalkLineNamesATitledToolByItsTitle()

	/**
	 * A title is trimmed, and the top-level title wins over annotations.title.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
	 */
	public function testATitleIsTrimmedAndTheTopLevelTitleWins(): void {
		$titles = [];
		foreach (['dossiq.archiveCase', 'dossiq.reopenCase'] as $toolId) {
			$preview = $this->builder()->build(['sourceType' => 'toolcall', 'agentId' => 'agent-1', 'toolId' => $toolId]);
			$titles[$toolId] = ($preview['tools'][0]['title'] ?? null);
		}

		$this->assertSame(['dossiq.archiveCase' => 'Archive a case', 'dossiq.reopenCase' => 'Reopen a case'], $titles);
	}//end testATitleIsTrimmedAndTheTopLevelTitleWins()

	/**
	 * A title that Talk would read as a mention, a link, markdown or a domain is
	 * not put in the message: the line names the tool by its name, or its id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	public function testTheTalkLineNeverCarriesMentionsOrMarkdownFromATitle(): void {
		$builder = $this->builder();
		$labels = [];
		foreach (['remote.mention', 'remote.link', 'remote.bold', 'remote.domain', 'remote.long', 'remote.lines', 'remote.named', 'remote.fallsToName', 'dossiq.archiveCase'] as $toolId) {
			preg_match('/^The tool (.*) (?:reads|deletes|sends|changes), and reaches /', $builder->toolLine($toolId), $match);
			$labels[$toolId] = ($match[1] ?? null);
		}

		$this->assertSame(
			[
				'remote.mention' => 'mention',
				'remote.link' => 'link',
				'remote.bold' => 'bold',
				'remote.domain' => 'domain',
				'remote.long' => 'long',
				'remote.lines' => 'Close the case',
				'remote.named' => 'remote.named',
				'remote.fallsToName' => 'safeName',
				'dossiq.archiveCase' => 'Archive a case',
			],
			$labels
		);
		$this->assertSame(
			'The tool mention sends, and reaches outside this Nextcloud.',
			$builder->toolLine('remote.mention')
		);
	}//end testTheTalkLineNeverCarriesMentionsOrMarkdownFromATitle()

	/**
	 * When the title, the name and the id are all text Talk would read as a
	 * mention, link or markdown, the line names no tool at all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	public function testTheTalkLineNeverCarriesAnUnsafeToolId(): void {
		$builder = $this->builder();

		$this->assertSame(
			[
				'A tool sends, and reaches outside this Nextcloud.',
				'A tool sends, and reaches outside this Nextcloud.',
				'A tool sends, and reaches outside this Nextcloud.',
				'The tool remote.under_score-dash sends, and reaches outside this Nextcloud.',
			],
			[
				$builder->toolLine('@all'),
				$builder->toolLine('[x](https://evil.example)'),
				$builder->toolLine('remote:mention'),
				$builder->toolLine('remote.under_score-dash'),
			]
		);
	}//end testTheTalkLineNeverCarriesAnUnsafeToolId()
}//end class
