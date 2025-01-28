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
}
