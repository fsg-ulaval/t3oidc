<?php

declare(strict_types=1);

namespace FSG\Oidc\Listener\Core\Package\Event;

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

use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Package\Event\AfterPackageActivationEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class AfterPackageActivationListener
 */
class AfterPackageActivationListener
{
    /**
     * @var string[]
     */
    protected array $excludedParameters = [
        'code',
        'state',
        'error',
        'error_description',
    ];

    /**
     * @param AfterPackageActivationEvent $event
     */
    public function __invoke(AfterPackageActivationEvent $event): void
    {
        if ($event->getPackageKey() === 't3oidc') {
            $path = ['FE', 'cacheHash', 'excludedParameters'];
            $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
            $excludeParameters = $configurationManager->getConfigurationValueByPath($path);

            $this->setValues($excludeParameters);

            $configurationManager->setLocalConfigurationValueByPath($path, $excludeParameters);
        }
    }

    /**
     * @param array<mixed> $excludeParameters
     */
    protected function setValues(array &$excludeParameters): void
    {
        foreach ($this->excludedParameters as $excludedParameter) {
            if (!in_array($excludedParameter, $excludeParameters)) {
                $excludeParameters[] = $excludedParameter;
            }
        }
    }
}
