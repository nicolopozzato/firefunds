<?php

namespace App\Update;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The latest release of the public repository (github.client, framework.yaml),
 * which the image workflow publishes once the image is ready to pull. Drafts
 * and pre-releases are never "latest".
 */
class GitHubReleases
{
    public function __construct(
        private readonly HttpClientInterface $githubClient,
    ) {
    }

    /** Null while the repository has no release yet; throws when GitHub can't be asked (down, rate limited). */
    public function latest(): ?Release
    {
        $response = $this->githubClient->request('GET', 'releases/latest');
        if ($response->getStatusCode() === 404) {
            return null;
        }
        $data = $response->toArray();

        return new Release(
            ltrim((string) $data['tag_name'], 'v'),
            (string) $data['html_url'],
            isset($data['published_at']) ? (string) $data['published_at'] : null,
        );
    }
}
