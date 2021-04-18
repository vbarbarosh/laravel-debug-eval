A rudimentary UI and backend for evaluating on a remote host. For
debugging purposes only.

## Installation

    composer require --save-dev vbarbarosh/laravel-debug-eval

## Usage in Laravel

    Route::any('/laravel-debug-eval', function () {
        // 1. Ensure that current user has access to this endpoint
        return vbarbarosh\laravel_debug_eval();
    });
