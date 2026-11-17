<?php

/**
 * Hermiq OutsideAgentGateway.
 *
 * One call from an AI agent outside this instance, from the two gates it has to
 * pass to the fields it is allowed to receive back.
 *
 * The order is load-bearing. First the registration: it has to exist, be enabled,
 * and list the tool, default-denying anything that writes by OpenRegister's own
 * classification rather than a second rule written here. Then the owning app, which
 * authorises the act for the calling principal exactly as it would for that person
 * at a screen. Neither gate is sufficient alone: a person who may write a case and
 * an agent that was not granted the write tool still cannot write it.
 *
 * A registration carries no rights of its own. It can only narrow what its
 * principal may already do, which is why revoking a person's access revokes their
 * agent's on the next call with nothing to edit here. The alternative, standing
 * rights on the registration, would be a second permission model beside
 * Nextcloud's, and the second one is always the one that is out of date.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\OutsideAgent
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
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\OutsideAgent;

use OCA\Hermiq\Kernel\Contracts\OutsideAgentGatewayInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a registration, applies both gates and narrows the response.
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-every-outside-call-must-be-authorised-as-the-calling-principal
 */
class OutsideAgentGateway implements OutsideAgentGatewayInterface {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for the outside-agent registrations.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'agentoutsideregistration';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister read/write path for registrations.
	 * @param OutsideToolSurface $surface What the owning apps declared reachable.
	 * @param ToolRegistryFacade $toolRegistryFacade Invokes the tool under the caller's own rights.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly OutsideToolSurface $surface,
		private readonly ToolRegistryFacade $toolRegistryFacade,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The registration belonging to one principal, or null when there is none.
	 *
	 * @param string $principal The Nextcloud user the outside agent authenticated as.
	 *
	 * @return array<string, mixed>|null The registration data, or null.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-outside-agent-must-reach-declared-tools-through-a-registration
	 */
	public function registrationFor(string $principal): ?array {
		if (trim($principal) === '') {
			return null;
		}

		foreach ($this->registrationObjects(principal: $principal) as $object) {
			$data = $this->registrationData(object: $object, principal: $principal);
			if ($data !== null) {
				return $data;
			}
		}

		return null;
	}//end registrationFor()

	/**
	 * The stored registrations filtered on the principal; a failed read is logged and finds none.
	 *
	 * @param string $principal The principal.
	 *
	 * @return array<int|string, mixed>
	 */
	private function registrationObjects(string $principal): array {
		try {
			return $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SCHEMA_SLUG)
				->findAll(config: ['filters' => ['principal' => $principal], 'limit' => 50], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not read the outside-agent registrations: ' . $e->getMessage(),
				['exception' => $e]
			);

			return [];
		}
	}//end registrationObjects()

	/**
	 * One stored object's registration data with its id, when it is this principal's.
	 *
	 * @param mixed  $object    One result of the registration read.
	 * @param string $principal The principal.
	 *
	 * @return array<string, mixed>|null
	 */
	private function registrationData(mixed $object, string $principal): ?array {
		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		$data = $object->getObject();
		if ((string)($data['principal'] ?? '') !== $principal) {
			return null;
		}

		$data['id'] = (string)($object->getUuid() ?? '');
		return $data;
	}//end registrationData()

	/**
	 * Gate one: the registration exists, is enabled, and lists this tool.
	 *
	 * An empty grant list is not "everything": a tool that writes is refused
	 * unless it is named, which is the same default-deny rule the per-agent grants
	 * already state.
	 *
	 * @param array<string, mixed>|null $registration The principal's registration.
	 * @param string $toolId The tool being called.
	 * @param string $principal The calling principal.
	 *
	 * @return void
	 *
	 * @throws OutsideCallRefusedException When the gate refuses.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-write-tool-is-denied-unless-granted
	 */
	public function assertGranted(?array $registration, string $toolId, string $principal): void {
		$call = new OutsideCall(toolId: $toolId, principal: $principal);
		$open = $this->openRegistration(registration: $registration, call: $call);
		$descriptor = $this->surface->describe(toolId: $toolId);
		if ($descriptor === null) {
			throw $call->refused(gate: OutsideCallRefusedException::GATE_SURFACE, reason: 'no app declares this tool reachable from outside the instance');
		}

		if (in_array($toolId, $this->grantedTools(registration: $open), true) === true) {
			return;
		}

		throw $this->ungranted(call: $call, descriptor: $descriptor);
	}//end assertGranted()

	/**
	 * The registration, refused when there is none or it is switched off.
	 *
	 * @param array<string, mixed>|null $registration The principal's registration.
	 * @param OutsideCall               $call         The call being gated.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws OutsideCallRefusedException At the registration gate.
	 */
	private function openRegistration(?array $registration, OutsideCall $call): array {
		if ($registration === null) {
			throw $call->refused(gate: OutsideCallRefusedException::GATE_REGISTRATION, reason: 'no outside-agent registration exists for this principal');
		}

		if (($registration['enabled'] ?? true) === false) {
			throw $call->refused(gate: OutsideCallRefusedException::GATE_REGISTRATION, reason: 'the registration is switched off');
		}

		return $registration;
	}//end openRegistration()

	/**
	 * The tool ids a registration names, as text; anything but a list names none.
	 *
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return array<int|string, string>
	 */
	private function grantedTools(array $registration): array {
		$granted = ($registration['tools'] ?? []);
		if (is_array($granted) === false) {
			return [];
		}

		return array_map('strval', $granted);
	}//end grantedTools()

	/**
	 * The grant-gate refusal for a declared tool the registration does not name.
	 *
	 * @param OutsideCall          $call       The call being gated.
	 * @param array<string, mixed> $descriptor The tool's declared descriptor.
	 *
	 * @return OutsideCallRefusedException
	 */
	private function ungranted(OutsideCall $call, array $descriptor): OutsideCallRefusedException {
		if ($this->surface->writes(toolId: $call->toolId, descriptor: $descriptor) === true) {
			return $call->refused(
				gate: OutsideCallRefusedException::GATE_GRANT,
				reason: 'this tool writes, and a tool that writes is denied unless the registration names it'
			);
		}

		return $call->refused(gate: OutsideCallRefusedException::GATE_GRANT, reason: 'the registration does not name this tool');
	}//end ungranted()

	/**
	 * Run one call: gate one here, gate two in the owning app.
	 *
	 * Nothing in this method impersonates anybody. The call runs as whoever is
	 * already authenticated, so the owning app's own authoriser decides, and a
	 * registration cannot reach a case its principal may not.
	 *
	 * @param string $principal The authenticated caller.
	 * @param string $toolId The tool to call.
	 * @param array<string, mixed> $arguments The call arguments, passed through untouched.
	 *
	 * @return array{result: mixed, isError: bool} The owning app's response, narrowed to the registration's fields.
	 *
	 * @throws OutsideCallRefusedException When either gate refuses.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
	 */
	public function call(string $principal, string $toolId, array $arguments): array {
		$registration = $this->registrationFor(principal: $principal);

		$this->assertGranted(registration: $registration, toolId: $toolId, principal: $principal);

		$envelope = $this->invokeAsCaller(call: new OutsideCall(toolId: $toolId, principal: $principal), arguments: $arguments);
		$payload = ($envelope['result'] ?? []);
		if (is_array($payload) === false) {
			return ['result' => $payload, 'isError' => false];
		}

		return [
			'result' => $this->narrow(registration: (array)$registration, result: $payload),
			'isError' => false,
		];
	}//end call()

	/**
	 * Invoke the tool as the authenticated caller; a throw or an error envelope is the owning app's refusal.
	 *
	 * @param OutsideCall          $call      The call.
	 * @param array<string, mixed> $arguments The call arguments, passed through untouched.
	 *
	 * @return array<string, mixed> The facade's envelope.
	 *
	 * @throws OutsideCallRefusedException At the owning-app gate.
	 */
	private function invokeAsCaller(OutsideCall $call, array $arguments): array {
		try {
			$envelope = $this->toolRegistryFacade->invokeTool(toolId: $call->toolId, arguments: $arguments);
		} catch (Throwable $e) {
			// The owning app refused, or failed. Either way nothing here can grant
			// what it withheld, and the refusal is reported as its own gate.
			throw $call->refused(gate: OutsideCallRefusedException::GATE_OWNING_APP, reason: $e->getMessage());
		}

		// The facade reports a refusal in its envelope rather than by throwing, and
		// an error read as a result is exactly how an unauthorised read becomes a
		// successful-looking response. So the envelope is checked, not assumed.
		if (($envelope['isError'] ?? false) === true) {
			throw $call->refused(gate: OutsideCallRefusedException::GATE_OWNING_APP, reason: $this->errorText(envelope: $envelope));
		}

		return $envelope;
	}//end invokeAsCaller()

	/**
	 * The owning app's own words for why it refused, when it gave any.
	 *
	 * @param array<string, mixed> $envelope The facade's response envelope.
	 *
	 * @return string The refusal text.
	 */
	private function errorText(array $envelope): string {
		$result = ($envelope['result'] ?? []);
		if (is_array($result) === true && is_scalar($result['error'] ?? null) === true) {
			return (string)$result['error'];
		}

		return 'the owning app refused this call for this principal';
	}//end errorText()

	/**
	 * Narrow a response to the fields the registration allows.
	 *
	 * Narrowing only. Nothing here renames, reshapes or computes a value: a filter
	 * that transformed values would hold a second copy of the owning app's data
	 * model, and a field renamed there would then silently produce a wrong shape
	 * here. An empty allowlist means the owning app's own response, untouched.
	 *
	 * The response is keyed by `array-key`, not `string`, and that is load-bearing:
	 * an owning app may answer with a LIST of rows, which the `array_is_list`
	 * branch below exists to handle. Declared as `array<string, mixed>` the branch
	 * was dead by its own type, and psalm said so (NoValue, "all possible types for
	 * this argument were invalidated"). The runtime always expected both shapes;
	 * only the docblock disagreed.
	 *
	 * @param array<string, mixed> $registration The registration.
	 * @param array<array-key, mixed> $result The owning app's response.
	 *
	 * @return array<array-key, mixed> The narrowed response.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-a-registration-may-narrow-what-a-tool-response-carries
	 */
	public function narrow(array $registration, array $result): array {
		$allowlist = ($registration['fieldAllowlist'] ?? []);
		if (is_array($allowlist) === false || $allowlist === []) {
			return $result;
		}

		$allowlist = array_map('strval', $allowlist);

		if (array_is_list($result) === true) {
			return array_map(fn (mixed $row): mixed => $this->keepInRow(row: $row, allowlist: $allowlist), $result);
		}

		return $this->keep(row: $result, allowlist: $allowlist);
	}//end narrow()

	/**
	 * Narrow one row of a list response; a row that is not an array passes as it is.
	 *
	 * @param mixed              $row       One row.
	 * @param array<int, string> $allowlist The allowed keys.
	 *
	 * @return mixed
	 */
	private function keepInRow(mixed $row, array $allowlist): mixed {
		if (is_array($row) === true) {
			return $this->keep(row: $row, allowlist: $allowlist);
		}

		return $row;
	}//end keepInRow()

	/**
	 * Keep the allowed keys of one row, with the owning app's own names and the
	 * owning app's own values.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<int, string> $allowlist The permitted field names.
	 *
	 * @return array<string, mixed> The narrowed row.
	 */
	private function keep(array $row, array $allowlist): array {
		$kept = [];
		foreach ($allowlist as $field) {
			if (array_key_exists($field, $row) === true) {
				$kept[$field] = $row[$field];
			}
		}

		return $kept;
	}//end keep()
}//end class
