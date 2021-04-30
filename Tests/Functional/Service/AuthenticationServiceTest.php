<?php

namespace FSG\Oidc\Tests\Functional\Service;

use DateTime;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\SchemaException;
use FSG\Oidc\Domain\Model\Dto\ExtensionConfiguration;
use FSG\Oidc\Service\AuthenticationService;
use PHPUnit\Framework\MockObject\MockObject;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Schema\Exception\StatementException;
use TYPO3\CMS\Core\Database\Schema\Exception\UnexpectedSignalReturnValueTypeException;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\AccessibleObjectInterface;
use TYPO3\TestingFramework\Core\Exception;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Testcase for class FSG\Oidc\Service\AuthenticationService
 */
class AuthenticationServiceTest extends FunctionalTestCase
{
    use ProphecyTrait;

    /**
     * @var AuthenticationService | MockObject | AccessibleObjectInterface
     */
    protected $authenticationService;

    /**
     * @var ExtensionConfiguration | ObjectProphecy
     */
    protected $extensionConfigurationProphesize;

    /**
     * @var array<string, string>
     */
    protected array $beDbUser = [
        'table'            => 'be_users',
        'username_column'  => 'oidc_identifier',
        'check_pid_clause' => '',
        'enable_clause'    => '',
    ];

    /**
     * @var array<string, string>
     */
    protected array $beDbGroup = [
        'table' => 'be_groups',
    ];

    /**
     * @var array<string, string>
     */
    protected array $feDbUser = [
        'table'            => 'fe_users',
        'username_column'  => 'oidc_identifier',
        'check_pid_clause' => '',
        'enable_clause'    => '',
    ];

    /**
     * @var array<string, string>
     */
    protected array $feDbGroup = [
        'table' => 'fe_groups',
    ];

    /**
     * @throws DBALException
     * @throws Exception
     * @throws SchemaException
     * @throws StatementException
     * @throws UnexpectedSignalReturnValueTypeException
     */
    protected function setUp(): void
    {
        $this->extensionConfigurationProphesize = $this->prophesize(ExtensionConfiguration::class);
        $this->extensionConfigurationProphesize->getRoleAdmin()->willReturn('administrator');
        $this->extensionConfigurationProphesize->getTokenUserIdentifier()->willReturn('sub');
        $this->extensionConfigurationProphesize->isUndeleteBackendUsers()->willReturn(false);
        $this->extensionConfigurationProphesize->isReEnableBackendUsers()->willReturn(false);
        $this->extensionConfigurationProphesize->isBackendUserMustExistLocally()->willReturn(false);
        $this->extensionConfigurationProphesize->isUndeleteFrontendUsers()->willReturn(false);
        $this->extensionConfigurationProphesize->isReEnableFrontendUsers()->willReturn(false);
        $this->extensionConfigurationProphesize->isFrontendUserMustExistLocally()->willReturn(false);

        $this->authenticationService = $this->getAccessibleMock(AuthenticationService::class, ['dummy'], [], '', false);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());
        $this->authenticationService->_set('logger', new NullLogger());
        $this->authenticationService->_set('login', ['status' => 'login', 'responsible' => true]);

        parent::setUp();

        $this->authenticationService->pObj = new BackendUserAuthentication();
        $this->importExtTablesDefinition();
        $this->importDataSet(ORIGINAL_ROOT . 'typo3conf/ext/t3oidc/Tests/Functional/Fixtures/be_users.xml');
        $this->importDataSet(ORIGINAL_ROOT . 'typo3conf/ext/t3oidc/Tests/Functional/Fixtures/be_groups.xml');
        $this->importDataSet(ORIGINAL_ROOT . 'typo3conf/ext/t3oidc/Tests/Functional/Fixtures/fe_users.xml');
        $this->importDataSet(ORIGINAL_ROOT . 'typo3conf/ext/t3oidc/Tests/Functional/Fixtures/fe_groups.xml');
    }

    /**
     * Imports the ext_tables.sql definition as done by the install tool.
     *
     * @throws DBALException
     * @throws SchemaException
     * @throws StatementException
     * @throws UnexpectedSignalReturnValueTypeException
     */
    protected function importExtTablesDefinition(): void
    {
        $schemaMigrationService = GeneralUtility::makeInstance(SchemaMigrator::class);
        $sqlReader              = GeneralUtility::makeInstance(SqlReader::class);
        $sqlCode                = file_get_contents(ORIGINAL_ROOT . 'typo3conf/ext/t3oidc/ext_tables.sql');

        $createTableStatements = $sqlReader->getCreateTableStatementArray($sqlCode);

        $updateResult     = $schemaMigrationService->install($createTableStatements);
        $failedStatements = array_filter($updateResult);
        $result           = [];
        foreach ($failedStatements as $query => $error) {
            $result[] = 'Query "' . $query . '" returned "' . $error . '"';
        }

        if (!empty($result)) {
            throw new RuntimeException(implode("\n", $result), 1615316197);
        }

        $insertStatements = $sqlReader->getInsertStatementArray($sqlCode);
        $schemaMigrationService->importStaticData($insertStatements);
    }

    private function setBackendEnvironment(): void
    {
        $this->authenticationService->_set('db_user', $this->beDbUser);
        $this->authenticationService->_set('db_groups', $this->beDbGroup);
        $this->authenticationService->_set('authInfo', ['loginType' => 'BE']);
    }

    private function setFrontendEnvironment(): void
    {
        $this->authenticationService->_set('db_user', $this->feDbUser);
        $this->authenticationService->_set('db_groups', $this->feDbGroup);
        $this->authenticationService->_set('authInfo', ['loginType' => 'FE']);
    }

    /**
     * @test
     */
    public function expectNullIfLoginStatusIsNotEqualToLoginTypeLogin(): void
    {
        $this->authenticationService->login['status'] = 'foo';

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->authenticationService, ['sub' => '']);

        $this->setBackendEnvironment();
        self::assertNull($this->authenticationService->getUser());
        $this->setFrontendEnvironment();
        self::assertNull($this->authenticationService->getUser());
    }

    /**
     * @test
     */
    public function expectNullIfServiceIsNotResponsible(): void
    {
        $this->authenticationService->login['responsible'] = false;

        $this->setBackendEnvironment();
        self::assertNull($this->authenticationService->getUser());
        $this->setFrontendEnvironment();
        self::assertNull($this->authenticationService->getUser());
    }

    /**
     * @test
     */
    public function expectNullIfUserInfoIdentifierIsNotSet(): void
    {
        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($this->authenticationService, []);

        $this->setBackendEnvironment();
        self::assertNull($this->authenticationService->getUser());
        $this->setFrontendEnvironment();
        self::assertNull($this->authenticationService->getUser());
    }

    /**
     * @test
     * @throws ReflectionException
     */
    public function expectUpdatedActiveUser(): void
    {
        $reflectionMethod = new ReflectionMethod(AuthenticationService::class, 'insertOrUpdateUser');
        $reflectionMethod->setAccessible(true);

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'Foo', 'name' => 'Foo FOO', 'email' => 'foo@foo.com', 'roles' => ['editor', 'user']]
        );

        // Set backend environment
        $this->setBackendEnvironment();
        $this->authenticationService->_set('db_user', $this->beDbUser);
        $this->authenticationService->_set('db_groups', $this->beDbGroup);
        $this->authenticationService->_set('authInfo', ['loginType' => 'BE']);
        $user = $reflectionMethod->invoke($this->authenticationService);

        $endtime = new DateTime('today +3 month');
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('Foo FOO', $user['realName']);
        self::assertSame('foo@foo.com', $user['email']);
        self::assertSame('9', $user['usergroup']);

        // Set frontend environment
        $this->setFrontendEnvironment();
        $user = $reflectionMethod->invoke($this->authenticationService);

        $endtime = new DateTime('today +3 month');
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('Foo FOO', $user['name']);
        self::assertSame('foo@foo.com', $user['email']);
        self::assertSame('4', $user['usergroup']);
    }

    /**
     * @test
     * @throws ReflectionException
     */
    public function expectNoUserIfDisableAndOrDeleted(): void
    {
        $reflectionMethod = new ReflectionMethod(AuthenticationService::class, 'insertOrUpdateUser');
        $reflectionMethod->setAccessible(true);

        // Set backend environment
        $this->setBackendEnvironment();

        // Authentication of a deleted backend user
        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'Bar', 'name' => 'Bar BAR', 'email' => 'bar@bar.com']
        );

        self::assertEmpty($reflectionMethod->invoke($this->authenticationService));

        // Authentication of a disabled backend user
        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'FooBar', 'name' => 'Foo BAR', 'email' => 'foobar@foobar.com']
        );

        self::assertEmpty($reflectionMethod->invoke($this->authenticationService));

        // Authentication of a deleted and disabled backend user
        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'BarFoo', 'name' => 'Bar Foo', 'email' => 'barfoo@barfoo.com']
        );

        self::assertEmpty($reflectionMethod->invoke($this->authenticationService));

        // Set frontend environment
        $this->setFrontendEnvironment();

        // Authentication of a deleted frontend user
        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'Bar', 'name' => 'FE Bar BAR', 'email' => 'febar@bar.com']
        );

        self::assertEmpty($reflectionMethod->invoke($this->authenticationService));

        // Authentication of a disabled frontend user
        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'FooBar', 'name' => 'FE Foo BAR', 'email' => 'fefoobar@foobar.com']
        );

        self::assertEmpty($reflectionMethod->invoke($this->authenticationService));

        // Authentication of a deleted and disabled frontend user
        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'BarFoo', 'name' => 'FE Bar Foo', 'email' => 'febarfoo@barfoo.com']
        );

        self::assertEmpty($reflectionMethod->invoke($this->authenticationService));
    }

    /**
     * @test
     * @throws ReflectionException
     */
    public function expectUpdatedUserIfDisableAndOrDeleted(): void
    {
        $endtime = new DateTime('today +3 month');

        $reflectionMethod = new ReflectionMethod(AuthenticationService::class, 'insertOrUpdateUser');
        $reflectionMethod->setAccessible(true);

        // Set backend environment
        $this->setBackendEnvironment();

        // Authentication of a deleted backend user
        $this->extensionConfigurationProphesize->isUndeleteBackendUsers()->willReturn(true);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'Bar', 'name' => 'Bar BAR', 'email' => 'bar@bar.com']
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('Bar BAR', $user['realName']);
        self::assertSame('bar@bar.com', $user['email']);

        // Authentication of a deleted and disabled backend user
        $this->extensionConfigurationProphesize->isReEnableBackendUsers()->willReturn(true);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'BarFoo', 'name' => 'Bar Foo', 'email' => 'barfoo@barfoo.com']
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('Bar Foo', $user['realName']);
        self::assertSame('barfoo@barfoo.com', $user['email']);

        // Authentication of a disabled backend user
        $this->extensionConfigurationProphesize->isUndeleteBackendUsers()->willReturn(false);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'FooBar', 'name' => 'Foo BAR', 'email' => 'foobar@foobar.com']
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('Foo BAR', $user['realName']);
        self::assertSame('foobar@foobar.com', $user['email']);

        // Set frontend environment
        $this->setFrontendEnvironment();

        // Authentication of a deleted frontend user
        $this->extensionConfigurationProphesize->isUndeleteFrontendUsers()->willReturn(true);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'Bar', 'name' => 'FE Bar BAR', 'email' => 'febar@bar.com']
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('FE Bar BAR', $user['name']);
        self::assertSame('febar@bar.com', $user['email']);

        // Authentication of a deleted and disabled frontend user
        $this->extensionConfigurationProphesize->isReEnableFrontendUsers()->willReturn(true);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'BarFoo', 'name' => 'FE Bar Foo', 'email' => 'febarfoo@barfoo.com']
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('FE Bar Foo', $user['name']);
        self::assertSame('febarfoo@barfoo.com', $user['email']);

        // Authentication of a disabled frontend user
        $this->extensionConfigurationProphesize->isUndeleteFrontendUsers()->willReturn(false);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'FooBar', 'name' => 'FE Foo BAR', 'email' => 'fefoobar@foobar.com']
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('FE Foo BAR', $user['name']);
        self::assertSame('fefoobar@foobar.com', $user['email']);
    }

    /**
     * @test
     * @throws ReflectionException
     */
    public function expectInsertedUser(): void
    {
        $endtime = new DateTime('today +3 month');

        $reflectionMethod = new ReflectionMethod(AuthenticationService::class, 'insertOrUpdateUser');
        $reflectionMethod->setAccessible(true);

        // Set backend environment
        $this->setBackendEnvironment();

        // Authentication of a new backend user
        $this->extensionConfigurationProphesize->isEnableBackendAuthentication()->willReturn(true);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'NewUser', 'name' => 'New USER', 'email' => 'new@user.com', 'roles' => ['editor']]
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('New USER', $user['realName']);
        self::assertSame('new@user.com', $user['email']);
        self::assertSame(0, $user['admin']);

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'NewUser', 'name' => 'New USER', 'email' => 'new@user.com', 'roles' => ['administrator']]
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('New USER', $user['realName']);
        self::assertSame('new@user.com', $user['email']);
        self::assertTrue($user['admin']);

        // Set backend environment
        $this->setFrontendEnvironment();

        // Authentication of a new frontend user
        $this->extensionConfigurationProphesize->isEnableFrontendAuthentication()->willReturn(true);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'NewUser', 'name' => 'New FE USER', 'email' => 'fenew@user.com', 'roles' => ['user']]
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertSame($endtime->getTimestamp(), $user['endtime']);
        self::assertSame('New FE USER', $user['name']);
        self::assertSame('fenew@user.com', $user['email']);
    }

    /**
     * @test
     * @throws ReflectionException
     */
    public function expectNotInsertedUser(): void
    {
        $reflectionMethod = new ReflectionMethod(AuthenticationService::class, 'insertOrUpdateUser');
        $reflectionMethod->setAccessible(true);

        // Set backend environment
        $this->setBackendEnvironment();

        // Authentication of a new backend user
        $this->extensionConfigurationProphesize->isEnableBackendAuthentication()->willReturn(true);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'NewUser', 'name' => 'New USER', 'email' => 'new@user.com']
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertEmpty($user);

        // Set backend environment
        $this->setFrontendEnvironment();

        // Authentication of a new frontend user
        $this->extensionConfigurationProphesize->isEnableFrontendAuthentication()->willReturn(true);
        $this->authenticationService->_set('extensionConfiguration', $this->extensionConfigurationProphesize->reveal());

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue(
            $this->authenticationService,
            ['sub' => 'NewUser', 'name' => 'New FE USER', 'email' => 'fenew@user.com']
        );

        $user = $reflectionMethod->invoke($this->authenticationService);
        self::assertEmpty($user);
    }
}
