<?php

/**
 * Unit tests for AgentFilesController (agent-related-files).
 *
 * Covers the curated related-files surface backed by the Context system (ADR-024):
 * create-on-demand on the first add, add idempotence by path, remove, the PUT-guard that
 * preserves the agent's other fields when contextRefs is updated, an empty list for an
 * agent with no bundle, the empty-path 400 guard, and the 401-before-service contract.
 *
 * The OpenRegister ObjectService is faked with an in-memory store (find/saveObject
 * callbacks) so the bundle-identity + PUT-guard behaviour is exercised end-to-end without
 * a live register.
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
 * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentFilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-related-files controller.
 *
 * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
 */
class AgentFilesControllerTest extends TestCase
{

    /**
     * In-memory object store shared with the faked ObjectService.
     *
     * Shape: ['agents' => [uuid => data], 'contexts' => [uuid => data],
     * 'saved' => [['schema' => ..., 'uuid' => ..., 'data' => ...], ...]].
     *
     * @var array<string, mixed>
     */
    private array $store = [];

    /**
     * Reset the in-memory store before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = [
            'agents'   => [],
            'contexts' => [],
            'saved'    => [],
        ];

    }//end setUp()

    /**
     * Build an ObjectEntity with a uuid + payload.
     *
     * @param string               $uuid The uuid.
     * @param array<string, mixed> $data The payload.
     *
     * @return ObjectEntity
     */
    private function entity(string $uuid, array $data): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($data);
        return $entity;

    }//end entity()

    /**
     * A faked ObjectService backed by the in-memory store.
     *
     * @return ObjectService
     */
    private function objectService(): ObjectService
    {
        $store = &$this->store;
        $self  = $this;

        $mock = $this->createMock(ObjectService::class);

        $mock->method('find')->willReturnCallback(
            function ($id, $_extend=[], $files=false, $register=null, $schema=null) use (&$store, $self): ?ObjectEntity {
                $bucket = ($schema === 'agent') ? 'agents' : 'contexts';
                if (isset($store[$bucket][$id]) === false) {
                    return null;
                }

                return $self->entity((string) $id, $store[$bucket][$id]);
            }
        );

        $mock->method('saveObject')->willReturnCallback(
            function ($object, $extend=[], $register=null, $schema=null, $uuid=null) use (&$store, $self): ObjectEntity {
                $bucket = ($schema === 'agent') ? 'agents' : 'contexts';
                $data   = is_array($object) === true ? $object : $object->getObject();
                if ($uuid === null || $uuid === '') {
                    $uuid = ($schema === 'agent') ? 'agent-new' : 'ctx-new';
                }

                $store[$bucket][$uuid] = $data;
                $store['saved'][]      = [
                    'schema' => $schema,
                    'uuid'   => $uuid,
                    'data'   => $data,
                ];

                return $self->entity((string) $uuid, $data);
            }
        );

        return $mock;

    }//end objectService()

    /**
     * A request mock returning the given params.
     *
     * @param array<string, mixed> $params The request params keyed by name.
     *
     * @return IRequest
     */
    private function request(array $params=[]): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default=null) use ($params) {
                return ($params[$key] ?? $default);
            }
        );
        return $request;

    }//end request()

    /**
     * A session with the given (or no) user.
     *
     * @param string|null $uid The UID, or null for unauthenticated.
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
     * Build the controller with the given session + request.
     *
     * @param IUserSession  $session The user session.
     * @param IRequest|null $request An optional request mock.
     *
     * @return AgentFilesController
     */
    private function controller(IUserSession $session, ?IRequest $request=null): AgentFilesController
    {
        return new AgentFilesController(
            ($request ?? $this->request()),
            $this->objectService(),
            $session,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * list() returns an empty array for an agent with no files bundle, and never writes.
     *
     * @return void
     */
    public function testListReturnsEmptyForAgentWithoutBundle(): void
    {
        $this->store['agents']['agent-1'] = ['prompt' => 'sys', 'contextRefs' => []];

        $response = $this->controller($this->session('alice'))->list('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([], $response->getData()['files']);
        $this->assertSame([], $this->store['saved']);

    }//end testListReturnsEmptyForAgentWithoutBundle()

    /**
     * list() lists the files from an existing bundle, deriving name from the basename.
     *
     * @return void
     */
    public function testListReturnsBundleFiles(): void
    {
        $this->store['agents']['agent-1']  = ['contextRefs' => ['ctx-1']];
        $this->store['contexts']['ctx-1']  = [
            'name'  => 'Agent files',
            'files' => [['path' => 'Docs/spec.md', 'description' => 'the spec']],
        ];

        $response = $this->controller($this->session('alice'))->list('agent-1');
        $files    = $response->getData()['files'];

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $files);
        $this->assertSame('Docs/spec.md', $files[0]['path']);
        $this->assertSame('spec.md', $files[0]['name']);
        $this->assertSame('the spec', $files[0]['description']);

    }//end testListReturnsBundleFiles()

    /**
     * add() creates the files bundle on the first add, seeds it with the (relative) file,
     * and references it from the agent — preserving the agent's other fields (PUT-guard).
     *
     * @return void
     */
    public function testAddCreatesBundleOnDemand(): void
    {
        $this->store['agents']['agent-1'] = [
            'name'        => 'Support bot',
            'prompt'      => 'You are helpful',
            'status'      => 'active',
            'contextRefs' => [],
        ];

        $request  = $this->request(['path' => '/Docs/spec.md']);
        $response = $this->controller($this->session('alice'), $request)->add('agent-1');
        $files    = $response->getData()['files'];

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $files);
        // Leading slash stripped → relative path.
        $this->assertSame('Docs/spec.md', $files[0]['path']);

        // A Context bundle was created with the reserved sentinel name + first file.
        $contextSaves = array_values(array_filter($this->store['saved'], static fn ($s): bool => $s['schema'] === 'context'));
        $this->assertCount(1, $contextSaves);
        $this->assertSame('Agent files', $contextSaves[0]['data']['name']);
        $this->assertSame('Docs/spec.md', $contextSaves[0]['data']['files'][0]['path']);

        // The agent was re-saved with the bundle referenced AND its other fields intact.
        $agentSaves = array_values(array_filter($this->store['saved'], static fn ($s): bool => $s['schema'] === 'agent'));
        $this->assertCount(1, $agentSaves);
        $this->assertContains($contextSaves[0]['uuid'], $agentSaves[0]['data']['contextRefs']);
        $this->assertSame('You are helpful', $agentSaves[0]['data']['prompt']);
        $this->assertSame('active', $agentSaves[0]['data']['status']);
        $this->assertSame('Support bot', $agentSaves[0]['data']['name']);

    }//end testAddCreatesBundleOnDemand()

    /**
     * add() is idempotent by path — re-adding an existing path (even with a differing
     * leading slash) is a no-op success and writes nothing.
     *
     * @return void
     */
    public function testAddIsIdempotentByPath(): void
    {
        $this->store['agents']['agent-1'] = ['prompt' => 'sys', 'contextRefs' => ['ctx-1']];
        $this->store['contexts']['ctx-1'] = [
            'name'  => 'Agent files',
            'files' => [['path' => 'Docs/spec.md']],
        ];

        $request  = $this->request(['path' => '/Docs/spec.md']);
        $response = $this->controller($this->session('alice'), $request)->add('agent-1');
        $files    = $response->getData()['files'];

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $files);
        // No bundle re-save and no agent re-save (ref already present).
        $this->assertSame([], $this->store['saved']);

    }//end testAddIsIdempotentByPath()

    /**
     * add() appends a distinct path to an existing bundle, preserving the bundle's name
     * and prior files (PUT-guard).
     *
     * @return void
     */
    public function testAddAppendsToExistingBundle(): void
    {
        $this->store['agents']['agent-1'] = ['contextRefs' => ['ctx-1']];
        $this->store['contexts']['ctx-1'] = [
            'name'       => 'Agent files',
            'charBudget' => 8000,
            'files'      => [['path' => 'Docs/spec.md']],
        ];

        $request  = $this->request(['path' => 'Docs/other.md']);
        $response = $this->controller($this->session('alice'), $request)->add('agent-1');
        $files    = $response->getData()['files'];

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(2, $files);

        $contextSaves = array_values(array_filter($this->store['saved'], static fn ($s): bool => $s['schema'] === 'context'));
        $this->assertCount(1, $contextSaves);
        // Untouched fields survive the PUT-semantic save.
        $this->assertSame('Agent files', $contextSaves[0]['data']['name']);
        $this->assertSame(8000, $contextSaves[0]['data']['charBudget']);
        $this->assertCount(2, $contextSaves[0]['data']['files']);

    }//end testAddAppendsToExistingBundle()

    /**
     * remove() drops the matching path and re-saves the bundle with the remainder.
     *
     * @return void
     */
    public function testRemoveDropsPath(): void
    {
        $this->store['agents']['agent-1'] = ['contextRefs' => ['ctx-1']];
        $this->store['contexts']['ctx-1'] = [
            'name'  => 'Agent files',
            'files' => [['path' => 'Docs/spec.md'], ['path' => 'Docs/other.md']],
        ];

        $request  = $this->request(['path' => 'Docs/spec.md']);
        $response = $this->controller($this->session('alice'), $request)->remove('agent-1');
        $files    = $response->getData()['files'];

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $files);
        $this->assertSame('Docs/other.md', $files[0]['path']);

    }//end testRemoveDropsPath()

    /**
     * remove() is idempotent: an unknown path (or no bundle) still returns 200 with the
     * current list and writes nothing.
     *
     * @return void
     */
    public function testRemoveUnknownPathIsIdempotent(): void
    {
        $this->store['agents']['agent-1'] = ['contextRefs' => ['ctx-1']];
        $this->store['contexts']['ctx-1'] = [
            'name'  => 'Agent files',
            'files' => [['path' => 'Docs/spec.md']],
        ];

        $request  = $this->request(['path' => 'Docs/missing.md']);
        $response = $this->controller($this->session('alice'), $request)->remove('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData()['files']);
        $this->assertSame([], $this->store['saved']);

    }//end testRemoveUnknownPathIsIdempotent()

    /**
     * add() rejects an empty/whitespace path with 400 and writes nothing.
     *
     * @return void
     */
    public function testAddEmptyPathIsBadRequest(): void
    {
        $this->store['agents']['agent-1'] = ['contextRefs' => []];

        $request  = $this->request(['path' => '   ']);
        $response = $this->controller($this->session('alice'), $request)->add('agent-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame([], $this->store['saved']);

    }//end testAddEmptyPathIsBadRequest()

    /**
     * add() returns 404 when the agent does not resolve.
     *
     * @return void
     */
    public function testAddUnknownAgentIsNotFound(): void
    {
        $request  = $this->request(['path' => 'Docs/spec.md']);
        $response = $this->controller($this->session('alice'), $request)->add('nope');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testAddUnknownAgentIsNotFound()

    /**
     * list() returns 401 for an unauthenticated caller.
     *
     * @return void
     */
    public function testListUnauthenticated(): void
    {
        $response = $this->controller($this->session(null))->list('agent-1');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testListUnauthenticated()

    /**
     * add() returns 401 for an unauthenticated caller, writing nothing.
     *
     * @return void
     */
    public function testAddUnauthenticated(): void
    {
        $request  = $this->request(['path' => 'Docs/spec.md']);
        $response = $this->controller($this->session(null), $request)->add('agent-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame([], $this->store['saved']);

    }//end testAddUnauthenticated()

    /**
     * remove() returns 401 for an unauthenticated caller.
     *
     * @return void
     */
    public function testRemoveUnauthenticated(): void
    {
        $request  = $this->request(['path' => 'Docs/spec.md']);
        $response = $this->controller($this->session(null), $request)->remove('agent-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testRemoveUnauthenticated()
}//end class
