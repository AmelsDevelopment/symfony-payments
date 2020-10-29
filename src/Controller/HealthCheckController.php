<?php

namespace App\Controller;

use App\Repository\HealthCheckRepository;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HealthCheckController extends AbstractController
{
    private $healthCheckRepository;

    public function __construct(HealthCheckRepository $healthCheckRepository)
    {
        $this->healthCheckRepository = $healthCheckRepository;
    }

    /**
     * @Route("/api/healthy")
     */
    public function onHealthCheck()
    {
        try {
            $this->healthCheckRepository->find(1);
        } catch (Exception $e) {
            return new Response("501", 500);
        }

        return new Response();
    }
}