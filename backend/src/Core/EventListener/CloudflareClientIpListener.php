<?php

namespace App\Core\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Behind Cloudflare the app would see Cloudflare's address instead of the
 * visitor's (a proxy in between, like Traefik by default, replaces
 * X-Forwarded-For with it), and login throttling would count attempts by
 * Cloudflare's servers. Cloudflare sends the visitor's address in
 * CF-Connecting-IP: it is used when the request really came from Cloudflare
 * (the address before the trusted proxies is one of Cloudflare's), never
 * otherwise, since anyone can send the header. Nothing to configure; with
 * Cloudflare Tunnel the visitor's address is already in X-Forwarded-For.
 *
 * Before everything that reads the client's address (the firewall, 8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final class CloudflareClientIpListener
{
    /** https://www.cloudflare.com/ips-v4 and /ips-v6, as of 2026-11-07. */
    public const RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $visitor = $request->headers->get('CF-Connecting-IP');
        if (!$event->isMainRequest() || $visitor === null || filter_var($visitor, \FILTER_VALIDATE_IP) === false) {
            return;
        }
        $peer = $request->getClientIp();
        if ($peer === null || !IpUtils::checkIp($peer, self::RANGES)) {
            return;
        }

        // From here on the visitor is the request's own address, and the only one forwarded.
        $request->server->set('REMOTE_ADDR', $visitor);
        $request->server->set('HTTP_X_FORWARDED_FOR', $visitor);
        $request->headers->set('X-Forwarded-For', $visitor);
    }
}
