<?php

namespace vbarbarosh;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

function laravel_debug_eval($options = [])
{
    // Configuring PHPStorm for debugging over HTTPS
    // https://youtrack.jetbrains.com/issue/WI-3033#focus=Comments-27-2345883.0-0

    $e = function ($s) { echo htmlspecialchars($s); };
    $cache_prefix = $options['cache_prefix'] ?? sprintf('laravel-debug-eval:%s', Auth::check() ? Auth::user()->getAuthIdentifier() : 'guest');

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // NOTE `session()->put(__METHOD__, str_random(64*1024))` will perform logout
        Cache::forever("$cache_prefix:php", $_POST['php']);
        $begin = microtime(true);
        try {
            ob_start();
            echo '<pre style="padding:0;">';
            $php = preg_replace('/^<\?php\b/', '', $_POST['php']);

            // Render a prefix appended to a string typed by user
            $use = 'use Carbon\\Carbon;';
            foreach (glob(app_path('/*.php')) as $path) {
                if (ends_with($path, 'test.php')) {
                    continue;
                }
                $use .= 'use App\\' . basename($path, '.php') . ';';
            }

            $ret = (function () use ($use, $php) { return eval("$use;$php;"); })();
            if ($ret instanceof Response) {
                ob_clean();
                return $ret;
            }
            if ($ret) {
                dump($ret);
            }
        }
        catch (Throwable $exception) {
            dump($exception);
        }
        Cache::forever("$cache_prefix:result", [
            'time' => sprintf('%ssec', number_format(microtime(true) - $begin, 4)),
            'html' => ob_get_clean(),
        ]);
        return redirect(request()->fullUrl());
    }

    ob_start();
?>
    <!DOCTYPE html>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/theme/monokai.min.css">
    <style>
        html { color: #C2C7A9; background: #403F33; }
        .CodeMirror { height: auto; }
        .CodeMirror-scroll { min-height: 100px; }
    </style>
    <form method="POST" enctype="multipart/form-data">
    <?php echo csrf_field() ?>
    <textarea name="php" style="display: none;"><?php $e(Cache::get("$cache_prefix:php")) ?></textarea>
    <br>
    <button>Submit</button>
    <!--</form>-->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/php/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/clike/clike.min.js"></script>
    <script>
        CodeMirror.fromTextArea(document.querySelector('textarea'), {
            mode: 'text/x-php',
            theme: 'monokai',
            indentUnit: 4,
            tabSize: 4,
            indentWithTabs: false,
            lineNumbers: true,
            autofocus: true,
            viewportMargin: Infinity,
            readOnly: false,
            extraKeys: {
                'Tab': 'indentMore',
                'Shift-Tab': 'indentLess',
                'Ctrl-Enter': function () {
                    document.querySelector('form').submit();
                }
            }
        });
    </script>
<?php
    $result = Cache::pull("$cache_prefix:result");
    if (isset($result['time'])) {
        echo "<pre>{$result['time']}</pre>";
    }
    echo $result['html'] ?? null;
?>
    <script>
        // https://laracasts.com/discuss/channels/general-discussion/expanding-dd-vardumper-by-default?page=1#reply=139959
        (function () {
            // Opening nodes at more depth might lead to slow scroll
            var i, elems = document.querySelectorAll('[data-depth="2"].sf-dump-compact, [data-depth="3"].sf-dump-compact');
            for (i = 0; i < elems.length; ++i) {
                elems[i].className = 'sf-dump-expanded';
            }
        })();
    </script>
<?php
    return ob_get_clean();
}