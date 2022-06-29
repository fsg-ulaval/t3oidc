<?php

declare(strict_types=1);

namespace FSG\Oidc\Service;

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

use DateTime;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Result;
use FSG\Oidc\Domain\Model\Dto\ExtensionConfiguration;
use FSG\Oidc\LoginProvider\OpenIDConnectSignInProvider;
use PDO;
use Symfony\Component\HttpFoundation\Session\Session;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Authentication\LoginType;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\SysLog\Action\Login as SystemLogLoginAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * OpenID Connect authentication service.
 */
class AuthenticationService extends \TYPO3\CMS\Core\Authentication\AuthenticationService
{
    /**
     * @var ExtensionConfiguration
     */
    protected ExtensionConfiguration $extensionConfiguration;

    /**
     * @var Session<mixed>
     */
    protected Session $session;

    /**
     * @var array<string, mixed>
     */
    protected array $userInfo = [];

    /**
     * @var QueryBuilder
     */
    protected QueryBuilder $queryBuilder;

    /**
     * AuthenticationService constructor.
     */
    public function __construct()
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $this->session                = new Session();
    }

    /**
     * @param string                     $mode
     * @param array<string, string>      $loginData
     * @param array<string, string>      $authInfo
     * @param AbstractUserAuthentication $pObj
     *
     * @throws Exception
     * @throws InvalidPasswordHashException
     */
    public function initAuth($mode, $loginData, $authInfo, $pObj): void
    {
        parent::initAuth($mode, $loginData, $authInfo, $pObj);

        $this->login['responsible'] = false;
        if (($authInfo['loginType'] == 'FE'
             || ($authInfo['loginType'] == 'BE'
                 && GeneralUtility::_GP('loginProvider')
                    == OpenIDConnectSignInProvider::LOGIN_PROVIDER))
            && $this->initializeUserInfo()) {
            $this->initialize();
            $this->login['status']      = 'login';
            $this->login['responsible'] = true;
            $this->handleLogin();
        }
    }

    /**
     * Initialize UserInfo if session exists
     */
    protected function initializeUserInfo(): bool
    {
        if ($this->session->has('t3oidcOAuthUser')
            && ($this->userInfo = unserialize($this->session->get('t3oidcOAuthUser')))) {
            return true;
        }

        return false;
    }

    /**
     * Initialize Service
     */
    protected function initialize(): void
    {
        $request     = ServerRequestFactory::fromGlobals();
        $matcher     = GeneralUtility::makeInstance(SiteMatcher::class);
        $routeResult = $matcher->matchRequest(ServerRequestFactory::fromGlobals());
        $site        = $routeResult->getSite();

        if ($this->authInfo['loginType'] == 'FE') {
            $pageArguments = $site->getRouter()->matchRequest($request, $routeResult);
        }

        $tsfe = GeneralUtility::makeInstance(
            TypoScriptFrontendController::class,
            null,
            $site,
            $routeResult->getLanguage(),
            $pageArguments ?? null,
            GeneralUtility::makeInstance(FrontendUserAuthentication::class)
        );

        $templateService = GeneralUtility::makeInstance(TemplateService::class, null, null, $tsfe);
        $rootLine        = GeneralUtility::makeInstance(
            RootlineUtility::class,
            $tsfe->getPageArguments()->getPageId()
        )->get();
        $templateService->start($rootLine);

        if ($this->authInfo['loginType'] == 'FE'
            && !empty($settings = $templateService->setup['plugin.']['tx_felogin_login.']['settings.'])) {
            // List of page IDs where to look for frontend user records
            if ($pids = $settings['pages']) {
                $tsfe->fe_user->checkPid_value = implode(',', GeneralUtility::intExplode(',', $pids));
                $this->authInfo                = $tsfe->fe_user->getAuthInfoArray();
                $this->db_user                 = $this->authInfo['db_user'];
            }
        }
    }

    /**
     * @throws Exception|InvalidPasswordHashException
     */
    protected function handleLogin(): void
    {
        if ($this->login['responsible'] === true) {
            switch ($this->mode) {
                case 'getUserFE':
                case 'getUserBE':
                    $this->logger->debug(sprintf('Process auth mode "%s".', $this->mode));
                    $this->insertOrUpdateUser();
                    break;
                case 'authUserFE':
                case 'authUserBE':
                    $this->logger->debug(sprintf('Skip auth mode "%s".', $this->mode));
                    break;
                default:
                    $this->logger->notice(sprintf('Undefined mode "%s". Skip.', $this->mode));
            }
        }
    }

    /**
     * Insert or update logged in user
     *
     * @return array<string, string> $user
     * @throws Exception|InvalidPasswordHashException
     */
    protected function insertOrUpdateUser(): array
    {
        $this->queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                                            ->getQueryBuilderForTable($this->db_user['table']);

        $context   = $this->authInfo['loginType'] == 'BE' ? 'Backend' : 'Frontend';
        $userPerms = $this->fetchUserPerms($context);
        $user      = $this->fetchUserIfItExists();

        // Insert a new user into database
        if (!empty($userPerms) && empty($user)
            && !$this->extensionConfiguration->{'is' . $context . 'UserMustExistLocally'}()) {
            $this->logger->notice(
                'Insert new user.',
                [
                    $this->db_user['username_column'] => $this->userInfo[$this->extensionConfiguration->getTokenUserIdentifier()],
                ]
            );
            $user = $this->insertUser($userPerms);
        } elseif (!empty($user)) {
            if (($user['deleted'] == 0
                 || $this->extensionConfiguration->{'isUndelete' . $context . 'Users'}())
                && ($user['disable'] == 0
                    || $this->extensionConfiguration->{'isReEnable' . $context . 'Users'}())) {
                if (!$this->updateUser($user, $userPerms)) {
                    $this->logger->notice(
                        'User found but it was not updated!',
                        [
                            $this->db_user['userid_column']   => $user[$this->db_user['userid_column']],
                            $this->db_user['username_column'] => $user[$this->db_user['username_column']],
                        ]
                    );
                }
            } else {
                $user = [];
            }
        }

        return $user;
    }

    /**
     * Fetches the user permissions based on its roles.
     *
     * @param string $context
     *
     * @return array<string, mixed>
     * @throws Exception
     */
    public function fetchUserPerms(string $context): array
    {
        $userPerms = ['isAdmin' => false, 'groups' => []];

        if (method_exists(ExtensionConfiguration::class, 'get' . $context . 'UserDefaultGroups')
            && !empty($this->extensionConfiguration->{'get' . $context . 'UserDefaultGroups'}())) {
            $query             = GeneralUtility::makeInstance(ConnectionPool::class)
                                               ->getQueryBuilderForTable($this->db_groups['table']);
            $expressionBuilder = $query->expr();
            $constraints       = $expressionBuilder->andX(
                $expressionBuilder->in(
                    'uid',
                    $query->createNamedParameter(
                        $this->extensionConfiguration->{'get' . $context . 'UserDefaultGroups'}(),
                        Connection::PARAM_INT_ARRAY
                    )
                ),
                $expressionBuilder->orX(
                    $expressionBuilder->eq('lockToDomain', $query->quote('')),
                    $expressionBuilder->isNull('lockToDomain'),
                    $expressionBuilder->eq(
                        'lockToDomain',
                        $query->createNamedParameter(GeneralUtility::getIndpEnv('HTTP_HOST'))
                    )
                )
            );

            $res = $query->select('uid')
                         ->from($this->db_groups['table'])
                         ->where($constraints)
                         ->execute();

            foreach ($res->fetchAllAssociative() as $group) {
                $userPerms['groups'][] = $group['uid'];
            }
        }

        $rolesKey = array_key_exists('roles', $this->userInfo) ? 'roles' : 'Roles';
        if (!is_array($this->userInfo[$rolesKey]) || empty($this->userInfo[$rolesKey])) {
            return $userPerms;
        }

        if ($adminRole = $this->extensionConfiguration->getRoleAdmin()) {
            $userPerms['isAdmin'] = in_array($adminRole, $this->userInfo[$rolesKey]);
        }

        $query             = GeneralUtility::makeInstance(ConnectionPool::class)
                                           ->getQueryBuilderForTable($this->db_groups['table']);
        $expressionBuilder = $query->expr();
        $constraints       = $expressionBuilder->andX(
            $expressionBuilder->in(
                'oidc_identifier',
                $query->createNamedParameter(
                    $this->userInfo[$rolesKey],
                    Connection::PARAM_STR_ARRAY
                )
            ),
            $expressionBuilder->orX(
                $expressionBuilder->eq('lockToDomain', $query->quote('')),
                $expressionBuilder->isNull('lockToDomain'),
                $expressionBuilder->eq(
                    'lockToDomain',
                    $query->createNamedParameter(GeneralUtility::getIndpEnv('HTTP_HOST'))
                )
            )
        );

        $res = $query->select('uid')
                     ->from($this->db_groups['table'])
                     ->where($constraints)
                     ->execute();

        foreach ($res->fetchAllAssociative() as $group) {
            $userPerms['groups'][] = $group['uid'];
        }

        return $userPerms;
    }

    /**
     * This returns the logged in user record if it exists locally
     *
     * @return array<string, mixed>
     * @throws Exception
     */
    protected function fetchUserIfItExists(): array
    {
        $query = clone $this->queryBuilder;
        $query->getRestrictions()->removeAll();
        $constraint = $query->expr()->eq(
            'oidc_identifier',
            $query->createNamedParameter($this->userInfo[$this->extensionConfiguration->getTokenUserIdentifier()])
        );

        /** @var Result $result */
        $result = $query->select('*')
                        ->from($this->db_user['table'])
                        ->where($constraint)
                        ->execute();

        return $result->fetchAssociative() ?: [];
    }

    /**
     * @param array<string, mixed> $userPerms
     *
     * @return array<string, mixed>
     * @throws InvalidPasswordHashException
     */
    public function insertUser(array $userPerms): array
    {
        switch ($this->db_user['table']) {
            case 'fe_users':
                $user = $this->insertFeUser($userPerms);
                break;
            case 'be_users':
                $user = $this->insertBeUser($userPerms);
                break;
            default:
                $this->logger->error(sprintf('"%s" is not a valid table name.', $this->db_user['table']));
        }
        return $user ?? [];
    }

    /**
     * Inserts a new frontend user
     *
     * @param array<string, mixed> $userPerms
     *
     * @return array<string, mixed>
     * @throws InvalidPasswordHashException
     */
    public function insertFeUser(array $userPerms): array
    {
        if (empty($userPerms['groups'])) {
            return [];
        }

        $defaults = $this->getTcaDefaults();
        $query    = clone $this->queryBuilder;
        $endtime  = new DateTime('today +3 month');

        $preset = [
            'pid'             => current(GeneralUtility::intExplode(',', $this->db_user['checkPidList'])),
            'tstamp'          => time(),
            'crdate'          => time(),
            'disable'         => 0,
            'endtime'         => $endtime->getTimestamp(),
            'username'        => $this->getUsername(),
            'password'        => $this->getPassword(),
            'usergroup'       => implode(',', $userPerms['groups']),
            'name'            => $this->userInfo['name'] ?? '',
            'email'           => $this->userInfo['email'] ?? '',
            'oidc_identifier' => $this->userInfo[$this->extensionConfiguration->getTokenUserIdentifier()],
        ];

        $values = array_merge($defaults, $preset);

        $query->insert($this->db_user['table'])->values($values)->execute();

        $dbUser = array_merge($this->db_user, ['username_column' => 'oidc_identifier']);
        return $this->fetchUserRecord($preset['oidc_identifier'], '', $dbUser);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTcaDefaults(): array
    {
        $defaults = [];
        $columns  = $GLOBALS['TCA'][$this->db_user['table']]['columns'] ?? [];

        foreach ($columns as $fieldName => $field) {
            if (isset($field['config']['default'])) {
                $defaults[$fieldName] = $field['config']['default'];
            }
        }

        return $defaults;
    }

    /**
     * @return string
     */
    protected function getUsername(): string
    {
        $userPrincipalName = $this->userInfo[$this->extensionConfiguration->getTokenUserPrincipalNameOrFallback()] ?:
            $this->userInfo[$this->extensionConfiguration->getTokenUserIdentifier()];
        return strtolower($userPrincipalName);
    }

    /**
     * @throws InvalidPasswordHashException
     */
    protected function getPassword(): string
    {
        $saltFactory = GeneralUtility::makeInstance(PasswordHashFactory::class)->getDefaultHashInstance(TYPO3_MODE);
        $password    = GeneralUtility::makeInstance(Random::class)->generateRandomHexString(50);

        return $saltFactory->getHashedPassword($password);
    }

    /**
     * Inserts a new backend user
     *
     * @param array<string, mixed> $userPerms
     *
     * @return array<string, mixed>
     * @throws InvalidPasswordHashException
     */
    public function insertBeUser(array $userPerms): array
    {
        if (empty($userPerms['groups']) && !$userPerms['isAdmin']) {
            return [];
        }

        $defaults = $this->getTcaDefaults();
        $query    = clone $this->queryBuilder;
        $endtime  = new DateTime('today +3 month');

        $preset = [
            'pid'             => 0,
            'tstamp'          => time(),
            'crdate'          => time(),
            'disable'         => 0,
            'endtime'         => $endtime->getTimestamp(),
            'username'        => $this->getUsername(),
            'password'        => $this->getPassword(),
            'admin'           => ($userPerms['isAdmin'] ? 1 : 0),
            'usergroup'       => implode(',', $userPerms['groups']),
            'email'           => $this->userInfo['email'] ?? '',
            'realName'        => $this->userInfo['name'] ?? '',
            'oidc_identifier' => $this->userInfo[$this->extensionConfiguration->getTokenUserIdentifier()],
        ];

        $values = array_merge($defaults, $preset);

        $query->insert($this->db_user['table'])->values($values)->execute();

        $dbUser = array_merge($this->db_user, ['username_column' => 'oidc_identifier']);
        return $this->fetchUserRecord($preset['oidc_identifier'], '', $dbUser);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $userPerms
     *
     * @return bool
     */
    protected function updateUser(array &$user, array $userPerms): bool
    {
        $context = $this->authInfo['loginType'] == 'BE' ? 'Backend' : 'Frontend';
        // If the user must exist locally and no role is defined on the authentication server, keep the local assignments.
        if ($this->extensionConfiguration->{'is' . $context . 'UserMustExistLocally'}()
            && empty($userPerms['groups'])) {
            $userPerms['groups'] = explode(',', $user['usergroup']);
        }

        switch ($this->db_user['table']) {
            case 'fe_users':
                $updated = $this->updateFeUser($user, $userPerms);
                break;
            case 'be_users':
                $updated = $this->updateBeUser($user, $userPerms);
                break;
            default:
                $this->logger->error(sprintf('"%s" is not a valid table name.', $this->db_user['table']));
        }
        return $updated ?? false;
    }

    /**
     * This update the frontend logged in user record
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $userPerms
     *
     * @return bool
     */
    protected function updateFeUser(array &$user, array $userPerms): bool
    {
        $endtime = new DateTime('today +3 month');
        $query   = clone $this->queryBuilder;
        $updated = (bool)$query->update($this->db_user['table'])
                               ->set('username', $this->getUsername())
                               ->set('usergroup', implode(',', $userPerms['groups']))
                               ->set('email', $this->userInfo['email'])
                               ->set('name', $this->userInfo['name'])
                               ->set('deleted', (count($userPerms['groups']) ? '0' : '1'), true, PDO::PARAM_INT)
                               ->set('disable', '0', true, PDO::PARAM_INT)
                               ->set('starttime', '0', true, PDO::PARAM_INT)
                               ->set('endtime', (string)$endtime->getTimestamp(), true, PDO::PARAM_INT)
                               ->where(
                                   $query->expr()->eq(
                                       'uid',
                                       $query->createNamedParameter(
                                           $user['uid'],
                                           PDO::PARAM_INT
                                       )
                                   )
                               )
                               ->execute();

        if ($updated) {
            $user['username']  = $this->getUsername();
            $user['usergroup'] = implode(',', $userPerms['groups']);
            $user['email']     = $this->userInfo['email'];
            $user['name']      = $this->userInfo['name'];
            $user['starttime'] = 0;
            $user['endtime']   = $endtime->getTimestamp();
            $user['deleted']   = 0;
            $user['disable']   = 0;
        }

        return $updated;
    }

    /**
     * This update the backend logged in user record
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $userPerms
     *
     * @return bool
     */
    protected function updateBeUser(array &$user, array $userPerms): bool
    {
        $endtime = new DateTime('today +3 month');
        $query   = clone $this->queryBuilder;
        $updated = (bool)$query->update($this->db_user['table'])
                               ->set('username', $this->getUsername())
                               ->set('admin', $userPerms['isAdmin'], true, PDO::PARAM_BOOL)
                               ->set('usergroup', implode(',', $userPerms['groups']))
                               ->set('email', $this->userInfo['email'])
                               ->set('realName', $this->userInfo['name'])
                               ->set(
                                   'deleted',
                                   (($userPerms['isAdmin'] || count($userPerms['groups'])) ? '0' : '1'),
                                   true,
                                   PDO::PARAM_INT
                               )
                               ->set('disable', '0', true, PDO::PARAM_INT)
                               ->set('starttime', '0', true, PDO::PARAM_INT)
                               ->set('endtime', (string)$endtime->getTimestamp(), true, PDO::PARAM_INT)
                               ->where(
                                   $query->expr()->eq(
                                       'uid',
                                       $query->createNamedParameter(
                                           $user['uid'],
                                           PDO::PARAM_INT
                                       )
                                   )
                               )
                               ->execute();

        if ($updated) {
            $user['username']  = $this->getUsername();
            $user['admin']     = $userPerms['isAdmin'];
            $user['usergroup'] = implode(',', $userPerms['groups']);
            $user['email']     = $this->userInfo['email'];
            $user['realName']  = $this->userInfo['name'];
            $user['starttime'] = 0;
            $user['endtime']   = $endtime->getTimestamp();
            $user['deleted']   = 0;
            $user['disable']   = 0;
        }

        return $updated;
    }

    /**
     * Find a user
     *
     * @return array<string, string>|null
     */
    public function getUser(): ?array
    {
        if ($this->login['status'] !== LoginType::LOGIN
            || $this->login['responsible'] === false
            || !isset($this->userInfo[$this->extensionConfiguration->getTokenUserIdentifier()])) {
            return null;
        }

        $dbUser = array_merge($this->db_user, ['username_column' => 'oidc_identifier']);
        $user   = $this->fetchUserRecord(
            $this->userInfo[$this->extensionConfiguration->getTokenUserIdentifier()],
            '',
            $dbUser
        ) ?: null;

        if (!is_array($user)) {
            // Failed login attempt (no username found)
            $this->writelog(
                SystemLogType::LOGIN,
                SystemLogLoginAction::ATTEMPT,
                SystemLogErrorClassification::SECURITY_NOTICE,
                2,
                'Login-attempt from ###IP###, username \'%s\' not found!!',
                [$this->login['uname']]
            );
            $this->logger->info(
                'Login-attempt from username \'' . $this->login['uname'] . '\' not found!',
                [
                    'REMOTE_ADDR' => $this->authInfo['REMOTE_ADDR'],
                ]
            );
        } else {
            $this->logger->debug(
                'User found',
                [
                    $this->db_user['userid_column']   => $user[$this->db_user['userid_column']],
                    $this->db_user['username_column'] => $user[$this->db_user['username_column']],
                ]
            );
        }

        return $user;
    }

    /**
     * Authenticate a user: Check user identifier if the service is responsible of the authentication and check domain
     * lock if configured.
     *
     * Returns one of the following status codes:
     *  >= 200: User authenticated successfully. No more checking is needed by other auth services.
     *  >= 100: User not authenticated; this service is not responsible. Other auth services will be asked.
     *  > 0:    User authenticated successfully. Other auth services will still be asked.
     *  <= 0:   Authentication failed, no more checking needed by other auth services.
     *
     * @param array<string, mixed> $user User data
     *
     * @return int Authentication status code, one of 0, 100, 200
     */
    public function authUser(array $user): int
    {
        if ($this->login['responsible'] === false) {
            // Service is not responsible. Check other services.
            return 100;
        }
        if (empty($user['oidc_identifier'])
            || (string)$user['oidc_identifier']
               !== $this->userInfo[$this->extensionConfiguration->getTokenUserIdentifier()]) {
            // Verification failed as identifier does not match. Maybe other services can handle this login.
            return 100;
        }
        if ($this->authInfo['loginType'] == 'BE' && !$this->hasWorkspacePerms($user)) {
            // Responsible, authentication ok, but user has no access defined
            $this->session->set('t3oidcOAuthUserAccessDenied', 'NotConfigured');
            $this->session->set(
                't3oidcOAuthUserAccessDenied',
                serialize(['code' => 1616191800, 'message' => 'Account not configured'])
            );
            return 0;
        }
        if ($this->authInfo['loginType'] == 'FE' && empty($user['usergroup'])) {
            // Responsible, authentication ok, but user has no usergroup defined
            return 0;
        }

        $queriedDomain   = $this->authInfo['HTTP_HOST'];
        $isDomainLockMet = false;

        if (empty($user['lockToDomain'])) {
            // No domain restriction set for user in db. This is ok.
            $isDomainLockMet = true;
        } elseif (!strcasecmp($user['lockToDomain'], $queriedDomain)) {
            // Domain restriction set and it matches given host. Ok.
            $isDomainLockMet = true;
        }

        if (!$isDomainLockMet) {
            // Configured domain lock not met
            $errorMessage = 'Login-attempt from ###IP###, username \'%s\', locked domain \'%s\' did not match \'%s\'!';
            $this->writeLogMessage(
                $errorMessage,
                $user[$this->db_user['username_column']],
                $user['lockToDomain'],
                $queriedDomain
            );
            $this->writelog(
                SystemLogType::LOGIN,
                SystemLogLoginAction::ATTEMPT,
                SystemLogErrorClassification::SECURITY_NOTICE,
                1,
                $errorMessage,
                [$user[$this->db_user['username_column']], $user['lockToDomain'], $queriedDomain]
            );
            $this->logger->info(sprintf(
                                    $errorMessage,
                                    $user[$this->db_user['username_column']],
                                    $user['lockToDomain'],
                                    $queriedDomain
                                ));
            // Responsible, authentication ok, but domain lock not ok, do NOT check other services
            $this->session->set(
                't3oidcOAuthUserAccessDenied',
                serialize(['code' => 1616191801, 'message' => 'Domain lock not met'])
            );
            return 0;
        }

        // Responsible, authentication ok, domain lock ok. Log successful login and return 'auth ok, do NOT check other services'
        $this->writeLogMessage(
            $this->pObj->loginType . ' Authentication successful for username \'%s\'',
            $user[$this->db_user['username_column']]
        );
        return 200;
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return bool
     */
    protected function hasWorkspacePerms(array $user): bool
    {
        $beUser       = clone $GLOBALS['BE_USER'];
        $beUser->user = $user;
        $beUser->fetchGroupData();
        return $beUser->getDefaultWorkspace() >= 0;
    }
}
