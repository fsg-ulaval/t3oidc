<?php

declare(strict_types=1);

namespace FSG\Oidc\Middleware;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use FSG\Oidc\Domain\Model\Dto\ExtensionConfiguration;
use FSG\Oidc\LoginProvider\OpenIDConnectSignInProvider;
use FSG\Oidc\Traits\AuthenticationTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpFoundation\Session\Session;
use TYPO3\CMS\Core\Authentication\LoginType;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Service\EnvironmentService;

/**
 * Class LogoutMiddleware
 */
class LogoutMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use AuthenticationTrait;
    use LoggerAwareTrait;

    const PATH         = '/oidc/logout';
    const BACKEND_URI  = '%s/typo3/?loginProvider=%d';
    const FRONTEND_URI = '%s://%s%s%slogintype=%s';

    /**
     * AuthenticationService constructor.
     */
    public function __construct(
        ExtensionConfiguration $extensionConfiguration,
        EnvironmentService $environmentService,
        Session $session,
        Context $context
    ) {
        $this->extensionConfiguration = $extensionConfiguration;
        $this->environmentService     = $environmentService;
        $this->session                = $session;
        $this->context                = $context;
    }

    /**
     * @param ServerRequestInterface  $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $frontendUser = $this->context->getAspect('frontend.user');
        $BackendUser = $this->context->getAspect('backend.user');

        $response = $handler->handle($request);

        return $this->handleLogout($request) ?? $response;
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ?RedirectResponse
     */
    protected function handleLogout(ServerRequestInterface $request): ?ResponseInterface
    {
        if (strpos($request->getUri()->getPath(), self::PATH) !== false
            || $request->getParsedBody()['logintype'] === LoginType::LOGOUT
            || $request->getQueryParams()['logintype'] === LoginType::LOGOUT
            || trim($request->getQueryParams()['route'] ?? '', '/') === LoginType::LOGOUT) {
            $context = $this->environmentService->isEnvironmentInBackendMode() ? 'Backend' : 'Frontend';
            $this->logoutUser();
            if (!$this->extensionConfiguration->{'isSoftLogout' . $context . 'Users'}()) {
                $this->logger->debug('Handle ' . $context . ' logout form everywhere.');
                $this->initReferrer($request, LoginType::LOGOUT);
                return new RedirectResponse($this->logoutUserFromEverywhereURL(), 302);
            }
        }
        return null;
    }
}
