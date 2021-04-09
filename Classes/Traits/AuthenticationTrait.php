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
use FSG\Oidc\Middleware\CallbackMiddleware;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Session;
use TYPO3\CMS\Core\Utility\GeneralUtility;

trait AuthenticationTrait
{

    /**
     * @var ExtensionConfiguration
     */
    protected ExtensionConfiguration $extensionConfiguration;

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
        $this->session                = new Session();
    }

    /**
     * Initialize local session and redirect to the authentication service if user attempt to log in the backend.
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
}
