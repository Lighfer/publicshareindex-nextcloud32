<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#bootstrap', 'url' => '/api/bootstrap', 'verb' => 'GET'],
        ['name' => 'page#children', 'url' => '/api/children', 'verb' => 'GET'],
        ['name' => 'page#search', 'url' => '/api/search', 'verb' => 'GET'],
    ],
];
