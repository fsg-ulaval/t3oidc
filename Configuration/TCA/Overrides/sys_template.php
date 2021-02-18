<?php

defined('TYPO3_MODE') || die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    't3oidc',
    'Configuration/TypoScript',
    'OpenID Connect authentication'
);
