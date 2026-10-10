<?php

/**
 * Unit tests for AiFeatureRunController: a gate's refusal is a 422 naming the
 * gate, a service refusal keeps its status, and a run answers its output.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#scenario-a-refusal-names-the-gate-that-refused
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AiFeatureRunController;
use OCA\Hermiq\Service\AiFeature\DocumentFeatureRun;
use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\AiFeature\ResidencyViolationException;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\Hermiq\Controller\AiFeatureRunController
 */
class AiFeatureRunControllerTest extends TestCase {

	/**
	 * Build the controller over a run that answers or throws.
	 *
	 * @param array<string, mixed>|Throwable $outcome  What the run answers or throws.
	 * @param bool                           $signedIn Whether somebody is signed in.
	 *
	 * @return AiFeatureRunController The controller.
	 */
	private function controller(array|Throwable $outcome, bool $signedIn = true): AiFeatureRunController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (['documentReference' => '42', 'instruction' => 'Summarise'][$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$run = $this->createMock(DocumentFeatureRun::class);
		if ($outcome instanceof Throwable) {
			$run->method('run')->willThrowException($outcome);
		} else {
			$run->method('run')->with('alice', 'document-summary', '42', 'Summarise')->willReturn($outcome);
		}

		return new AiFeatureRunController(request: $request, run: $run, userSession: $session, logger: new NullLogger());
	}//end controller()

	/**
	 * A run answers the output.
	 *
	 * @return void
	 */
	public function testARunAnswersItsOutput(): void {
		$response = $this->controller(outcome: ['feature' => 'document-summary', 'documentReference' => '42', 'output' => 'Verleend.', 'notices' => []])
			->runOnDocument(slug: 'document-summary');

		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertSame(expected: 'Verleend.', actual: $response->getData()['output']);
	}//end testARunAnswersItsOutput()

	/**
	 * Each gate's refusal is a 422 naming that gate.
	 *
	 * @return void
	 */
	public function testEachGateRefusalNamesItsGate(): void {
		$refusals = [
			'redaction' => new RedactionRequiredException(featureSlug: 'document-summary', documentReference: '42', reason: 'no redaction recorded'),
			'residency' => new ResidencyViolationException(featureSlug: 'document-summary', requiredResidency: 'eu', actualResidency: 'us', provider: 'openai'),
			'guardrail' => new GuardrailBlockedException(reason: 'bsn'),
		];

		foreach ($refusals as $gate => $refusal) {
			$response = $this->controller(outcome: $refusal)->runOnDocument(slug: 'document-summary');

			self::assertSame(expected: 422, actual: $response->getStatus(), message: $gate);
			self::assertSame(expected: $gate, actual: $response->getData()['gate']);
		}
	}//end testEachGateRefusalNamesItsGate()

	/**
	 * A service refusal keeps its status; anything else is a 500; nobody signed in is 401.
	 *
	 * @return void
	 */
	public function testStatusesAreKeptOrHidden(): void {
		self::assertSame(expected: 400, actual: $this->controller(outcome: new RuntimeException('A feature run on a document needs the document reference', 400))->runOnDocument(slug: 'x')->getStatus());
		self::assertSame(expected: 404, actual: $this->controller(outcome: new RuntimeException('Document not found', 404))->runOnDocument(slug: 'x')->getStatus());
		self::assertSame(expected: 500, actual: $this->controller(outcome: new RuntimeException('boom', 0))->runOnDocument(slug: 'x')->getStatus());
		self::assertSame(expected: 401, actual: $this->controller(outcome: [], signedIn: false)->runOnDocument(slug: 'x')->getStatus());
	}//end testStatusesAreKeptOrHidden()
}//end class
