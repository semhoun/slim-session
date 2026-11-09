<?php
declare(strict_types=1);

namespace Semhoun\SlimSession\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RefreshHandler implements RequestHandlerInterface
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->responses->createResponse(405)->withHeader('Allow', 'POST');
        }
        $context = SessionGuard::requireSession($request);
        $context->excludeActivity();
        $context->renew();
        return $this->responses->createResponse()->withHeader(SessionMiddleware::METADATA_RESPONSE, '1');
    }
}
