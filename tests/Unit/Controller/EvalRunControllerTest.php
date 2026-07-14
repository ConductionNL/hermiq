<?php

/**
 * Hermiq EvalRunController unit tests.
 *
 * Covers the owner-guard (IDOR) posture: unauthenticated → 401; a dataset or agent the
 * caller does not own → 404 (never 403, so existence is not confirmed); the happy path
 * delegates to EvalRunService and returns its result (agent-evals).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-run-trigger-endpoint-is-owner-guarded-idor
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\EvalRunController;
use OCA\Hermiq\Service\EvalRunService;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * EvalRunController owner-guard tests (agent-evals).
 *
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-run-trigger-endpoint-is-owner-guarded-idor
 */
class EvalRunControllerTest extends TestCase
{

    /**
     * A user session that resolves to $uid, or null (unauthenticated) when $uid is null.
     *
     * @param string|null $uid The UID, or null for no user.
     *
     * @return IUserSession
     */
    private function session(?string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
            return $session;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        return $session;

    }//end session()

    /**
     * A dataset ObjectEntity owned by $owner.
     *
     * @param string $owner The owner UID.
     *
     * @return ObjectEntity
     */
    private function dataset(string $owner): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('ds-1');
        $entity->setOwner($owner);
        return $entity;

    }//end dataset()

    /**
     * An unauthenticated caller is refused with 401.
     *
     * @return void
     */
    public function testUnauthenticatedReturns401(): void
    {
        $controller = new EvalRunController(
            request: $this->createMock(IRequest::class),
            objectService: $this->createMock(ObjectService::class),
            agentMapper: $this->createMock(AgentMapper::class),
            userSession: $this->session(null),
            evalRunService: $this->createMock(EvalRunService::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->run('ds-1')->getStatus());

    }//end testUnauthenticatedReturns401()

    /**
     * A dataset the caller does not own is 404 — never 403 (no existence oracle).
     *
     * @return void
     */
    public function testNonOwnedDatasetReturns404(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->dataset('someone-else'));

        $controller = new EvalRunController(
            request: $this->createMock(IRequest::class),
            objectService: $objectService,
            agentMapper: $this->createMock(AgentMapper::class),
            userSession: $this->session('alice'),
            evalRunService: $this->createMock(EvalRunService::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->run('ds-1')->getStatus());

    }//end testNonOwnedDatasetReturns404()

    /**
     * An agent the caller does not own is 404, even when the dataset is owned.
     *
     * @return void
     */
    public function testNonOwnedAgentReturns404(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->dataset('alice'));

        $agent = $this->createMock(Agent::class);
        $agent->method('getOwner')->willReturn('bob');
        $agentMapper = $this->createMock(AgentMapper::class);
        $agentMapper->method('findByUuid')->willReturn($agent);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default=null) => $key === 'agentId' ? 'ag-1' : $default
        );

        $controller = new EvalRunController(
            request: $request,
            objectService: $objectService,
            agentMapper: $agentMapper,
            userSession: $this->session('alice'),
            evalRunService: $this->createMock(EvalRunService::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->run('ds-1')->getStatus());

    }//end testNonOwnedAgentReturns404()

    /**
     * When the caller owns both the dataset and the agent, the run delegates to
     * EvalRunService and returns its result.
     *
     * @return void
     */
    public function testOwnedRunDelegatesToService(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->dataset('alice'));

        $agent = $this->createMock(Agent::class);
        $agent->method('getOwner')->willReturn('alice');
        $agentMapper = $this->createMock(AgentMapper::class);
        $agentMapper->method('findByUuid')->willReturn($agent);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default=null) => $key === 'agentId' ? 'ag-1' : $default
        );

        $outcome = ['evalRunId' => 'er-1', 'status' => 'complete', 'passRate' => 100.0, 'regressionGateResult' => 'pass', 'previousPassRate' => null];
        $evalRunService = $this->createMock(EvalRunService::class);
        $evalRunService->expects($this->once())->method('run')->with($this->anything(), $agent)->willReturn($outcome);

        $controller = new EvalRunController(
            request: $request,
            objectService: $objectService,
            agentMapper: $agentMapper,
            userSession: $this->session('alice'),
            evalRunService: $evalRunService,
            logger: $this->createMock(LoggerInterface::class),
        );

        $response = $controller->run('ds-1');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($outcome, $response->getData());

    }//end testOwnedRunDelegatesToService()
}//end class
