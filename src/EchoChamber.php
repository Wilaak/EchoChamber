<?php

namespace Wilaak;

use Exception;

/**
 * Simple Pub/Sub for real-time PHP applications.
 */
class EchoChamber
{
    /**
     * Whether to use igbinary for serialization.
     */
    public bool $useIgbinarySerializer = false;

    /**
     * Specifies that the subscriber / publisher should subscribe / publish to all channels.
     */
    public const ALL_CHANNELS = '*';

    /**
     * Constructor for the EchoChamber class.
     * 
     * @param string $fifoPath       The path to the FIFO named pipe special file for inter-process communication.
     * @param string $filePath       The path to the file where events are stored.
     * @param float  $backlogSeconds This is used to prevent subscribers from missing out too much if they are slow at processing.
     *                               Lowering this dramatically increases performance, but may cause subscribers to miss events.
     *
     * @throws Exception If the FIFO or file cannot be created.
     */
    public function __construct(
        public string $fifoPath       = 'wakeup.fifo',
        public string $filePath       = 'events.bin',
        public float  $backlogSeconds = 0.1
    ) {
        if (!file_exists($this->fifoPath) && !posix_mkfifo($this->fifoPath, 0666)) {
            throw new Exception("Failed to create FIFO at {$this->fifoPath}");
        }
        if (!file_exists($this->filePath) && !touch($this->filePath)) {
            throw new Exception("Failed to create file at {$this->filePath}");
        }

        // check if igbinary is installed
        if (extension_loaded('igbinary')) {
            $this->useIgbinarySerializer = true;
        }
    }

    /**
     * Serialize data using igbinary or standard PHP serialization.
     */
    private function serialize(mixed $data): string
    {
        if ($this->useIgbinarySerializer) {
            return igbinary_serialize($data);
        }
        return serialize($data);
    }

    /**
     * Unserialize data using igbinary or standard PHP unserialization.
     */
    public function unserialize(string $data): mixed
    {
        if ($this->useIgbinarySerializer) {
            return igbinary_unserialize($data);
        }
        return unserialize($data);
    }

    /**
     * Subscribe to a channels and processes events using a callback function.
     * 
     * @param string|array $channels The name of the channel to subscribe to, or an array of channels.
     *                               Use `self::ALL_CHANNELS` to subscribe to all channels.
     * @param callable $callback     The callback function to process each event. The callback receives event data as its parameter.
     *                               The callback should return `true` to continue processing or `false` to stop.
     * @param float|null $timestamp  The timestamp to start processing events from. If null, it defaults to the current time minus the self::$backlogSeconds value.
     * 
     * @return void
     */
    public function subscribe(string|array $channels, callable $callback, ?float $timestamp = null): void
    {
        if (!is_array($channels)) {
            $channels = [$channels];
        }
        $lastTimestamp = $timestamp ?? microtime(true) - $this->backlogSeconds;
        $keepRunning = true;
        while (connection_aborted() === 0 && $keepRunning === true) {
            $lock = fopen($this->filePath, 'c');
            if (!flock($lock, LOCK_SH)) {
                throw new Exception('Could not lock file');
            }

            $events = $this->unserialize(file_get_contents($this->filePath));
            if ($events === false) {
                //throw new Exception('Failed to unserialize events');
            }

            if (!is_array($events)) {
                $events = [];
            }

            if (!flock($lock, LOCK_UN)) {
                throw new Exception('Could not unlock file');
            }
            fclose($lock);
            foreach ($events as $event) {
                if ($event['timestamp'] <= $lastTimestamp) {
                    break;
                }
                if (array_intersect($channels, $event['channels']) || in_array(self::ALL_CHANNELS, $channels) || in_array(self::ALL_CHANNELS, $event['channels'])) {
                    $keepRunning = $callback($event) ?? true;
                }
            }
            $lastTimestamp = $events[0]['timestamp'] ?? $lastTimestamp;

            // In case of Server-Sent Events (SSE), periodically send a signal to the client 
            // to ensure the connection is still active and avoid indefinite blocking.
            if (headers_sent()) {
                flush();
            }

            // Wait for signal
            file_get_contents($this->fifoPath);
        }
    }

    /**
     * Broadcast event to subscribers on specified channels.
     *
     * @param string|array $channels The name of the channel to publish to, or an array of channels.
     *                               Use `self::ALL_CHANNELS` to publish to all channels.
     * 
     * @param mixed  $data The data to be published to the channel.
     *
     * @return void
     */
    public function publish(string|array $channels, mixed $data): void
    {
        if (!is_array($channels)) {
            $channels = [$channels];
        }

        $lock = fopen($this->filePath, 'c');
        if (!flock($lock, LOCK_EX)) {
            throw new Exception('Could not lock file');
        }

        $events = $this->unserialize(file_get_contents($this->filePath));
        if ($events === false) {
            //throw new Exception('Failed to unserialize events');
        }

        if (!is_array($events)) {
            $events = [];
        }

        $timestamp = microtime(true);
        $events = array_filter($events, function ($event) use ($timestamp) {
            return $timestamp - $event['timestamp'] <= $this->backlogSeconds;
        });

        $newEvent = [
            'channels' => $channels,
            'data' => $data,
            'timestamp' => $timestamp,
        ];
        array_unshift($events, $newEvent);

        file_put_contents($this->filePath, $this->serialize($events));

        if (!flock($lock, LOCK_UN)) {
            throw new Exception('Could not unlock file');
        }
        fclose($lock);

        // Wakeup all subscribers
        file_put_contents($this->fifoPath, null);
    }

    /**
     * Ensures everything is running smoothly.
     */
    public function runAsWorker(): void
    {
        if (php_sapi_name() !== 'cli') {
            throw new Exception('You may only start the worker in CLI mode');
        }

        $isChildProcess = pcntl_fork() === 0;

        if ($isChildProcess) {
            $this->workerIngest();
        } else {
            $this->workerHeartbeat();
        }
    }

    private function workerIngest(): void
    {
        $startTime = microtime(true);
        $requestCount = 0;

        echo "[" . date('Y-m-d H:i:s') . "] Using " . 
             ($this->useIgbinarySerializer ? "igbinary serializer" : "standard serializer") . PHP_EOL;

        if (!$this->useIgbinarySerializer) {
            echo "It is recommended that you install igbinary for better performance" . PHP_EOL;
        }

        $this->subscribe(self::ALL_CHANNELS, function () use (&$startTime, &$requestCount) {
            $requestCount++;
            if (microtime(true) - $startTime >= 1) {
                echo "[" . date('Y-m-d H:i:s') . "] Events Per Second: {$requestCount}" . PHP_EOL;
                $requestCount = 0;
                $startTime = microtime(true);
            }
        });
    }

    private function workerHeartbeat(): void
    {
        while (true) {
            sleep(1);
            $this->publish('heartbeat', null);
        }
    }
}
