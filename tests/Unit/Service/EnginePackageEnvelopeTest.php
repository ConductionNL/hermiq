<?php

/**
 * Unit tests for EnginePackageEnvelope (store-through-federated-config, G7).
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

use OCA\Hermiq\Service\EnginePackageEnvelope;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use PHPUnit\Framework\TestCase;

/**
 * The engine's `{type, version, skills|templates}` envelope gives up its first
 * package; anything else is not an envelope.
 *
 * @covers \OCA\Hermiq\Service\EnginePackageEnvelope
 *
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-1
 */
final class EnginePackageEnvelopeTest extends TestCase {

	/**
	 * A skill envelope gives its first non-empty package.
	 *
	 * @return void
	 */
	public function testASkillEnvelopeGivesItsFirstPackage(): void {
		$envelope = (string)json_encode(
			[
				'type' => 'hermiq.skill',
				'version' => '1.0',
				'skills' => [['name' => 'empty', 'package' => '  '], 'junk', ['name' => 'a', 'package' => "---\nname: a\n---\nBody"], ['name' => 'b', 'package' => 'second']],
				'provenance' => ['publicKey' => 'k', 'signature' => 's'],
			]
		);

		$this->assertSame("---\nname: a\n---\nBody", EnginePackageEnvelope::packageOf(kind: GitHubTemplateCatalogService::KIND_SKILL, contents: $envelope));
	}//end testASkillEnvelopeGivesItsFirstPackage()

	/**
	 * An agent-template envelope reads its `templates` list.
	 *
	 * @return void
	 */
	public function testATemplateEnvelopeReadsItsTemplates(): void {
		$envelope = (string)json_encode(['type' => 'hermiq.agent-template', 'version' => '1.0', 'templates' => [['name' => 'Demo', 'package' => '{"name":"Demo"}']]]);

		$this->assertSame('{"name":"Demo"}', EnginePackageEnvelope::packageOf(kind: GitHubTemplateCatalogService::KIND_AGENT_TEMPLATE, contents: $envelope));
	}//end testATemplateEnvelopeReadsItsTemplates()

	/**
	 * Text that is no envelope of the kind is not unwrapped.
	 *
	 * @return void
	 */
	public function testAnythingElseIsNoEnvelope(): void {
		$skill = GitHubTemplateCatalogService::KIND_SKILL;
		$template = GitHubTemplateCatalogService::KIND_AGENT_TEMPLATE;

		$this->assertNull(EnginePackageEnvelope::packageOf(kind: $skill, contents: "---\nname: a\n---\n"));
		$this->assertNull(EnginePackageEnvelope::packageOf(kind: $template, contents: '{"name":"Demo","description":"A native template"}'));
		$this->assertNull(EnginePackageEnvelope::packageOf(kind: $template, contents: '{"type":"hermiq.skill","skills":[{"package":"x"}]}'));
		$this->assertNull(EnginePackageEnvelope::packageOf(kind: $template, contents: '{"type":"other.app","templates":[{"package":"x"}]}'));
		$this->assertNull(EnginePackageEnvelope::packageOf(kind: $skill, contents: '{"skills":[{"package":"x"}]}'));
		$this->assertNull(EnginePackageEnvelope::packageOf(kind: $skill, contents: '{"type":"hermiq.skill","skills":"x"}'));
		$this->assertNull(EnginePackageEnvelope::packageOf(kind: $skill, contents: '{"type":"hermiq.skill","skills":[{"package":7}]}'));
		$this->assertNull(EnginePackageEnvelope::packageOf(kind: $skill, contents: '{"type":"hermiq.skill"}'));
		$this->assertNull(EnginePackageEnvelope::packageOf(kind: 'skill-bundle', contents: '{"type":"hermiq.skill","skills":[{"package":"x"}]}'));
	}//end testAnythingElseIsNoEnvelope()
}//end class
