<?php

declare(strict_types=1);

namespace FSG\Oidc\ExpressionLanguage;

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

use FSG\Oidc\ExpressionLanguage\FunctionsProvider\ExtensionConfigurationConditionFunctionsProvider;
use TYPO3\CMS\Core\ExpressionLanguage\AbstractProvider;

/**
 * Class ExtensionConfigurationConditionProvider
 */
class ExtensionConfigurationConditionProvider extends AbstractProvider
{
    /**
     * ExtensionConfigurationConditionProvider constructor.
     */
    public function __construct()
    {
        $this->expressionLanguageProviders = [
            ExtensionConfigurationConditionFunctionsProvider::class,
        ];
    }
}
