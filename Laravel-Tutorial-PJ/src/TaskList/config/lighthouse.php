<?php

use GraphQL\Validator\Rules\DisableIntrospection;

return [
    'route' => false,
    'schema_path' => resource_path('graphql/schema.graphql'),
    'schema_cache' => [
        'enable' => env('LIGHTHOUSE_SCHEMA_CACHE_ENABLE', false),
        'path' => base_path('bootstrap/cache/lighthouse-schema.php'),
    ],
    'security' => [
        'max_query_complexity' => 200,
        'max_query_depth' => 8,
        'disable_introspection' => DisableIntrospection::DISABLED,
    ],
    'debug' => 0,
];
