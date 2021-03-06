<?php

declare(strict_types=1);

namespace FSG\Oidc\LoginProvider;

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
use FSG\Oidc\Error\ConfigurationException;
use FSG\Oidc\Error\HTTPSConnectionException;
use FSG\Oidc\Error\InvalidStateException;
use FSG\Oidc\Middleware\CallbackMiddleware;
use FSG\Oidc\Service\StatusService;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Session;
use TYPO3\CMS\Backend\Controller\LoginController;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\Authentication\LoginType;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Class OpenIDConnectSignInProvider
 */
class OpenIDConnectSignInProvider implements LoginProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const LOGIN_PROVIDER = 1613676682;

    /**
     * @var string
     */
    private string $action = '';

    /**
     * @var array<string, string>
     */
    private array $userInfo = [];

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
     * @param StandaloneView  $view
     * @param PageRenderer    $pageRenderer
     * @param LoginController $loginController
     *
     * @throws HTTPSConnectionException
     */
    public function render(StandaloneView $view, PageRenderer $pageRenderer, LoginController $loginController): void
    {
        try {
            $this->initializeView($view);

            // Figure out whether extension is configured
            StatusService::isEnabled('BE');

            if (isset(GeneralUtility::_GET('oidc')['action'])) {
                $this->action = GeneralUtility::_GET('oidc')['action'];
            }

            if (!$this->handleRequest()) {
                $error = 'Unexpected error';
            }

            // Assign variables and OpenID Connect response to view
            $view->assignMultiple([
                                      'oidcError'            => GeneralUtility::_GET('error'),
                                      'oidcErrorDescription' => GeneralUtility::_GET('error_description'),
                                      'handlingError'        => $error ?? '',
                                      'code'                 => GeneralUtility::_GET('code'),
                                      'userInfo'             => $this->userInfo,
                                  ]);
        } catch (ConfigurationException | RuntimeException $e) {
            $view->assign('error', $e);
        }
    }

    /**
     * @param StandaloneView $view
     */
    protected function initializeView(StandaloneView $view): void
    {
        $view->setTemplate('BackendLogin');
        $view->setLayoutRootPaths(['EXT:t3oidc/Resources/Private/Layouts/']);
        $view->setTemplateRootPaths(['EXT:t3oidc/Resources/Private/Templates']);
    }

    /**
     * Handle the current request
     *
     * @return bool
     */
    protected function handleRequest(): bool
    {
        try {
            if ($this->action === LoginType::LOGOUT) {
                // Logout user from authentication service
                $this->logger->debug('Logout user.');

                if ($this->session->has('t3oidcOAuthUser')) {
                    $this->session->remove('t3oidcOAuthUser');
                }
            } elseif ($this->action === LoginType::LOGIN) {
                // Login user to authentication service
                $this->logger->debug('Handle backend login.');
                $this->authenticateUser();
            } elseif ($providedState = GeneralUtility::_GP('state')) {
                // Process authentication service response
                if ($this->session->has('t3oidcOAuthUser')) {
                    $this->session->remove('t3oidcOAuthUser');
                }

                if ($this->session->has('t3oidcOAuthState')) {
                    $expectedState = $this->session->get('t3oidcOAuthState');
                    $this->session->remove('t3oidcOAuthState');
                    if ($expectedState != $providedState) {
                        throw new InvalidStateException(
                            'The provided auth state did not match the expected value',
                            1613752400
                        );
                    }
                }

                if ($code = GeneralUtility::_GP('code')) {
                    /**
                     * @var AccessToken $accessToken
                     */
                    $accessToken    = $this->getOAuthClient()->getAccessToken('authorization_code', ['code' => $code]);
                    $this->userInfo = $this->getOAuthClient()->getResourceOwner($accessToken)->toArray();

                    if (isset($this->userInfo[$this->extensionConfiguration->getTokenUserIdentifier()])) {
                        $this->session->set('t3oidcOAuthUser', serialize($this->userInfo));
                        $this->logger->notice(sprintf(
                            'Found user with OpenID Connect identifier "%s".',
                            $this->userInfo[$this->extensionConfiguration->getTokenUserIdentifier()]
                        ));
                    } else {
                        $this->userInfo = [];
                    }
                }
            } else {
                if ($this->session->has('t3oidcOAuthUser')) {
                    $this->userInfo = unserialize($this->session->get('t3oidcOAuthUser'));
                }
            }
        } catch (InvalidStateException | IdentityProviderException $e) {
            $this->logger->error(sprintf('Error %s: %s', $e->getCode(), $e->getMessage()));
            return false;
        }
        return true;
    }

    /**
     * Initialize local session and redirect to the authentication service.
     */
    protected function authenticateUser(): void
    {
        $authorizationUrl = $this->getOAuthClient()->getAuthorizationUrl();

        if ($this->session->has('t3oidcOAuthState')) {
            $this->session->replace(['t3oidcOAuthState' => $this->getOAuthClient()->getState()]);
        } else {
            $this->session->set('t3oidcOAuthState', $this->getOAuthClient()->getState());
        }

        HttpUtility::redirect($authorizationUrl);
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
}
