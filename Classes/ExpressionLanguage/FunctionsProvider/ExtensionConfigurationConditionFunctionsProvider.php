<?php

declare(strict_types=1);

namespace FSG\Oidc\ExpressionLanguage\FunctionsProvider;

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

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class ExtensionConfigurationConditionFunctionsProvider
 */
class ExtensionConfigurationConditionFunctionsProvider implements ExpressionFunctionProviderInterface
{
    /**
     * @return ExpressionFunction[]
     */
    public function getFunctions(): array
    {
        return [
            $this->getExtensionConfigurationFunction(),
        ];
    }

    /**
     * @return ExpressionFunction
     */
    protected function getExtensionConfigurationFunction(): ExpressionFunction
    {
        return new ExpressionFunction('extensionConfiguration', function () {
            // Not implemented, we only use the evaluator
        }, function ($existingVariables, $extension, $configuration) {
            return GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extension)[$configuration];
        });
    }
}
