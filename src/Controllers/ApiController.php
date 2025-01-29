<?php

namespace App\Controllers;

use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Flash\Messages;
use Slim\Views\Twig;

class ApiController
{
    public function __construct(
        private Twig $view,
        private FileManager $fileManager,
        private StreamRepository $streamRepository,
        private UserRepository $userRepository,
        private Messages $messages,
    ) {}

    public function new(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $ownerEmail = $data['owner_email'] ?? null;
        $formSlug = $data['form_slug'] ?? null;
        $organizationSlug = $data['organization_slug'] ?? null;
        $title = $data['title'] ?? null;

        if (!$ownerEmail || !$formSlug || !$organizationSlug || !$title) {
            $response->getBody()->write(json_encode(['error' => 'all fields are mandatory']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $guid = bin2hex(random_bytes(16));

        $this->streamRepository->createCharityStreamDB($guid, $ownerEmail, $formSlug, $organizationSlug, $title);
        $user = $this->userRepository->insertUser($ownerEmail);
        $this->userRepository->insertResetToken($user);

        $data = [
            "status" => "ok",
            "reset_password_url" => $_SERVER['WEBSITE_DOMAIN'] . "/reset_password/$user->reset_token"
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
