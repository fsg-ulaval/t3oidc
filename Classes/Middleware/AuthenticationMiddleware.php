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

    const PATH        = '/oidc/authentication';
    const BACKEND_URI = '%s/typo3/?loginProvider=%d';

    /**
     * @param ServerRequestInterface  $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strpos($request->getUri()->getPath(), self::PATH) === false) {
            // Middleware is not responsible for given request
            return $handler->handle($request);
        }

        return $this->handleCallback($request);
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return RedirectResponse
     */
    protected function handleCallback(ServerRequestInterface $request): RedirectResponse
    {
        $uri      = new Uri($request->getServerParams()['HTTP_REFERER']);
        $referrer = sprintf('%s://%s%s', $uri->getScheme(), $uri->getHost(), $uri->getPath());

        $queryParams = $request->getQueryParams();
        if ($queryParams['action'] === LoginType::LOGOUT) {
            // Logout user
            $this->logger->debug('Logout user.');

            if ($this->session->has('t3oidcOAuthUser')) {
                $this->session->remove('t3oidcOAuthUser');
            }

            if (preg_match('/\/typo3($|\/)/', $uri->getPath())) {
                $redirectUri = sprintf(
                    self::BACKEND_URI,
                    GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST'),
                    OpenIDConnectSignInProvider::LOGIN_PROVIDER
                );
            } else {
                $redirectUri = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
            }
        } else {
            // Login user to authentication service
            $this->logger->debug('Handle login.');
            $redirectUri = $this->authenticateUser();
            if ($this->session->has('t3oidcOAuthReferrer')) {
                $this->session->replace(['t3oidcOAuthReferrer' => $referrer]);
            } else {
                $this->session->set('t3oidcOAuthReferrer', $referrer);
            }
        }

        return new RedirectResponse($redirectUri, 302);
    }
}
