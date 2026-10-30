<?php

namespace App\Api\Controller;

use App\Api\OpenApi\OpenApiDocument;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** The description of the API for other programs, at this server's own address. */
class OpenApiController extends AbstractController
{
    public function __construct(
        private readonly OpenApiDocument $document,
        #[Autowire(env: 'APP_VERSION')]
        private readonly string $version,
    ) {
    }

    #[Route('/api/openapi.json', name: 'api_openapi', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        return $this->json($this->document->build($request->getSchemeAndHttpHost(), $this->version));
    }
}
