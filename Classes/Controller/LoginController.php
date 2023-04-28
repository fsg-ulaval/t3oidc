<?php

declare(strict_types=1);

namespace FSG\Oidc\Controller;

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
use FSG\Oidc\Middleware\AuthenticationMiddleware;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Used for plugin login
 */
class LoginController extends \TYPO3\CMS\FrontendLogin\Controller\LoginController
{
    /**
     * Show login form
     */
    public function initializeLoginAction(): void
    {
        if ($this->settings['strictMode'] === '1'
            && !$this->userAspect->isLoggedIn()
            && !$this->isLoginOrLogoutInProgress()) {
            $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);

            if ($extensionConfiguration->isEnableFrontendAuthentication()) {
                $referrer    = $this->response->getRequest()->getRequestUri();
                $redirectUri = AuthenticationMiddleware::PATH . ($referrer ? '?referrer=' . $referrer : '');
                $this->redirectToUri($redirectUri);
            }
        }
    }
}
