<?php

/**
 * Copyright (c) 2013 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Test\User;

use OC\Session\Memory;
use OC\User\User;

/**
 * @group DB
 * @package Test\User
 */
class SessionTest extends \Test\TestCase {

	/** @var \OCP\AppFramework\Utility\ITimeFactory */
	private $timeFactory;

	/** @var \OC\Authentication\Token\DefaultTokenProvider */
	protected $tokenProvider;

	/** @var \OCP\IConfig */
	private $config;

	protected function setUp() {
		parent::setUp();

		$this->timeFactory = $this->getMock('\OCP\AppFramework\Utility\ITimeFactory');
		$this->timeFactory->expects($this->any())
			->method('getTime')
			->will($this->returnValue(10000));
		$this->tokenProvider = $this->getMock('\OC\Authentication\Token\IProvider');
		$this->config = $this->getMock('\OCP\IConfig');
	}

	public function testGetUser() {
		$token = new \OC\Authentication\Token\DefaultToken();
		$token->setLoginName('User123');

		$expectedUser = $this->getMock('\OCP\IUser');
		$expectedUser->expects($this->any())
			->method('getUID')
			->will($this->returnValue('user123'));
		$session = $this->getMock('\OC\Session\Memory', array(), array(''));
		$session->expects($this->at(0))
			->method('get')
			->with('user_id')
			->will($this->returnValue($expectedUser->getUID()));
		$sessionId = 'abcdef12345';

		$manager = $this->getMockBuilder('\OC\User\Manager')
			->disableOriginalConstructor()
			->getMock();
		$session->expects($this->once())
			->method('getId')
			->will($this->returnValue($sessionId));
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->will($this->returnValue($token));
		$session->expects($this->at(2))
			->method('get')
			->with('last_login_check')
			->will($this->returnValue(null)); // No check has been run yet
		$this->tokenProvider->expects($this->once())
			->method('getPassword')
			->with($token, $sessionId)
			->will($this->returnValue('password123'));
		$manager->expects($this->once())
			->method('checkPassword')
			->with('User123', 'password123')
			->will($this->returnValue(true));
		$expectedUser->expects($this->once())
			->method('isEnabled')
			->will($this->returnValue(true));
		$session->expects($this->at(3))
			->method('set')
			->with('last_login_check', 10000);

		$session->expects($this->at(4))
			->method('get')
			->with('last_token_update')
			->will($this->returnValue(null)); // No check run so far
		$this->tokenProvider->expects($this->once())
			->method('updateToken')
			->with($token);
		$session->expects($this->at(5))
			->method('set')
			->with('last_token_update', $this->equalTo(10000));

		$manager->expects($this->any())
			->method('get')
			->with($expectedUser->getUID())
			->will($this->returnValue($expectedUser));

		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);
		$user = $userSession->getUser();
		$this->assertSame($expectedUser, $user);
	}

	public function isLoggedInData() {
		return [
			[true],
			[false],
		];
	}

	/**
	 * @dataProvider isLoggedInData
	 */
	public function testIsLoggedIn($isLoggedIn) {
		$session = $this->getMock('\OC\Session\Memory', array(), array(''));

		$manager = $this->getMockBuilder('\OC\User\Manager')
			->disableOriginalConstructor()
			->getMock();

		$userSession = $this->getMockBuilder('\OC\User\Session')
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config])
			->setMethods([
				'getUser'
			])
			->getMock();
		$user = new User('sepp', null);
		$userSession->expects($this->once())
			->method('getUser')
			->will($this->returnValue($isLoggedIn ? $user : null));
		$this->assertEquals($isLoggedIn, $userSession->isLoggedIn());
	}

	public function testSetUser() {
		$session = $this->getMock('\OC\Session\Memory', array(), array(''));
		$session->expects($this->once())
			->method('set')
			->with('user_id', 'foo');

		$manager = $this->getMock('\OC\User\Manager');

		$backend = $this->getMock('\Test\Util\User\Dummy');

		$user = $this->getMock('\OC\User\User', array(), array('foo', $backend));
		$user->expects($this->once())
			->method('getUID')
			->will($this->returnValue('foo'));

		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);
		$userSession->setUser($user);
	}

	public function testLoginValidPasswordEnabled() {
		$session = $this->getMock('\OC\Session\Memory', array(), array(''));
		$session->expects($this->once())
			->method('regenerateId');
		$session->expects($this->exactly(2))
			->method('set')
			->with($this->callback(function ($key) {
					switch ($key) {
						case 'user_id':
						case 'loginname':
							return true;
							break;
						default:
							return false;
							break;
					}
				}, 'foo'));

		$managerMethods = get_class_methods('\OC\User\Manager');
		//keep following methods intact in order to ensure hooks are
		//working
		$doNotMock = array('__construct', 'emit', 'listen');
		foreach ($doNotMock as $methodName) {
			$i = array_search($methodName, $managerMethods, true);
			if ($i !== false) {
				unset($managerMethods[$i]);
			}
		}
		$manager = $this->getMock('\OC\User\Manager', $managerMethods, array());

		$backend = $this->getMock('\Test\Util\User\Dummy');

		$user = $this->getMock('\OC\User\User', array(), array('foo', $backend));
		$user->expects($this->any())
			->method('isEnabled')
			->will($this->returnValue(true));
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('foo'));
		$user->expects($this->once())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('checkPassword')
			->with('foo', 'bar')
			->will($this->returnValue($user));

		$userSession = $this->getMockBuilder('\OC\User\Session')
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config])
			->setMethods([
				'prepareUserLogin'
			])
			->getMock();
		$userSession->expects($this->once())
			->method('prepareUserLogin');
		$userSession->login('foo', 'bar');
		$this->assertEquals($user, $userSession->getUser());
	}

	/**
	 * @expectedException \OC\User\LoginException
	 */
	public function testLoginValidPasswordDisabled() {
		$session = $this->getMock('\OC\Session\Memory', array(), array(''));
		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');

		$managerMethods = get_class_methods('\OC\User\Manager');
		//keep following methods intact in order to ensure hooks are
		//working
		$doNotMock = array('__construct', 'emit', 'listen');
		foreach ($doNotMock as $methodName) {
			$i = array_search($methodName, $managerMethods, true);
			if ($i !== false) {
				unset($managerMethods[$i]);
			}
		}
		$manager = $this->getMock('\OC\User\Manager', $managerMethods, array());

		$backend = $this->getMock('\Test\Util\User\Dummy');

		$user = $this->getMock('\OC\User\User', array(), array('foo', $backend));
		$user->expects($this->any())
			->method('isEnabled')
			->will($this->returnValue(false));
		$user->expects($this->never())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('checkPassword')
			->with('foo', 'bar')
			->will($this->returnValue($user));

		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);
		$userSession->login('foo', 'bar');
	}

	public function testLoginInvalidPassword() {
		$session = $this->getMock('\OC\Session\Memory', array(), array(''));
		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');

		$managerMethods = get_class_methods('\OC\User\Manager');
		//keep following methods intact in order to ensure hooks are
		//working
		$doNotMock = array('__construct', 'emit', 'listen');
		foreach ($doNotMock as $methodName) {
			$i = array_search($methodName, $managerMethods, true);
			if ($i !== false) {
				unset($managerMethods[$i]);
			}
		}
		$manager = $this->getMock('\OC\User\Manager', $managerMethods, array());

		$backend = $this->getMock('\Test\Util\User\Dummy');

		$user = $this->getMock('\OC\User\User', array(), array('foo', $backend));
		$user->expects($this->never())
			->method('isEnabled');
		$user->expects($this->never())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('checkPassword')
			->with('foo', 'bar')
			->will($this->returnValue(false));

		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);
		$userSession->login('foo', 'bar');
	}

	public function testLoginNonExisting() {
		$session = $this->getMock('\OC\Session\Memory', array(), array(''));
		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');

		$manager = $this->getMock('\OC\User\Manager');

		$backend = $this->getMock('\Test\Util\User\Dummy');

		$manager->expects($this->once())
			->method('checkPassword')
			->with('foo', 'bar')
			->will($this->returnValue(false));

		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);
		$userSession->login('foo', 'bar');
	}

	public function testLogClientInNoTokenPasswordWith2fa() {
		$manager = $this->getMockBuilder('\OC\User\Manager')
			->disableOriginalConstructor()
			->getMock();
		$session = $this->getMock('\OCP\ISession');

		/** @var \OC\User\Session $userSession */
		$userSession = $this->getMockBuilder('\OC\User\Session')
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config])
			->setMethods(['login'])
			->getMock();

		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with('doe')
			->will($this->throwException(new \OC\Authentication\Exceptions\InvalidTokenException()));
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('token_auth_enforced', false)
			->will($this->returnValue(true));

		$this->assertFalse($userSession->logClientIn('john', 'doe'));
	}

	public function testLogClientInNoTokenPasswordNo2fa() {
		$manager = $this->getMockBuilder('\OC\User\Manager')
			->disableOriginalConstructor()
			->getMock();
		$session = $this->getMock('\OCP\ISession');
		$user = $this->getMock('\OCP\IUser');

		/** @var \OC\User\Session $userSession */
		$userSession = $this->getMockBuilder('\OC\User\Session')
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config])
			->setMethods(['login', 'isTwoFactorEnforced'])
			->getMock();

		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with('doe')
			->will($this->throwException(new \OC\Authentication\Exceptions\InvalidTokenException()));
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('token_auth_enforced', false)
			->will($this->returnValue(false));

		$userSession->expects($this->once())
			->method('isTwoFactorEnforced')
			->with('john')
			->will($this->returnValue(true));

		$this->assertFalse($userSession->logClientIn('john', 'doe'));
	}

	public function testRememberLoginValidToken() {
		$session = $this->getMock('\OC\Session\Memory', array(), array(''));
		$session->expects($this->exactly(1))
			->method('set')
			->with($this->callback(function ($key) {
					switch ($key) {
						case 'user_id':
							return true;
						default:
							return false;
					}
				}, 'foo'));
		$session->expects($this->once())
			->method('regenerateId');

		$managerMethods = get_class_methods('\OC\User\Manager');
		//keep following methods intact in order to ensure hooks are
		//working
		$doNotMock = array('__construct', 'emit', 'listen');
		foreach ($doNotMock as $methodName) {
			$i = array_search($methodName, $managerMethods, true);
			if ($i !== false) {
				unset($managerMethods[$i]);
			}
		}
		$manager = $this->getMock('\OC\User\Manager', $managerMethods, array());

		$backend = $this->getMock('\Test\Util\User\Dummy');

		$user = $this->getMock('\OC\User\User', array(), array('foo', $backend));

		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('foo'));
		$user->expects($this->once())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('get')
			->with('foo')
			->will($this->returnValue($user));

		//prepare login token
		$token = 'goodToken';
		\OC::$server->getConfig()->setUserValue('foo', 'login_token', $token, time());

		$userSession = $this->getMock(
			'\OC\User\Session',
			//override, otherwise tests will fail because of setcookie()
			array('setMagicInCookie'),
			//there  are passed as parameters to the constructor
			array($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config));

		$granted = $userSession->loginWithCookie('foo', $token);

		$this->assertSame($granted, true);
	}

	public function testRememberLoginInvalidToken() {
		$session = $this->getMock('\OC\Session\Memory', array(), array(''));
		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');

		$managerMethods = get_class_methods('\OC\User\Manager');
		//keep following methods intact in order to ensure hooks are
		//working
		$doNotMock = array('__construct', 'emit', 'listen');
		foreach ($doNotMock as $methodName) {
			$i = array_search($methodName, $managerMethods, true);
			if ($i !== false) {
				unset($managerMethods[$i]);
			}
		}
		$manager = $this->getMock('\OC\User\Manager', $managerMethods, array());

		$backend = $this->getMock('\Test\Util\User\Dummy');

		$user = $this->getMock('\OC\User\User', array(), array('foo', $backend));

		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('foo'));
		$user->expects($this->never())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('get')
			->with('foo')
			->will($this->returnValue($user));

		//prepare login token
		$token = 'goodToken';
		\OC::$server->getConfig()->setUserValue('foo', 'login_token', $token, time());

		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);
		$granted = $userSession->loginWithCookie('foo', 'badToken');

		$this->assertSame($granted, false);
	}

	public function testRememberLoginInvalidUser() {
		$session = $this->getMock('\OC\Session\Memory', array(), array(''));
		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');

		$managerMethods = get_class_methods('\OC\User\Manager');
		//keep following methods intact in order to ensure hooks are
		//working
		$doNotMock = array('__construct', 'emit', 'listen');
		foreach ($doNotMock as $methodName) {
			$i = array_search($methodName, $managerMethods, true);
			if ($i !== false) {
				unset($managerMethods[$i]);
			}
		}
		$manager = $this->getMock('\OC\User\Manager', $managerMethods, array());

		$backend = $this->getMock('\Test\Util\User\Dummy');

		$user = $this->getMock('\OC\User\User', array(), array('foo', $backend));

		$user->expects($this->never())
			->method('getUID');
		$user->expects($this->never())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('get')
			->with('foo')
			->will($this->returnValue(null));

		//prepare login token
		$token = 'goodToken';
		\OC::$server->getConfig()->setUserValue('foo', 'login_token', $token, time());

		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);
		$granted = $userSession->loginWithCookie('foo', $token);

		$this->assertSame($granted, false);
	}

	public function testActiveUserAfterSetSession() {
		$users = array(
			'foo' => new User('foo', null),
			'bar' => new User('bar', null)
		);

		$manager = $this->getMockBuilder('\OC\User\Manager')
			->disableOriginalConstructor()
			->getMock();

		$manager->expects($this->any())
			->method('get')
			->will($this->returnCallback(function ($uid) use ($users) {
					return $users[$uid];
				}));

		$session = new Memory('');
		$session->set('user_id', 'foo');
		$userSession = $this->getMockBuilder('\OC\User\Session')
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config])
			->setMethods([
				'validateSession'
			])
			->getMock();
		$userSession->expects($this->any())
			->method('validateSession');

		$this->assertEquals($users['foo'], $userSession->getUser());

		$session2 = new Memory('');
		$session2->set('user_id', 'bar');
		$userSession->setSession($session2);
		$this->assertEquals($users['bar'], $userSession->getUser());
	}

	public function testCreateSessionToken() {
		$manager = $this->getMockBuilder('\OC\User\Manager')
			->disableOriginalConstructor()
			->getMock();
		$session = $this->getMock('\OCP\ISession');
		$token = $this->getMock('\OC\Authentication\Token\IToken');
		$user = $this->getMock('\OCP\IUser');
		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);

		$random = $this->getMock('\OCP\Security\ISecureRandom');
		$config = $this->getMock('\OCP\IConfig');
		$csrf = $this->getMockBuilder('\OC\Security\CSRF\CsrfTokenManager')
			->disableOriginalConstructor()
			->getMock();
		$request = new \OC\AppFramework\Http\Request([
			'server' => [
				'HTTP_USER_AGENT' => 'Firefox',
			]
		], $random, $config, $csrf);

		$uid = 'user123';
		$loginName = 'User123';
		$password = 'passme';
		$sessionId = 'abcxyz';

		$manager->expects($this->once())
			->method('get')
			->with($uid)
			->will($this->returnValue($user));
		$session->expects($this->once())
			->method('getId')
			->will($this->returnValue($sessionId));
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with($password)
			->will($this->throwException(new \OC\Authentication\Exceptions\InvalidTokenException()));
		
		$this->tokenProvider->expects($this->once())
			->method('generateToken')
			->with($sessionId, $uid, $loginName, $password, 'Firefox');

		$this->assertTrue($userSession->createSessionToken($request, $uid, $loginName, $password));
	}

	public function testCreateSessionTokenWithTokenPassword() {
		$manager = $this->getMockBuilder('\OC\User\Manager')
			->disableOriginalConstructor()
			->getMock();
		$session = $this->getMock('\OCP\ISession');
		$token = $this->getMock('\OC\Authentication\Token\IToken');
		$user = $this->getMock('\OCP\IUser');
		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);

		$random = $this->getMock('\OCP\Security\ISecureRandom');
		$config = $this->getMock('\OCP\IConfig');
		$csrf = $this->getMockBuilder('\OC\Security\CSRF\CsrfTokenManager')
			->disableOriginalConstructor()
			->getMock();
		$request = new \OC\AppFramework\Http\Request([
			'server' => [
				'HTTP_USER_AGENT' => 'Firefox',
			]
		], $random, $config, $csrf);

		$uid = 'user123';
		$loginName = 'User123';
		$password = 'iamatoken';
		$realPassword = 'passme';
		$sessionId = 'abcxyz';

		$manager->expects($this->once())
			->method('get')
			->with($uid)
			->will($this->returnValue($user));
		$session->expects($this->once())
			->method('getId')
			->will($this->returnValue($sessionId));
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with($password)
			->will($this->returnValue($token));
		$this->tokenProvider->expects($this->once())
			->method('getPassword')
			->with($token, $password)
			->will($this->returnValue($realPassword));
		
		$this->tokenProvider->expects($this->once())
			->method('generateToken')
			->with($sessionId, $uid, $loginName, $realPassword, 'Firefox');

		$this->assertTrue($userSession->createSessionToken($request, $uid, $loginName, $password));
	}

	public function testCreateSessionTokenWithNonExistentUser() {
		$manager = $this->getMockBuilder('\OC\User\Manager')
			->disableOriginalConstructor()
			->getMock();
		$session = $this->getMock('\OCP\ISession');
		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);
		$request = $this->getMock('\OCP\IRequest');

		$uid = 'user123';
		$loginName = 'User123';
		$password = 'passme';

		$manager->expects($this->once())
			->method('get')
			->with($uid)
			->will($this->returnValue(null));
		
		$this->assertFalse($userSession->createSessionToken($request, $uid, $loginName, $password));
	}

	public function testTryTokenLoginWithDisabledUser() {
		$manager = $this->getMockBuilder('\OC\User\Manager')
			->disableOriginalConstructor()
			->getMock();
		$session = new Memory('');
		$token = $this->getMock('\OC\Authentication\Token\IToken');
		$user = $this->getMock('\OCP\IUser');
		$userSession = new \OC\User\Session($manager, $session, $this->timeFactory, $this->tokenProvider, $this->config);
		$request = $this->getMock('\OCP\IRequest');

		$request->expects($this->once())
			->method('getHeader')
			->with('Authorization')
			->will($this->returnValue('token xxxxx'));
		$this->tokenProvider->expects($this->once())
			->method('validateToken')
			->with('xxxxx')
			->will($this->returnValue($token));
		$token->expects($this->once())
			->method('getUID')
			->will($this->returnValue('user123'));
		$manager->expects($this->once())
			->method('get')
			->with('user123')
			->will($this->returnValue($user));
		$user->expects($this->once())
			->method('isEnabled')
			->will($this->returnValue(false));

		$this->assertFalse($userSession->tryTokenLogin($request));
	}

	public function testValidateSessionDisabledUser() {
		$userManager = $this->getMock('\OCP\IUserManager');
		$session = $this->getMock('\OCP\ISession');
		$timeFactory = $this->getMock('\OCP\AppFramework\Utility\ITimeFactory');
		$tokenProvider = $this->getMock('\OC\Authentication\Token\IProvider');
		$userSession = $this->getMockBuilder('\OC\User\Session')
			->setConstructorArgs([$userManager, $session, $timeFactory, $tokenProvider, $this->config])
			->setMethods(['logout'])
			->getMock();

		$user = $this->getMock('\OCP\IUser');
		$token = $this->getMock('\OC\Authentication\Token\IToken');

		$session->expects($this->once())
			->method('getId')
			->will($this->returnValue('sessionid'));
		$tokenProvider->expects($this->once())
			->method('getToken')
			->with('sessionid')
			->will($this->returnValue($token));
		$session->expects($this->once())
			->method('get')
			->with('last_login_check')
			->will($this->returnValue(1000));
		$timeFactory->expects($this->once())
			->method('getTime')
			->will($this->returnValue(5000));
		$tokenProvider->expects($this->once())
			->method('getPassword')
			->with($token, 'sessionid')
			->will($this->returnValue('123456'));
		$token->expects($this->once())
			->method('getLoginName')
			->will($this->returnValue('User5'));
		$userManager->expects($this->once())
			->method('checkPassword')
			->with('User5', '123456')
			->will($this->returnValue(true));
		$user->expects($this->once())
			->method('isEnabled')
			->will($this->returnValue(false));
		$userSession->expects($this->once())
			->method('logout');

		$this->invokePrivate($userSession, 'validateSession', [$user]);
	}

	public function testValidateSessionNoPassword() {
		$userManager = $this->getMock('\OCP\IUserManager');
		$session = $this->getMock('\OCP\ISession');
		$timeFactory = $this->getMock('\OCP\AppFramework\Utility\ITimeFactory');
		$tokenProvider = $this->getMock('\OC\Authentication\Token\IProvider');
		$userSession = $this->getMockBuilder('\OC\User\Session')
			->setConstructorArgs([$userManager, $session, $timeFactory, $tokenProvider, $this->config])
			->setMethods(['logout'])
			->getMock();

		$user = $this->getMock('\OCP\IUser');
		$token = $this->getMock('\OC\Authentication\Token\IToken');

		$session->expects($this->once())
			->method('getId')
			->will($this->returnValue('sessionid'));
		$tokenProvider->expects($this->once())
			->method('getToken')
			->with('sessionid')
			->will($this->returnValue($token));
		$session->expects($this->once())
			->method('get')
			->with('last_login_check')
			->will($this->returnValue(1000));
		$timeFactory->expects($this->once())
			->method('getTime')
			->will($this->returnValue(5000));
		$tokenProvider->expects($this->once())
			->method('getPassword')
			->with($token, 'sessionid')
			->will($this->throwException(new \OC\Authentication\Exceptions\PasswordlessTokenException()));
		$session->expects($this->once())
			->method('set')
			->with('last_login_check', 5000);

		$this->invokePrivate($userSession, 'validateSession', [$user]);
	}

}
