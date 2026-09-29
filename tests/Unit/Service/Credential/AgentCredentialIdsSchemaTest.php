<?php

/**
 * The Agent's `credentialIds` against the REAL Agent schema fragment
 * (operations-a-credential-per-agent).
 *
 * The agent form saves `credentialIds` into OpenRegister; this validates that exact
 * payload shape against the schema in lib/Settings/hermiq_register.json with Opis, so
 * a write the schema would refuse fails here rather than live.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Credential;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Validates Agent payloads carrying credentialIds.
 *
 * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001
 */
class AgentCredentialIdsSchemaTest extends TestCase {

	/**
	 * The Agent schema from the register file, with OpenRegister's slug `$ref`s removed
	 * (they name schemas, not JSON pointers).
	 *
	 * @return object The schema.
	 */
	private function agentSchema(): object {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->Agent;
		$this->stripSlugRefs($schema);

		return $schema;
	}//end agentSchema()

	/**
	 * Remove every `$ref` that is not a JSON pointer, recursively.
	 *
	 * @param mixed $node A schema node.
	 *
	 * @return void
	 */
	private function stripSlugRefs(mixed $node): void {
		if (is_object($node) === false && is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $child) {
			if ($key === '$ref' && is_string($child) === true && str_starts_with($child, '#') === false) {
				unset($node->{'$ref'});
				continue;
			}

			$this->stripSlugRefs($child);
		}
	}//end stripSlugRefs()

	/**
	 * Validate a payload against the Agent schema.
	 *
	 * @param array<string, mixed> $payload The payload the form saves.
	 *
	 * @return bool Whether it is valid.
	 */
	private function valid(array $payload): bool {
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $this->agentSchema())->isValid();
	}//end valid()

	/**
	 * An agent with its own OpenAI key saves.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001
	 */
	public function testAnAgentWithItsOwnKeySaves(): void {
		$this->assertTrue(
			$this->valid(['name' => 'Supplier digest', 'credentialIds' => ['openai' => '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a5c']])
		);
		$this->assertTrue($this->valid(['name' => 'Permit helper']), 'The pin is optional.');
		$this->assertTrue($this->valid(['name' => 'Permit helper', 'credentialIds' => new \stdClass()]));

	}//end testAnAgentWithItsOwnKeySaves()

	/**
	 * A pin that is not a credential uuid is refused, and so is a list in place of the map.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001
	 */
	public function testAPinThatIsNotAUuidIsRefused(): void {
		$this->assertFalse($this->valid(['name' => 'Supplier digest', 'credentialIds' => ['openai' => 'sk-live-not-a-reference']]));
		$this->assertFalse($this->valid(['name' => 'Supplier digest', 'credentialIds' => ['5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a5c']]));

	}//end testAPinThatIsNotAUuidIsRefused()
}//end class
