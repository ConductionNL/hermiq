<?php

/**
 * Placeholders in an agent's instructions (agents-instruction-variables).
 *
 * A table test over every placeholder for one acting person, the person's own
 * time zone for {{today}} and {{now}}, start field answers with their defaults,
 * and the rule that nothing but the instructions is a template: an unknown
 * placeholder stays as written and a value that itself reads like a placeholder
 * is inserted as text, never filled in again.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\Engine\PromptVariableResolver;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for PromptVariableResolver.
 *
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */
class PromptVariableResolverTest extends TestCase {

	/**
	 * A resolver for Fatima el Amrani (Amsterdam, Dutch) at 2026-09-26 23:30 UTC,
	 * which is already 27 September in her time zone.
	 *
	 * @param string $timezone The person's time zone setting ('' = none).
	 *
	 * @return PromptVariableResolver
	 */
	private function resolver(string $timezone = 'Europe/Amsterdam'): PromptVariableResolver {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Fatima el Amrani');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'fatima' ? $user : null));

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, mixed $default = '') use ($timezone): string {
				return match ($key) {
					'timezone' => $timezone,
					'lang' => 'nl',
					default => (string)$default,
				};
			}
		);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $default
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			static function (string $when = 'now', ?DateTimeZone $zone = null): DateTimeImmutable|\DateTime {
				$at = new \DateTime('2026-09-26 23:30:00', new DateTimeZone('UTC'));
				if ($zone !== null) {
					$at->setTimezone($zone);
				}

				return $at;
			}
		);

		$organisation = new Organisation();
		$organisation->setName('Gemeente Tilburg');
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($organisation): Organisation {
				if ($uuid !== 'org-1') {
					throw new RuntimeException('not found');
				}

				return $organisation;
			}
		);

		return new PromptVariableResolver(userManager: $users, config: $config, time: $time, organisations: $organisations);
	}//end resolver()

	/**
	 * Every placeholder resolves to the acting person's value.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function placeholders(): array {
		return [
			'display name' => ['{{user.displayName}}', 'Fatima el Amrani'],
			'user id' => ['{{user.id}}', 'fatima'],
			'language' => ['{{user.language}}', 'nl'],
			'organisation' => ['{{organisation.name}}', 'Gemeente Tilburg'],
			'today, her date not UTC' => ['{{today}}', '2026-09-27'],
			'now, her time' => ['{{now}}', '2026-09-27 01:30'],
			'agent name' => ['{{agent.name}}', 'Vergunningen helper'],
			'app id' => ['{{app.id}}', 'dossiq'],
			'answered field' => ['{{field.department}}', 'Permits'],
			'unanswered field uses its default' => ['{{field.channel}}', 'counter'],
			'unanswered field without default is empty' => ['[{{field.case}}]', '[]'],
			'spaces inside the braces' => ['{{ user.id }}', 'fatima'],
			'unknown placeholder stays' => ['{{user.email}}', '{{user.email}}'],
			'undeclared field stays' => ['{{field.nope}}', '{{field.nope}}'],
		];
	}//end placeholders()

	/**
	 * Each placeholder fills in for one turn.
	 *
	 * @param string $prompt The instructions.
	 * @param string $expected The filled-in text.
	 *
	 * @return void
	 *
	 * @dataProvider placeholders
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public function testEachPlaceholderFillsIn(string $prompt, string $expected): void {
		$variables = $this->resolver()->variablesFor(
			userId: 'fatima',
			agentData: [
				'name' => 'Vergunningen helper',
				'startFields' => [
					['key' => 'department', 'label' => 'Department', 'type' => 'select', 'options' => ['Permits', 'Taxes'], 'required' => true],
					['key' => 'channel', 'label' => 'Channel', 'type' => 'text', 'default' => 'counter'],
					['key' => 'case', 'label' => 'Case number', 'type' => 'text'],
				],
			],
			organisation: 'org-1',
			appContext: ['appId' => 'dossiq'],
			startValues: ['department' => 'Permits']
		);

		$this->assertSame($expected, PromptVariableResolver::fill(prompt: $prompt, variables: $variables));

	}//end testEachPlaceholderFillsIn()

	/**
	 * The spec's scenario sentence, end to end.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public function testTheGreetingScenario(): void {
		$variables = $this->resolver()->variablesFor(userId: 'fatima', agentData: ['name' => 'x']);

		$this->assertSame(
			'Address Fatima el Amrani. Today is 2026-09-27.',
			PromptVariableResolver::fill(prompt: 'Address {{user.displayName}}. Today is {{today}}.', variables: $variables)
		);

	}//end testTheGreetingScenario()

	/**
	 * Without a time zone of her own, the date is the server's (UTC here).
	 *
	 * @return void
	 */
	public function testWithoutAPersonalTimeZoneTheServerZoneIsUsed(): void {
		$variables = $this->resolver(timezone: '')->variablesFor(userId: 'fatima', agentData: []);

		$this->assertSame('2026-09-26', $variables['today']);
		$this->assertSame('2026-09-26 23:30', $variables['now']);

	}//end testWithoutAPersonalTimeZoneTheServerZoneIsUsed()

	/**
	 * A value that reads like a placeholder is text: one pass, never filled in again.
	 *
	 * @return void
	 */
	public function testAValueIsNeverReadAsATemplate(): void {
		$variables = $this->resolver()->variablesFor(
			userId: 'fatima',
			agentData: ['startFields' => [['key' => 'note', 'label' => 'Note', 'type' => 'text']]],
			startValues: ['note' => '{{user.id}}']
		);

		$this->assertSame('Note: {{user.id}}', PromptVariableResolver::fill(prompt: 'Note: {{field.note}}', variables: $variables));

	}//end testAValueIsNeverReadAsATemplate()

	/**
	 * An unknown organisation, app or person leaves an empty value, not an error.
	 *
	 * @return void
	 */
	public function testMissingFactsResolveEmpty(): void {
		$variables = $this->resolver()->variablesFor(userId: 'ghost', agentData: [], organisation: 'org-missing');

		$this->assertSame('ghost', $variables['user.displayName']);
		$this->assertSame('', $variables['organisation.name']);
		$this->assertSame('', $variables['app.id']);

	}//end testMissingFactsResolveEmpty()

	/**
	 * The preview lists the placeholders it could not fill.
	 *
	 * @return void
	 */
	public function testUnknownPlaceholdersAreListed(): void {
		$variables = $this->resolver()->variablesFor(userId: 'fatima', agentData: []);

		$this->assertSame(
			['{{user.email}}', '{{field.x}}'],
			PromptVariableResolver::unknown(prompt: 'Hi {{user.id}} {{user.email}} {{field.x}} {{user.email}}', variables: $variables)
		);

	}//end testUnknownPlaceholdersAreListed()
}//end class
