<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LogoutHandler implements RequestHandlerInterface
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->responses->createResponse(405)->withHeader('Allow', 'POST');
        }
        SessionGuard::requireSession($request)->destroy();
        return $this->responses->createResponse(204);
    }
}
