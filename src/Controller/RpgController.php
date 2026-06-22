<?php

namespace App\Controller;

use App\Entity\Gear;
use App\Entity\Quest;
use App\Entity\QuestProgress;
use App\Entity\User;
use App\Service\GamificationWidgetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/rpg')]
final class RpgController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GamificationWidgetService $gamification,
    ) {}

    /** Fiche personnage + quêtes + gear */
    #[Route('', name: 'app_rpg', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isRpgMode()) {
            return $this->redirectToRoute('app_profile');
        }

        $rpgData = $this->gamification->buildWidgetData($user);

        // Toutes les quêtes actives + progression user
        $allQuests = $this->em->getRepository(Quest::class)->findBy(['active' => true], ['type' => 'ASC']);
        $progresses = $this->em->getRepository(QuestProgress::class)->findBy(['user' => $user]);
        $progressByQuestId = [];
        foreach ($progresses as $p) {
            $progressByQuestId[$p->getQuest()->getId()] = $p;
        }

        // Gear du user
        $gears = $this->em->getRepository(Gear::class)->findBy(['user' => $user], ['active' => 'DESC', 'createdAt' => 'DESC']);

        return $this->render('rpg/index.html.twig', [
            'username' => $user->getUserIdentifier(),
            'rpg'      => $rpgData,
            'quests'   => $allQuests,
            'progress' => $progressByQuestId,
            'gears'    => $gears,
        ]);
    }

    /** Rejoindre / démarrer une quête */
    #[Route('/quest/{id}/start', name: 'app_rpg_quest_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function startQuest(int $id, Request $request): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isRpgMode()) {
            return $this->redirectToRoute('app_profile');
        }

        if (!$this->isCsrfTokenValid('rpg_quest_start_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('app_rpg');
        }

        $quest = $this->em->getRepository(Quest::class)->find($id);
        if (!$quest instanceof Quest) {
            throw $this->createNotFoundException();
        }

        $existing = $this->em->getRepository(QuestProgress::class)->findOneBy(['user' => $user, 'quest' => $quest]);
        if (!$existing) {
            $p = new QuestProgress();
            $p->setUser($user)->setQuest($quest);
            $this->em->persist($p);
            $this->em->flush();
            $this->addFlash('success', '⚔️ Quête démarrée : ' . $quest->getTitle());
        }

        return $this->redirectToRoute('app_rpg');
    }

    /** Ajouter un équipement */
    #[Route('/gear/add', name: 'app_rpg_gear_add', methods: ['POST'])]
    public function addGear(Request $request): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isRpgMode()) {
            return $this->redirectToRoute('app_profile');
        }

        if (!$this->isCsrfTokenValid('rpg_gear', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('app_rpg');
        }

        $name      = trim((string) $request->request->get('name', ''));
        $skillType = (string) $request->request->get('skill_type', 'speed');
        $modifier  = (int) $request->request->get('modifier', 0);

        if ($name === '' || !in_array($skillType, ['speed', 'endurance', 'recovery'], true)) {
            $this->addFlash('error', 'Données invalides.');
            return $this->redirectToRoute('app_rpg');
        }

        $gear = new Gear();
        $gear->setUser($user)->setName($name)->setSkillType($skillType)->setModifier($modifier);
        $this->em->persist($gear);
        $this->em->flush();

        $this->addFlash('success', '🛡️ Équipement ajouté : ' . $name);
        return $this->redirectToRoute('app_rpg');
    }

    /** Activer / désactiver un équipement */
    #[Route('/gear/{id}/toggle', name: 'app_rpg_gear_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleGear(int $id, Request $request): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isRpgMode()) {
            return $this->redirectToRoute('app_profile');
        }

        if (!$this->isCsrfTokenValid('rpg_gear_toggle_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('app_rpg');
        }

        $gear = $this->em->getRepository(Gear::class)->findOneBy(['id' => $id, 'user' => $user]);
        if (!$gear instanceof Gear) {
            throw $this->createNotFoundException();
        }

        $gear->setActive(!$gear->isActive());
        if (!$gear->isActive()) {
            $gear->setRetiredAt(new \DateTimeImmutable());
        } else {
            $gear->setRetiredAt(null);
        }
        $this->em->flush();

        $this->addFlash('success', $gear->isActive() ? '✅ Équipement équipé.' : '📦 Équipement rangé.');
        return $this->redirectToRoute('app_rpg');
    }

    /** Supprimer un équipement */
    #[Route('/gear/{id}/delete', name: 'app_rpg_gear_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteGear(int $id, Request $request): RedirectResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isRpgMode()) {
            return $this->redirectToRoute('app_profile');
        }

        if (!$this->isCsrfTokenValid('rpg_gear_delete_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('app_rpg');
        }

        $gear = $this->em->getRepository(Gear::class)->findOneBy(['id' => $id, 'user' => $user]);
        if ($gear instanceof Gear) {
            $this->em->remove($gear);
            $this->em->flush();
        }

        $this->addFlash('success', 'Équipement supprimé.');
        return $this->redirectToRoute('app_rpg');
    }
}
