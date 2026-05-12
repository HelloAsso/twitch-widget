<?php

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware
{
    public function __construct(private ?string $requiredRole = null) {}

    public function __invoke(Request $request, Handler $handler): Response
    {
        if (isset($_SESSION['user'])) {
            $user = $_SESSION['user'];
            if ($this->requiredRole === null || $user->role === $this->requiredRole) {
                return $handler->handle($request->withAttribute('user', $user));
            }
        }

        $response = new SlimResponse();
        return $response->withHeader('Location', '/')->withStatus(302);
    }
}
