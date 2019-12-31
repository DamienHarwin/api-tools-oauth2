<?php

/**
 * @see       https://github.com/laminas-api-tools/api-tools-oauth2 for the canonical source repository
 * @copyright https://github.com/laminas-api-tools/api-tools-oauth2/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas-api-tools/api-tools-oauth2/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\ApiTools\OAuth2\Controller;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Adapter\Driver\Pdo\Pdo as PdoDriver;
use Laminas\Db\Sql\Sql;
use Laminas\Stdlib\Parameters;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;
use ReflectionProperty;

class AuthControllerTest extends AbstractHttpControllerTestCase
{
    protected $db;

    public function setUp()
    {
        $this->setApplicationConfig(include __DIR__ . '/../TestAsset/pdo.application.config.php');
        parent::setUp();
        $this->setupDb();
    }

    public function setupDb()
    {
        $pdo = $this->getApplication()->getServiceManager()->get('Laminas\ApiTools\OAuth2\Adapter\PdoAdapter');
        $r = new ReflectionProperty($pdo, 'db');
        $r->setAccessible(true);
        $db = $r->getValue($pdo);

        $sql = file_get_contents(__DIR__ . '/../TestAsset/database/pdo.sql');
        $db->exec($sql);
    }

    public function getDb()
    {
        if ($this->db) {
            return $this->db;
        }

        $adapter = $this->getApplication()->getServiceManager()->get('Laminas\ApiTools\OAuth2\Adapter\PdoAdapter');
        $r = new ReflectionProperty($adapter, 'db');
        $r->setAccessible(true);
        $this->db = new Adapter(new PdoDriver($r->getValue($adapter)));
        return $this->db;
    }

    public function testToken()
    {
        $request = $this->getRequest();
        $request->getPost()->set('grant_type', 'client_credentials');
        $request->getServer()->set('PHP_AUTH_USER', 'testclient');
        $request->getServer()->set('PHP_AUTH_PW', 'testpass');
        $request->setMethod('POST');

        $this->dispatch('/oauth');
        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('token');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertTrue(!empty($response['access_token']));
        $this->assertTrue(!empty($response['expires_in']));
        $this->assertTrue(array_key_exists('scope', $response));
        $this->assertTrue(!empty($response['token_type']));
    }

    public function testTokenErrorIsApiProblem()
    {
        $request = $this->getRequest();
        $request->getPost()->set('grant_type', 'fake_grant_type');
        $request->getServer()->set('PHP_AUTH_USER', 'testclient');
        $request->getServer()->set('PHP_AUTH_PW', 'testpass');
        $request->setMethod('POST');

        $this->dispatch('/oauth');
        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('token');
        $this->assertResponseStatusCode(400);

        $headers = $this->getResponse()->getHeaders();
        $this->assertEquals('application/problem+json', $headers->get('content-type')->getFieldValue());

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertEquals('unsupported_grant_type', $response['title']);
        $this->assertEquals('Grant type "fake_grant_type" not supported', $response['detail']);
        $this->assertEquals('400', $response['status']);
    }

    public function testTokenErrorIsOAuth2Format()
    {
        $request = $this->getRequest();
        $request->getPost()->set('grant_type', 'fake_grant_type');
        $request->getServer()->set('PHP_AUTH_USER', 'testclient');
        $request->getServer()->set('PHP_AUTH_PW', 'testpass');
        $request->setMethod('POST');

        $this->setIsOAuth2FormatResponse();

        $this->dispatch('/oauth');
        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('token');
        $this->assertResponseStatusCode(400);

        $headers = $this->getResponse()->getHeaders();
        $this->assertEquals('application/json', $headers->get('content-type')->getFieldValue());

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertEquals('unsupported_grant_type', $response['error']);
        $this->assertEquals('Grant type "fake_grant_type" not supported', $response['error_description']);
    }

    public function testAuthorizeForm()
    {
        $request = $this->getRequest();
        $request->getHeaders()->addHeaderLine('Accept', 'text/html');

        $this->dispatch('/oauth/authorize', 'GET', [
            'response_type' => 'code',
            'client_id'     => 'testclient',
            'state'         => 'xyz',
        ]);
        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('authorize');
        $this->assertResponseStatusCode(200);
        $this->assertXpathQuery('//form/input[@name="authorized" and @value="yes"]');
        $this->assertXpathQuery('//form/input[@name="authorized" and @value="no"]');
    }

    public function testAuthorizeParamErrorIsApiProblem()
    {
        $this->dispatch('/oauth/authorize');

        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('authorize');
        $this->assertResponseStatusCode(400);

        $headers = $this->getResponse()->getHeaders();
        $this->assertEquals('application/problem+json', $headers->get('content-type')->getFieldValue());

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertEquals('invalid_client', $response['title']);
        $this->assertEquals('No client id supplied', $response['detail']);
        $this->assertEquals('400', $response['status']);
    }

    public function testAuthorizeParamErrorIsOAuth2Format()
    {
        $this->setIsOAuth2FormatResponse();

        $this->dispatch('/oauth/authorize');

        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('authorize');
        $this->assertResponseStatusCode(400);

        $headers = $this->getResponse()->getHeaders();
        $this->assertEquals('application/json', $headers->get('content-type')->getFieldValue());

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertEquals('invalid_client', $response['error']);
        $this->assertEquals('No client id supplied', $response['error_description']);
    }

    public function testAuthorizeCode()
    {
        $request = $this->getRequest();
        $request->setQuery(new Parameters([
            'response_type' => 'code',
            'client_id'     => 'testclient',
            'state'         => 'xyz',
            'user_id'       => 123,
            'redirect_uri'  => '/oauth/receivecode',
        ]));
        $request->setPost(new Parameters([
            'authorized' => 'yes',
        ]));
        $request->setMethod('POST');

        $this->dispatch('/oauth/authorize');
        $this->assertTrue($this->getResponse()->isRedirect());
        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('authorize');

        $location = $this->getResponse()->getHeaders()->get('Location')->getUri();
        if (preg_match('#code=([0-9a-f]+)#', $location, $matches)) {
            $code = $matches[1];
        }

        // test data in database is correct
        $adapter = $this->getDb();
        $sql = new Sql($adapter);
        $select = $sql->select();
        $select->from('oauth_authorization_codes');
        $select->where(['authorization_code' => $code]);

        $selectString = $sql->getSqlStringForSqlObject($select);
        $results = $adapter->query($selectString, $adapter::QUERY_MODE_EXECUTE)->toArray();
        $this->assertEquals('123', $results[0]['user_id']);

        // test get token from authorized code
        $request = $this->getRequest();
        $request->getPost()->set('grant_type', 'authorization_code');
        $request->getPost()->set('code', $code);
        $request->getPost()->set('redirect_uri', '/oauth/receivecode');
        $request->getServer()->set('PHP_AUTH_USER', 'testclient');
        $request->getServer()->set('PHP_AUTH_PW', 'testpass');

        $this->dispatch('/oauth');
        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('token');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertTrue(!empty($response['access_token']));
    }

    public function testImplicitClientAuth()
    {
        $config = $this->getApplication()->getConfig();
        $allowImplicit = isset($config['api-tools-oauth2']['allow_implicit']) ? $config['api-tools-oauth2']['allow_implicit'] : false;

        if (!$allowImplicit) {
            $this->markTestSkipped('The allow implicit client mode is disabled');
        }

        $request = $this->getRequest();
        $request->getQuery()->set('response_type', 'token');
        $request->getQuery()->set('client_id', 'testclient');
        $request->getQuery()->set('state', 'xyz');
        $request->getQuery()->set('redirect_uri', '/oauth/receivecode');
        $request->getPost()->set('authorized', 'yes');
        $request->setMethod('POST');

        $this->dispatch('/oauth/authorize');
        $this->assertTrue($this->getResponse()->isRedirect());
        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('authorize');

        $token    = '';
        $location = $this->getResponse()->getHeaders()->get('Location')->getUri();

        if (preg_match('#access_token=([0-9a-f]+)#', $location, $matches)) {
            $token = $matches[1];
        }
        $this->assertTrue(!empty($token));
    }

    public function testResource()
    {
        $request = $this->getRequest();
        $request->getPost()->set('grant_type', 'client_credentials');
        $request->getServer()->set('PHP_AUTH_USER', 'testclient');
        $request->getServer()->set('PHP_AUTH_PW', 'testpass');
        $request->setMethod('POST');

        $this->dispatch('/oauth');
        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('token');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertTrue(!empty($response['access_token']));

        $token = $response['access_token'];

        // test resource through token by POST
        $post = $request->getPost();
        unset($post['grant_type']);
        $post->set('access_token', $token);
        $server = $request->getServer();
        unset($server['PHP_AUTH_USER']);
        unset($server['PHP_AUTH_PW']);

        $this->dispatch('/oauth/resource');
        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('resource');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);

        $this->assertTrue($response['success']);
        $this->assertEquals('You accessed my APIs!', $response['message']);

        // test resource through token by Bearer header
        $request->getHeaders()
            ->addHeaderLine('Authorization', 'Bearer ' . $token);
        unset($post['access_token']);
        $request->setMethod('GET');

        $this->dispatch('/oauth/resource');
        $this->assertControllerName('Laminas\ApiTools\OAuth2\Controller\Auth');
        $this->assertActionName('resource');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals('You accessed my APIs!', $response['message']);
    }

    protected function setIsOAuth2FormatResponse()
    {
        $serviceManager = $this->getApplication()->getServiceManager();

        $config = $serviceManager->get('Config');
        $config['api-tools-oauth2']['api_problem_error_response'] = false;

        $serviceManager->setAllowOverride(true);
        $serviceManager->setService('Config', $config);
    }
}
