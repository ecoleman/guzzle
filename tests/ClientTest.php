<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Adapter\FakeParallelAdapter;
use GuzzleHttp\Adapter\MockAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Subscriber\History;
use GuzzleHttp\Subscriber\Mock;

/**
 * @covers GuzzleHttp\Client
 */
class ClientTest extends \PHPUnit_Framework_TestCase
{
    public function testProvidesDefaultUserAgent()
    {
        $this->assertEquals(1, preg_match('#^Guzzle/.+ curl/.+ PHP/.+$#', Client::getDefaultUserAgent()));
    }

    public function testUsesDefaultDefaultOptions()
    {
        $client = new Client();
        $this->assertTrue($client->getConfig('defaults/allow_redirects'));
        $this->assertTrue($client->getConfig('defaults/exceptions'));
        $this->assertContains('cacert.pem', $client->getConfig('defaults/verify'));
    }

    public function testUsesProvidedDefaultOptions()
    {
        $client = new Client([
            'defaults' => [
                'allow_redirects' => false,
                'query' => ['foo' => 'bar']
            ]
        ]);
        $this->assertFalse($client->getConfig('defaults/allow_redirects'));
        $this->assertTrue($client->getConfig('defaults/exceptions'));
        $this->assertContains('cacert.pem', $client->getConfig('defaults/verify'));
        $this->assertEquals(['foo' => 'bar'], $client->getConfig('defaults/query'));
    }

    public function testCanSpecifyBaseUrl()
    {
        $this->assertEquals(null, (new Client())->getConfig('base_url'));
        $this->assertEquals('http://foo', (new Client([
            'base_url' => 'http://foo'
        ]))->getConfig('base_url'));
    }

    public function testCanSpecifyBaseUrlUriTemplate()
    {
        $client = new Client(['base_url' => ['http://foo.com/{var}/', ['var' => 'baz']]]);
        $this->assertEquals('http://foo.com/baz/', $client->getConfig('base_url'));
    }

    public function testClientUsesDefaultAdapterWhenNoneIsSet()
    {
        $client = new Client();
        if (!extension_loaded('curl')) {
            $adapter = 'GuzzleHttp\Adapter\StreamAdapter';
        } elseif (ini_get('allow_url_fopen')) {
            $adapter = 'GuzzleHttp\Adapter\StreamingProxyAdapter';
        } else {
            $adapter = 'GuzzleHttp\Adapter\Curl\CurlAdapter';
        }
        $this->assertInstanceOf($adapter, $this->readAttribute($client, 'adapter'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Foo
     */
    public function testCanSpecifyAdapter()
    {
        $adapter = $this->getMockBuilder('GuzzleHttp\Adapter\AdapterInterface')
            ->setMethods('send')
            ->getMockForAbstractClass();
        $adapter->expects($this->once())
            ->method('send')
            ->will($this->throwException(new \Exception('Foo')));
        $client = new Client(['adapter' => $adapter]);
        $client->get('http://httpbin.org');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Foo
     */
    public function testCanSpecifyMessageFactory()
    {
        $factory = $this->getMockBuilder('GuzzleHttp\Message\MessageFactoryInterface')
            ->setMethods('createRequest')
            ->getMockForAbstractClass();
        $factory->expects($this->once())
            ->method('createRequest')
            ->will($this->throwException(new \Exception('Foo')));
        $client = new Client(['message_factory' => $factory]);
        $client->get();
    }

    public function testAddsDefaultUserAgentHeaderWithDefaultOptions()
    {
        $client = new Client(['defaults' => ['allow_redirects' => false]]);
        $this->assertFalse($client->getConfig('defaults/allow_redirects'));
        $this->assertEquals(['User-Agent' => Client::getDefaultUserAgent()], $client->getConfig('defaults/headers'));
    }

    public function testAddsDefaultUserAgentHeaderWithoutDefaultOptions()
    {
        $client = new Client();
        $this->assertEquals(['User-Agent' => Client::getDefaultUserAgent()], $client->getConfig('defaults/headers'));
    }

    private function getRequestClient()
    {
        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->setMethods(['send'])
            ->getMock();
        $client->expects($this->once())
            ->method('send')
            ->will($this->returnArgument(0));

        return $client;
    }

    public function requestMethodProvider()
    {
        return [
            ['GET', false],
            ['HEAD', false],
            ['DELETE', false],
            ['OPTIONS', false],
            ['POST', 'foo'],
            ['PUT', 'foo'],
            ['PATCH', 'foo']
        ];
    }

    /**
     * @dataProvider requestMethodProvider
     */
    public function testClientProvidesMethodShortcut($method, $body)
    {
        $client = $this->getRequestClient();
        if ($body) {
            $request = $client->{$method}('http://foo.com', [
                'headers' => ['X-Baz' => 'Bar'],
                'body' => $body,
                'query' => ['a' => 'b']
            ]);
        } else {
            $request = $client->{$method}('http://foo.com', [
                'headers' => ['X-Baz' => 'Bar'],
                'query' => ['a' => 'b']
            ]);
        }
        $this->assertEquals($method, $request->getMethod());
        $this->assertEquals('Bar', $request->getHeader('X-Baz'));
        $this->assertEquals('a=b', $request->getQuery());
        if ($body) {
            $this->assertEquals($body, $request->getBody());
        }
    }

    public function testClientMergesDefaultOptionsWithRequestOptions()
    {
        $f = $this->getMockBuilder('GuzzleHttp\Message\MessageFactoryInterface')
            ->setMethods(array('createRequest'))
            ->getMockForAbstractClass();

        $o = null;
        // Intercept the creation
        $f->expects($this->once())
            ->method('createRequest')
            ->will($this->returnCallback(
                function ($method, $url, array $options = []) use (&$o) {
                    $o = $options;
                    return (new MessageFactory())->createRequest($method, $url, $options);
                }
            ));

        $client = new Client([
            'message_factory' => $f,
            'defaults' => [
                'headers' => ['Foo' => 'Bar'],
                'query' => ['baz' => 'bam'],
                'exceptions' => false
            ]
        ]);

        $request = $client->createRequest('GET', 'http://foo.com?a=b', [
            'headers' => ['Hi' => 'there', '1' => 'one'],
            'allow_redirects' => false,
            'query' => ['t' => 1]
        ]);

        $this->assertFalse($o['allow_redirects']);
        $this->assertFalse($o['exceptions']);
        $this->assertEquals('Bar', $request->getHeader('Foo'));
        $this->assertEquals('there', $request->getHeader('Hi'));
        $this->assertEquals('one', $request->getHeader('1'));
        $this->assertEquals('a=b&baz=bam&t=1', $request->getQuery());
    }

    public function testUsesBaseUrlWhenNoUrlIsSet()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/baz?bam=bar',
            $client->createRequest('GET')->getUrl()
        );
    }

    public function testUsesBaseUrlCombinedWithProvidedUrl()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/bar/bam',
            $client->createRequest('GET', 'bar/bam')->getUrl()
        );
    }

    public function testUsesBaseUrlCombinedWithProvidedUrlViaUriTemplate()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/bar/123',
            $client->createRequest('GET', ['bar/{bam}', ['bam' => '123']])->getUrl()
        );
    }

    public function testSettingAbsoluteUrlOverridesBaseUrl()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://www.foo.com/foo',
            $client->createRequest('GET', '/foo')->getUrl()
        );
    }

    public function testSettingAbsoluteUriTemplateOverridesBaseUrl()
    {
        $client = new Client(['base_url' => 'http://www.foo.com/baz?bam=bar']);
        $this->assertEquals(
            'http://goo.com/1',
            $client->createRequest(
                'GET',
                ['http://goo.com/{bar}', ['bar' => '1']]
            )->getUrl()
        );
    }

    public function testCanSetRelativeUrlStartingWithHttp()
    {
        $client = new Client(['base_url' => 'http://www.foo.com']);
        $this->assertEquals(
            'http://www.foo.com/httpfoo',
            $client->createRequest('GET', 'httpfoo')->getUrl()
        );
    }

    public function testClientSendsRequests()
    {
        $response = new Response(200);
        $adapter = new MockAdapter();
        $adapter->setResponse($response);
        $client = new Client(['adapter' => $adapter]);
        $this->assertSame($response, $client->get('http://test.com'));
        $this->assertEquals('http://test.com', $response->getEffectiveUrl());
    }

    public function testSendingRequestCanBeIntercepted()
    {
        $response = new Response(200);
        $response2 = new Response(200);
        $adapter = new MockAdapter();
        $adapter->setResponse($response);
        $client = new Client(['adapter' => $adapter]);
        $client->getEmitter()->on(
            'before',
            function (BeforeEvent $e) use ($response2) {
                $e->intercept($response2);
            }
        );
        $this->assertSame($response2, $client->get('http://test.com'));
        $this->assertEquals('http://test.com', $response2->getEffectiveUrl());
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage No response
     */
    public function testEnsuresResponseIsPresentAfterSending()
    {
        $adapter = $this->getMockBuilder('GuzzleHttp\Adapter\MockAdapter')
            ->setMethods(['send'])
            ->getMock();
        $adapter->expects($this->once())
            ->method('send');
        $client = new Client(['adapter' => $adapter]);
        $client->get('http://httpbin.org');
    }

    public function testClientHandlesErrorsDuringBeforeSend()
    {
        $client = new Client();
        $client->getEmitter()->on('before', function ($e) {
            throw new \Exception('foo');
        });
        $client->getEmitter()->on('error', function ($e) {
            $e->intercept(new Response(200));
        });
        $this->assertEquals(200, $client->get('http://test.com')->getStatusCode());
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage foo
     */
    public function testClientHandlesErrorsDuringBeforeSendAndThrowsIfUnhandled()
    {
        $client = new Client();
        $client->getEmitter()->on('before', function ($e) {
            throw new RequestException('foo', $e->getRequest());
        });
        $client->get('http://httpbin.org');
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage foo
     */
    public function testClientWrapsExceptions()
    {
        $client = new Client();
        $client->getEmitter()->on('before', function ($e) {
            throw new \Exception('foo');
        });
        $client->get('http://httpbin.org');
    }

    public function testCanSetConfigValues()
    {
        $client = new Client(['foo' => 'bar']);
        $client->setConfig('foo', 'baz');
        $client->setConfig('defaults/headers/foo', 'bar');
        $this->assertEquals('baz', $client->getConfig('foo'));
        $this->assertEquals('bar', $client->getConfig('defaults/headers/foo'));
    }

    public function testSendsAllInParallel()
    {
        $client = new Client();
        $client->getEmitter()->attach(new Mock([
            new Response(200),
            new Response(201),
            new Response(202),
        ]));
        $history = new History();
        $client->getEmitter()->attach($history);

        $requests = [
            $client->createRequest('GET', 'http://test.com'),
            $client->createRequest('POST', 'http://test.com'),
            $client->createRequest('PUT', 'http://test.com')
        ];

        $client->sendAll($requests);
        $requests = array_map(function($r) { return $r->getMethod(); }, $history->getRequests());
        $this->assertContains('GET', $requests);
        $this->assertContains('POST', $requests);
        $this->assertContains('PUT', $requests);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresDefaultsIsAnArray()
    {
        $client = new Client();
        $client->setConfig('defaults', 'foo');
    }

    public function testCanSetCustomParallelAdapter()
    {
        $called = false;
        $pa = new FakeParallelAdapter(new MockAdapter(function () use (&$called) {
            $called = true;
            return new Response(203);
        }));
        $client = new Client(['parallel_adapter' => $pa]);
        $client->sendAll([$client->createRequest('GET', 'http://www.foo.com')]);
        $this->assertTrue($called);
    }

    public function testCanDisableAuthPerRequest()
    {
        $client = new Client(['defaults' => ['auth' => 'foo']]);
        $request = $client->createRequest('GET', 'http://test.com');
        $this->assertEquals('foo', $request->getConfig()['auth']);
        $request = $client->createRequest('GET', 'http://test.com', ['auth' => null]);
        $this->assertFalse($request->getConfig()->hasKey('auth'));
    }

    /**
     * @expectedException \PHPUnit_Framework_Error_Deprecated
     */
    public function testHasDeprecatedGetEmitter()
    {
        $client = new Client();
        $client->getEventDispatcher();
    }
}
