<?php

/**
 * The public chat schemas: a channel is a declaration and a conversation carries no identity.
 *
 * Pins delivery-public-web-chat task 1 (REQ-PUBCHAT-001): the register ships
 * PublicChannel and PublicConversation and lists both, a channel is off by
 * default and only admins change it, and a conversation is readable by admins
 * only and has no place for a user or an IP address.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Guards a declarative register file, not a PHP class.
 */
class PublicChatSchemasTest extends TestCase {

	/**
	 * The shipped register, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'), true);
		$this->assertIsArray($register, 'The register must be valid JSON.');

		return $register;
	}//end register()

	/**
	 * Both schemas ship, are listed in the register and moved its version.
	 *
	 * @return void
	 */
	public function testBothSchemasShipAndAreListed(): void {
		$register = $this->register();
		$schemas = $register['components']['schemas'];

		$this->assertSame('agentpublicchannel', $schemas['PublicChannel']['slug']);
		$this->assertSame('agentpublicconversation', $schemas['PublicConversation']['slug']);
		$listed = $register['components']['registers']['hermiq']['schemas'];
		$this->assertContains('agentpublicchannel', $listed);
		$this->assertContains('agentpublicconversation', $listed);
		$this->assertTrue(version_compare($register['info']['version'], '0.52.0', '>'), 'the import is gated on the register version');
	}//end testBothSchemasShipAndAreListed()

	/**
	 * A channel is off by default, names its agent, and only admins change it.
	 *
	 * @return void
	 */
	public function testAChannelIsAnAdminDeclarationThatStartsOff(): void {
		$channel = $this->register()['components']['schemas']['PublicChannel'];

		$this->assertSame(false, $channel['properties']['enabled']['default']);
		$this->assertSame(500, $channel['properties']['dailyQuestionLimit']['default']);
		$this->assertSame(['website', 'app'], $channel['properties']['kind']['enum']);
		$this->assertStringContainsString('AI assistant', $channel['properties']['disclosureText']['default']);
		$this->assertSame(['name', 'kind', 'agentId'], $channel['required']);
		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertSame(['admin'], $channel['authorization'][$action], $action . ' is for admins only');
		}
	}//end testAChannelIsAnAdminDeclarationThatStartsOff()

	/**
	 * A conversation is for admins only and has nowhere to keep who asked.
	 *
	 * @return void
	 */
	public function testAConversationKeepsNoIdentity(): void {
		$conversation = $this->register()['components']['schemas']['PublicConversation'];

		$this->assertSame(['admin'], $conversation['authorization']['read']);
		$fields = array_merge(
			array_keys($conversation['properties']),
			array_keys($conversation['properties']['turns']['items']['properties'])
		);
		foreach (['userId', 'user', 'ip', 'ipAddress', 'remoteAddress', 'email'] as $identity) {
			$this->assertNotContains($identity, $fields);
		}
	}//end testAConversationKeepsNoIdentity()
}//end class
