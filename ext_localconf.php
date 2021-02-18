<?php

defined('TYPO3_MODE') || die();

(static function (string $extKey) {
    /**
     * Configuration of authentication service
     *
     * @var \FSG\Oidc\Domain\Model\Dto\ExtensionConfiguration $settings
     */
    $settings = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
        FSG\Oidc\Domain\Model\Dto\ExtensionConfiguration::class
    );

    // Require 3rd-party libraries, in case TYPO3 does not run in composer mode
    $pharFileName = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($extKey)
                    . 'Libraries/league-oauth2-client.phar';
    if (is_file($pharFileName)) {
        @include 'phar://' . $pharFileName . '/vendor/autoload.php';
    }
})('t3oidc');
