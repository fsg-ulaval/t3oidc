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

use FSG\Oidc\Error\InvalidStateException;
use FSG\Oidc\LoginProvider\OpenIDConnectSignInProvider;
use FSG\Oidc\Traits\AuthenticationTrait;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Security\MissingReferrerException;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class CallbackMiddleware
 */
class CallbackMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use AuthenticationTrait;
    use LoggerAwareTrait;

    const PATH         = '/oidc/callback';
    const BACKEND_URI  = '%s/typo3/?loginProvider=%d';
    const FRONTEND_URI = '%s://%s%s%s';

    /**
     * @param ServerRequestInterface  $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     * @throws MissingReferrerException
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
     * @throws MissingReferrerException
     */
    protected function handleCallback(ServerRequestInterface $request): RedirectResponse
    {
        $redirectUri = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
        $queryParams = $request->getQueryParams();

        try {
            if (!$this->session->has('t3oidcOAuthReferrer')) {
                throw new MissingReferrerException('Missing referrer for OpenID Connect authentication', 1616191700);
            }

            $uri = new Uri($this->session->get('t3oidcOAuthReferrer'));
            if (preg_match('/\/typo3($|\/)/', $uri->getPath())) {
                $redirectUri = $this->getBackendUri();
            } else {
                $redirectUri = $this->getFrontendUri($uri);
            }

            if (!empty($queryParams['code']) && !empty($queryParams['state'])) {
                // Process authentication service response
                if ($this->session->has('t3oidcOAuthUser')) {
                    $this->session->remove('t3oidcOAuthUser');
                }

                if ($this->session->has('t3oidcOAuthState')) {
                    $expectedState = $this->session->get('t3oidcOAuthState');
                    $this->session->remove('t3oidcOAuthState');
                    if ($expectedState != $queryParams['state']) {
                        throw new InvalidStateException(
                            'The provided auth state did not match the expected value',
                            1613752400
                        );
                    }
                }

                /**
                 * @var AccessToken $accessToken
                 */
                $accessToken = $this->getOAuthClient()->getAccessToken(
                    'authorization_code',
                    ['code' => $queryParams['code']]
                );
                $userInfo    = array_merge(
                    $this->getOAuthClient()->getResourceOwner($accessToken)->toArray(),
                    $this->processAccessToken($accessToken)
                );

                if (isset($userInfo[$this->extensionConfiguration->getTokenUserIdentifier()])) {
                    $this->session->set('t3oidcOAuthUser', serialize($userInfo));
                    $this->logger->notice(sprintf(
                        'Found user with OpenID Connect identifier "%s".',
                        $userInfo[$this->extensionConfiguration->getTokenUserIdentifier()]
                    ));
                }
            }
        } catch (InvalidStateException | IdentityProviderException $e) {
            $this->logger->error(sprintf('Error %s: %s', $e->getCode(), $e->getMessage()));
            $redirectUri .= (strpos($redirectUri, '?') !== false ? '&' : '?') . 'handlingError=1';
        }

        // Add error parameters to frontend uri if exists
        if (!empty($queryParams['error']) && !empty($queryParams['error_description'])) {
            $redirectUri .= (strpos($redirectUri, '?') !== false ? '&' : '?')
                            . sprintf(
                                'error=%s&error_description=%s',
                                $queryParams['error'],
                                $queryParams['error_description']
                            );
        }

        return new RedirectResponse($redirectUri, 302);
    }

    /**
     * @return string
     */
    protected function getBackendUri(): string
    {
        // $this->session->remove('t3oidcOAuthReferrer');
        return sprintf(
            self::BACKEND_URI,
            GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST'),
            OpenIDConnectSignInProvider::LOGIN_PROVIDER
        );
    }

    /**
     * @param Uri $uri
     *
     * @return string
     */
    protected function getFrontendUri(Uri $uri): string
    {
        // $this->session->remove('t3oidcOAuthReferrer');
        $query = $uri->getQuery() ? '?' . $uri->getQuery() : '';
        return sprintf(self::FRONTEND_URI, $uri->getScheme(), $uri->getHost(), $uri->getPath(), $query);
    }
}
