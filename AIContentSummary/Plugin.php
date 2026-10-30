<?php
/**
 * AIContentSummary 是一个用于通过调用 AI 接口，根据文章内容生成摘要的 Typecho 插件
 *
 * @package AIContentSummary
 * @author Andrew (Andrew8102)
 * @version 2.1.0
 * @link https://moechu.cn
 */

/*
 * ---------------------------------------------------------------------------
 * 说明：上方 docblock 供 Typecho\Plugin::parseInfo() 解析插件元信息，
 * 只允许出现它认识的字段：@package / @author / @link / @since / @version。
 * 其余以 @ 开头的键（如 @description、@category）会被静默丢弃；
 * 且在第一个 @ 字段之前的所有非空行都会被逐行拼进「描述」。
 * 所以变更记录、兼容说明、版权声明一律不许写在上方 docblock 里。
 *
 * 本段及下方变更记录都使用普通块注释（非 docblock）——
 * parseInfo 只读文件里第一个 DOC_COMMENT，普通块注释它不会解析。
 * 改完请跑：php _probe/tmp/hdr_guard.php _v21/AIContentSummary/Plugin.php
 * ---------------------------------------------------------------------------
 *
 * 兼容说明：
 *  - 运行环境：PHP 7.4 ~ 8.4（不使用 8.x 废弃语法，类型化/空值访问均已加防护）
 *  - 程序版本：Typecho 1.2 / 1.3
 *  - 字段与配置向下兼容：默认自定义字段仍是 content；配置项同时识别
 *    本插件历史键名（modelName / keyValue / apiUrl / maxLength / enableAuto）
 *    与上游 2.0 键名（fieldName / summaryLength / finishPublishSummary /
 *    outputSummaryInHeader / summaryTemplate / prompt），从任意方向升级都不丢配置。
 *
 * 版权与来源（Apache License 2.0）：
 *  本文件基于 Rockytkg/AIContentSummary 修改而来，原项目版权归 Rockytkg 所有，
 *  原项目地址 https://github.com/Rockytkg/AIContentSummary
 *  修改者 Andrew（https://moechu.cn ，GitHub @Andrew8102），修改时间 2026-10
 *  修改内容：PHP 8.4 兼容；Kimi K2/K3 等思考型模型的 temperature 与 max_tokens 适配；
 *           白屏与不生成摘要的修复；配置键双名兼容；后台面板与正文前置摘要的整合。
 *  修改部分同样以 Apache-2.0 发布，详见仓库 README。
 */

namespace TypechoPlugin\AIContentSummary;

use Typecho\Common;
use Typecho\Db;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Layout;
use Utils\Helper;
use Widget\Base\Contents;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 公共 API 逻辑（纯 PHP，不依赖 Typecho 类）
// 用 is_file 兜一层：万一只上传了 Plugin.php 而漏了 Api.php，
// 也不能让后台插件页直接白屏，只在测试/生成时提示缺文件。
if (is_file(__DIR__ . '/Api.php')) {
    require_once __DIR__ . '/Api.php';
}

// 注意：运行时清理失效钩子的代码放在文件末尾（类定义之后），
// 因为要调用 Plugin::pruneLegacyHooks()，此处类还没定义。

// 关于 activate() / deactivate() 的返回类型：
// Typecho 1.3 的 PluginInterface 里这两个方法**并未声明** `: void`，
// 而且 Widget\Plugins\Edit::activate() 里 `$result` 是被完全忽略的 ——
// 启用是否成功只看有没有抛 Plugin\Exception。所以方法体里不必 return 任何文案。
// 这里仍显式写 `: void`（合法的子类型细化），代价是方法体绝对不能 return 值，
// 否则会报「A void method must not return a value」——这正是 2.1.0 初稿踩过的坑。
//
// 注意：必须写成 implements 而不是 extends。Typecho\Plugin::parseInfo() 靠
// token_get_all() 里的 T_IMPLEMENTS 令牌来置位 $isClass，再用它判断
// activate/deactivate/config/personalConfig 四个方法是否存在；写成 extends
// 就不会产生 T_IMPLEMENTS，四个标记全为 false，后台插件页会把它归为「即插即用」，
// 连「禁用」「设置」链接都不显示。

/*
 * 变更记录（放在这里而不是文件头 docblock，避免被 parseInfo 拼进插件描述）
 *
 * 2.1.0 —— 吸收上游 2.0 的优点：
 *  - 代码结构对齐上游 2.0：命名空间 TypechoPlugin\AIContentSummary，
 *    拆为 Plugin.php（主逻辑）+ Action.php（后台动作）+ template/summaries.php（管理面板）。
 *    这样上游将来若更新，可以直接对照 diff 合并。
 *  - 新增后台「摘要管理」面板：列表显示所有文章的摘要，支持逐条就地编辑 / 清空 / 单篇生成，
 *    以及批量生成（串行，避免限流）。上游 2.0 的核心体验。
 *  - 新增「正文前置摘要」（contentEx 钩子）：可配置摘要模板，用 {summary} 占位符，
 *    默认输出 <aside class="ai-content-summary"> 结构。
 *  - 新增「系统提示词」可自定义；留空则用内置的中文摘要提示词。
 *  - 新增编辑页的「AI 摘要」自定义字段（管理员可见），手填即跳过自动生成。
 *  - 修正：类声明改用 implements PluginInterface，避免后台被判为「即插即用」。
 *
 * 1.8.2 —— 思考型模型的正文为空：
 *  - 现象：批量补生成时 kimi-k3 这类模型报「模型没有返回正文（finish_reason=length）」。
 *  - 根因：max_tokens 按「摘要字数 × 4」给（100 字 → 400），思考型模型光推理就用光预算。
 *  - 修正：识别思考型模型并给 4096~32768 预算；仍被截断时自动放大 4 倍重试一次；
 *    再不行就从 reasoning_content 里保守提取草稿，不让整批任务白跑。
 *
 * 1.8.1 —— 已有摘要的文章再次保存时直接跳过，不重复调接口、不覆盖手工修订。
 *
 * 1.8 —— 修复「保存文章白屏 + 从不生成摘要」：
 *  - 白屏根因：onFinishPublish 里调用了 fastcgi_finish_request()，提前结束响应，
 *    导致 Notice::set() 与跳转发不出去。已删除该调用与 session_write_close()。
 *  - 不生成摘要的根因：cid 用 isset($obj->cid) 判断，而 Typecho 的 Widget 属性是
 *    魔术属性且未实现 __isset，isset() 恒为 false → cid=0 → 直接 return。
 *
 * 1.7 —— 测试接口改为独立文件 test.php，不再依赖 Typecho 路由表（路由表存在数据库里，
 *  插件升级后旧路由不会自动消失，指向插件类会导致 500），并自动清理遗留的坏路由。
 */

final class Plugin implements PluginInterface
{
    /**
     * 插件名（也是后台面板与配置的标识，勿改）
     */
    public const NAME = 'AIContentSummary';

    /**
     * 后台面板模板（相对插件目录）
     */
    public const PANEL = 'AIContentSummary/template/summaries.php';

    /**
     * 后台动作名
     */
    public const ACTION = 'summaries';

    /**
     * 旧版测试路由名，仅用于清理
     */
    private const LEGACY_ROUTE = 'aicontentsummary_test';

    /**
     * 1.x 的扁平类名。旧版把钩子注册成 ['AIContentSummary_Plugin', 'customExcerpt']，
     * 升级到命名空间版本后这个类已不存在，残留在数据库里的回调会让整站 500。
     */
    private const LEGACY_CLASS = 'AIContentSummary_Plugin';

    /**
     * 日志文件（位于插件目录）
     */
    public const LOG_FILE = 'debug.log';

    /**
     * 注册前台渲染钩子、发布钩子、编辑页字段与后台管理入口。
     */
    public static function activate(): void
    {
        TypechoPlugin::factory('Widget\Base\Contents')->excerptEx = self::class . '::excerpt';
        TypechoPlugin::factory('Widget\Base\Contents')->contentEx = self::class . '::content';
        TypechoPlugin::factory('Widget\Contents\Post\Edit')->finishPublish = self::class . '::finishPublish';
        TypechoPlugin::factory('Widget\Contents\Post\Edit')->getDefaultFieldItems = self::class . '::addField';

        // 清掉 1.5/1.6 遗留的错误路由（那条路由指向插件类，访问必 500）
        self::cleanupLegacyRoute();

        // 清掉 1.x 遗留的钩子回调（旧扁平类 AIContentSummary_Plugin 已不存在，
        // 残留的 excerptEx 回调会让文章页抛「does not have a method customExcerpt」）
        self::cleanupLegacyHooks();

        Helper::addPanel(3, self::PANEL, _t('摘要管理'), _t('管理 AI 摘要'), 'administrator');
        Helper::addAction(self::ACTION, Action::class);
    }

    /**
     * 卸载插件时移除后台面板与动作路由。
     */
    public static function deactivate(): void
    {
        Helper::removePanel(3, self::PANEL);
        Helper::removeAction(self::ACTION);
        self::cleanupLegacyRoute();
        self::cleanupLegacyHooks();
    }

    /**
     * 构建插件配置表单。
     *
     * 键名沿用本插件历史命名（modelName / keyValue / apiUrl / maxLength / enableAuto），
     * 避免老用户升级后配置被清空；读取时通过 Api::cfg() 兼容上游 2.0 的键名。
     */
    public static function config(Form $form): void
    {
        $form->addInput(
            new Text(
                'modelName',
                null,
                'gpt-3.5-turbo-16k',
                _t('模型名称'),
                _t('请输入用于生成摘要的模型名，默认 gpt-3.5-turbo-16k。<br>'
                    . 'Kimi / Moonshot：kimi-k3.1-pro、kimi-k3、kimi-k2.6（地址填 https://api.moonshot.cn/v1）；'
                    . 'DeepSeek：deepseek-chat。Kimi 新模型只接受 temperature=1，插件已自动适配。')
            )
        );

        $form->addInput(
            new Text(
                'keyValue',
                null,
                '',
                _t('API KEY'),
                _t('输入调用 AI 接口所用的 API Key')
            )
        );

        $form->addInput(
            new Text(
                'apiUrl',
                null,
                '',
                _t('API 地址'),
                _t('请输入 API 地址，需包含 http:// 或 https://。是否带 /v1 都可以，插件会自动补全。')
            )
        );

        $form->addInput(
            new Text(
                'maxLength',
                null,
                '100',
                _t('摘要最大长度'),
                _t('请输入输出摘要的最大文字数量，留空按 100 处理；同时用于前台摘录截断。')
            )
        );

        $form->addInput(
            new Radio(
                'enableAuto',
                array('1' => _t('开启'), '0' => _t('关闭')),
                '1',
                _t('发布时自动生成摘要'),
                _t('开启后，文章发布完成会自动调用 AI 生成摘要并保存为自定义字段。<br>'
                    . '<strong>已有摘要的文章不会重复调用接口</strong>（避免重复消耗额度、避免覆盖手工修订的摘要）；'
                    . '想让某篇重新生成，清空它自定义字段里的摘要再保存一次，或用下面的「扫描并补生成」。')
            )
        );

        // ---------- 以下为 2.1.0 新增：吸收上游 2.0 的能力 ----------

        $form->addInput(
            new Textarea(
                'prompt',
                null,
                '',
                _t('系统提示词（可选）'),
                _t('留空则使用内置的中文摘要提示词（按上方的「摘要最大长度」自动填充字数要求）。<br>'
                    . '填写后将完全替代内置提示词，适合需要固定摘要风格或格式的场景。')
            )
        );

        $form->addInput(
            new Radio(
                'outputSummaryInHeader',
                array('1' => _t('开启'), '0' => _t('关闭')),
                '0',
                _t('正文前置摘要'),
                _t('开启后，文章正文顶部会渲染下方模板（用摘要内容替换 {summary}）。默认关闭，避免改变现有主题的排版。')
            )
        );

        $form->addInput(
            new Textarea(
                'summaryTemplate',
                null,
                '<aside class="ai-content-summary"><strong>摘要：</strong><p>{summary}</p></aside>',
                _t('前置摘要模板'),
                _t('必须包含 {summary} 占位符，摘要内容会被 HTML 转义后填入。')
            )
        );

        // 顺手清掉遗留的错误路由
        self::cleanupLegacyRoute();

        // 测试接口 = 插件目录下的独立文件，不经过 Typecho 路由表。
        // 只取 path，让浏览器以「当前后台页面同源」发起请求，彻底规避 CORS / http-https 混用。
        $pluginUrl = Helper::options()->pluginUrl;
        $testUrl = Common::url(self::NAME . '/test.php', $pluginUrl);
        $parsedPath = parse_url($testUrl);
        $testPath = isset($parsedPath['path']) ? $parsedPath['path'] : '/usr/plugins/AIContentSummary/test.php';

        // 令牌由 API KEY 派生（外部猜不到），防止接口被陌生请求拿去刷额度
        $apiReady = class_exists('AIContentSummary_Api');
        $keyForToken = '';

        if ($apiReady) {
            try {
                $keyForToken = \AIContentSummary_Api::cfg(Options::alloc()->plugin(self::NAME), 'key');
            } catch (\Throwable $e) {
                $keyForToken = '';
            }
        }

        $testPathJson = json_encode($testPath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $regenUrl = Common::url(self::NAME . '/regen.php', $pluginUrl);
        $regenParsed = parse_url($regenUrl);
        $regenPath = isset($regenParsed['path']) ? $regenParsed['path'] : '/usr/plugins/AIContentSummary/regen.php';
        $regenPathJson = json_encode($regenPath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tokenJson = json_encode($apiReady ? \AIContentSummary_Api::token($keyForToken) : '');
        $fileOkFlag = is_file(__DIR__ . '/test.php') ? 'true' : 'false';
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
        只处理「已发布且没有摘要字段」的文章，逐篇串行调用接口，页面保持打开即可。<br>
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
     * 插件未提供个人配置项，保留空实现以符合接口约定。
     */
    public static function personalConfig(Form $form): void
    {
    }

    /**
     * 在摘要存在时优先输出摘要作为 excerpt。
     *
     * 注意：Config 继承 stdClass 但值存在内部数组里，用 isset() / ?? 判断
     * 魔术属性永远是 false，所以这里必须直接取值并 try/catch 兜底。
     */
    public static function excerpt(string $excerpt, Contents $widget): string
    {
        $summary = self::postSummary($widget);
        if ($summary === '') {
            return $excerpt;
        }

        $maxLength = 100;

        try {
            $settings = Options::alloc()->plugin(self::NAME);
            $maxLength = \AIContentSummary_Api::maxLength($settings);
        } catch (\Throwable $e) {
            $maxLength = 100;
        }

        if (Common::strLen($summary) > $maxLength) {
            $summary = Common::subStr($summary, 0, $maxLength) . '...';
        }

        return $summary;
    }

    /**
     * 按配置将摘要插入正文顶部（上游 2.0 的正文前置摘要）。
     *
     * 默认关闭，开启后才有行为变化，不影响现有主题的排版。
     */
    public static function content(string $content, Contents $widget): string
    {
        try {
            $settings = Options::alloc()->plugin(self::NAME);
        } catch (\Throwable $e) {
            return $content;
        }

        // Config 的魔术属性不能用 isset/??，直接取值
        $enabled = '';
        try {
            $enabled = (string) $settings->outputSummaryInHeader;
        } catch (\Throwable $e) {
            $enabled = '';
        }

        if ($enabled !== '1') {
            return $content;
        }

        $summary = self::postSummary($widget);
        if ($summary === '') {
            return $content;
        }

        $template = '';
        try {
            $template = (string) $settings->summaryTemplate;
        } catch (\Throwable $e) {
            $template = '';
        }

        return \AIContentSummary_Api::wrapSummary($template, $summary) . $content;
    }

    /**
     * 发布完成后按需生成摘要。
     *
     * 重要：这个钩子在 Typecho 里是「发布成功之后、跳转之前」调用的。
     * 绝不能在这里调 fastcgi_finish_request() 或 session_write_close()，
     * 否则后面的 Notice::set() 与 redirect() 发不出去，后台直接白屏。
     */
    public static function finishPublish(array $contents, $editor): void
    {
        try {
            $settings = self::settings();

            if (\AIContentSummary_Api::cfg($settings, 'autoGenerate', '1') !== '1') {
                return;
            }

            $cid = self::resolveCid($editor, $contents);
            $text = isset($contents['text']) ? (string) $contents['text'] : '';

            if ($cid <= 0) {
                self::log('跳过生成：cid=0（未能从钩子参数取到 cid）');

                return;
            }

            if (trim($text) === '') {
                self::log('跳过生成：cid=' . $cid . ' 正文为空');

                return;
            }

            // 已有摘要直接跳过：既不重复消耗额度，也不覆盖手工改过的摘要
            if (self::getSummary($cid) !== '') {
                self::log('跳过生成：cid=' . $cid . ' 已有摘要，不再调用接口');

                return;
            }

            // 生成放在本次请求内同步完成，保证跳转前字段已落库
            @set_time_limit(120);

            $result = \AIContentSummary_Api::generateSummary(
                $settings,
                isset($contents['title']) ? (string) $contents['title'] : '',
                $text
            );

            if (!$result['ok']) {
                self::log('生成失败：cid=' . $cid . ' ' . $result['msg'] . ' ' . $result['detail']);
                self::notice('AI 摘要生成失败，可在「设置 → 插件 → AIContentSummary」点测试查看具体原因', 'error');

                return;
            }

            self::saveSummary($cid, $result['summary']);
            self::log('生成成功：cid=' . $cid . ' 摘要长度=' . Common::strLen($result['summary']));
        } catch (\Throwable $e) {
            // 任何异常都只写日志，绝不让保存动作挂掉
            self::log('异常：' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /**
     * 为管理员添加文章编辑页的「AI 摘要」字段。
     *
     * 注入的是默认自定义字段元素，最终会被 admin/custom-fields.php
     * 包装成字段名 / 字段值两列布局。
     */
    public static function addField(Layout $layout): void
    {
        if (!self::isAdministrator()) {
            return;
        }

        try {
            $settings = Options::alloc()->plugin(self::NAME);
        } catch (\Throwable $e) {
            return;
        }

        $field = new Textarea(
            \AIContentSummary_Api::fieldName($settings),
            null,
            null,
            _t('AI 摘要'),
            _t('手动填写后将优先作为文章摘要输出，同时跳过发布时的自动生成。')
        );

        // 自定义字段区域会把 textarea 放进 field-value 列，这里只控制输入框尺寸
        if (null !== $field->input) {
            $field->input->setAttribute('class', 'w-100');
        }

        $layout->addItem($field);
    }

    /**
     * 为没有摘要的文章生成摘要（发布钩子用）。
     */
    public static function ensureSummary(int $cid, string $text = ''): ?string
    {
        if ($cid <= 0) {
            return null;
        }

        if ($text === '') {
            $post = self::post($cid);
            $text = (string) $post['text'];
        }

        if (self::getSummary($cid) !== '') {
            return null;
        }

        $result = \AIContentSummary_Api::generateSummary(self::settings(), '', $text);
        if (!$result['ok']) {
            throw new \RuntimeException($result['msg'] . ($result['detail'] !== '' ? '：' . $result['detail'] : ''));
        }

        self::saveSummary($cid, $result['summary']);

        return $result['summary'];
    }

    /**
     * 生成并保存摘要（后台单篇生成用）。
     *
     * @return array{cid:int,summary:string,length:int,hasSummary:bool}
     */
    public static function generateForPost(int $cid): array
    {
        $post = self::post($cid);
        $result = \AIContentSummary_Api::generateSummary(
            self::settings(),
            (string) $post['title'],
            (string) $post['text']
        );

        if (!$result['ok']) {
            throw new \RuntimeException($result['msg'] . ($result['detail'] !== '' ? '：' . $result['detail'] : ''));
        }

        self::saveSummary($cid, $result['summary']);

        return self::summaryResponse($cid, $result['summary']);
    }

    /**
     * 手动保存或清空摘要。
     *
     * @return array{cid:int,summary:string,length:int,hasSummary:bool}
     */
    public static function saveManual(int $cid, string $summary): array
    {
        // 先校验文章存在，避免为失效 cid 写入自定义字段
        self::post($cid);

        $summary = trim($summary);
        $fieldName = \AIContentSummary_Api::fieldName(self::settings());

        if ($summary === '') {
            // 手动清空时直接删除字段，前台渲染可自然回退到默认摘要逻辑
            $db = Db::get();
            $db->query(
                $db->delete('table.fields')
                    ->where('cid = ?', $cid)
                    ->where('name = ?', $fieldName)
            );

            return self::summaryResponse($cid, '');
        }

        self::saveSummary($cid, $summary);

        return self::summaryResponse($cid, $summary);
    }

    /**
     * 读取某篇文章的摘要（供面板与接口使用）。
     */
    public static function getSummary(int $cid): string
    {
        if ($cid <= 0) {
            return '';
        }

        try {
            $db = Db::get();
            $row = $db->fetchRow(
                $db->select('str_value')
                    ->from('table.fields')
                    ->where('cid = ?', $cid)
                    ->where('name = ?', \AIContentSummary_Api::fieldName(self::settings()))
                    ->limit(1)
            );
        } catch (\Throwable $e) {
            self::log('读取已有摘要失败：cid=' . $cid . ' ' . $e->getMessage());

            return '';
        }

        if (!$row) {
            return '';
        }

        $row = (array) $row;

        return isset($row['str_value']) ? trim((string) $row['str_value']) : '';
    }

    /**
     * 当前的摘要字段名（面板列名与接口共用）。
     */
    public static function currentFieldName(): string
    {
        return \AIContentSummary_Api::fieldName(self::settings());
    }

    /**
     * 取插件配置；未保存过配置时返回空对象而不是抛异常。
     *
     * @return object
     */
    public static function settings()
    {
        try {
            $settings = Options::alloc()->plugin(self::NAME);

            return is_object($settings) ? $settings : new \stdClass();
        } catch (\Throwable $e) {
            return new \stdClass();
        }
    }

    /**
     * 写一行日志（失败静默，绝不因为日志影响主流程）。
     */
    public static function log(string $message): void
    {
        try {
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
            @file_put_contents(__DIR__ . '/' . self::LOG_FILE, $line, FILE_APPEND);
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    /**
     * 判断当前用户是否为管理员。
     */
    public static function isAdministrator(): bool
    {
        try {
            return (bool) User::alloc()->pass('administrator', true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 读取文章记录（仅允许正式文章与草稿）。
     *
     * @return array<string, mixed>
     * @throws \Typecho\Widget\Exception
     */
    public static function post(int $cid): array
    {
        if ($cid <= 0) {
            throw new \Typecho\Widget\Exception(_t('无效的文章 ID'), 400);
        }

        $db = Db::get();
        $post = $db->fetchRow(
            $db->select('cid', 'title', 'text')
                ->from('table.contents')
                ->where('cid = ?', $cid)
                ->where('type IN ?', array('post', 'post_draft'))
                ->limit(1)
        );

        if (!$post) {
            throw new \Typecho\Widget\Exception(_t('文章不存在'), 404);
        }

        return (array) $post;
    }

    /**
     * 取文章 cid。
     *
     * 重点：Typecho 的 Widget 属性是魔术属性，且 **没有实现 __isset**，
     * 所以 isset($obj->cid) 永远是 false —— 1.8 之前的版本正是因为这一句，
     * 每次都把 cid 判成 0 直接 return，摘要根本没生成过。
     */
    private static function resolveCid($editor, array $contents): int
    {
        if (isset($contents['cid']) && (int) $contents['cid'] > 0) {
            return (int) $contents['cid'];
        }

        if (!is_object($editor)) {
            return 0;
        }

        foreach (array('cid', 'theId', 'id') as $prop) {
            try {
                $value = (int) $editor->$prop;
                if ($value > 0) {
                    return $value;
                }
            } catch (\Throwable $e) {
                // 继续尝试下一个属性
            }
        }

        return 0;
    }

    /**
     * 从内容 Widget 上取当前文章的摘要。
     */
    private static function postSummary(Contents $widget): string
    {
        // 同样的坑：Config 的魔术属性不能用 isset/?? 判断
        $fieldName = self::currentFieldName();
        $summary = null;

        try {
            $fields = $widget->fields;
            if (is_object($fields)) {
                $summary = $fields->{$fieldName};
            }
        } catch (\Throwable $e) {
            $summary = null;
        }

        return is_string($summary) ? trim($summary) : '';
    }

    /**
     * 保存摘要字段（存在则更新，不存在则插入，保持幂等）。
     */
    private static function saveSummary(int $cid, string $summary): void
    {
        $summary = trim($summary);
        $fieldName = \AIContentSummary_Api::fieldName(self::settings());
        $db = Db::get();

        $exists = $db->fetchRow(
            $db->select('cid')
                ->from('table.fields')
                ->where('cid = ?', $cid)
                ->where('name = ?', $fieldName)
                ->limit(1)
        );

        if ($exists) {
            // 编辑、重新生成时优先更新已有字段，避免产生重复 name/cid 记录
            $db->query(
                $db->update('table.fields')
                    ->rows(array(
                        'type' => 'str',
                        'str_value' => $summary,
                        'int_value' => 0,
                        'float_value' => 0
                    ))
                    ->where('cid = ?', $cid)
                    ->where('name = ?', $fieldName)
            );

            return;
        }

        $db->query(
            $db->insert('table.fields')->rows(array(
                'cid' => $cid,
                'name' => $fieldName,
                'type' => 'str',
                'str_value' => $summary,
                'int_value' => 0,
                'float_value' => 0
            ))
        );
    }

    /**
     * 组装面板/接口共用的返回结构。
     *
     * @return array{cid:int,summary:string,length:int,hasSummary:bool}
     */
    private static function summaryResponse(int $cid, string $summary): array
    {
        $summary = trim($summary);

        return array(
            'cid' => $cid,
            'summary' => $summary,
            'length' => Common::strLen($summary),
            'hasSummary' => $summary !== ''
        );
    }

    /**
     * 清理 1.5/1.6 遗留的测试路由。
     *
     * 那条路由的 widget 指向插件类（非 Widget），一旦被访问就会抛
     * 「Call to undefined method ...::execute()」。1.7 起测试改走独立文件，
     * 因此把残留路由删掉即可。
     */
    private static function cleanupLegacyRoute(): void
    {
        try {
            Helper::removeRoute(self::LEGACY_ROUTE);
        } catch (\Throwable $e) {
            // 清理失败不影响插件功能
        }
    }

    /**
     * 清理 1.x 遗留的钩子回调（写库）。
     *
     * 背景：1.x 用扁平类 AIContentSummary_Plugin，钩子挂在旧式 handle 名上
     * （Widget_Abstract_Contents:excerptEx）；2.x 改成带命名空间的类，钩子挂在新式
     * handle 名上（Widget\Base\Contents:excerptEx）。**两者是完全不同的键**——
     * Typecho 的 Common::nativeClassName() 把 `\` 换成 `_`，旧类名与新类名转换后不等，
     * 所以新版 activate() 只是新增键，旧键原封不动留在数据库的 options.plugins 里。
     *
     * 而 Typecho\Plugin::filter() 是直接 call_user_func_array()，并不校验 callback 是否可调用，
     * 于是旧回调 ['AIContentSummary_Plugin','customExcerpt'] 在类已不存在时会抛
     * 「does not have a method "customExcerpt"」，整站文章页 500。
     *
     * Typecho 自带的 Plugin::deactivate() 只清理「当前插件名下登记的 handles」，
     * 旧版本登记在新旧不同的键上，它清不到。这里直接把残留项从库里摘掉。
     */
    private static function cleanupLegacyHooks(): void
    {
        try {
            $db = Db::get();
            $row = $db->fetchRow(
                $db->select('value')->from('table.options')->where('name = ?', 'plugins')
            );

            if (empty($row['value'])) {
                return;
            }

            $plugins = json_decode($row['value'], true);
            if (!is_array($plugins)) {
                return;
            }

            $pruned = self::pruneLegacyHooks($plugins);
            if ($pruned === $plugins) {
                return;
            }

            $db->query(
                $db->update('table.options')
                    ->rows(array('value' => json_encode($pruned)))
                    ->where('name = ?', 'plugins')
            );

            self::log('已清理 1.x 遗留钩子回调（' . self::LEGACY_CLASS . '）');
        } catch (\Throwable $e) {
            // 清理失败不影响插件功能，但记一笔便于排查
            self::log('清理遗留钩子失败：' . $e->getMessage());
        }
    }

    /**
     * 纯函数：返回剔除 1.x 遗留回调后的插件配置。
     *
     * 供两处共用 ——
     *  - cleanupLegacyHooks()：清理数据库（持久）
     *  - 插件文件加载时：清理内存里的静态表（当次立即生效，已升级的站点不必重新启用）
     *
     * 输入输出结构就是 Plugin::export() / options.plugins 的 json 结构。
     * 没有需要剔除的项时原样返回（调用方可用 !== 判断是否需要写库）。
     */
    public static function pruneLegacyHooks(array $plugins): array
    {
        if (empty($plugins['handles']) && empty($plugins['activated'])) {
            return $plugins;
        }

        // 1.x 遗留的扁平类名
        $isLegacy = function ($callback): bool {
            if (is_array($callback) && isset($callback[0]) && is_string($callback[0])) {
                return ltrim($callback[0], '\\') === self::LEGACY_CLASS;
            }

            if (is_string($callback) && strpos($callback, self::LEGACY_CLASS) !== false) {
                return true;
            }

            return false;
        };

        // 剔掉一组回调里的遗留项；全被剔光就返回 null 表示整个 handle 该删
        $filter = function ($callbacks) use ($isLegacy) {
            if (!is_array($callbacks)) {
                return $callbacks;
            }

            $kept = array();
            foreach ($callbacks as $callback) {
                if (!$isLegacy($callback)) {
                    $kept[] = $callback;
                }
            }

            if (count($kept) === count($callbacks)) {
                return $callbacks; // 没变化，保持原值
            }

            return empty($kept) ? null : array_values($kept);
        };

        // 1) 主 hook 表
        if (!empty($plugins['handles']) && is_array($plugins['handles'])) {
            foreach ($plugins['handles'] as $handle => $callbacks) {
                $result = $filter($callbacks);
                if ($result === $callbacks) {
                    continue;
                }
                if ($result === null) {
                    unset($plugins['handles'][$handle]);
                } else {
                    $plugins['handles'][$handle] = $result;
                }
            }
        }

        // 2) activated 里登记的各插件 handles（停用时要靠它做 diff，留着旧项会出错）
        if (!empty($plugins['activated']) && is_array($plugins['activated'])) {
            foreach ($plugins['activated'] as $name => $meta) {
                if (empty($meta['handles']) || !is_array($meta['handles'])) {
                    continue;
                }

                foreach ($meta['handles'] as $handle => $callbacks) {
                    $result = $filter($callbacks);
                    if ($result === $callbacks) {
                        continue;
                    }
                    if ($result === null) {
                        unset($plugins['activated'][$name]['handles'][$handle]);
                    } else {
                        $plugins['activated'][$name]['handles'][$handle] = $result;
                    }
                }

                if (empty($plugins['activated'][$name]['handles'])) {
                    unset($plugins['activated'][$name]['handles']);
                }
            }
        }

        return $plugins;
    }
}

/*
 * 运行时兜底：清掉内存里的失效钩子回调（放在类定义之后才能调用 Plugin::pruneLegacyHooks）。
 *
 * 为什么需要「运行时」而不是只在 activate() 里清一次：
 * 已经升级上来、插件仍是启用状态的站点不会重新走 activate()，而数据库里的
 * options.plugins 还留着 1.x 的 ['AIContentSummary_Plugin','customExcerpt']。
 * Typecho\Plugin::filter() 直接 call_user_func_array()，不校验可调用性，
 * 于是文章页会抛 TypeError 整站 500 —— 用户看到的就是
 * 「class TypechoPlugin\AIContentSummary\Plugin does not have a method "customExcerpt"」。
 *
 * 这里在插件文件被加载时把静态 $plugin 表过滤一遍并回写，当次请求立刻恢复；
 * 数据库的持久清理由 activate() / deactivate() 里的 cleanupLegacyHooks() 负责。
 * 整段包 try/catch：兜底代码本身绝不能成为新的故障点。
 */
try {
    if (class_exists('\Typecho\Plugin') && class_exists(__NAMESPACE__ . '\Plugin', false)) {
        $GLOBALS['__aics_raw_hooks'] = \Typecho\Plugin::export();
        $GLOBALS['__aics_pruned_hooks'] = Plugin::pruneLegacyHooks($GLOBALS['__aics_raw_hooks']);

        if ($GLOBALS['__aics_pruned_hooks'] !== $GLOBALS['__aics_raw_hooks']) {
            \Typecho\Plugin::init($GLOBALS['__aics_pruned_hooks']);
        }
    }
} catch (\Throwable $e) {
    // 兜底失败也不能影响页面
} finally {
    unset($GLOBALS['__aics_raw_hooks'], $GLOBALS['__aics_pruned_hooks']);
}
