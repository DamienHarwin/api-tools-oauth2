<?php

/**
 * @see       https://github.com/laminas-api-tools/api-tools-oauth2 for the canonical source repository
 * @copyright https://github.com/laminas-api-tools/api-tools-oauth2/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas-api-tools/api-tools-oauth2/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\ApiTools\OAuth2\Factory;

use Laminas\ApiTools\OAuth2\Controller\AuthController;
use Laminas\ApiTools\OAuth2\Factory\AuthControllerFactory;
use Laminas\Mvc\Controller\ControllerManager;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class AuthControllerFactoryTest extends AbstractHttpControllerTestCase
{
    /**
     * @var ControllerManager
     */
    protected $controllers;

    /**
     * @var AuthControllerFactory
     */
    protected $factory;

    /**
     * @var ServiceManager
     */
    protected $services;



    public function testControllerCreated()
    {
        $oauthServerFactory = function () {
        };
        $this->services->setService('Laminas\ApiTools\OAuth2\Service\OAuth2Server', $oauthServerFactory);

        $userIdProvider = $this->getMock('Laminas\ApiTools\OAuth2\Provider\UserId\UserIdProviderInterface');
        $this->services->setService('Laminas\ApiTools\OAuth2\Provider\UserId', $userIdProvider);

        $controller = $this->factory->createService($this->controllers);

        $this->assertInstanceOf('Laminas\ApiTools\OAuth2\Controller\AuthController', $controller);
        $this->assertEquals(new AuthController($oauthServerFactory, $userIdProvider), $controller);
    }

    protected function setUp()
    {
        $this->factory = new AuthControllerFactory();

        $this->services = $services = new ServiceManager();

        $this->services->setService('Config', [
            'api-tools-oauth2' => [
                'api_problem_error_response' => true,
            ],
        ]);

        $this->controllers = $controllers = new ControllerManager();
        $controllers->setServiceLocator(new ServiceManager());
        $controllers->getServiceLocator()->setService('ServiceManager', $services);

        $this->setApplicationConfig([
            'modules' => [
                'Laminas\ApiTools\OAuth2',
            ],
            'module_listener_options' => [
                'module_paths' => [__DIR__ . '/../../'],
                'config_glob_paths' => [],
            ],
            'service_listener_options' => [],
            'service_manager' => [],
        ]);
        parent::setUp();
    }
}
