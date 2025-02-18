<?php

namespace App\Controllers;

use App\Repositories\FileManager;
use App\Repositories\StreamRepository;
use App\Repositories\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Flash\Messages;
use Slim\Routing\RouteContext;
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

        $stream = $this->streamRepository->insert($formSlug, $organizationSlug, $title);
        $user = $this->userRepository->select($ownerEmail);
        if ($user == null) {
            $user = $this->userRepository->insert($ownerEmail);
        }
        $this->userRepository->insertRight($user, $stream, null);
        $this->userRepository->insertResetToken($user);

        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        $data = [
            "status" => "ok",
            "reset_password_url" => $_SERVER['WEBSITE_DOMAIN'] . $routeParser->urlFor('app_reset_password', ["token" => $user->reset_token])
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
