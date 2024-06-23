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
        Cache::forever("$cache_prefix:vars", array_diff_key($_POST, ['_token' => 1, 'php' => 1]));
        $begin = microtime(true);
        try {
            ob_start();
            echo '<pre style="padding:0;">';
            $php = preg_replace('/^<\?php\b/', '', $_POST['php']);

            // Render a prefix appended to a string typed by user
            $use = 'use Carbon\\Carbon;';
            foreach (glob(app_path('*.php')) as $path) {
                if (ends_with($path, 'test.php')) {
                    continue;
                }
                $use .= sprintf("use App\\%s;", basename($path, '.php'));
            }
            foreach (glob(app_path('Models/*.php')) as $path) {
                $use .= sprintf("use App\\Models\\%s;", basename($path, '.php'));
            }
            $use .= "use function vbarbarosh\laravel_debug_eval_longrun as longrun;\n";

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
    <link href="https://unpkg.com/@vbarbarosh/smcss@0.8.2/dist/sm.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.44.0/theme/monokai.min.css">
    <style>
        html { color: #C2C7A9; background: #403F33; margin: 0; padding: 0; }
        body { margin: 10px; }
        button { cursor: pointer; }
        .CodeMirror { height: auto; }
        .CodeMirror-scroll { min-height: 100px; }
        .w400 { width: 400px; }
        .z1000 { z-index: 1000; }
        .xborder-table,
        .xborder-table td,
        .xborder-table th { border: none; }
    </style>

    <form ref="form" method="POST" enctype="multipart/form-data">
    <?php echo csrf_field() ?>

    <div v-show="true" id="app" style="display:none;">
        <div>
            <input v-model="php" name="php" type="hidden">
            <div class="black">
                <table class="xborder-table">
                <tbody>
                <tr v-for="var_name in var_names">
                    <td>
                        <label v-bind:for="var_name.replace(':', '-')" class="db cur-pointer">
                            {{ var_name.substr(6) }}
                        </label>
                    </td>
                    <td>
                        <input v-model="var_values[var_name]" v-bind:name="var_name" v-bind:id="var_name.replace(':', '-')" type="text">
                    </td>
                </tr>
                </tbody>
                </table>
            </div>
            <vue-codemirror v-if="is_editor_visible" v-model="php" v-on:ctrl-enter="ctrl_enter_codemirror"></vue-codemirror>
            <br>
            <button type="submit">Submit</button>
        </div>
        <div v-bind:class="{w400: is_sidebar_visible}" class="abs-r r10 mv10">
            <div class="abs-tr z1000 mi5 nowrap">
                <button v-on:click="click_toggle_editor" type="button">editor</button>
                <button v-on:click="click_toggle_sidebar" type="button">sidebar</button>
            </div>
            <template v-if="is_sidebar_visible">
                <input v-model="filter" type="text" class="bbox ww mb10">
                <ul class="fluid oa xm xp xls pg5">
                    <li v-for="snippet in snippets" v-on:click="click_snippet(snippet)" v-bind:key="snippet.title" class="cur-pointer">
                        {{ snippet.title }}
                    </li>
                </ul>
            </template>
        </div>
    </div>

    <div class="oa">
        <?php
        $result = Cache::pull("$cache_prefix:result");
        $resources = trim(($result['time'] ?? '') . ' ' . ($result['memory'] ?? ''));
        if ($resources) {
            echo "<pre>$resources</pre>";
        }
        echo $result['html'] ?? null;
        ?>
    </div>

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
    <script src="https://unpkg.com/vue@2.7.14/dist/vue.js"></script>
    <script src="https://unpkg.com/jquery@3.6.1/dist/jquery.js"></script>
    <script>
        (function () {
            const listeners = [];
            const listeners_jquery = {
                mouseup: () => setTimeout(check, 0),
            };
            let timer = null;

            /**
             * Notes
             * Will not work for `fixed` elements.
             *
             * Credits
             * https://stackoverflow.com/a/21696585/1478566
             *
             * @param elem
             * @returns {boolean}
             */
            function elem_is_visible(elem)
            {
                return elem.offsetParent !== null;
            }

            /**
             * Fire an event when an element become visible (once for
             * each transition hidden -> visible).
             */
            function trigger_from_elem_visible(elem, callback)
            {
                attach(elem, callback);
                return {off: () => detach(elem, callback)};
            }

            function attach(elem, callback)
            {
                let i = listeners.findIndex(v => v.elem === elem);
                if (i == -1) {
                    i = listeners.push({elem, visibility: null, callbacks: []}) - 1;
                }
                listeners[i].callbacks.push(callback);
                if (listeners.length == 1) {
                    jQuery(document).on(listeners_jquery);
                    timer = setInterval(check, 250);
                }
            }

            function detach(elem, callback)
            {
                const i = listeners.findIndex(v => v.elem === elem);
                if (i != -1) {
                    const row = listeners[i];
                    const j = row.callbacks.indexOf(callback);
                    if (j != -1) {
                        if (row.callbacks.length > 1) {
                            row.callbacks.splice(j, 1);
                        }
                        else {
                            listeners.splice(i, 1);
                            if (listeners.length == 0) {
                                jQuery(document).off(listeners_jquery);
                                clearInterval(timer);
                                timer = null;
                            }
                        }
                    }
                }
            }

            function check()
            {
                for (let i = 0, end = listeners.length; i < end; ++i) {
                    const row = listeners[i];
                    const visible = elem_is_visible(row.elem);
                    if (row.visibility != visible) {
                        row.visibility = visible;
                        if (visible) {
                            row.callbacks.forEach(notify);
                        }
                    }
                }
            }

            function notify(cb)
            {
                try {
                    cb();
                }
                catch (error) {
                    if (__DEV__) {
                        console.error('[trigger_from_element_visible] notify failed', error);
                    }
                }
            }

            window.trigger_from_elem_visible = trigger_from_elem_visible;
        })();

        Vue.mixin({
            methods: {
                px: function (value) {
                    return value ? `${value}px` : '0';
                },
                emit_input: function (...args) {
                    this.$emit('input', ...args);
                },
            },
        });

        Vue.component('vue-codemirror', {
            template: '<div v-once />',
            props: ['value', 'mode', 'placeholder', 'autofocus'],
            data: function () {
                return {
                    orig: this.value
                };
            },
            watch: {
                value: function () {
                    if (this.editor && this.value !== this.orig) {
                        this.info('setValue', this.value);
                        this.editor.setValue(this.value);
                    }
                }
            },
            methods: {
                api_replaceSelection: function (text) {
                    this.editor.replaceSelection(text);
                },
                info: function (...args) {
                    // if (__DEV__) {
                    //     console.log(`[${this.$options._componentTag}-${this.uid()}]`, ...args);
                    // }
                },
                become_visible: function () {
                    this.info('become_visible');
                    this.editor ? this.editor.refresh() : this.ready();
                },
                ready: function () {
                    const _this = this;
                    this.info('ready');
                    // const mode = (this.mode == 'html') ? {mode: 'xml', htmlMode: true} : {mode: this.mode};
                    const autofocus = typeof this.autofocus == 'string' ? true : !!this.autofocus;
                    this.editor = CodeMirror(this.$el, {
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
                                _this.$emit('ctrl-enter');
                            },
                        },
                        value: this.value || '',
                        placeholder: this.placeholder || '',
                        autofocus,
                        // ...mode,
                    });
                    this.editor.on('change', this.change);
                    this.$once('hook:beforeDestroy', this.clean);
                    if (autofocus) {
                        jQuery(this.$el).closest('.modal').one('shown.bs.modal', () => this.editor.focus());
                    }
                },
                clean: function () {
                    this.info('clean');
                    this.editor.off('change', this.change);
                    this.editor = null;
                },
                change: function () {
                    this.info('change');
                    this.orig = this.editor.getValue();
                    this.emit_input(this.orig);
                },
            },
            mounted: function () {
                this.info('mounted');
                this.$once('hook:beforeDestroy', trigger_from_elem_visible(this.$el, this.become_visible).off);
            },
        });

        if (localStorage['VBARBAROSH_LARAVEL_DEBUG_EVAL_EDITOR'] === undefined) {
            localStorage['VBARBAROSH_LARAVEL_DEBUG_EVAL_EDITOR'] = '1';
        }

        new Vue({
            el: '#app',
            data: {
                is_sidebar_visible: !!localStorage['VBARBAROSH_LARAVEL_DEBUG_EVAL_SIDEBAR'],
                is_editor_visible: !!localStorage['VBARBAROSH_LARAVEL_DEBUG_EVAL_EDITOR'],
                php: <?php echo json_encode(strval(Cache::get("$cache_prefix:php"))) ?>,
                filter: localStorage['VBARBAROSH_LARAVEL_DEBUG_EVAL'] || '',
                snippets_orig: <?php echo json_encode($snippets) ?>,
                var_values: <?php echo json_encode(Cache::get("$cache_prefix:vars", [])) ?>,
            },
            computed: {
                var_names: function () {
                    const names = (this.php.match(/\$_POST\[(['"])input:([^']+)\1\]/g) || []).map(v => v.substr(8, v.length - 10));
                    const unique = {};
                    names.forEach(v => unique[v] = v);
                    return Object.values(unique);
                },
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
                is_sidebar_visible: {
                    immediate: true,
                    handler: function (next) {
                        localStorage['VBARBAROSH_LARAVEL_DEBUG_EVAL_SIDEBAR'] = next ? '1' : '';
                        jQuery('body').css({paddingRight: next ? 410 : 0});
                    },
                },
                is_editor_visible: {
                    immediate: true,
                    handler: function (next) {
                        localStorage['VBARBAROSH_LARAVEL_DEBUG_EVAL_EDITOR'] = next ? '1' : '';
                    },
                },
            },
            methods: {
                ctrl_enter_codemirror: function () {
                    document.querySelector('form').submit();
                },
                click_toggle_sidebar: function () {
                    this.is_sidebar_visible = !this.is_sidebar_visible;
                },
                click_toggle_editor: function () {
                    this.is_editor_visible = !this.is_editor_visible;
                },
                click_snippet: function (snippet) {
                    this.php = snippet.body;
                },
            },
        });
    </script>
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

/**
 * ```php
 * laravel_debug_eval([
 *     'job' => '2023/11/24 09:51:38',
 *     'chunk' => 50,
 *     'refresh' => 2000, // Automatically submit form after this amount of milliseconds
 *     'acc' => 0, // Intermediate value which will be stored between `run` (`[].reduce` in javascript)
 *     'init' => function ($acc) {
 *         // fetch all items which will be passed to `run` handler one `chunk` at a time
 *         return Article::query()->pluck('id');
 *     },
 *     'done' => function ($acc) {},
 *     'run' => function ($chunk, $acc) {
 *         // do job
 *         return $acc + count($chunk);
 *     },
 * ])
 * ```
 */
function laravel_debug_eval_longrun(array $params)
{
    $job = $params['job'] ?? 'any';
    $key = 'laravel_debug_eval_longrun' . (Auth::check() ? Auth::user()->getAuthIdentifier() : 0);

    $storage = cache()->get($key);
    if (!isset($storage['job']) || $storage['job'] !== $job) {
        $items = call_user_func($params['init'], $params['acc'] ?? null);
        if ($items === null) {
            // Treat `null` as "not yet ready" marker. Useful when a snippet imports data from a file.
            // In this case, the first time `init` methods was called, it will render a FORM and returns.
            // After a user submits a file, it will be able to read it and return `items` to `longrun`.
            return;
        }
        $items = collect($items)->toArray();
        $storage = [
            'job' => $job,
            'items' => $items,
            'total' => count($items),
            'done' => 0,
            'acc' => $params['acc'] ?? null,
        ];
        cache()->put($key, $storage);
        dump([
            'acc' => $storage['acc'],
            'total' => $storage['total'],
            'done' => $storage['done'],
            'remained' => count($storage['items']),
        ]);
    }
    else if (count($storage['items'])) {
        dump([
            'acc' => $storage['acc'],
            'total' => $storage['total'],
            'done' => $storage['done'],
            'remained' => count($storage['items']),
        ]);
        $items = array_splice($storage['items'], 0, $params['chunk'] ?? 100);
        $storage['acc'] = call_user_func($params['run'], $items, $storage['acc']);
        $storage['done'] += count($items);
        cache()->put($key, $storage);
    }
    else {
        dump([
            'acc' => $storage['acc'],
            'total' => $storage['total'],
            'done' => $storage['done'],
            'remained' => count($storage['items']),
        ]);
        call_user_func($params['done'] ?? function () {}, $storage['acc']);
        cache()->delete($key);
        return;
    }
    echo '<script>setTimeout(() => document.querySelector("form").submit(), ', ($params['refresh'] ?? 2000),')</script>';
}
