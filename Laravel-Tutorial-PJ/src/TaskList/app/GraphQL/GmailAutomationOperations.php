<?php

namespace App\GraphQL;

use App\Models\TaskExtraction;
use App\Services\GmailAutomation;
use App\Services\GmailConnectionManager;
use App\Services\TaskExtractionManager;
use GraphQL\Error\Error;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class GmailAutomationOperations
{
    public function state(mixed $root, array $args, GraphQLContext $context): array
    {
        return app(GmailAutomation::class)->state($context->user());
    }

    public function save(mixed $root, array $args, GraphQLContext $context): array
    {
        return app(GmailAutomation::class)->save($context->user(), $args['input']);
    }

    public function history(mixed $root, array $args, GraphQLContext $context): array
    {
        if (! app(GmailConnectionManager::class)->eligible($context->user())) {
            return [];
        }

        return TaskExtraction::where('user_id', $context->user()->id)->latest()->limit(20)->get()->all();
    }

    public function retry(mixed $root, array $args, GraphQLContext $context): ?TaskExtraction
    {
        $run = TaskExtraction::where('user_id', $context->user()->id)->where('status', 'failed')->find($args['id']);
        if (! $run) {
            throw new Error('再試行できる読み取りが見つかりません。', extensions: ['code' => 'NOT_FOUND']);
        }

        return app(TaskExtractionManager::class)->start($context->user(), $run->scope, 'manual', $run);
    }
}
