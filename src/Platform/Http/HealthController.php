<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Platform\ReadinessProbe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): Response
    {
        return new Response('OK', headers: ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(ReadinessProbe $probe): Response
    {
        $ready = $probe->isReady();

        return new Response($ready ? 'OK' : 'Unavailable', $ready ? 200 : 503, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-store',
        ]);
    }
}
