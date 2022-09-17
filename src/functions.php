<?php

namespace vbarbarosh;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
                $use .= sprintf("use App\\%s;\n", basename($path, '.php'));
            }
            $use .= "// -------\n\n";

            $s = "$use;$php;";
            Log::info(sprintf('[laravel_debug_eval] %s', json_encode($s, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)));
            $ret = (function () use ($s) { return eval($s); })();
            if ($ret instanceof Response || $ret instanceof BinaryFileResponse) {
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
            'memory' => sprintf('%sM', number_format(memory_get_peak_usage()/1024/1024, 2)),
            'html' => ob_get_clean(),
        ]);
        return redirect(request()->fullUrl());
    }

    $snippets = [];
    $snipppets_dir = base_path('snippets');
    if (is_dir($snipppets_dir)) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($snipppets_dir));
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if ($file->isDir()) {
                continue;
            }
            $snippets[] = ['title' => $it->getSubPathName(), 'body' => file_get_contents($file->getPathname())];
        }
        usort($snippets, function ($a, $b) {
            $aa = str_contains($a['title'], '/') ? 0 : 1;
            $bb = str_contains($b['title'], '/') ? 0 : 1;
            return $aa - $bb ?: strcmp($a['title'], $b['title']);
        });
    }

    ob_start();
?>
    <!DOCTYPE html>
    <link href="https://unpkg.com/@vbarbarosh/smcss@0.6.2/dist/sm.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/theme/monokai.min.css">
    <style>
        html { color: #C2C7A9; background: #403F33; margin: 0; padding: 0; }
        body { margin: 10px; }
        .CodeMirror { height: auto; }
        .CodeMirror-scroll { min-height: 100px; }
    </style>
<?php if (count($snippets)): ?>
    <div id="app" class="fix-r vsplit w400 m10">
        <input v-model="filter" type="text" class="bbox ww mb10">
        <ul class="fluid oa xm xp xls mg5">
            <li v-for="snippet in snippets" v-on:click="click_snippet(snippet)" v-bind:key="snippet.title" class="cur-pointer">
                {{ snippet.title }}
            </li>
        </ul>
    </div>
<?php endif ?>
    <form method="POST" enctype="multipart/form-data" style="margin-right:410px;">
    <?php echo csrf_field() ?>
        <textarea name="php" style="display: none;"><?php $e(Cache::get("$cache_prefix:php")) ?></textarea>
        <br>
        <button>Submit</button>
    <!-- Leaving FORM element opened allows snippets to have the following: -->
    <!-- <input name="user" /><input type="submit" /> -->
    <!-- </form> -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/php/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/mode/clike/clike.min.js"></script>
    <script src="https://unpkg.com/vue@2.6.14/dist/vue.js"></script>
    <script>
        const editor = CodeMirror.fromTextArea(document.querySelector('textarea'), {
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
<?php if (count($snippets)): ?>
    <script>
    new Vue({
        el: '#app',
        data: {
            filter: localStorage['VBARBAROSH_LARAVEL_DEBUG_EVAL'] || '',
            snippets_orig: <?php echo json_encode($snippets) ?>,
        },
        computed: {
            snippets: function () {
                const _this = this;
                return this.snippets_orig.filter(function (snippet) {
                    return snippet.title.includes(_this.filter.toLowerCase());
                });
            },
        },
        watch: {
            filter: function (next) {
                localStorage['VBARBAROSH_LARAVEL_DEBUG_EVAL'] = next;
            },
        },
        methods: {
            click_snippet: function (snippet) {
                editor.setValue(snippet.body);
                editor.focus();
            },
        },
    });
    </script>
<?php endif ?>
<?php
    $result = Cache::pull("$cache_prefix:result");
    $resources = trim(($result['time'] ?? '') . ' ' . ($result['memory'] ?? ''));
    if ($resources) {
        echo "<pre>$resources</pre>";
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
