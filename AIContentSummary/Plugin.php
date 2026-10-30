<?php
/**
 * AIContentSummary 是一个用于通过调用 AI 接口，根据文章内容生成摘要的 Typecho 插件
 *
 * @description 根据文章内容调用 AI 生成摘要，修复 PHP 8.4 兼容与 Kimi K2/K3 等思考型模型适配
 * @category plugin
 *
 * 兼容说明：
 *  - 运行环境：PHP 7.4 ~ 8.4（不使用 8.x 废弃语法，类型化/空值访问均已加防护）
 *  - 程序版本：Typecho 1.2 / 1.3
 *  - 字段与配置向下兼容：沿用旧版自定义字段名（content）与配置项
 *    （modelName / keyValue / apiUrl / maxLength），旧文章已生成的 AI 摘要可继续显示。
 *
 * 1.7 变更（关键）：
 *  - 测试接口改为「独立文件」test.php，不再依赖 Typecho 路由表。
 *    原因：1.5/1.6 把测试地址注册成路由，而路由表存在数据库里；
 *    插件升级后旧路由（widget 指向插件类 AIContentSummary_Plugin）不会自动消失，
 *    请求进来后 Router 会调用 $widget->execute()，插件类没有该方法 →
 *    「Call to undefined method AIContentSummary_Plugin::execute()」。
 *    现在改为直接请求 usr/plugins/AIContentSummary/test.php，上传即用，
 *    不受路由表、插件是否重新启用、CDN 缓存影响。
 *  - 同时自动清理数据库里遗留的错误路由。
 *  - API 调用逻辑抽到 Api.php，插件与接口共用同一份实现，避免两边行为不一致。
 *  - 修正若干 PHP 8.x 隐患：空配置读取、maxLength 为空导致摘要变 "..."、异常捕获不全等。
 *
 * 1.8 变更（修复「保存文章白屏 + 从不生成摘要」）：
 *  - 白屏根因：onFinishPublish 里调用了 fastcgi_finish_request()。该钩子在 Typecho 中
 *    是「发布完成 → 设置提示 → 跳转后台列表」之间执行的，提前结束响应后，
 *    Notice::set() 与 response->redirect() 全部发不出去，浏览器只收到一个空白 200。
 *    此外前面还做了 session_write_close()，会连带把成功提示一起丢掉。两处均已删除。
 *  - 不生成摘要的根因：cid 用 isset($obj->cid) 判断。Typecho 的 Widget 属性是魔术属性，
 *    且没有实现 __isset，isset() 恒为 false → cid=0 → 直接 return，摘要代码从未执行过。
 *    改为直接读取 + try/catch，并增加 theId / id 兜底。
 *  - 整个钩子包 try/catch，任何异常只写日志，绝不影响保存与跳转。
 *  - 新增 debug.log（usr/plugins/AIContentSummary/debug.log），记录跳过原因、API 失败原文。
 *  - 新增 regen.php：给历史文章补生成摘要，设置页有对应按钮（逐篇串行，最多 50 篇）。
 *  - 正文先清洗（去 HTML/markdown 标记、限 8000 字）再送模型，降低超长失败概率。
 *
 * 1.8.1 变更（避免重复消耗接口额度）：
 *  - 已有摘要的文章（自定义字段 content 非空）再次保存时直接跳过，不再调用 AI 接口。
 *    之前每次编辑保存都会重新调用一次接口，既费额度，又会覆盖手工修订过的摘要。
 *  - 想重新生成某篇：把它的 content 字段清空再保存，或用设置页的「扫描并补生成」。
 *
 * 1.8.2 变更（思考型模型的正文为空）：
 *  - 现象：批量补生成时 kimi-k3 这类模型报「模型没有返回正文（finish_reason=length）」，
 *    返回体里 content 是空字符串，但 reasoning_content 已经把文章复述了一遍。
 *  - 根因：max_tokens 按「摘要字数 × 4」给（100 字 → 400），思考型模型光推理就用光了预算，
 *    正文一个 token 都没轮到，finish_reason 直接是 length。
 *  - 修正：Api::isThinkingModel() 识别思考型模型，maxTokensFor() 给 4096~32768 的预算；
 *    仍被截断时自动放大 4 倍重试一次；再不行就从 reasoning_content 里保守提取草稿，
 *    不让整批任务白跑。彻底不行时，报错信息会直接给出换模型/调小 maxLength 的建议。
 *
 * 版权与来源（Apache License 2.0）：
 *  本文件基于 Rockytkg/AIContentSummary 修改而来，原项目版权归 Rockytkg 所有，
 *  原项目地址 https://github.com/Rockytkg/AIContentSummary
 *  修改者 Andrew（https://moechu.cn ，GitHub @Andrew8102），修改时间 2026-10
 *  修改内容：PHP 8.4 兼容；Kimi K2/K3 等思考型模型的 temperature 与 max_tokens 适配。
 *  修改部分同样以 Apache-2.0 发布，详见仓库 README。
 *
 * @package AIContentSummary
 * @author Andrew (Andrew8102)
 * @version 1.8.2
 * @link https://moechu.cn
 * @original_author Joinliu (Rockytkg)
 * @original_link https://github.com/Rockytkg/AIContentSummary
 */

// 防止被直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 公共 API 逻辑（纯 PHP，不依赖 Typecho 类）
// 用 is_file 兜一层：万一只上传了 Plugin.php 而漏了 Api.php，
// 也不能让后台插件页直接白屏，只在测试时提示缺文件。
if (is_file(__DIR__ . '/Api.php')) {
    require_once __DIR__ . '/Api.php';
}

class AIContentSummary_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 自定义字段名（与旧版保持一致，请勿修改，否则旧文章的摘要会失效）
     */
    const FIELD_NAME = 'content';

    /**
     * 1.5/1.6 遗留的测试路由名，仅用于清理（1.7 起不再使用路由）
     */
    const LEGACY_ROUTE_NAME = 'aicontentsummary_test';

    /**
     * 测试接口文件名（位于插件目录下）
     */
    const TEST_FILE = 'test.php';

    /**
     * 激活插件
     *
     * @access public
     * @return string
     */
    public static function activate()
    {
        // 列表 / 摘要输出时，优先使用 AI 生成的摘要
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('AIContentSummary_Plugin', 'customExcerpt');

        // 文章发布完成后，调用 AI 生成摘要并写入自定义字段
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('AIContentSummary_Plugin', 'onFinishPublish');

        // 清掉旧版本注册的错误路由（指向插件类，访问必 500）
        self::cleanupRoute();

        return _t('AIContentSummary 启用成功，请前往「设置 → 插件」填写 API 配置。');
    }

    /**
     * 禁用插件
     *
     * @access public
     * @return string
     */
    public static function deactivate()
    {
        Helper::removeRoute(self::LEGACY_ROUTE_NAME);

        return _t('AIContentSummary 已禁用。');
    }

    /**
     * 清理 1.5/1.6 遗留的测试路由
     *
     * 那条路由的 widget 指向插件类（非 Widget），一旦被访问就会
     * 抛「Call to undefined method AIContentSummary_Plugin::execute()」。
     * 1.7 起测试改走独立文件 test.php，因此把残留路由删掉即可。
     *
     * @return void
     */
    private static function cleanupRoute()
    {
        try {
            $routes = Helper::options()->routingTable;

            if (is_array($routes) && isset($routes[self::LEGACY_ROUTE_NAME])) {
                Helper::removeRoute(self::LEGACY_ROUTE_NAME);
            }
        } catch (\Throwable $e) {
            // 清理失败不影响插件功能
        }
    }

    /**
     * 插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $modelName = new Typecho_Widget_Helper_Form_Element_Text(
            'modelName',
            NULL,
            'gpt-3.5-turbo-16k',
            _t('模型名称'),
            _t('请输入用于生成摘要的模型名，默认 gpt-3.5-turbo-16k。<br>'
                . 'Kimi / Moonshot：kimi-k3.1-pro、kimi-k3、kimi-k2.6（地址填 https://api.moonshot.cn/v1）；'
                . 'DeepSeek：deepseek-chat。Kimi 新模型只接受 temperature=1，插件已自动适配。')
        );
        $form->addInput($modelName);

        $keyValue = new Typecho_Widget_Helper_Form_Element_Text(
            'keyValue',
            NULL,
            NULL,
            _t('API KEY'),
            _t('输入调用 AI 接口所用的 API Key')
        );
        $form->addInput($keyValue);

        $apiUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'apiUrl',
            NULL,
            NULL,
            _t('API 地址'),
            _t('请输入 API 地址，需包含 http:// 或 https://。是否带 /v1 都可以，插件会自动补全。')
        );
        $form->addInput($apiUrl);

        $maxLength = new Typecho_Widget_Helper_Form_Element_Text(
            'maxLength',
            NULL,
            '100',
            _t('摘要最大长度'),
            _t('请输入输出摘要的最大文字数量，留空按 100 处理。')
        );
        $form->addInput($maxLength);

        $enableAuto = new Typecho_Widget_Helper_Form_Element_Radio(
            'enableAuto',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('发布时自动生成摘要'),
            _t('开启后，文章发布完成会自动调用 AI 生成摘要并保存为自定义字段。<br>'
                . '<strong>已有摘要的文章不会重复调用接口</strong>（避免重复消耗额度、避免覆盖手工修订的摘要）；'
                . '想让某篇重新生成，清空它自定义字段里的 content 再保存一次，或用下面的「扫描并补生成」。')
        );
        $form->addInput($enableAuto);

        // 1.7：顺手清掉 1.5/1.6 遗留的错误路由（那条路由指向插件类，访问必 500）
        self::cleanupRoute();

        // 测试接口 = 插件目录下的独立文件，不经过 Typecho 路由表。
        // 只取 path，让浏览器以「当前后台页面同源」发起请求，彻底规避 CORS / http-https 混用。
        $pluginUrl = Helper::options()->pluginUrl;
        $testUrl = Typecho_Common::url('AIContentSummary/' . self::TEST_FILE, $pluginUrl);
        $parsedPath = parse_url($testUrl);
        $testPath = isset($parsedPath['path']) ? $parsedPath['path'] : '/usr/plugins/AIContentSummary/test.php';

        // 令牌由 API KEY 派生（外部猜不到），防止接口被陌生请求拿去刷额度
        $apiReady = class_exists('AIContentSummary_Api');
        $keyForToken = '';

        if ($apiReady) {
            try {
                $keyForToken = AIContentSummary_Api::opt(
                    Helper::options()->plugin('AIContentSummary'),
                    'keyValue'
                );
            } catch (\Throwable $e) {
                $keyForToken = '';
            }
        }

        $testPathJson = json_encode($testPath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 补生成接口路径（与 test.php 同目录）
        $regenUrl = Typecho_Common::url('AIContentSummary/regen.php', $pluginUrl);
        $regenParsed = parse_url($regenUrl);
        $regenPath = isset($regenParsed['path']) ? $regenParsed['path'] : '/usr/plugins/AIContentSummary/regen.php';
        $regenPathJson = json_encode($regenPath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tokenJson = json_encode($apiReady ? AIContentSummary_Api::token($keyForToken) : '');
        $fileOkFlag = is_file(__DIR__ . '/' . self::TEST_FILE) ? 'true' : 'false';
        $apiFileOkFlag = $apiReady ? 'true' : 'false';

        echo <<<HTML
<div style="padding:12px 0;border-top:1px dashed #ddd;margin-top:15px;">
    <strong style="display:block;margin-bottom:8px;">API 连通性测试</strong>
    <button type="button" id="ai-test-btn" class="btn btn-s">测试 API 连通性</button>
    <span id="ai-test-loading" style="display:none;margin-left:10px;color:#888;">测试中，最多等 30 秒...</span>
    <div id="ai-test-result" style="display:none;margin-top:10px;padding:10px 12px;border-radius:4px;font-size:13px;line-height:1.7;"></div>
    <p style="color:#888;font-size:12px;margin-top:8px;">
        提示：先点页面底部的「保存设置」，再点测试。<br>
        测试由<strong>服务器</strong>直接访问 API（不经浏览器），因此不受 CORS 限制；失败时会指出卡在哪一步。
    </p>
</div>
<div style="padding:12px 0;border-top:1px dashed #ddd;margin-top:15px;">
    <strong style="display:block;margin-bottom:8px;">补生成历史文章的摘要</strong>
    <button type="button" id="ai-regen-btn" class="btn btn-s">扫描并补生成（最多 50 篇）</button>
    <span id="ai-regen-status" style="margin-left:10px;color:#888;"></span>
    <div id="ai-regen-log" style="display:none;margin-top:10px;padding:10px 12px;border:1px solid #e3e3e3;border-radius:4px;font-size:12px;line-height:1.7;max-height:220px;overflow:auto;background:#fafafa;"></div>
    <p style="color:#888;font-size:12px;margin-top:8px;">
        只处理「已发布且没有 content 自定义字段」的文章，逐篇串行调用接口，页面保持打开即可。<br>
        生成结果同时写入 <code>usr/plugins/AIContentSummary/debug.log</code>，排查问题时可直接看这个文件。
    </p>
</div>
<script>
(function () {
    var PATH = {$testPathJson};
    var REGEN = {$regenPathJson};
    var TOKEN = {$tokenJson};
    var FILE_OK = {$fileOkFlag};
    var API_OK = {$apiFileOkFlag};
    var btn = document.getElementById('ai-test-btn');
    var loading = document.getElementById('ai-test-loading');
    var box = document.getElementById('ai-test-result');
    if (!btn) { return; }

    function esc(v) {
        return String(v === undefined || v === null ? '' : v).replace(/[&<>]/g, function (c) {
            return c === '&' ? '&amp;' : (c === '<' ? '&lt;' : '&gt;');
        });
    }

    function paint(ok, title, lines) {
        box.style.display = 'block';
        box.style.background = ok ? '#f0f9f2' : '#fdf1f1';
        box.style.border = '1px solid ' + (ok ? '#bfe3c6' : '#f0c2c2');
        box.style.color = ok ? '#1f5b2b' : '#8a2222';
        var html = '<strong>' + esc(title) + '</strong>';
        if (lines && lines.length) {
            html += '<div style="margin-top:6px;">';
            for (var i = 0; i < lines.length; i++) {
                if (lines[i]) { html += '<div>' + lines[i] + '</div>'; }
            }
            html += '</div>';
        }
        box.innerHTML = html;
    }

    function linesOf(r) {
        var out = [];
        if (r.msg) { out.push(esc(r.msg)); }
        if (r.hint) { out.push('建议：' + esc(r.hint)); }
        if (r.endpoint) { out.push('接口地址：<code>' + esc(r.endpoint) + '</code>'); }
        if (r.model) { out.push('模型：' + esc(r.model)); }
        if (r.httpCode) { out.push('上游 HTTP：' + esc(r.httpCode)); }
        if (r.reply) { out.push('模型回复：' + esc(r.reply)); }
        if (r.temperature) { out.push('temperature：' + esc(r.temperature)); }
        if (r.cost) { out.push('耗时：' + esc(r.cost)); }
        if (r.response) { out.push('上游返回：<code style="word-break:break-all;">' + esc(r.response) + '</code>'); }
        if (r.stage && r.stage !== 'ok') { out.push('失败环节：' + esc(r.stage)); }
        return out;
    }

    btn.addEventListener('click', function () {
        if (!API_OK || !FILE_OK) {
            paint(false, '接口文件缺失', [
                '插件目录下需要 <code>test.php</code> 和 <code>Api.php</code>。',
                API_OK ? '' : '缺少：<code>Api.php</code>',
                FILE_OK ? '' : '缺少：<code>test.php</code>',
                '请把压缩包里的这两个文件一起上传到 usr/plugins/AIContentSummary/'
            ]);
            return;
        }

        btn.disabled = true;
        loading.style.display = 'inline';
        box.style.display = 'none';

        var url = PATH + (PATH.indexOf('?') >= 0 ? '&' : '?') + '_t=' + Date.now();

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: 't=' + encodeURIComponent(TOKEN)
        }).then(function (resp) {
            return resp.text().then(function (text) {
                return {status: resp.status, text: text};
            });
        }).then(function (resp) {
            var data = null;
            try { data = JSON.parse(resp.text); } catch (e) { data = null; }

            if (!data) {
                var snippet = resp.text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').slice(0, 300);
                paint(false, '接口返回的不是 JSON（HTTP ' + resp.status + '）', [
                    '请求地址：<code>' + esc(PATH) + '</code>',
                    '返回片段：' + esc(snippet),
                    '常见原因：① test.php 没上传（404）；② 主机/宝塔禁止直接访问 usr/plugins 下的 PHP（403）；③ CDN 缓存了旧内容。'
                ]);
                return;
            }

            paint(!!data.ok, (data.ok ? '连通正常：' : '测试未通过：') + (data.msg || ''), linesOf(data));
        }).catch(function (err) {
            paint(false, '请求没发出去', [
                '浏览器报错：' + esc(err && err.message ? err.message : err),
                '请求地址：<code>' + esc(PATH) + '</code>'
            ]);
        }).then(function () {
            btn.disabled = false;
            loading.style.display = 'none';
        });
    });

    // ---------- 补生成历史摘要 ----------
    var regenBtn = document.getElementById('ai-regen-btn');
    var regenStatus = document.getElementById('ai-regen-status');
    var regenLog = document.getElementById('ai-regen-log');

    function regenSay(html, color) {
        regenLog.style.display = 'block';
        regenLog.innerHTML += '<div style="color:' + (color || '#444') + ';">' + html + '</div>';
        regenLog.scrollTop = regenLog.scrollHeight;
    }

    function postRegen(params) {
        return fetch(REGEN + '?_t=' + Date.now(), {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: params
        }).then(function (r) {
            return r.text().then(function (t) {
                try { return JSON.parse(t); } catch (e) {
                    return {ok: false, msg: 'regen.php 返回的不是 JSON：' + t.replace(/<[^>]*>/g, ' ').slice(0, 200)};
                }
            });
        });
    }

    if (regenBtn) {
        regenBtn.addEventListener('click', function () {
            if (!API_OK) {
                regenSay('缺少 Api.php，无法补生成', '#8a2222');
                return;
            }

            regenBtn.disabled = true;
            regenLog.innerHTML = '';
            regenStatus.textContent = '正在扫描...';

            postRegen('t=' + encodeURIComponent(TOKEN)).then(function (data) {
                if (!data.ok) {
                    regenStatus.textContent = '扫描失败';
                    regenSay(esc(data.msg || '未知错误'), '#8a2222');
                    regenBtn.disabled = false;
                    return;
                }

                var list = data.list || [];
                if (!list.length) {
                    regenStatus.textContent = '没有需要补生成的文章';
                    regenSay('全部已发布文章都已有摘要，无需补生成。', '#1f5b2b');
                    regenBtn.disabled = false;
                    return;
                }

                var done = 0;
                var failed = 0;
                regenStatus.textContent = '共 ' + list.length + ' 篇待处理';

                function next(i) {
                    if (i >= list.length) {
                        regenStatus.textContent = '完成：成功 ' + done + ' 篇，失败 ' + failed + ' 篇';
                        regenBtn.disabled = false;
                        return;
                    }

                    var item = list[i];
                    regenStatus.textContent = '处理中 ' + (i + 1) + '/' + list.length + '：' + item.title;

                    postRegen('t=' + encodeURIComponent(TOKEN) + '&cid=' + item.cid).then(function (r) {
                        if (r.ok) {
                            done++;
                            regenSay('✔ ' + esc(item.title) + '（' + esc(r.cost || '') + '）', '#1f5b2b');
                        } else {
                            failed++;
                            regenSay('✘ ' + esc(item.title) + '：' + esc(r.msg || '') + (r.detail ? '｜' + esc(r.detail) : ''), '#8a2222');
                        }
                        next(i + 1);
                    }).catch(function (e) {
                        failed++;
                        regenSay('✘ ' + esc(item.title) + '：请求失败 ' + esc(e && e.message ? e.message : e), '#8a2222');
                        next(i + 1);
                    });
                }

                next(0);
            }).catch(function (e) {
                regenStatus.textContent = '扫描失败';
                regenSay('请求失败：' + esc(e && e.message ? e.message : e), '#8a2222');
                regenBtn.disabled = false;
            });
        });
    }
})();
</script>
HTML;
    }

    /**
     * 个人用户配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 读取插件配置（配置未保存时返回空值而不是报错）
     *
     * @return object|null
     */
    private static function pluginOptions()
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('AIContentSummary');
            return is_object($options) ? $options : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 自定义摘要：优先使用 AI 生成的字段内容
     *
     * @access public
     * @param string $excerpt
     * @param Widget_Abstract_Contents $widget
     * @return string
     */
    public static function customExcerpt($excerpt, $widget)
    {
        $customContent = null;

        // 注意：Typecho 的 Config 类继承 stdClass 但值存在内部数组里，
        // 用 isset() / ?? 判断魔术属性永远是 false，所以这里必须直接取值
        // （Config::offsetGet 内部是 `$arr[$k] ?? null`，缺字段不会报 Warning）。
        if (is_object($widget)) {
            try {
                $fields = $widget->fields;
                if (is_object($fields)) {
                    $customContent = $fields->{self::FIELD_NAME};
                }
            } catch (\Throwable $e) {
                $customContent = null;
            }
        }

        if (!is_string($customContent) || trim($customContent) === '') {
            return $excerpt;
        }

        $excerpt = $customContent;

        $maxLength = 100;
        $plugin = self::pluginOptions();
        if ($plugin) {
            $configured = $plugin->maxLength;
            if ($configured !== null && $configured !== '') {
                $maxLength = (int) $configured;
            }
        }
        if ($maxLength <= 0) {
            $maxLength = 100;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($excerpt, 'UTF-8') > $maxLength) {
                $excerpt = mb_substr($excerpt, 0, $maxLength, 'UTF-8') . '...';
            }
        }

        return $excerpt;
    }

    /**
     * 安全读取 Config 里的配置项（Config 的魔术属性不能用 isset/?? 判断）
     *
     * @param mixed $config
     * @param string $name
     * @param string $default
     * @return string
     */
    private static function configValue($config, $name, $default = '')
    {
        if (!is_object($config)) {
            return $default;
        }

        try {
            $value = $config->$name;
        } catch (\Throwable $e) {
            return $default;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    /**
     * 文章发布完成后，生成摘要并写入自定义字段
     *
     * 1.8 重要修正（白屏根因）：
     *  - 删掉 fastcgi_finish_request()。这个钩子在 Typecho 里是「发布成功之后、跳转之前」调用的，
     *    在这里提前结束响应，后面的 Notice::set() 和 $this->response->redirect() 全部发不出去，
     *    浏览器拿到的就是一个空白 200 → 后台白屏，成功提示也丢了。
     *  - 删掉 session_write_close()：Typecho 的提示信息存在 session 里，提前关闭同样会丢提示。
     *  - 整个方法用 try/catch 包住，任何异常都只写日志，绝不让保存动作挂掉。
     *
     * @access public
     * @param array $contents
     * @param Widget_Contents_Post_Edit $obj
     * @return array
     */
    public static function onFinishPublish($contents, $obj)
    {
        try {
            $plugin = self::pluginOptions();

            // 未开启自动生成时直接返回
            if (self::configValue($plugin, 'enableAuto', '1') !== '1') {
                return $contents;
            }

            if (!is_array($contents)) {
                return $contents;
            }

            $title = isset($contents['title']) ? (string) $contents['title'] : '';
            $text = isset($contents['text']) ? (string) $contents['text'] : '';
            $cid = self::resolveCid($obj, $contents);

            if ($cid <= 0 || trim($text) === '') {
                self::log('跳过生成：cid=' . $cid . ' 正文长度=' . strlen($text));
                return $contents;
            }

            // 已有摘要就直接跳过：既不重复消耗接口额度，也不会覆盖手工改过的摘要。
            // 想让某篇文章重新生成，把它的自定义字段 content 清空再保存一次即可。
            $existing = self::getSummary($cid);

            if ($existing !== '') {
                self::log(
                    '跳过生成：cid=' . $cid . ' 已有摘要（'
                    . (function_exists('mb_strlen') ? mb_strlen($existing, 'UTF-8') : strlen($existing))
                    . ' 字），不再调用接口'
                );
                return $contents;
            }

            // 摘要生成放在本次请求内同步完成（最长约 30 秒），保证跳转前字段已落库。
            // 不再用 fastcgi_finish_request 提前断响应，否则后台会白屏。
            @set_time_limit(120);

            $summary = self::callApi($title, $text, $plugin);

            if ($summary === '') {
                self::log('生成失败：cid=' . $cid . '（API 返回为空，原因见上方 API 日志）');
                self::notice('AI 摘要生成失败，可在「设置 → 插件 → AIContentSummary」点测试查看具体原因', 'error');
                return $contents;
            }

            self::saveSummary($cid, $summary);
            self::log('生成成功：cid=' . $cid . ' 摘要长度=' . (function_exists('mb_strlen') ? mb_strlen($summary, 'UTF-8') : strlen($summary)));
        } catch (\Throwable $e) {
            self::log('异常：' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }

        return $contents;
    }

    /**
     * 取文章 cid
     *
     * 重点：Typecho 的 Widget 属性是魔术属性，且 **没有实现 __isset**，
     * 所以 isset($obj->cid) 永远是 false —— 之前的版本正是因为这一句，
     * 每次都把 cid 判成 0 直接 return，摘要根本没生成过。必须直接读、用 try/catch 兜。
     *
     * @param mixed $obj
     * @param array $contents
     * @return int
     */
    private static function resolveCid($obj, $contents)
    {
        if (is_array($contents) && !empty($contents['cid'])) {
            return (int) $contents['cid'];
        }

        if (!is_object($obj)) {
            return 0;
        }

        try {
            $cid = (int) $obj->cid;
            if ($cid > 0) {
                return $cid;
            }
        } catch (\Throwable $e) {
            // 继续尝试下面的方式
        }

        // 再兜一层：有些版本用 theId / id 暴露
        foreach (array('theId', 'id') as $prop) {
            try {
                $cid = (int) $obj->$prop;
                if ($cid > 0) {
                    return $cid;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return 0;
    }

    /**
     * 给后台写一条提示（失败信息要能被看到，不能静默）
     *
     * @param string $message
     * @param string $type notice / success / error
     * @return void
     */
    private static function notice($message, $type = 'notice')
    {
        try {
            if (class_exists('Widget\Notice')) {
                \Widget\Notice::alloc()->set($message, $type);
            } elseif (class_exists('Typecho_Widget') && class_exists('Widget_Notice')) {
                \Widget_Notice::alloc()->set($message, $type);
            }
        } catch (\Throwable $e) {
            // 提示写不进去也不影响主流程
        }
    }

    /**
     * 写调试日志到插件目录 debug.log（只记关键节点与失败原因）
     *
     * @param string $message
     * @return void
     */
    private static function log($message)
    {
        try {
            $file = __DIR__ . '/debug.log';

            // 超过 200KB 就清空重来，避免无限增长
            if (is_file($file) && filesize($file) > 204800) {
                @file_put_contents($file, '');
            }

            @file_put_contents(
                $file,
                '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            // 日志失败无所谓
        }
    }

    /**
     * 读取某篇文章已保存的摘要（自定义字段 content）
     *
     * 用于「已有摘要就不再调用接口」的判断。字段不存在、为空字符串、
     * 或查询出错时统一返回空串，让主流程继续照常生成。
     *
     * @param int $cid
     * @return string
     */
    private static function getSummary($cid)
    {
        $cid = (int) $cid;

        if ($cid <= 0) {
            return '';
        }

        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select()->from('table.fields')
                    ->where('cid = ?', $cid)
                    ->where('name = ?', self::FIELD_NAME)
                    ->limit(1)
            );

            if (!$row) {
                return '';
            }

            $row = (array) $row;

            return isset($row['str_value']) ? trim((string) $row['str_value']) : '';
        } catch (\Throwable $e) {
            self::log('读取已有摘要失败：cid=' . $cid . ' ' . $e->getMessage());

            return '';
        }
    }

    /**
     * 写入 / 更新自定义字段
     *
     * @param int $cid
     * @param string $summary
     * @return void
     */
    private static function saveSummary($cid, $summary)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select()->from('table.fields')
                ->where('cid = ?', $cid)
                ->where('name = ?', self::FIELD_NAME)
        );

        if ($row) {
            $db->query(
                $db->update('table.fields')
                    ->rows(array('str_value' => $summary))
                    ->where('cid = ?', $cid)
                    ->where('name = ?', self::FIELD_NAME)
            );
            return;
        }

        $db->query(
            $db->insert('table.fields')->rows(array(
                'cid' => $cid,
                'name' => self::FIELD_NAME,
                'type' => 'str',
                'str_value' => $summary,
                'int_value' => 0,
                'float_value' => 0
            ))
        );
    }

    /**
     * 调用 AI 接口生成摘要（带重试）
     *
     * @access private
     * @param string $title 文章标题
     * @param string $text  文章正文
     * @param object $plugin 插件配置
     * @return string
     */
    private static function callApi($title, $text, $plugin)
    {
        if (!class_exists('AIContentSummary_Api')) {
            return '';
        }

        $modelName = trim(self::configValue($plugin, 'modelName', 'gpt-3.5-turbo-16k'));
        $keyValue = trim(self::configValue($plugin, 'keyValue'));
        $apiUrl = trim(self::configValue($plugin, 'apiUrl'));
        $maxLength = (int) self::configValue($plugin, 'maxLength', '100');

        if ($modelName === '') {
            $modelName = 'gpt-3.5-turbo-16k';
        }
        if ($maxLength <= 0) {
            $maxLength = 100;
        }
        if ($apiUrl === '' || $keyValue === '') {
            return '';
        }

        $endpoint = AIContentSummary_Api::buildEndpoint($apiUrl);

        // 正文先清洗：去掉 HTML / markdown 标记并限长，避免超长请求必然失败
        $plainText = AIContentSummary_Api::prepareText($text, 8000);

            // 采样温度交给 Api::buildPayload 判断：Kimi K2 / o 系等模型不接受 temperature=0
            $data = AIContentSummary_Api::buildPayload(
                $modelName,
                array(
                    array(
                        'role' => 'system',
                        'content' => '请你扮演一个文本摘要生成器，下面是一篇关于 “' . $title . '” 的文章，'
                            . '请你根据文章内容生成 ' . $maxLength . ' 字左右的摘要，'
                            . '除了你生成的摘要内容，请不要输出其他任何无关内容'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $plainText
                    )
                ),
                // 思考型模型（kimi-k3 等）要额外留推理预算，否则正文会被推理挤空
                array('max_tokens' => AIContentSummary_Api::maxTokensFor($modelName, $maxLength))
            );

        // 最多两轮：第一轮正文为空且是 finish=length（被截断）时，放大预算再试一次
        $attempts = 0;

        while ($attempts < 2) {
            $attempts++;
            $maxRetries = 2;
            $waitTime = 1;

            for ($retry = 0; $retry < $maxRetries; $retry++) {
                $response = AIContentSummary_Api::postChat($endpoint, $keyValue, $data, 40);

                if ($response['success'] && $response['httpCode'] == 200) {
                    $parsed = AIContentSummary_Api::parseReply($response['body']);

                    if ($parsed['reply'] !== '') {
                        return $parsed['reply'];
                    }

                    // 正文为空：先从推理残留里捞一份草稿
                    $fallback = AIContentSummary_Api::extractFromReasoning($parsed['reasoning'], $maxLength);

                    self::log(
                        'API 200 但正文为空：model=' . $modelName
                        . ' finish=' . $parsed['finish']
                        . ' max_tokens=' . $data['max_tokens']
                        . ' reasoning长度=' . strlen($parsed['reasoning'])
                        . ' 兜底提取=' . ($fallback === '' ? '无' : '有')
                    );

                    // 还能再试一次且确实是被长度截断 → 放大预算重来
                    if ($attempts < 2 && $parsed['finish'] === 'length') {
                        $buddy = max(8192, (int) $data['max_tokens'] * 4);
                        $data['max_tokens'] = min(32768, $buddy);
                        self::log('正文被 max_tokens 截断，放大到 ' . $data['max_tokens'] . ' 重试');
                        continue 2;
                    }

                    return $fallback;
                }

                $reason = $response['success']
                    ? 'http=' . $response['httpCode'] . ' body=' . AIContentSummary_Api::cut($response['body'], 300)
                    : 'network=' . $response['error'] . ' errno=' . $response['errno'];

                self::log('API 调用失败：model=' . $modelName . ' ' . $reason);

                // 4xx 是参数/权限问题，重试没意义，直接退出
                if ($response['success'] && $response['httpCode'] >= 400 && $response['httpCode'] < 500) {
                    return '';
                }

                if ($retry + 1 < $maxRetries) {
                    sleep($waitTime);
                }
            }

            break;
        }

        return '';
    }
}
