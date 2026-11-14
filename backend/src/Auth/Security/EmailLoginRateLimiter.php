<?php

namespace App\Auth\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Login throttling (security.yaml) counts failed logins per email only, not
 * per client address: behind a proxy, or Cloudflare, the address the app
 * sees isn't the visitor's, and it would need setting up for each one.
 * The price: someone who knows an email can hold its login off for a while.
 */
final class EmailLoginRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.login')] private RateLimiterFactoryInterface $factory,
    ) {
    }

    protected function getLimiters(Request $request): array
    {
        $email = (string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');

        return [$this->factory->create(mb_strtolower(trim($email)))];
    }
}
