<?php

declare(strict_types=1);

return [
    'frontend' => [
        'fsg/oidc/authentication' => [
            'target' => \FSG\Oidc\Middleware\AuthenticationMiddleware::class,
            'after'  => [
            ],
            'before' => [
                'typo3/cms-frontend/authentication',
                'typo3/cms-frontend/backend-user-authentication',
            ],
        ],
        'fsg/oidc/callback'       => [
            'target' => \FSG\Oidc\Middleware\CallbackMiddleware::class,
            'after'  => [
            ],
            'before' => [
                'fsg/oidc/authentication',
            ],
        ],
    ],
    'backend' => [
        'fsg/oidc/authentication' => [
            'target' => \FSG\Oidc\Middleware\AuthenticationMiddleware::class,
            'after'  => [
            ],
            'before' => [
                'typo3/cms-backend/authentication',
            ],
        ],
        'fsg/oidc/callback'       => [
            'target' => \FSG\Oidc\Middleware\CallbackMiddleware::class,
            'after'  => [
            ],
            'before' => [
                'fsg/oidc/authentication',
            ],
        ],
    ],
];
