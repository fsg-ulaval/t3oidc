<?php

namespace FSG\Oidc\Tests\Unit\Service;

use FSG\Oidc\Domain\Model\Dto\ExtensionConfiguration;
use FSG\Oidc\Error\ConfigurationException;
use FSG\Oidc\Error\HTTPSConnectionException;
use FSG\Oidc\Service\StatusService;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use UnexpectedValueException;

/**
 * Class StatusServiceTest
 */
class StatusServiceTest extends UnitTestCase
{
    /**
     * @var ServerRequest
     */
    private ServerRequest $request;

    /**
     * Sets up this test case.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetSingletonInstances = true;
        $this->request                 = new ServerRequest('/');
    }

    /**
     * Test if the right exception is thrown for an invalid mode
     *
     * @test
     */
    public function expectExceptionForInvalidMode(): void
    {
        $this->expectException(UnexpectedValueException::class);
        StatusService::isEnabled('FOO');
    }

    /**
     * Test if the right exception code is return for a none HTTPS environment
     *
     * @test
     */
    public function expectExceptionForNoneHTTPSEnvironment(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request->withAttribute(
            'normalizedParams',
            new NormalizedParams([], [], '', '')
        );

        $this->expectException(HTTPSConnectionException::class);
        $this->expectExceptionCode(1613676691);
        StatusService::isEnabled('BE');
    }

    /**
     * Test if the right exception code is return for no client ID configured
     *
     * @test
     */
    public function expectExceptionForNoClientIDConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request->withAttribute(
            'normalizedParams',
            new NormalizedParams(['HTTPS' => 1], [], '', '')
        );

        $settings = [
            'clientId'          => '',
            'clientSecret'      => 'bar',
            'clientScopes'      => 'foo',
            'endpointAuthorize' => 'bar',
            'endpointToken'     => 'foo',
            'endpointUserInfo'  => 'bar',
        ];

        $extensionConfigurationMock = $this->getAccessibleMock(ExtensionConfiguration::class, ['dummy'], [], '', false);
        foreach ($settings as $key => $value) {
            $extensionConfigurationMock->_set($key, $value);
        }

        /**
         * @var ExtensionConfiguration $extensionConfigurationMock
         */
        GeneralUtility::setSingletonInstance(ExtensionConfiguration::class, $extensionConfigurationMock);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(1613676692);
        StatusService::isEnabled('BE');
    }

    /**
     * Test if the right exception code is return for no client secret configured
     *
     * @test
     */
    public function expectExceptionForNoClientSecretConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request->withAttribute(
            'normalizedParams',
            new NormalizedParams(['HTTPS' => 1], [], '', '')
        );

        $settings = [
            'clientId'          => 'foo',
            'clientSecret'      => '',
            'clientScopes'      => 'foo',
            'endpointAuthorize' => 'bar',
            'endpointToken'     => 'foo',
            'endpointUserInfo'  => 'bar',
        ];

        $extensionConfigurationMock = $this->getAccessibleMock(ExtensionConfiguration::class, ['dummy'], [], '', false);
        foreach ($settings as $key => $value) {
            $extensionConfigurationMock->_set($key, $value);
        }

        /**
         * @var ExtensionConfiguration $extensionConfigurationMock
         */
        GeneralUtility::setSingletonInstance(ExtensionConfiguration::class, $extensionConfigurationMock);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(1613676693);
        StatusService::isEnabled('BE');
    }

    /**
     * Test if the right exception code is return for no client scope configured
     *
     * @test
     */
    public function expectExceptionForNoClientScopeConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request->withAttribute(
            'normalizedParams',
            new NormalizedParams(['HTTPS' => 1], [], '', '')
        );

        $settings = [
            'clientId'          => 'foo',
            'clientSecret'      => 'bar',
            'clientScopes'      => '',
            'endpointAuthorize' => 'bar',
            'endpointToken'     => 'foo',
            'endpointUserInfo'  => 'bar',
        ];

        $extensionConfigurationMock = $this->getAccessibleMock(ExtensionConfiguration::class, ['dummy'], [], '', false);
        foreach ($settings as $key => $value) {
            $extensionConfigurationMock->_set($key, $value);
        }

        /**
         * @var ExtensionConfiguration $extensionConfigurationMock
         */
        GeneralUtility::setSingletonInstance(ExtensionConfiguration::class, $extensionConfigurationMock);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(1613676694);
        StatusService::isEnabled('BE');
    }

    /**
     * Test if the right exception code is return for no authorize endpoint configured
     *
     * @test
     */
    public function expectExceptionForNoAuthorizeEndpointConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request->withAttribute(
            'normalizedParams',
            new NormalizedParams(['HTTPS' => 1], [], '', '')
        );

        $settings = [
            'clientId'          => 'foo',
            'clientSecret'      => 'bar',
            'clientScopes'      => 'foo',
            'endpointAuthorize' => '',
            'endpointToken'     => 'foo',
            'endpointUserInfo'  => 'bar',
        ];

        $extensionConfigurationMock = $this->getAccessibleMock(ExtensionConfiguration::class, ['dummy'], [], '', false);
        foreach ($settings as $key => $value) {
            $extensionConfigurationMock->_set($key, $value);
        }

        /**
         * @var ExtensionConfiguration $extensionConfigurationMock
         */
        GeneralUtility::setSingletonInstance(ExtensionConfiguration::class, $extensionConfigurationMock);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(1613676695);
        StatusService::isEnabled('BE');
    }

    /**
     * Test if the right exception code is return for no token endpoint configured
     *
     * @test
     */
    public function expectExceptionForNoTokenEndpointConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request->withAttribute(
            'normalizedParams',
            new NormalizedParams(['HTTPS' => 1], [], '', '')
        );

        $settings = [
            'clientId'          => 'foo',
            'clientSecret'      => 'bar',
            'clientScopes'      => 'foo',
            'endpointAuthorize' => 'bar',
            'endpointToken'     => '',
            'endpointUserInfo'  => 'bar',
        ];

        $extensionConfigurationMock = $this->getAccessibleMock(
            ExtensionConfiguration::class,
            ['dummy'],
            [],
            'ExtensionConfigurationMock',
            false
        );
        foreach ($settings as $key => $value) {
            $extensionConfigurationMock->_set($key, $value);
        }

        /**
         * @var ExtensionConfiguration $extensionConfigurationMock
         */
        GeneralUtility::setSingletonInstance(ExtensionConfiguration::class, $extensionConfigurationMock);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(1613676696);
        StatusService::isEnabled('BE');
    }

    /**
     * Test if the right exception code is return for no user info endpoint configured
     *
     * @test
     */
    public function expectExceptionForNoUserInfoEndpointConfigured(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->request->withAttribute(
            'normalizedParams',
            new NormalizedParams(['HTTPS' => 1], [], '', '')
        );

        $settings = [
            'clientId'          => 'foo',
            'clientSecret'      => 'bar',
            'clientScopes'      => 'foo',
            'endpointAuthorize' => 'bar',
            'endpointToken'     => 'foo',
            'endpointUserInfo'  => '',
        ];

        $extensionConfigurationMock = $this->getAccessibleMock(ExtensionConfiguration::class, ['dummy'], [], '', false);
        foreach ($settings as $key => $value) {
            $extensionConfigurationMock->_set($key, $value);
        }

        /**
         * @var ExtensionConfiguration $extensionConfigurationMock
         */
        GeneralUtility::setSingletonInstance(ExtensionConfiguration::class, $extensionConfigurationMock);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(1613676697);
        StatusService::isEnabled('BE');
    }
}
