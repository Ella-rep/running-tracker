<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GpxReplayController extends AbstractController
{
    #[Route('/gpx-replay', name: 'gpx_replay')]
    public function index(): Response
    {
        return $this->render('base/gpx-replay.html.twig', [
            'mapbox_token' => $_ENV['MAPBOX_TOKEN'] ?? '',
        ]);
    }
}
