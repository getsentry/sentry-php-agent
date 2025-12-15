<?php

declare(strict_types=1);

namespace Sentry\Agent\Tests;

/**
 * This is a test agent that can be used to test the agent forwarding.
 *
 * It spawns the agent process and provides methods to send envelopes to it.
 *
 * In your test call `$this->startTestAgent($upstreamAddress)` to start the agent.
 * Then use `$this->sendEnvelopeToAgent($envelope)` to send an envelope.
 * After you are done, call `$this->stopTestAgent()` to stop the agent.
 */
trait TestAgent
{
    /**
     * @var resource|null the agent process handle
     */
    protected $agentProcess;

    /**
     * @var resource|null the agent stderr handle
     */
    protected $agentStderr;

    /**
     * @var int the port on which the agent is listening, this default value was somwhat randomly chosen
     */
    protected $agentPort = 45248;

    /**
     * @var int the port on which the control server is listening, this default value was somwhat randomly chosen
     */
    protected $controlServerPort = 45249;

    /**
     * Start the test agent.
     *
     * @return string the address the agent is listening on
     */
    public function startTestAgent(): string
    {
        if ($this->agentProcess !== null) {
            throw new \RuntimeException('There is already a test agent instance running.');
        }

        $pipes = [];

        $this->agentProcess = proc_open(
            $command = \sprintf(
                'php %s --listen=127.0.0.1:%d --control-server=127.0.0.1:%d --upstream-timeout=5 --upstream-concurrency=1 --queue-limit=10 --verbose',
                realpath(__DIR__ . '/../src/sentry-agent.php'),
                $this->agentPort,
                $this->controlServerPort
            ),
            [
                0 => ['pipe', 'r'], // stdin
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ],
            $pipes
        );

        $this->agentStderr = $pipes[2];

        $pid = proc_get_status($this->agentProcess)['pid'];

        if (!\is_resource($this->agentProcess)) {
            throw new \RuntimeException("Error starting test agent on pid {$pid}, command failed: {$command}");
        }

        $address = "127.0.0.1:{$this->agentPort}";

        // Wait for the agent to be ready to accept connections
        $startTime = microtime(true);
        $timeout = 5; // 5 seconds timeout

        while (true) {
            $socket = @stream_socket_client("tcp://{$address}", $errno, $errstr, 1);

            if ($socket !== false) {
                fclose($socket);
                break;
            }

            if (microtime(true) - $startTime > $timeout) {
                $this->stopTestAgent();
                throw new \RuntimeException("Timeout waiting for test agent to start on {$address}");
            }

            usleep(10000);
        }

        // Ensure the process is still running
        if (!proc_get_status($this->agentProcess)['running']) {
            throw new \RuntimeException("Error starting test agent on pid {$pid}, command failed: {$command}");
        }

        // Wait for the control server to be ready by checking its /status endpoint
        $controlServerAddress = "127.0.0.1:{$this->controlServerPort}";
        $streamContext = stream_context_create(['http' => ['timeout' => 1]]);

        while (true) {
            $response = @file_get_contents("http://{$controlServerAddress}/status", false, $streamContext);

            if ($response !== false) {
                break;
            }

            if (microtime(true) - $startTime > $timeout) {
                $this->stopTestAgent();
                throw new \RuntimeException("Timeout waiting for control server to start on {$controlServerAddress}");
            }

            usleep(10000);
        }

        return $address;
    }

    /**
     * Send an envelope to the test agent.
     *
     * The envelope must be a string in Sentry envelope format.
     */
    public function sendEnvelopeToAgent(string $envelope): void
    {
        if ($this->agentProcess === null) {
            throw new \RuntimeException('There is no test agent instance running.');
        }

        $address = "127.0.0.1:{$this->agentPort}";
        $socket = stream_socket_client("tcp://{$address}", $errno, $errstr, 5);

        if ($socket === false) {
            throw new \RuntimeException("Failed to connect to test agent: {$errstr} ({$errno})");
        }

        // The agent uses a 4-byte big-endian length prefix protocol
        // The length includes the 4 bytes of the header itself
        $length = \strlen($envelope) + 4;
        $header = pack('N', $length);

        fwrite($socket, $header . $envelope);

        // Gracefully shutdown the write side, ensuring all data is sent before closing
        stream_socket_shutdown($socket, \STREAM_SHUT_WR);

        fclose($socket);
    }

    /**
     * Wait for the agent queue to drain (all envelopes processed).
     *
     * This blocks until the queue is empty.
     *
     * @param float $timeout Maximum time to wait in seconds
     *
     * @throws \RuntimeException if timeout is reached or control server is unavailable
     */
    public function waitForQueueDrain(float $timeout = 10.0): void
    {
        if ($this->agentProcess === null) {
            throw new \RuntimeException('There is no test agent instance running.');
        }

        $controlServerAddress = "127.0.0.1:{$this->controlServerPort}";

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
            ],
        ]);

        $result = @file_get_contents("http://{$controlServerAddress}/drain", false, $context);

        if ($result === false) {
            throw new \RuntimeException("Failed to drain queue: control server at {$controlServerAddress} is unavailable");
        }
    }

    /**
     * Get the current queue size from the agent.
     *
     * @return int the number of envelopes in the queue
     *
     * @throws \RuntimeException if control server is unavailable
     */
    public function getQueueSize(): int
    {
        if ($this->agentProcess === null) {
            throw new \RuntimeException('There is no test agent instance running.');
        }

        $controlServerAddress = "127.0.0.1:{$this->controlServerPort}";

        $result = @file_get_contents("http://{$controlServerAddress}/status");

        if ($result === false) {
            throw new \RuntimeException("Failed to get queue status: control server at {$controlServerAddress} is unavailable");
        }

        $status = json_decode($result, true);

        return $status['queue_size'] ?? 0;
    }

    /**
     * Stop the test agent and return stderr output.
     *
     * This waits for the queue to drain via the control server, then kills the process.
     *
     * @return string the stderr output from the agent
     */
    public function stopTestAgent(): string
    {
        if (!$this->agentProcess) {
            throw new \RuntimeException('There is no test agent instance running.');
        }

        // Wait for the queue to drain before killing the process
        $this->waitForQueueDrain();

        for ($i = 0; $i < 20; ++$i) {
            $status = proc_get_status($this->agentProcess);

            if (!$status['running']) {
                break;
            }

            $this->killAgentProcess($status['pid']);

            usleep(10000);
        }

        if ($status['running']) {
            throw new \RuntimeException('Could not kill test agent');
        }

        stream_set_blocking($this->agentStderr, false);
        $stderrOutput = stream_get_contents($this->agentStderr);

        proc_close($this->agentProcess);

        $this->agentProcess = null;
        $this->agentStderr = null;

        return $stderrOutput;
    }

    private function killAgentProcess(int $pid): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /pid {$pid} /f /t");
        } else {
            // Kills any child processes
            exec("pkill -P {$pid}");

            // Kill the parent process
            exec("kill {$pid}");
        }

        proc_terminate($this->agentProcess, 9);
    }
}
