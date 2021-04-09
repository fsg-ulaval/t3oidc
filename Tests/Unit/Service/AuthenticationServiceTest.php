<?php

namespace FSG\Oidc\Tests\Unit\Service;

use FSG\Oidc\Domain\Model\Dto\ExtensionConfiguration;
use FSG\Oidc\Service\AuthenticationService;
use PHPUnit\Framework\MockObject\MockObject;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\AccessibleObjectInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Class AuthenticationServiceTest
 */
class AuthenticationServiceTest extends UnitTestCase
{
    use ProphecyTrait;

    /**
     * @var ExtensionConfiguration | MockObject | AccessibleObjectInterface
     */
    private $extensionConfigurationMock;

    /**
     * @var BackendUserAuthentication | ObjectProphecy
     */
    private $beUserProphecy;

    /**
     * @var array<string, mixed>
     */
    private array $beUser = [
        'uid'             => '1',
        'username'        => 'Foo',
        'oidc_identifier' => 'foo',
    ];

    /**
     * Sets up this test case.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetSingletonInstances = true;

        $this->beUserProphecy = $this->prophesize(BackendUserAuthentication::class);
        $this->beUserProphecy->fetchGroupData()->willReturn();
        $this->beUserProphecy->getDefaultWorkspace()->willReturn(0);
        $this->beUserProphecy->setLogger(new NullLogger());
        $GLOBALS['BE_USER'] = $this->beUserProphecy->reveal();
    }

    /**
     * @test
     * @return AuthenticationService | MockObject | AccessibleObjectInterface
     */
    public function expect100IfServiceIsNotResponsible()
    {
        /** @var AuthenticationService | MockObject | AccessibleObjectInterface $authenticationServiceMock */
        $authenticationServiceMock = $this->getAccessibleMock(AuthenticationService::class, ['dummy'], [], '', false);
        $authenticationServiceMock->_set('login', ['responsible' => false]);

        self::assertSame(100, $authenticationServiceMock->authUser($this->beUser));

        return $authenticationServiceMock;
    }

    /**
     * @test
     * @depends expect100IfServiceIsNotResponsible
     *
     * @param AuthenticationService | MockObject | AccessibleObjectInterface $authenticationServiceMock
     *
     * @return AuthenticationService | MockObject | AccessibleObjectInterface
     */
    public function expect100IfNoOIDCIdentifier($authenticationServiceMock)
    {
        $authenticationServiceMock->_set('login', ['responsible' => true]);

        $user = array_merge($this->beUser, ['oidc_identifier' => null]);
        self::assertSame(100, $authenticationServiceMock->authUser($user));

        return $authenticationServiceMock;
    }

    /**
     * @test
     * @depends expect100IfNoOIDCIdentifier
     *
     * @param AuthenticationService | MockObject | AccessibleObjectInterface $authenticationServiceMock
     *
     * @return AuthenticationService | MockObject | AccessibleObjectInterface
     */
    public function expect100IfOIDCIdentifierDoesNotMatchUserInfoIdentifier($authenticationServiceMock)
    {
        /** @var ExtensionConfiguration | MockObject | AccessibleObjectInterface $extensionConfigurationMock */
        $extensionConfigurationMock = $this->getAccessibleMock(ExtensionConfiguration::class, ['dummy'], [], '', false);
        $extensionConfigurationMock->_set('tokenUserIdentifier', 'sub');

        $authenticationServiceMock->_set('extensionConfiguration', $extensionConfigurationMock);

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'userInfo');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($authenticationServiceMock, ['sub' => 'foo']);

        $user = array_merge($this->beUser, ['oidc_identifier' => 'bar']);
        self::assertSame(100, $authenticationServiceMock->authUser($user));

        return $authenticationServiceMock;
    }

    /**
     * @test
     * @depends expect100IfOIDCIdentifierDoesNotMatchUserInfoIdentifier
     *
     * @param AuthenticationService | MockObject | AccessibleObjectInterface $authenticationServiceMock
     *
     * @return AuthenticationService | MockObject | AccessibleObjectInterface
     */
    public function expect0IfUserHasNoAccessDefined($authenticationServiceMock)
    {
        /** @var AbstractUserAuthentication | ObjectProphecy $pObjProphecy */
        $pObjProphecy = $this->prophesize(AbstractUserAuthentication::class);
        /** @var NullLogger | ObjectProphecy $pObjProphecy */
        $loggerProphecy = $this->prophesize(NullLogger::class);
        /** @var Session<mixed> | ObjectProphecy<SessionInterface> $sessionProphecy */
        $sessionProphecy = $this->prophesize(Session::class);

        $reflectionProperty = new ReflectionProperty(AuthenticationService::class, 'session');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($authenticationServiceMock, $sessionProphecy->reveal());

        $authenticationServiceMock->_set('pObj', $pObjProphecy->reveal());
        $authenticationServiceMock->_set('logger', $loggerProphecy->reveal());
        $authenticationServiceMock->_set('loginType', 'BE');
        $authenticationServiceMock->_set('authInfo', ['HTTP_HOST' => 'example.com', 'loginType' => 'BE']);
        $authenticationServiceMock->_set('db_user', ['username_column' => 'username']);

        $this->beUserProphecy->getDefaultWorkspace()->willReturn(-99);
        $GLOBALS['BE_USER'] = $this->beUserProphecy->reveal();

        self::assertSame(0, $authenticationServiceMock->authUser($this->beUser));

        return $authenticationServiceMock;
    }

    /**
     * @test
     * @depends expect100IfOIDCIdentifierDoesNotMatchUserInfoIdentifier
     *
     * @param AuthenticationService | MockObject | AccessibleObjectInterface $authenticationServiceMock
     */
    public function expect0IfDomainLockDoesNotMatch($authenticationServiceMock): void
    {
        $user = array_merge($this->beUser, ['lockToDomain' => 'not.example.com']);
        self::assertSame(0, $authenticationServiceMock->authUser($user));
    }

    /**
     * @test
     * @depends expect100IfOIDCIdentifierDoesNotMatchUserInfoIdentifier
     *
     * @param AuthenticationService | MockObject | AccessibleObjectInterface $authenticationServiceMock
     */
    public function expect200IfDomainLockDoesMatch($authenticationServiceMock): void
    {
        $user = array_merge($this->beUser, ['lockToDomain' => 'example.com']);
        self::assertSame(200, $authenticationServiceMock->authUser($user));
    }

    /**
     * @test
     * @depends expect100IfOIDCIdentifierDoesNotMatchUserInfoIdentifier
     *
     * @param AuthenticationService | MockObject | AccessibleObjectInterface $authenticationServiceMock
     */
    public function expect200IfNoDomainLock($authenticationServiceMock): void
    {
        self::assertSame(200, $authenticationServiceMock->authUser($this->beUser));
    }
}
