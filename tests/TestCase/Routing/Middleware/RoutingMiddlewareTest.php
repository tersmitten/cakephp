<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         3.3.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Routing\Middleware;

use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use TestApp\Application;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequestFactory;

/**
 * Test for RoutingMiddleware
 */
class RoutingMiddlewareTest extends TestCase
{
    protected $log = [];

    /**
     * Setup method
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();
        Router::reload();
        Router::connect('/articles', ['controller' => 'Articles', 'action' => 'index']);
        $this->log = [];
    }

    /**
     * Test redirect responses from redirect routes
     *
     * @return void
     */
    public function testRedirectResponse()
    {
        Router::redirect('/testpath', '/pages');
        $request = ServerRequestFactory::fromGlobals(['REQUEST_URI' => '/testpath']);
        $request = $request->withAttribute('base', '/subdir');

        $response = new Response();
        $next = function ($req, $res) {
        };
        $middleware = new RoutingMiddleware();
        $response = $middleware($request, $response, $next);

        $this->assertEquals(301, $response->getStatusCode());
        $this->assertEquals('http://localhost/subdir/pages', $response->getHeaderLine('Location'));
    }

    /**
     * Test redirects with additional headers
     *
     * @return void
     */
    public function testRedirectResponseWithHeaders()
    {
        Router::redirect('/testpath', '/pages');
        $request = ServerRequestFactory::fromGlobals(['REQUEST_URI' => '/testpath']);
        $response = new Response('php://memory', 200, ['X-testing' => 'Yes']);
        $next = function ($req, $res) {
        };
        $middleware = new RoutingMiddleware();
        $response = $middleware($request, $response, $next);

        $this->assertEquals(301, $response->getStatusCode());
        $this->assertEquals('http://localhost/pages', $response->getHeaderLine('Location'));
        $this->assertEquals('Yes', $response->getHeaderLine('X-testing'));
    }

    /**
     * Test that Router sets parameters
     *
     * @return void
     */
    public function testRouterSetParams()
    {
        $request = ServerRequestFactory::fromGlobals(['REQUEST_URI' => '/articles']);
        $response = new Response();
        $next = function ($req, $res) {
            $expected = [
                'controller' => 'Articles',
                'action' => 'index',
                'plugin' => null,
                'pass' => [],
                '_matchedRoute' => '/articles'
            ];
            $this->assertEquals($expected, $req->getAttribute('params'));
        };
        $middleware = new RoutingMiddleware();
        $middleware($request, $response, $next);
    }

    /**
     * Test middleware invoking hook method
     *
     * @return void
     */
    public function testRoutesHookInvokedOnApp()
    {
        Router::reload();
        $this->assertFalse(Router::$initialized, 'Router precondition failed');

        $request = ServerRequestFactory::fromGlobals(['REQUEST_URI' => '/app/articles']);
        $response = new Response();
        $next = function ($req, $res) {
            $expected = [
                'controller' => 'Articles',
                'action' => 'index',
                'plugin' => null,
                'pass' => [],
                '_matchedRoute' => '/app/articles'
            ];
            $this->assertEquals($expected, $req->getAttribute('params'));
            $this->assertTrue(Router::$initialized, 'Router state should indicate routes loaded');
            $this->assertCount(1, Router::routes());
        };
        $app = new Application(CONFIG);
        $middleware = new RoutingMiddleware($app);
        $middleware($request, $response, $next);
    }

    /**
     * Test that routing is not applied if a controller exists already
     *
     * @return void
     */
    public function testRouterNoopOnController()
    {
        $request = ServerRequestFactory::fromGlobals(['REQUEST_URI' => '/articles']);
        $request = $request->withAttribute('params', ['controller' => 'Articles']);
        $response = new Response();
        $next = function ($req, $res) {
            $this->assertEquals(['controller' => 'Articles'], $req->getAttribute('params'));
        };
        $middleware = new RoutingMiddleware();
        $middleware($request, $response, $next);
    }

    /**
     * Test missing routes not being caught.
     *
     * @expectedException \Cake\Routing\Exception\MissingRouteException
     */
    public function testMissingRouteNotCaught()
    {
        $request = ServerRequestFactory::fromGlobals(['REQUEST_URI' => '/missing']);
        $response = new Response();
        $next = function ($req, $res) {
        };
        $middleware = new RoutingMiddleware();
        $middleware($request, $response, $next);
    }

    /**
     * Test route with _method being parsed correctly.
     *
     * @return void
     */
    public function testFakedRequestMethodParsed()
    {
        Router::connect('/articles-patch', [
            'controller' => 'Articles',
            'action' => 'index',
            '_method' => 'PATCH'
        ]);
        $request = ServerRequestFactory::fromGlobals(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/articles-patch'
            ],
            null,
            ['_method' => 'PATCH']
        );
        $response = new Response();
        $next = function ($req, $res) {
            $expected = [
                'controller' => 'Articles',
                'action' => 'index',
                '_method' => 'PATCH',
                'plugin' => null,
                'pass' => [],
                '_matchedRoute' => '/articles-patch'
            ];
            $this->assertEquals($expected, $req->getAttribute('params'));
            $this->assertEquals('PATCH', $req->getMethod());
        };
        $middleware = new RoutingMiddleware();
        $middleware($request, $response, $next);
    }

    /**
     * Test invoking simple scoped middleware
     *
     * @return void
     */
    public function testInvokeScopedMiddleware()
    {
        $this->counter = 0;
        Router::scope('/api', function ($routes) {
            $routes->registerMiddleware('first', function ($req, $res, $next) {
                $this->log[] = 'first';

                return $next($req, $res);
            });
            $routes->registerMiddleware('second', function ($req, $res, $next) {
                $this->log[] = 'second';

                return $next($req, $res);
            });
            $routes->connect('/ping', ['controller' => 'Pings']);
            // Connect middleware in reverse to test ordering.
            $routes->applyMiddleware('second', 'first');
        });

        $request = ServerRequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/ping'
        ]);
        $response = new Response();
        $next = function ($req, $res) {
            $this->log[] = 'last';

            return $res;
        };
        $middleware = new RoutingMiddleware();
        $result = $middleware($request, $response, $next);
        $this->assertSame($response, $result, 'Should return result');
        $this->assertSame(['second', 'first', 'last'], $this->log);
    }

    /**
     * Test control flow in scoped middleware.
     *
     * @return void
     */
    public function testInvokeScopedMiddlewareReturnResponse()
    {
        $this->counter = 0;
        Router::scope('/api', function ($routes) {
            $routes->registerMiddleware('first', function ($req, $res, $next) {
                $this->log[] = 'first';

                return $res;
            });
            $routes->registerMiddleware('second', function ($req, $res, $next) {
                $this->log[] = 'second';

                return $next($req, $res);
            });

            $routes->applyMiddleware('second');
            $routes->connect('/ping', ['controller' => 'Pings']);

            $routes->scope('/v1', function ($routes) {
                $routes->applyMiddleware('first');
                $routes->connect('/articles', ['controller' => 'Articles']);
            });
        });

        $request = ServerRequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/articles'
        ]);
        $response = new Response();
        $next = function ($req, $res) {
            $this->fail('Should not be invoked as second returns a response');
        };
        $middleware = new RoutingMiddleware();
        $result = $middleware($request, $response, $next);

        $this->assertSame($response, $result, 'Should return result');
        $this->assertSame(['second', 'first'], $this->log);
    }
}