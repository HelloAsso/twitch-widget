<?php

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

class AuthApiMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        $providedKey = $request->getHeaderLine('X_API_KEY');

        if (!empty($providedKey) && $providedKey === $_SERVER['API_KEY']) {
            return $handler->handle($request);
        }

        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
