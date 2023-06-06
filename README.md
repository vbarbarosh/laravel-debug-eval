A UI and backend for evaluating PHP remotely.  For debugging purposes only.

![cover](cover.png)

## Installation

    composer require vbarbarosh/laravel-debug-eval

## Usage in Laravel

    Route::any('/laravel-debug-eval', function () {
        // 1. Ensure that current user has access to this endpoint
        return vbarbarosh\laravel_debug_eval();
    });

## YouTube

[![ALT](https://img.youtube.com/vi/gSofz-bkuCs/0.jpg)](https://www.youtube.com/watch?v=gSofz-bkuCs)

## Credits

* Right pannel with a list of snippets was inspired by https://github.com/tinkerun/tinkerun
