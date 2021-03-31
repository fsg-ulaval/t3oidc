<?php

defined('TYPO3_MODE') || die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    't3oidc',
    'Configuration/TypoScript',
    'OpenID Connect authentication'
);

if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('felogin')
    && \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
        \TYPO3\CMS\Core\Configuration\Features::class
    )->isFeatureEnabled('felogin.extbase')
    && \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
        \FSG\Oidc\Domain\Model\Dto\ExtensionConfiguration::class
    )->isEnableFrontendAuthentication()) {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
        't3oidc',
        'Configuration/TypoScript/felogin',
        'OpenID Connect for felogin'
    );
}
