<?php

declare(strict_types=1);

namespace FSG\Oidc\Traits;

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
use FSG\Oidc\Middleware\CallbackMiddleware;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Session;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\EnvironmentService;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

trait AuthenticationTrait
{

    /**
     * @var Context
     */
    protected Context $context;

    /**
     * @var ExtensionConfiguration
     */
    protected ExtensionConfiguration $extensionConfiguration;

    /**
     * @var EnvironmentService
     */
    protected EnvironmentService $environmentService;

    /**
     * @var GenericProvider
     */
    protected GenericProvider $oauthClient;

    /**
     * @var Session<mixed>
     */
    private Session $session;

    /**
     * AuthenticationService constructor.
     */
    public function __construct()
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $this->environmentService     = GeneralUtility::makeInstance(EnvironmentService::class);
        $this->session                = new Session();
        $this->context = GeneralUtility::makeInstance(Context::class);
    }

    /**
     * Initialize local session and return the url of the authentication service login endpoint.
     *
     * @return string
     */
    protected function authenticateUser(): string
    {
        $authorizationUrl = $this->getOAuthClient()->getAuthorizationUrl();

        if ($this->session->has('t3oidcOAuthState')) {
            $this->session->replace(['t3oidcOAuthState' => $this->getOAuthClient()->getState()]);
        } else {
            $this->session->set('t3oidcOAuthState', $this->getOAuthClient()->getState());
        }

        return $authorizationUrl;
    }

    /**
     * Remove local session, logout user and return the current context.
     *
     * @return string
     */
    protected function logoutUser(): string
    {
        $context = $this->environmentService->isEnvironmentInBackendMode() ? 'Backend' : 'Frontend';

        if ($this->session->has('t3oidcOAuthUser')) {
            $this->session->remove('t3oidcOAuthUser');
        }

        $this->logoutUserFromFrontend();
        $this->logoutUserFromBackend();

        return $context;
    }

    /**
     * Return the url of the authentication service logout endpoint.
     *
     * @return string
     */
    protected function logoutUserFromEverywhereURL(): string
    {
        return $this->extensionConfiguration->getEndpointLogout()
               . '?post_logout_redirect_uri='
               . $this->getCallbackUrl();
    }

    /**
     *
     */
    protected function logoutUserFromFrontend()
    {
        $frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);

        // Authenticate now
        $frontendUser->start();
        $frontendUser->unpack_uc();
        //
        // // Register the frontend user as aspect and within the session
        // $this->setFrontendUserAspect($frontendUser);
        // $request = $request->withAttribute('frontend.user', $frontendUser);
        //
        // $response = $handler->handle($request);
        //
        // // Store session data for fe_users if it still exists
        // if ($frontendUser instanceof FrontendUserAuthentication) {
        //     $frontendUser->storeSessionData();
        // }

        /**
         * @var Context
         */
        $userAspect = $this->context->getAspect('frontend.user');
        // $this->context->g  ('frontend.user', GeneralUtility::makeInstance(UserAspect::class, $user));

    }

    /**
     *
     */
    protected function logoutUserFromBackend()
    {

    }

    /**
     * @return GenericProvider
     */
    protected function getOAuthClient(): GenericProvider
    {
        if (!isset($this->oauthClient)) {
            $this->oauthClient = new GenericProvider(
                [
                    'clientId'                => $this->extensionConfiguration->getClientId(),
                    'clientSecret'            => $this->extensionConfiguration->getClientSecret(),
                    'redirectUri'             => $this->getCallbackUrl(),
                    'urlAuthorize'            => $this->extensionConfiguration->getEndpointAuthorize(),
                    'urlAccessToken'          => $this->extensionConfiguration->getEndpointToken(),
                    'urlResourceOwnerDetails' => $this->extensionConfiguration->getEndpointUserInfo(),
                    'scopes'                  => GeneralUtility::trimExplode(
                        ',',
                        $this->extensionConfiguration->getClientScopes(),
                        true
                    ),
                ]
            );
        }

        return $this->oauthClient;
    }

    /**
     * @return string
     */
    protected function getCallbackUrl(): string
    {
        return GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST') . CallbackMiddleware::PATH;
    }

    /**
     * @param AccessToken $accessToken
     *
     * @return array<string, mixed>|null
     */
    protected function processAccessToken(AccessToken $accessToken): ?array
    {
        $idTokenClaims = null;
        if (array_key_exists('id_token', $accessToken->getValues())) {
            try {
                $tks = explode('.', $accessToken->getValues()['id_token']);
                // Check if the id_token contains signature
                if (2 <= count($tks) && !empty($tks[1])) {
                    $idTokenClaims = (array)json_decode(base64_decode($tks[1]));
                }
            } catch (\UnexpectedValueException $e) {
                throw new RuntimeException('Unable to parse the id_token!');
            }
        }
        return $idTokenClaims;
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
