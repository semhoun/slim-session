<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class StatusHandler implements RequestHandlerInterface
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = SessionGuard::context($request);
        if ($request->getMethod() !== 'GET') {
            return $this->responses->createResponse(405)->withHeader('Allow', 'GET');
        }
        if ($context->state() === \Semhoun\SlimSession\SessionState::Open) {
            $context->excludeActivity();
        }
        return $this->responses->createResponse()->withHeader(SessionMiddleware::METADATA_RESPONSE, '1');
    }
}
