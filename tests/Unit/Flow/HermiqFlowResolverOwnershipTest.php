<?php
/**
 * A resolver must only claim flows it actually owns.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\HermiqFlowResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hermiq\Flow\HermiqFlowResolver
 */
class HermiqFlowResolverOwnershipTest extends TestCase
{

    private const HERMIQ_REGISTER_ID = 99;

    private const HERMIQ_SCHEMA_ID = 111;

    private const FOREIGN_REGISTER_ID = 2487;

    private const FOREIGN_SCHEMA_ID = 5077;


    /**
     * Build a resolver whose ObjectService returns the given object.
     *
     * `find()` returning a foreign object is not a contrived mock: on a live
     * instance it does exactly that, because a uuid resolves cross-table and the
     * register/schema arguments do not scope the lookup.
     *
     * @param ObjectEntity $found The object `find()` will return.
     *
     * @return HermiqFlowResolver The resolver.
     */
    private function resolverFinding(ObjectEntity $found): HermiqFlowResolver
    {
        $objects = $this->createMock(ObjectService::class);
        $objects->method('find')->willReturn($found);

        $register = new Register();
        $register->setId(self::HERMIQ_REGISTER_ID);
        $register->setSlug('hermiq');
        $register->setSchemas([self::HERMIQ_SCHEMA_ID]);

        $registers = $this->createMock(RegisterMapper::class);
        $registers->method('find')->willReturn($register);

        $schema = new Schema();
        $schema->setId(self::HERMIQ_SCHEMA_ID);
        $schema->setSlug('agentflow');

        $schemas = $this->createMock(SchemaMapper::class);
        $schemas->method('find')->willReturn($schema);

        return new HermiqFlowResolver(
            $objects,
            $registers,
            $schemas,
            $this->createMock(LoggerInterface::class)
        );
    }//end resolverFinding()


    /**
     * Build an object in a given store.
     *
     * @param int|string $register The register id.
     * @param int|string $schema   The schema id.
     *
     * @return ObjectEntity The object.
     */
    private function objectIn(int | string $register, int | string $schema): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('11111111-2222-3333-4444-555555555555');
        $object->setRegister($register);
        $object->setSchema($schema);
        $object->setObject(['nodes' => [['id' => 'a']], 'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'a']]]);
        return $object;
    }//end objectIn()


    /**
     * A flow in hermiq's own store is claimed.
     *
     * @return void
     */
    public function testAFlowInHermiqsStoreIsResolved(): void
    {
        $resolver = $this->resolverFinding($this->objectIn(self::HERMIQ_REGISTER_ID, self::HERMIQ_SCHEMA_ID));

        $doc = $resolver->resolveFlow('11111111-2222-3333-4444-555555555555');

        $this->assertIsArray($doc, 'hermiq still resolves its own flows');
        $this->assertSame('11111111-2222-3333-4444-555555555555', $doc['id']);
    }//end testAFlowInHermiqsStoreIsResolved()


    /**
     * A flow in ANOTHER app's store is declined.
     *
     * The regression this guards: `FlowResolverRegistry` takes the first
     * non-null answer, so claiming a foreign flow does not just return the wrong
     * document — it stops the owning app's resolver from ever being asked, and
     * every field this class does not copy (executionMode, and anything added
     * later) is silently dropped.
     *
     * @return void
     */
    public function testAFlowInAnotherAppsStoreIsDeclined(): void
    {
        $resolver = $this->resolverFinding($this->objectIn(self::FOREIGN_REGISTER_ID, self::FOREIGN_SCHEMA_ID));

        $this->assertNull(
            $resolver->resolveFlow('11111111-2222-3333-4444-555555555555'),
            'a foreign flow must be left to the resolver that owns it'
        );
    }//end testAFlowInAnotherAppsStoreIsDeclined()


    /**
     * The right register but the wrong schema is still not hermiq's flow store.
     *
     * @return void
     */
    public function testTheRightRegisterButWrongSchemaIsDeclined(): void
    {
        $resolver = $this->resolverFinding($this->objectIn(self::HERMIQ_REGISTER_ID, 12345));

        $this->assertNull($resolver->resolveFlow('11111111-2222-3333-4444-555555555555'));
    }//end testTheRightRegisterButWrongSchemaIsDeclined()
}//end class
