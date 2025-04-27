# EchoChamber 🗣️

Simple Pub/Sub for real-time PHP applications.

## Install

**Note**: This library does not support Windows platforms.

    composer require wilaak/echochamber

Requires PHP 8.3 or later and the following:

- A UNIX-like operating system (e.g., `GNU/Linux`)
- [PHP POSIX](https://www.php.net/manual/en/book.posix.php) - Implements IEEE 1003.1 (POSIX.1) standards. This module is typically enabled by default on POSIX-like systems
- [PHP PCNTL](https://www.php.net/manual/en/book.pcntl.php) - Unix style of process creation, program execution, signal handling and process termination. This should not be enabled within a web server environment and is used only for the EchoChamber Worker.

For better performance you may optionally install:

 - [PHP Igbinary](https://www.php.net/manual/en/book.igbinary.php) - A faster drop in replacement for `serialize()`

## Usage

Create a Worker, this is the process that ensures everything is running smoothly. Put it in a file (e.g., `EchoChamberWorker.php`)

```php
<?php require __DIR__ . '/../vendor/autoload.php';

use Wilaak\EchoChamber;

// You do not need to provide these arguments as they are the default values
$events = new EchoChamber(
    'wakeup.fifo', // Pipe used to tell subscribers about new events
    'events.bin',  // Where to temporarily store event data
    0.25           // How long to persist event data in seconds (lower value increases performance but may cause subscribers to miss events if they are slow)
);
$events->runAsWorker();
```

Run the script from the command line: `php EchoChamberWorker.php`. You should see some output, keep this running while we create the next files.

Create a subscriber:

```php 
<?php require __DIR__ . '/../vendor/autoload.php';

use Wilaak\EchoChamber;

$events = new EchoChamber;

$events->subscribe('mychannel', function ($event) {
    var_dump($event);
});
```

Create a publisher:

```php
<?php require __DIR__ . '/../vendor/autoload.php';

use Wilaak\EchoChamber;

$events = new EchoChamber;

$events->publish(EchoChamber::ALL_CHANNELS, [
    'message' => 'I am sending all channels a message!'
]);
```

Run the subscriber and it should be blocking until it receives a message. Run the publisher and you should see that the subscriber outputs the event in the console.
