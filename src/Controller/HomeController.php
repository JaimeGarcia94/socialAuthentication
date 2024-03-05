<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\Google;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class HomeController extends AbstractController
{

    private $github_provider;
    private $google_provider;
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->github_provider = new Github([
            'clientId'          => $_ENV['GITHUB_ID'],
            'clientSecret'      => $_ENV['GITHUB_SECRET'],
            'redirectUri'       => $_ENV['GITHUB_CALLBACK'],
        ]);

        $this->google_provider = new Google([
            'clientId'     => $_ENV['GOOGLE_ID'],
            'clientSecret' => $_ENV['GOOGLE_SECRET'],
            'redirectUri'  => $_ENV['GOOGLE_CALLBACK'],
        ]);

        $this->em = $em;
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }

    #[Route('/github-login', name: 'github_login')]
    public function githubLogin(): Response
    {
        if (!isset($_GET['code'])) {

            $options = [
                'scope' => ['user','user:email'] // array or string; at least 'user:email' is required
            ];
            
            $authUrl = $this->github_provider->getAuthorizationUrl($options);
            $_SESSION['oauth2state'] = $this->github_provider->getState();
            header('Location: '.$authUrl);
            exit;            
        
        // Check given state against previously stored one to mitigate CSRF attack
        } elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
        
            unset($_SESSION['oauth2state']);
            exit('Invalid state');
        
        }
    }

    #[Route('/github-callback', name: 'github_callback')]
    public function githubCallback(): Response
    {
        $token = $this->github_provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);

        try {
            $user = $this->github_provider->getResourceOwner($token);
            $user = $user->toArray();
            $email = $user['email'];
            $name = $user['login'];
            $picture = $user['avatar_url'];

            $userExist = $this->em->getRepository(User::class)->findOneByEmail($email);

            if($userExist) {
                $userExist->setName($name);
                $userExist->setPictureUrl($picture);

                $this->em->flush();

                return $this->render('home/view.html.twig',[
                    'name' => $name,
                    'picture' => $picture,
                ]);

            } else {
                $newUser = new User();
                $newUser->setName($name);
                $newUser->setPictureUrl($picture);
                $newUser->setEmail($email);
                $newUser->setPassword(sha1(str_shuffle('abscdop123390hHHH;:::000I')));

                $this->em->persist($newUser);
                $this->em->flush();

                return $this->render('home/view.html.twig',[
                    'name' => $name,
                    'picture' => $picture,
                ]);
            }
    
        } catch (\Throwable $th) {
    
            return $th->getMessage();
        }
    }

    #[Route('/google-login', name: 'google_login')]
    public function googleLogin(): Response
    {
        if (!empty($_GET['error'])) {

            // Got an error, probably user denied access
            exit('Got error: ' . htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'));
        
        } elseif (empty($_GET['code'])) {
        
            // If we don't have an authorization code then get one
            $authUrl = $this->google_provider->getAuthorizationUrl();
            $_SESSION['oauth2state'] = $this->google_provider->getState();
            header('Location: ' . $authUrl);
            exit;
        
        } elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
        
            // State is invalid, possible CSRF attack in progress
            unset($_SESSION['oauth2state']);
            exit('Invalid state');
        }
    }

    #[Route('/google-callback', name: 'google_callback')]
    public function googleCallback(): Response
    {
        $token = $this->google_provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);

        dd($token);

        try {
            $user = $this->google_provider->getResourceOwner($token);
    
            $user = $user->toArray();

            dd($user);

            $name = $user['name'];
            $picture = $user['picture'];

            return $this->render('home/view.html.twig',[
                'name' => $name,
                'picture' => $picture,
            ]);
    
        } catch (\Throwable $th) {
    
            return $th->getMessage();
        }
    }
}
