<?php

namespace App\GraphQL;

use App\Services\GmailConnectionManager;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class GmailConnectionOperations
{
    public function state(mixed $root, array $args, GraphQLContext $context): array
    {
        return app(GmailConnectionManager::class)->state($context->user());
    }

    public function connect(mixed $root, array $args, GraphQLContext $context): array
    {
        return app(GmailConnectionManager::class)->connect($context->user());
    }

    public function disconnect(mixed $root, array $args, GraphQLContext $context): array
    {
        return app(GmailConnectionManager::class)->disconnect($context->user(), $args['confirm']);
    }
}
