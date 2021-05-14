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

use FSG\Oidc\LoginProvider\OpenIDConnectSignInProvider;
use FSG\Oidc\Traits\AuthenticationTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Authentication\LoginType;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class AuthenticationMiddleware
 */
class AuthenticationMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use AuthenticationTrait;
    use LoggerAwareTrait;

    const PATH         = '/oidc/authentication';
    const BACKEND_URI  = '%s/typo3/?loginProvider=%d';
    const FRONTEND_URI = '%s://%s%s%slogintype=%s';

    /**
     * @param ServerRequestInterface  $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (parse_url($request->getServerParams()['HTTP_REFERER'] ?? '', PHP_URL_HOST)
            !== parse_url(GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST'), PHP_URL_HOST)) {
            return $handler->handle($request);
        }
        if (strpos($request->getUri()->getPath(), self::PATH) === false) {
            $response = $handler->handle($request);
            return $this->handle($request, false) ?? $response;
        }

        return $this->handle($request);
    }

    /**
     * @param ServerRequestInterface $request
     * @param bool                   $responsible
     *
     * @return ?RedirectResponse
     */
    protected function handle(ServerRequestInterface $request, bool $responsible = true): ?ResponseInterface
    {
        if (!$responsible || $request->getQueryParams()['action'] === LoginType::LOGOUT) {
            return $this->handleLogout($request);
        }

        $this->logger->debug('Handle login.');
        $this->initReferrer($request, LoginType::LOGIN);
        return new RedirectResponse($this->authenticateUser(), 302);
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ?RedirectResponse
     */
    protected function handleLogout(ServerRequestInterface $request): ?ResponseInterface
    {
        if ($request->getParsedBody()['logintype'] === LoginType::LOGOUT
            || $request->getQueryParams()['logintype'] === LoginType::LOGOUT
            || trim($request->getQueryParams()['route'] ?? '', '/') === LoginType::LOGOUT) {
            $context = $this->environmentService->isEnvironmentInBackendMode() ? 'Backend' : 'Frontend';
            if (!$this->extensionConfiguration->{'isSoftLogout' . $context . 'Users'}()) {
                $this->logger->debug('Handle ' . $context . ' soft logout.');
                $this->initReferrer($request, LoginType::LOGOUT);
                return new RedirectResponse($this->logoutUser(), 302);
            }
        } elseif ($request->getQueryParams()['action'] === LoginType::LOGOUT) {
            $this->logger->debug('Handle logout.');
            $this->initReferrer($request, LoginType::LOGOUT);
            return new RedirectResponse($this->logoutUser(), 302);
        }

        return null;
    }

    /**
     * @param ServerRequestInterface $request
     * @param string                 $loginType
     */
    protected function initReferrer(ServerRequestInterface $request, string $loginType): void
    {
        if ($this->environmentService->isEnvironmentInBackendMode()) {
            $referrer = sprintf(
                self::BACKEND_URI,
                GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST'),
                OpenIDConnectSignInProvider::LOGIN_PROVIDER
            );
        } else {
            $rawReferrer = $request->getServerParams()['HTTP_REFERER'];
            $uri         = $rawReferrer ? new Uri($rawReferrer) : $request->getUri();
            parse_str($uri->getQuery(), $queryParams);
            unset($queryParams['logintype']);

            $query    = http_build_query($queryParams);
            $query    = $query ? '?' . $query . '&' : '?';
            $referrer = sprintf(
                self::FRONTEND_URI,
                $uri->getScheme(),
                $uri->getHost(),
                $uri->getPath(),
                $query,
                $loginType
            );
        }

        if ($this->session->has('t3oidcOAuthReferrer')) {
            $this->session->replace(['t3oidcOAuthReferrer' => $referrer]);
        } else {
            $this->session->set('t3oidcOAuthReferrer', $referrer);
        }
    }
}
