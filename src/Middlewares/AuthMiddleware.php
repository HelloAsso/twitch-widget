<?php

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class AuthMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        if (!isset($_SESSION['user']) && !in_array($_SERVER['REMOTE_ADDR'], isset($_SERVER['HA_IPS']) ? explode(",", $_SERVER['HA_IPS']) : [])) {
            $response = new \Slim\Psr7\Response();
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        return $handler->handle($request);
    }
}
