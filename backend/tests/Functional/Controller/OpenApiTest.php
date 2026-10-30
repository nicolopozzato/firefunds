<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\WebTestCaseBase;
use Symfony\Component\Routing\RouterInterface;

/** The API description: served to a token, and every path in it is a real route. */
class OpenApiTest extends WebTestCaseBase
{
    public function testItIsServedToADraftsToken(): void
    {
        $client = static::createClient();
        static::authenticate($client, static::createUser());
        $client->request('POST', '/api/tokens', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['name' => 'Comandi', 'scope' => 'drafts']));
        $token = json_decode((string) $client->getResponse()->getContent(), true)['token'];

        $client->request('GET', '/api/openapi.json', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_HOST' => 'firefunds.example.test']);

        $this->assertResponseIsSuccessful();
        $spec = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame('http://firefunds.example.test', $spec['servers'][0]['url']);
        $this->assertArrayHasKey('/api/drafts', $spec['paths']);
    }

    public function testEveryDocumentedOperationIsARoute(): void
    {
        static::bootKernel();
        $spec = static::getContainer()->get(\App\Api\OpenApi\OpenApiDocument::class)->build('http://localhost', 'test');
        $router = static::getContainer()->get(RouterInterface::class);

        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $concrete = preg_replace('/\{(\w+)\}/', 'asset', $path);
                $context = $router->getContext();
                $context->setMethod(strtoupper($method));
                try {
                    $router->match($concrete);
                } catch (\Throwable $e) {
                    $this->fail(strtoupper($method).' '.$path.' is not a route: '.$e->getMessage());
                }
            }
        }
        $this->addToAssertionCount(1);
    }
}
