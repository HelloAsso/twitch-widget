<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Flash\Messages;
use Slim\Views\Twig;

class HomeController
{
    public function __construct(
        private Twig $view,
        private Messages $messages,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $messages = $this->messages->getMessages();
        return $this->view->render($response, 'index.html.twig', $messages);
    }

    public function forgotPassword(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'password-forgot.html.twig');
    }

    public function resetPassword(Request $request, Response $response, $args): Response
    {
        $data = [
            "token" => $args['token'],
            "messages" => $this->messages->getMessages(),
        ];

        return $this->view->render($response, 'password-reset.html.twig', $data);
    }
}
