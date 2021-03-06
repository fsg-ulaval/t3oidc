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

use FSG\Oidc\Domain\Model\Dto\ExtensionConfiguration;
use FSG\Oidc\LoginProvider\OpenIDConnectSignInProvider;
use Symfony\Component\HttpFoundation\Session\Session;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Authentication\LoginType;
use TYPO3\CMS\Core\SysLog\Action\Login as SystemLogLoginAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
    private Session $session;

    /**
     * @var array<string, string>
     */
    private array $userInfo = [];

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
     */
    public function initAuth($mode, $loginData, $authInfo, $pObj): void
    {
        parent::initAuth($mode, $loginData, $authInfo, $pObj);

        $this->login['responsible'] = false;
        if (GeneralUtility::_GP('loginProvider') == OpenIDConnectSignInProvider::LOGIN_PROVIDER
            && $this->initializeUserInfo()) {
            $this->login['status']      = 'login';
            $this->login['responsible'] = true;
            $this->handleLogin();
        }
    }

    /**
     * Initializes UserInfo if session exists
     */
    protected function initializeUserInfo(): bool
    {
        if ($this->session->has('t3oidcOAuthUser')
            && ($this->userInfo = unserialize($this->session->get('t3oidcOAuthUser')))) {
            return true;
        }

        return false;
    }

    protected function handleLogin(): void
    {
        if ($this->login['responsible'] === true) {
            switch ($this->mode) {
                case 'getUserFE':
                case 'getUserBE':
                    $this->logger->debug(sprintf('Process auth mode "%s".', $this->mode));
                    // $this->insertOrUpdateUser();
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
     * Find a user
     *
     * @return array<string,string>|null
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
            return 0;
        }

        // Responsible, authentication ok, domain lock ok. Log successful login and return 'auth ok, do NOT check other services'
        $this->writeLogMessage(
            $this->pObj->loginType . ' Authentication successful for username \'%s\'',
            $user[$this->db_user['username_column']]
        );
        return 200;
    }
}
