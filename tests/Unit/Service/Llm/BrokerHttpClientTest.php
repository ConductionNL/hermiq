<?php

/**
 * Hermiq BrokerHttpClient unit tests.
 *
 * Pins the invariants that make the LLM-key migration real rather than cosmetic: the host
 * is the broker's to choose, the auth header is the broker's to set, and there is no path
 * back to a direct, app-authenticated provider call.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/llm-keys-via-broker/tasks.md#task-1-brokerhttpclient
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use GuzzleHttp\Psr7\Request;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

class BrokerHttpClientTest extends TestCase
{
    /**
     * It is a PSR-18 client, which is the whole trick: `OpenAI::factory()` takes any
     * PSR-18 client, and LLPhant's OpenAIChat honours a pre-built one. That is how the
     * library's calls get proxied without rewriting the library.
     *
     * @return void
     */
    public function testItIsAPsr18Client(): void
    {
        $client = new BrokerHttpClient(credentialId: 'cred-1', logger: new NullLogger());

        $this->assertInstanceOf(ClientInterface::class, $client);
    }//end testItIsAPsr18Client()

    /**
     * Every auth header the LLM library set is dropped before the broker call.
     *
     * openai-php REQUIRES an api key and sets it as a Bearer header before this client
     * ever sees the request. That placeholder must not survive: the broker injects the
     * real secret and discards caller-supplied auth anyway.
     *
     * @return void
     */
    public function testAuthHeadersAreStripped(): void
    {
        $client = new BrokerHttpClient(credentialId: 'cred-1', logger: new NullLogger());

        $strip = (new ReflectionClass($client))->getMethod('headersWithoutAuth');
        $strip->setAccessible(true);

        $request = new Request(
            'POST',
            'https://api.openai.com/v1/chat/completions',
            [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.BrokerHttpClient::BROKER_MANAGED_KEY,
                'X-API-Key'     => 'anything',
                'OpenAI-Beta'   => 'assistants=v2',
            ]
        );

        $out = $strip->invoke($client, $request);

        $this->assertArrayNotHasKey('Authorization', $out);
        $this->assertArrayNotHasKey('X-API-Key', $out);
        $this->assertSame('application/json', $out['Content-Type']);
        $this->assertSame('assistants=v2', $out['OpenAI-Beta']);
    }//end testAuthHeadersAreStripped()

    /**
     * No credential → no call. There is deliberately no app-held key to fall back to.
     *
     * @return void
     */
    public function testItFailsClosedWithoutACredential(): void
    {
        $client = new BrokerHttpClient(credentialId: '', logger: new NullLogger());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no broker credential');

        $client->sendRequest(new Request('POST', 'https://api.openai.com/v1/chat/completions'));
    }//end testItFailsClosedWithoutACredential()

    /**
     * The placeholder we hand openai-php is a label, not a key — and it is meant to be
     * recognisable in a log if it ever escapes.
     *
     * @return void
     */
    public function testThePlaceholderIsNotSecretShaped(): void
    {
        $this->assertSame('__managed_by_credential_broker__', BrokerHttpClient::BROKER_MANAGED_KEY);
        $this->assertStringStartsNotWith('sk-', BrokerHttpClient::BROKER_MANAGED_KEY);
    }//end testThePlaceholderIsNotSecretShaped()

    /**
     * Hermiq identifies itself to the broker as `hermiq`. If this drifts, the broker's
     * allowed-app guard silently stops matching and every call is refused.
     *
     * @return void
     */
    public function testItIdentifiesItselfAsHermiq(): void
    {
        $this->assertSame('hermiq', BrokerHttpClient::APP_ID);
    }//end testItIdentifiesItselfAsHermiq()
}//end class
