<?php

declare(strict_types=1);

namespace FSG\Oidc\Listener\FrontendLogin\Event;

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
use TYPO3\CMS\FrontendLogin\Event\ModifyLoginFormViewEvent;

/**
 * Class ModifyLoginFormViewListener
 */
class ModifyLoginFormViewListener
{
    /**
     * @var ExtensionConfiguration
     */
    protected ExtensionConfiguration $extensionConfiguration;

    /**
     * AuthenticationService constructor.
     */
    public function __construct()
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
    }

    /**
     * @param ModifyLoginFormViewEvent $event
     */
    public function __invoke(ModifyLoginFormViewEvent $event): void
    {
        if ($this->extensionConfiguration->isEnableFrontendAuthentication()) {
            $event->getView()->assign('oidcAuthenticationUrl', AuthenticationMiddleware::PATH);
        }
    }
}
