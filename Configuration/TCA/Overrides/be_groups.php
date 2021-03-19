<?php

defined('TYPO3_MODE') || die();

$t3oidc_columns = [

    'oidc_identifier' => [
        'exclude' => true,
        'label'   => 'LLL:EXT:t3oidc/Resources/Private/Language/locallang_db.xlf:oidc_identifier',
        'config'  => [
            'type'     => 'input',
            'readOnly' => false,
            'size'     => 30,
            'eval'     => 'trim',
        ],
    ],

];

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('be_groups', $t3oidc_columns);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'be_groups',
    'oidc_identifier',
    '',
    'after:' . $GLOBALS['TCA']['be_groups']['ctrl']['label']
);
