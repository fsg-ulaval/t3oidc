<?php

namespace FSG\T3oidc\Tests\Unit\Domain\Model\Dto;

use FSG\Oidc\Domain\Model\Dto\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Class ExtensionConfigurationTest
 */
class ExtensionConfigurationTest extends UnitTestCase
{

    /**
     * Test if default settings can be read
     *
     * @test
     */
    public function defaultSettingsCanBeRead(): void
    {
        $settings = [
            'clientId'          => '',
            'clientSecret'      => '',
            'clientScopes'      => '',
            'endpointAuthorize' => '',
            'endpointToken'     => '',
            'endpointUserInfo'  => '',
            'endpointLogout'    => '',
        ];

        $configurationInstance = $this->getAccessibleMock(ExtensionConfiguration::class, ['dummy'], [], '', false);
        foreach ($settings as $key => $value) {
            $functionName = 'get' . ucwords($key);
            self::assertEquals($value, $configurationInstance->$functionName());
        }
    }

    /**
     * Test if the settings can be read
     *
     * @test
     */
    public function settingsCanBeRead(): void
    {
        $settings = [
            'clientId'          => 'foo',
            'clientSecret'      => 'bar',
            'clientScopes'      => 'foo',
            'endpointAuthorize' => 'bar',
            'endpointToken'     => 'foo',
            'endpointUserInfo'  => 'bar',
            'endpointLogout'    => 'foo',
        ];

        $configurationInstance = $this->getAccessibleMock(ExtensionConfiguration::class, ['dummy'], [], '', false);
        foreach ($settings as $key => $value) {
            $configurationInstance->_set($key, $value);
        }
        foreach ($settings as $key => $value) {
            $functionName = 'get' . ucwords($key);
            self::assertEquals($value, $configurationInstance->$functionName());
        }
    }
}
