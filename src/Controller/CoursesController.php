<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders the races and courses page shell.
 */
class CoursesController extends AbstractController
{
    /**
     * Displays the courses page.
     */
    #[Route('/courses', name: 'app_courses')]
    public function index(): Response
    {
        return $this->render('courses/index.html.twig', [
            'username' => $this->getUser()?->getUserIdentifier(),
        ]);
    }
}
