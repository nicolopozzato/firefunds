<?php

namespace App\Notification\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\PushSubscription;
use App\Notification\DTO\PushSubscriptionRequest;
use App\Notification\Push\PushSender;
use App\Notification\Push\PushTexts;
use App\Notification\Push\VapidKeys;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/** The user's devices for push notifications: subscribe this one, list, test, remove. */
#[Route('/api/push')]
class PushController extends AbstractController
{
    use ResolvesCurrentUser;

    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly VapidKeys $keys,
        private readonly PushSender $sender,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /** The server's public key, which the browser needs to subscribe. */
    #[Route('/key', name: 'api_push_key', methods: ['GET'])]
    public function key(): JsonResponse
    {
        return $this->json(['publicKey' => $this->keys->publicKey()]);
    }

    #[Route('/subscriptions', name: 'api_push_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(self::toArray(...), $this->subscriptions->findByUser($this->getCurrentUser())));
    }

    /** This device: added, or renewed when it subscribed before (new keys, another user). */
    #[Route('/subscriptions', name: 'api_push_subscribe', methods: ['POST'])]
    public function subscribe(#[MapRequestPayload] PushSubscriptionRequest $request, Request $http): JsonResponse
    {
        $user = $this->getCurrentUser();
        $this->keys->rememberOrigin($http->getSchemeAndHttpHost());
        $device = $this->subscriptions->findOneByEndpoint($request->endpoint);
        if ($device === null) {
            $device = new PushSubscription($user, $request->endpoint, $request->keys['p256dh'], $request->keys['auth'], trim($request->label), $this->clock->now());
            $this->entityManager->persist($device);
        } else {
            $device->renew($user, $request->keys['p256dh'], $request->keys['auth'], trim($request->label));
        }
        $this->entityManager->flush();

        return $this->json(self::toArray($device), 201);
    }

    #[Route('/subscriptions/{id}', name: 'api_push_remove', methods: ['DELETE'], requirements: self::ID)]
    public function remove(string $id): JsonResponse
    {
        $this->sender->forget($this->findOwned($id));

        return new JsonResponse(null, 204);
    }

    /** Turning push off on this device: forgotten by its address (the app doesn't know its id). */
    #[Route('/unsubscribe', name: 'api_push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        $device = $this->subscriptions->findOneByEndpoint((string) ($request->toArray()['endpoint'] ?? ''));
        if ($device !== null && $device->getUser()->getId()->equals($this->getCurrentUser()->getId())) {
            $this->sender->forget($device);
        }

        return new JsonResponse(null, 204);
    }

    /** A notification to this one device, now: whether it arrives tells push works there. */
    #[Route('/subscriptions/{id}/test', name: 'api_push_test', methods: ['POST'], requirements: self::ID)]
    public function test(string $id): JsonResponse
    {
        $device = $this->findOwned($id);
        $user = $this->getCurrentUser();
        $reached = $this->sender->send($user, [...PushTexts::of('test', [], $user->getLocale()), 'url' => '/preferences', 'tag' => 'test'], [$device]);
        if ($reached === 0) {
            throw new ApiException(ErrorCode::PushFailed, [], 502);
        }

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private static function toArray(PushSubscription $device): array
    {
        return [
            'id' => (string) $device->getId(),
            'endpoint' => $device->getEndpoint(),
            'label' => $device->getLabel(),
            'createdAt' => $device->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastSentAt' => $device->getLastSentAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function findOwned(string $id): PushSubscription
    {
        return $this->subscriptions->findOneByUserAndId($this->getCurrentUser(), $id)
            ?? throw ApiException::notFound(ErrorCode::PushDeviceNotFound);
    }
}
