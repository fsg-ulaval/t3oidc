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
use FSG\Oidc\Service\StatusService;
use FSG\Oidc\Traits\AuthenticationTrait;
use League\OAuth2\Client\Provider\GenericProvider;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Session;
use TYPO3\CMS\Backend\Controller\LoginController;
use TYPO3\CMS\Backend\LoginProvider\LoginProviderInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Class OpenIDConnectSignInProvider
 */
class OpenIDConnectSignInProvider implements LoginProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use AuthenticationTrait;

    public const LOGIN_PROVIDER = 1613676682;

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

            $errors = [];
            if (GeneralUtility::_GET('error') || GeneralUtility::_GET('error_description')) {
                $errors[] = [
                    'code'    => GeneralUtility::_GET('error'),
                    'message' => GeneralUtility::_GET('error_description'),
                ];
            } elseif (!$this->handleRequest()) {
                $errors[] = ['code' => 1616191700, 'message' => 'Handling error'];
            } elseif ($this->session->has('t3oidcOAuthUserAccessDenied')) {
                $errors[] = unserialize($this->session->get('t3oidcOAuthUserAccessDenied'));
                $this->session->remove('t3oidcOAuthUserAccessDenied');
            }

            // Assign variables and OpenID Connect response to view
            $view->assignMultiple([
                                      'oidcErrors' => $errors,
                                      'code'       => GeneralUtility::_GET('code'),
                                      'userInfo'   => $this->userInfo,
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
        if (GeneralUtility::_GP('handlingError')) {
            return false;
        }
        if ($this->session->has('t3oidcOAuthUser')) {
            $this->userInfo = unserialize($this->session->get('t3oidcOAuthUser'));
        }

        return true;
    }
}
