<?php

namespace App\Admin\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Pagination\PaginationParams;
use App\Entity\TaskTrigger;
use App\Notification\Push\PushDelivery;
use App\Notification\Service\SystemHealth;
use App\Task\TaskRunner;
use App\Task\TasksOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The scheduled tasks: how their runs went, run one now. */
#[Route('/api/admin/tasks')]
#[IsGranted('ROLE_ADMIN')]
class TasksController extends AbstractController
{
    use ResolvesCurrentUser;

    public function __construct(
        private readonly TaskRunner $runner,
        private readonly TasksOverview $overview,
        private readonly SystemHealth $health,
        private readonly PushDelivery $push,
    ) {
    }

    #[Route('', name: 'api_admin_tasks_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->overview->build());
    }

    /** A task's runs, page by page, newest first. */
    #[Route('/{key}/runs', name: 'api_admin_tasks_runs', methods: ['GET'], requirements: ['key' => '[a-z_]+'])]
    public function runs(string $key, Request $request): JsonResponse
    {
        return $this->json($this->overview->runs($key, PaginationParams::fromRequest($request, $this->getCurrentUser())));
    }

    /** Runs a task while the request waits (a first price history or a backup can take a minute). */
    #[Route('/{key}/run', name: 'api_admin_tasks_run', methods: ['POST'], requirements: ['key' => '[a-z_]+'])]
    public function run(string $key): JsonResponse
    {
        set_time_limit(600);
        $this->runner->run($key, TaskTrigger::Manual);
        $this->health->check();
        $this->push->deliver();

        return $this->json($this->overview->build());
    }
}
