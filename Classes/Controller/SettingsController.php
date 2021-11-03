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


use Doctrine\DBAL\Driver\Exception;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Exception\InvalidExtensionNameException;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Settings controller
 */
class SettingsController
{
    /**
     * Helper method to initialize a standalone view instance.
     *
     * @param        $request
     * @param string $templatePath
     *
     * @return StandaloneView
     * @throws InvalidExtensionNameException
     * @internal param string $template
     */
    protected function initializeStandaloneView($request, string $templatePath): StandaloneView
    {
        $viewRootPath = GeneralUtility::getFileAbsFileName('EXT:t3oidc/Resources/Private/');
        $view         = GeneralUtility::makeInstance(StandaloneView::class);
        $view->getRequest()->setControllerExtensionName('T3oidc');
        $view->setTemplatePathAndFilename($viewRootPath . 'Templates/' . $templatePath);
        $view->setLayoutRootPaths([$viewRootPath . 'Layouts/']);
        $view->setPartialRootPaths([$viewRootPath . 'Partials/']);
        return $view;
    }

    /**
     * Return a list of possible and active feGroups
     *
     * @param array $params
     *
     * @return string
     * @throws InvalidExtensionNameException
     * @throws Exception
     */
    public function feGroupGetListAction(array $params): string
    {
        $view           = $this->initializeStandaloneView(new Request(), 'Settings/FrontendUserDefaultGroups.html');
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        // We have to respect the enable fields here by our own because no TCA is loaded
        $queryBuilder = $connectionPool->getQueryBuilderForTable('fe_groups');
        $queryBuilder->getRestrictions()->removeAll();
        $groups = $queryBuilder
            ->select('uid', 'title', 'hidden')
            ->from('fe_groups')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)))
            ->orderBy('uid')
            ->execute()
            ->fetchAllAssociative();

        $currentSelection = GeneralUtility::trimExplode(',', $params['fieldValue'], true);
        $feGroups = [];
        foreach ($groups as $group) {
            $feGroups[] = [
                'uid'        => $group['uid'],
                'title'      => $group['title'],
                'isSelected' => in_array($group['uid'], $currentSelection, false),
            ];
        }

        $view->assignMultiple([
                                  'value'  => implode(',', $currentSelection),
                                  'groups' => $feGroups,
                              ]);

        return $view->render();
    }
}
