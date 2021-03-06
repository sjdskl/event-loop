<?php

namespace React\Tests\EventLoop;

use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;

class StreamSelectLoopTest extends AbstractLoopTest
{
    protected function tearDown()
    {
        parent::tearDown();
        if (strncmp($this->getName(false), 'testSignal', 10) === 0 && extension_loaded('pcntl')) {
            $this->resetSignalHandlers();
        }
    }

    public function createLoop()
    {
        return new StreamSelectLoop();
    }

    public function testStreamSelectTimeoutEmulation()
    {
        $this->loop->addTimer(
            0.05,
            $this->expectCallableOnce()
        );

        $start = microtime(true);

        $this->loop->run();

        $end = microtime(true);
        $interval = $end - $start;

        $this->assertGreaterThan(0.04, $interval);
    }

    public function signalProvider()
    {
        return [
            ['SIGUSR1'],
            ['SIGHUP'],
            ['SIGTERM'],
        ];
    }

    /**
     * Test signal interrupt when no stream is attached to the loop
     * @dataProvider signalProvider
     */
    public function testSignalInterruptNoStream($signal)
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('"pcntl" extension is required to run this test.');
        }

        // dispatch signal handler every 10ms for 0.1s
        $check = $this->loop->addPeriodicTimer(0.01, function() {
            pcntl_signal_dispatch();
        });
        $this->loop->addTimer(0.1, function () use ($check) {
            $this->loop->cancelTimer($check);
        });

        $handled = false;
        $this->assertTrue(pcntl_signal(constant($signal), function () use (&$handled) {
            $handled = true;
        }));

        // spawn external process to send signal to current process id
        $this->forkSendSignal($signal);

        $this->loop->run();
        $this->assertTrue($handled);
    }

    /**
     * Test signal interrupt when a stream is attached to the loop
     * @dataProvider signalProvider
     */
    public function testSignalInterruptWithStream($signal)
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('"pcntl" extension is required to run this test.');
        }

        // dispatch signal handler every 10ms
        $this->loop->addPeriodicTimer(0.01, function() {
            pcntl_signal_dispatch();
        });

        // add stream to the loop
        list($writeStream, $readStream) = $this->createSocketPair();
        $this->loop->addReadStream($readStream, function ($stream) {
            /** @var $loop LoopInterface */
            $read = fgets($stream);
            if ($read === "end loop\n") {
                $this->loop->stop();
            }
        });
        $this->loop->addTimer(0.1, function() use ($writeStream) {
            fwrite($writeStream, "end loop\n");
        });

        $handled = false;
        $this->assertTrue(pcntl_signal(constant($signal), function () use (&$handled) {
            $handled = true;
        }));

        // spawn external process to send signal to current process id
        $this->forkSendSignal($signal);

        $this->loop->run();

        $this->assertTrue($handled);
    }

    /**
     * reset all signal handlers to default
     */
    protected function resetSignalHandlers()
    {
        foreach($this->signalProvider() as $signal) {
            pcntl_signal(constant($signal[0]), SIG_DFL);
        }
    }

    /**
     * fork child process to send signal to current process id
     */
    protected function forkSendSignal($signal)
    {
        $currentPid = posix_getpid();
        $childPid = pcntl_fork();
        if ($childPid == -1) {
            $this->fail("Failed to fork child process!");
        } else if ($childPid === 0) {
            // this is executed in the child process
            usleep(20000);
            posix_kill($currentPid, constant($signal));
            die();
        }
    }
}
