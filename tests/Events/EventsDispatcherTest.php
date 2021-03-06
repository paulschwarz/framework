<?php

use Mockery as m;
use Illuminate\Events\Dispatcher;

class EventsDispatcherTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        m::close();
    }

    public function testBasicEventExecution()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher;
        $d->listen('foo', function ($foo) {
            $_SERVER['__event.test'] = $foo;
        });
        $d->fire('foo', ['bar']);
        $this->assertEquals('bar', $_SERVER['__event.test']);
    }

    public function testContainerResolutionOfEventHandlers()
    {
        $d = new Dispatcher($container = m::mock('Illuminate\Container\Container'));
        $container->shouldReceive('make')->once()->with('FooHandler')->andReturn($handler = m::mock('StdClass'));
        $handler->shouldReceive('onFooEvent')->once()->with('foo', 'bar');
        $d->listen('foo', 'FooHandler@onFooEvent');
        $d->fire('foo', ['foo', 'bar']);
    }

    public function testContainerResolutionOfEventHandlersWithDefaultMethods()
    {
        $d = new Dispatcher($container = m::mock('Illuminate\Container\Container'));
        $container->shouldReceive('make')->once()->with('FooHandler')->andReturn($handler = m::mock('StdClass'));
        $handler->shouldReceive('handle')->once()->with('foo', 'bar');
        $d->listen('foo', 'FooHandler');
        $d->fire('foo', ['foo', 'bar']);
    }

    public function testQueuedEventsAreFired()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher;
        $d->push('update', ['name' => 'taylor']);
        $d->listen('update', function ($name) {
            $_SERVER['__event.test'] = $name;
        });

        $this->assertFalse(isset($_SERVER['__event.test']));
        $d->flush('update');
        $this->assertEquals('taylor', $_SERVER['__event.test']);
    }

    public function testQueuedEventsCanBeForgotten()
    {
        $_SERVER['__event.test'] = 'unset';
        $d = new Dispatcher;
        $d->push('update', ['name' => 'taylor']);
        $d->listen('update', function ($name) {
            $_SERVER['__event.test'] = $name;
        });

        $d->forgetPushed();
        $d->flush('update');
        $this->assertEquals('unset', $_SERVER['__event.test']);
    }

    public function testWildcardListeners()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher;
        $d->listen('foo.bar', function () {
            $_SERVER['__event.test'] = 'regular';
        });
        $d->listen('foo.*', function () {
            $_SERVER['__event.test'] = 'wildcard';
        });
        $d->listen('bar.*', function () {
            $_SERVER['__event.test'] = 'nope';
        });
        $d->fire('foo.bar');

        $this->assertEquals('wildcard', $_SERVER['__event.test']);
    }

    public function testListenersCanBeRemoved()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher;
        $d->listen('foo', function () {
            $_SERVER['__event.test'] = 'foo';
        });
        $d->forget('foo');
        $d->fire('foo');

        $this->assertFalse(isset($_SERVER['__event.test']));
    }

    public function testWildcardListenersCanBeRemoved()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher;
        $d->listen('foo.*', function () {
            $_SERVER['__event.test'] = 'foo';
        });
        $d->forget('foo.*');
        $d->fire('foo.bar');

        $this->assertFalse(isset($_SERVER['__event.test']));
    }

    public function testListenersCanBeFound()
    {
        $d = new Dispatcher;
        $this->assertFalse($d->hasListeners('foo'));

        $d->listen('foo', function () {
        });
        $this->assertTrue($d->hasListeners('foo'));
    }

    public function testWildcardListenersCanBeFound()
    {
        $d = new Dispatcher;
        $this->assertFalse($d->hasListeners('foo.*'));

        $d->listen('foo.*', function () {
        });
        $this->assertTrue($d->hasListeners('foo.*'));
    }

    public function testFiringReturnsCurrentlyFiredEvent()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher;
        $d->listen('foo', function () use ($d) {
            $_SERVER['__event.test'] = $d->firing();
            $d->fire('bar');
        });
        $d->listen('bar', function () use ($d) {
            $_SERVER['__event.test'] = $d->firing();
        });
        $d->fire('foo');

        $this->assertEquals('bar', $_SERVER['__event.test']);
    }

    public function testQueuedEventHandlersAreQueued()
    {
        $d = new Dispatcher;
        $queue = m::mock('Illuminate\Contracts\Queue\Queue');
        $queue->shouldReceive('push')->once()->with('Illuminate\Events\CallQueuedHandler@call', [
            'class' => 'TestDispatcherQueuedHandler',
            'method' => 'someMethod',
            'data' => serialize(['foo', 'bar']),
        ]);
        $d->setQueueResolver(function () use ($queue) {
            return $queue;
        });

        $d->listen('some.event', 'TestDispatcherQueuedHandler@someMethod');
        $d->fire('some.event', ['foo', 'bar']);
    }

    public function testQueuedEventHandlersAreQueuedWithCustomHandlers()
    {
        $d = new Dispatcher;
        $queue = m::mock('Illuminate\Contracts\Queue\Queue');
        $queue->shouldReceive('push')->once()->with('Illuminate\Events\CallQueuedHandler@call', [
            'class' => 'TestDispatcherQueuedHandlerCustomQueue',
            'method' => 'someMethod',
            'data' => serialize(['foo', 'bar']),
        ]);
        $d->setQueueResolver(function () use ($queue) {
            return $queue;
        });

        $d->listen('some.event', 'TestDispatcherQueuedHandlerCustomQueue@someMethod');
        $d->fire('some.event', ['foo', 'bar']);
    }

    public function testClassesWork()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher;
        $d->listen('ExampleEvent', function () {
            $_SERVER['__event.test'] = 'baz';
        });
        $d->fire(new ExampleEvent);

        $this->assertSame('baz', $_SERVER['__event.test']);
    }

    public function testInterfacesWork()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher;
        $d->listen('SomeEventInterface', function () {
            $_SERVER['__event.test'] = 'bar';
        });
        $d->fire(new AnotherEvent);

        $this->assertSame('bar', $_SERVER['__event.test']);
    }

    public function testBothClassesAndInterfacesWork()
    {
        unset($_SERVER['__event.test1'], $_SERVER['__event.test2']);
        $d = new Dispatcher;
        $d->listen('AnotherEvent', function () {
            $_SERVER['__event.test1'] = 'fooo';
        });
        $d->listen('SomeEventInterface', function () {
            $_SERVER['__event.test2'] = 'baar';
        });
        $d->fire(new AnotherEvent);

        $this->assertSame('fooo', $_SERVER['__event.test1']);
        $this->assertSame('baar', $_SERVER['__event.test2']);
    }

    public function testAbstractClassesWork()
    {
        unset($_SERVER['__event.test']);
        $d = new Dispatcher;
        $d->listen('SomeAbstractClass', function () {
            $_SERVER['__event.test'] = 'bar';
        });
        $d->fire(new ExampleConcreteEvent);

        $this->assertSame('bar', $_SERVER['__event.test']);
    }

    public function testBothClassesAndAbstractClassesWork()
    {
        unset($_SERVER['__event.test1'], $_SERVER['__event.test2']);
        $d = new Dispatcher;
        $d->listen('ExampleConcreteEvent', function () {
            $_SERVER['__event.test1'] = 'fooo';
        });
        $d->listen('SomeAbstractClass', function () {
            $_SERVER['__event.test2'] = 'baar';
        });
        $d->fire(new ExampleConcreteEvent);

        $this->assertSame('fooo', $_SERVER['__event.test1']);
        $this->assertSame('baar', $_SERVER['__event.test2']);
    }
}

class TestDispatcherQueuedHandler implements Illuminate\Contracts\Queue\ShouldQueue
{
    public function handle()
    {
    }
}

class TestDispatcherQueuedHandlerCustomQueue implements Illuminate\Contracts\Queue\ShouldQueue
{
    public function handle()
    {
    }

    public function queue($queue, $handler, array $payload)
    {
        $queue->push($handler, $payload);
    }
}

class ExampleEvent
{
    //
}

interface SomeEventInterface
{
    //
}

class AnotherEvent implements SomeEventInterface
{
    //
}

abstract class SomeAbstractClass
{
    //
}

class ExampleConcreteEvent extends SomeAbstractClass
{
    //
}
