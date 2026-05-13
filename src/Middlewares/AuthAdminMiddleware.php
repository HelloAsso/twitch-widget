<?php

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * @deprecated Utiliser AuthMiddleware('ADMIN') à la place.
 */
class AuthAdminMiddleware
{
    private AuthMiddleware $middleware;

    public function __construct()
    {
        $this->middleware = new AuthMiddleware('ADMIN');
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        return ($this->middleware)($request, $handler);
    }
}
