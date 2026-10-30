<?php
/**
 * AIContentSummary 公共 API 逻辑
 *
 * 该文件不依赖任何 Typecho 类，可被插件（Plugin.php）、
 * 路由版接口（Action.php）和独立接口（test.php）共同加载。
 *
 * 版权与来源：基于 Rockytkg/AIContentSummary 修改，原项目版权归 Rockytkg 所有；
 * 修改者 Andrew（https://moechu.cn），Apache-2.0。
 *
 * @package AIContentSummary
 * @author Andrew (Andrew8102)
 * @version 1.8.2
 * @link https://moechu.cn
 */

if (!class_exists('AIContentSummary_Api')) {

    class AIContentSummary_Api
    {
        /**
         * 默认模型
         */
        const DEFAULT_MODEL = 'gpt-3.5-turbo-16k';

        /**
         * 安全读取配置值
         *
         * Typecho 的 Config 继承 stdClass 但值存在内部数组里，
         * 用 isset() / ?? 判断魔术属性永远为 false，所以必须直接取值并 try/catch。
         *
         * @param mixed  $config  插件配置（Config 对象或数组）
         * @param string $name    键名
         * @param string $default 默认值
         * @return string
         */
        public static function opt($config, $name, $default = '')
        {
            if (is_array($config)) {
                $value = array_key_exists($name, $config) ? $config[$name] : null;
            } elseif (is_object($config)) {
                try {
                    $value = $config->$name;
                } catch (\Throwable $e) {
                    return $default;
                }
            } else {
                return $default;
            }

            if ($value === null || $value === '') {
                return $default;
            }

            return (string) $value;
        }

        /**
         * 组装 chat/completions 地址，容忍用户多填/少填 /v1
         *
         * @param string $apiUrl
         * @return string
         */
        public static function buildEndpoint($apiUrl)
        {
            $apiUrl = rtrim(trim((string) $apiUrl), '/');

            if (preg_match('#/chat/completions$#i', $apiUrl)) {
                return $apiUrl;
            }

            if (preg_match('#/v1$#i', $apiUrl)) {
                return $apiUrl . '/chat/completions';
            }

            return $apiUrl . '/v1/chat/completions';
        }

        /**
         * 生成测试接口的校验令牌（由 API KEY 派生，外部无法猜测）
         *
         * @param string $apiKey
         * @return string
         */
        public static function token($apiKey)
        {
            return substr(sha1('aicontentsummary|' . (string) $apiKey), 0, 24);
        }

        /**
         * 该模型是否「只接受默认 temperature」
         *
         * 部分新模型（Kimi K2 / kimi-thinking、OpenAI o 系、gpt-5 系）不接受自定义采样温度，
         * 传 temperature=0 会直接 400：invalid temperature: only 1 is allowed for this model。
         * 这类模型一律不传 temperature，交给服务端默认值。
         *
         * @param string $model
         * @return bool
         */
        public static function isFixedTemperature($model)
        {
            $model = strtolower(trim((string) $model));
            if ($model === '') {
                return false;
            }

            // 关键词命中：
            //  - Kimi / Moonshot 全系（kimi-k3.1-pro、kimi-k3、kimi-k2.x、kimi-k2-*-preview …）
            //    新模型普遍只接受 temperature=1，传 0 直接 400
            //  - 各类 thinking / reasoning 模型
            $keywords = array('kimi', 'moonshot', 'thinking', 'reasoning');
            foreach ($keywords as $kw) {
                if (strpos($model, $kw) !== false) {
                    return true;
                }
            }

            // 去掉 vendor 前缀后按开头判断（openai/o3-mini、azure/gpt-5 之类）
            $core = $model;
            $pos = strrpos($core, '/');
            if ($pos !== false) {
                $core = substr($core, $pos + 1);
            }

            return (bool) preg_match('/^(o[1-9]|gpt-5)/', $core);
        }

        /**
         * 该模型是否是「先推理、再作答」的思考型模型
         *
         * 这类模型会先把大量 token 花在 reasoning_content / thinking 上，
         * max_tokens 给小了就会被推理阶段吃光，正文变成空字符串、finish_reason=length。
         *
         * @param string $model
         * @return bool
         */
        public static function isThinkingModel($model)
        {
            $model = strtolower(trim((string) $model));
            if ($model === '') {
                return false;
            }

            $keywords = array('kimi-k3', 'kimi-k2', 'kimi-thinking', 'thinking', 'reasoning', 'reasoner');
            foreach ($keywords as $kw) {
                if (strpos($model, $kw) !== false) {
                    return true;
                }
            }

            // OpenAI o 系 / gpt-5 系（去掉 vendor/ 前缀后判断）
            $core = preg_replace('#^[^/]+/#', '', $model);

            return (bool) preg_match('/^(o[1-9]|gpt-5)/', $core);
        }

        /**
         * 计算 max_tokens
         *
         * 普通模型按「摘要字数 × 4」给，一个中文字大约 1~2 token，留足余量；
         * 思考型模型必须额外准备推理预算，否则正文永远出不来。
         *
         * @param string $model
         * @param int    $maxLength 期望摘要字数
         * @return int
         */
        public static function maxTokensFor($model, $maxLength)
        {
            $maxLength = (int) $maxLength;
            if ($maxLength <= 0) {
                $maxLength = 100;
            }

            if (self::isThinkingModel($model)) {
                // 推理 + 正文：32768 是 Kimi / 多数思考模型都能接受的值
                return max(4096, min(32768, $maxLength * 8 + 4096));
            }

            return max(256, min(2048, $maxLength * 4));
        }

        /**
         * 组装 chat/completions 请求体
         *
         * @param string $model
         * @param array  $messages
         * @param array  $extra 附加参数（max_tokens 等）
         * @return array
         */
        public static function buildPayload($model, array $messages, array $extra = array())
        {
            $payload = array(
                'model' => (string) $model,
                'messages' => $messages
            );

            // 只对支持自定义温度的模型传 0，其余交给服务端默认值
            if (!self::isFixedTemperature($model)) {
                $payload['temperature'] = 0;
            }

            foreach ($extra as $key => $value) {
                $payload[$key] = $value;
            }

            return $payload;
        }

        /**
         * 请求体是否因为 temperature 不合法被拒
         *
         * @param int    $httpCode
         * @param string $body
         * @return bool
         */
        public static function isTemperatureError($httpCode, $body)
        {
            $httpCode = (int) $httpCode;
            if ($httpCode !== 400 && $httpCode !== 422) {
                return false;
            }

            return stripos((string) $body, 'temperature') !== false;
        }

        /**
         * 带 temperature 兼容回退的 chat 请求
         *
         * 先按常规请求；若服务端抱怨 temperature，则依次尝试 temperature=1、
         * 以及完全不带 temperature，避免因为一个采样参数让整站摘要功能哑掉。
         *
         * @param string $endpoint
         * @param string $apiKey
         * @param array  $payload
         * @param int    $timeout
         * @return array 同 request()，命中回退时多一个 adjusted 字段
         */
        public static function postChat($endpoint, $apiKey, array $payload, $timeout = 30)
        {
            $result = self::request($endpoint, $apiKey, $payload, $timeout);

            if (!$result['success'] || !self::isTemperatureError($result['httpCode'], $result['body'])) {
                return $result;
            }

            $first = (string) $result['body'];

            // 回退 1：显式 temperature = 1
            $retry = $payload;
            $retry['temperature'] = 1;
            $second = self::request($endpoint, $apiKey, $retry, $timeout);
            if ($second['success'] && !self::isTemperatureError($second['httpCode'], $second['body'])) {
                $second['adjusted'] = 'temperature=1';
                return $second;
            }

            // 回退 2：完全不传 temperature
            unset($retry['temperature']);
            $third = self::request($endpoint, $apiKey, $retry, $timeout);
            if ($third['success'] && !self::isTemperatureError($third['httpCode'], $third['body'])) {
                $third['adjusted'] = 'omit temperature';
                return $third;
            }

            // 都不行：把最初的报错还回去，信息更贴近真实原因
            $result['adjusted'] = '';
            $result['firstError'] = $first;
            return $result;
        }

        /**
         * 清洗正文：去掉 markdown 标记、HTML 标签、多余空白，并限制长度
         *
         * 直接把整篇 HTML 丢给模型既浪费 token 也容易触发长度上限，
         * 摘要只需要正文语言，标签全部剥掉。
         *
         * @param string $text
         * @param int    $limit 最大字符数（按 UTF-8 字符算）
         * @return string
         */
        public static function prepareText($text, $limit = 8000)
        {
            $text = (string) $text;

            // Typecho 的 markdown 标记 / 摘要分隔符
            $text = str_replace(array('<!--markdown-->', '<!--more-->'), ' ', $text);

            // 脚本、样式整段删掉，避免把 CSS/JS 当正文
            $text = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $text);
            $text = strip_tags((string) $text);
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

            // &nbsp; / 全角空格会解码成特殊空白字符，先统一成普通空格
            $text = str_replace(array("\xC2\xA0", "\xE3\x80\x80"), ' ', $text);
            // 不加 /u，避免非法 UTF-8 让 preg_replace 返回 null
            $text = preg_replace('/[ \t\r\n]+/', ' ', $text);
            $text = trim((string) $text);

            if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
                $text = mb_substr($text, 0, (int) $limit, 'UTF-8');
            }

            return $text;
        }

        /**
         * 从 chat/completions 响应里取出正文与结束原因
         *
         * @param string $body
         * @return array{reply:string,finish:string,error:string}
         */
        public static function parseReply($body)
        {
            $json = json_decode((string) $body, true);

            $reply = '';
            $finish = '';
            $error = '';
            $reasoning = '';

            if (is_array($json)) {
                if (isset($json['choices'][0]['message']['content'])) {
                    $reply = trim((string) $json['choices'][0]['message']['content']);
                }
                if (isset($json['choices'][0]['message']['reasoning_content'])) {
                    $reasoning = trim((string) $json['choices'][0]['message']['reasoning_content']);
                }
                if (isset($json['choices'][0]['finish_reason'])) {
                    $finish = (string) $json['choices'][0]['finish_reason'];
                }
                if (isset($json['error']['message'])) {
                    $error = (string) $json['error']['message'];
                } elseif (isset($json['message']) && is_string($json['message'])) {
                    $error = (string) $json['message'];
                }
            }

            return array('reply' => $reply, 'finish' => $finish, 'error' => $error, 'reasoning' => $reasoning);
        }

        /**
         * 从残留的推理文字里捞出可用摘要（最后兜底）
         *
         * 思考型模型的 max_tokens 被推理吃光时，正文是空的，但 reasoning_content 里
         * 往往已经把文章要点复述了一遍。这里按段落挑出「像正文」的那几段拼成摘要，
         * 既保住这次请求的成本，也让作者有个能改的初稿。
         *
         * 只做保守提取：剔除指令复述、标题行、列表标记，命中不了就返回空串。
         *
         * @param string $reasoning
         * @param int    $maxLength
         * @return string
         */
        public static function extractFromReasoning($reasoning, $maxLength = 100)
        {
            $text = trim((string) $reasoning);
            if ($text === '') {
                return '';
            }

            $maxLength = (int) $maxLength;
            if ($maxLength <= 0) {
                $maxLength = 100;
            }

            $paragraphs = preg_split('/\r\n|\r|\n/', $text);
            if (!is_array($paragraphs)) {
                return '';
            }

            // 明显的指令/元信息行，不能当摘要。
            // 注意：只拦「我要做什么」这类叙述句，不拦「这篇文章……」这种正常摘要开头，
            // 否则会把模型真正写好的摘要第一段误杀。
            $banned = '/^(the user|user|i |i\'?ll|i will|let me|let\'s|first,|first |step \d|okay|ok,|'
                . 'sure|here|we |we\'?ll|now |next,|开始|首先|用户|我需要|我将|让我|好的|现在)/iu';

            $picked = array();

            foreach ($paragraphs as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }

                // 去掉列表/标题标记
                $p = preg_replace('/^\s*(?:[#>*\-+]|\d+[.)、])\s*/u', '', $p);
                $p = trim((string) $p);

                $len = function_exists('mb_strlen') ? mb_strlen($p, 'UTF-8') : strlen($p);
                // 太短的碎片、纯英文元叙述、以冒号结尾的过渡句都跳过
                if ($len < 12 || substr($p, -1) === ':' || substr($p, -1) === '：') {
                    continue;
                }

                if (preg_match($banned, $p)) {
                    continue;
                }

                // 至少要有一定比例的中文，避免抓到英文思考过程
                $cn = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $p);
                if ($cn < 8) {
                    continue;
                }

                $picked[] = $p;

                $joined = implode('', $picked);
                if (mb_strlen($joined, 'UTF-8') >= $maxLength) {
                    break;
                }
            }

            if (empty($picked)) {
                return '';
            }

            $out = trim(implode('', $picked));
            // 去掉模型爱加的前后缀包裹
            $out = preg_replace('/^["“「]+|["”」]+$/u', '', $out);
            $out = trim((string) $out);

            if (mb_strlen($out, 'UTF-8') > $maxLength) {
                $out = mb_substr($out, 0, $maxLength, 'UTF-8');
            }

            return $out;
        }

        /**
         * 截断字符串
         *
         * @param string $str
         * @param int    $len
         * @return string
         */        public static function cut($str, $len)
        {
            $str = (string) $str;
            if (function_exists('mb_substr')) {
                return mb_substr($str, 0, (int) $len, 'UTF-8');
            }
            return substr($str, 0, (int) $len);
        }

        /**
         * curl 错误码给出可操作的提示
         *
         * @param int $errno
         * @return string
         */
        public static function curlHint($errno)
        {
            switch ((int) $errno) {
                case 6:
                    return '域名解析失败：API 地址拼写是否正确？';
                case 7:
                    return '无法连接：目标端口不通，或被服务器防火墙拦截。';
                case 28:
                    return '请求超时：网络慢或 API 无响应，可稍后重试。';
                case 35:
                case 51:
                case 60:
                    return 'SSL 证书校验失败：检查服务器时间是否准确、证书链是否完整（宝塔可更新 CA 证书）。';
                default:
                    return '';
            }
        }

        /**
         * 统一的 curl POST
         *
         * @param string $url
         * @param string $apiKey
         * @param array  $data
         * @param int    $timeout
         * @return array{success:bool,httpCode:int,body:string,error:string,errno:int}
         */
        public static function request($url, $apiKey, array $data, $timeout = 30)
        {
            if (!function_exists('curl_init')) {
                return array(
                    'success' => false,
                    'httpCode' => 0,
                    'body' => '',
                    'error' => '服务器没有启用 curl 扩展（宝塔：软件商店 → PHP → 安装扩展 curl）',
                    'errno' => -2
                );
            }

            $payload = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($payload === false) {
                return array(
                    'success' => false,
                    'httpCode' => 0,
                    'body' => '',
                    'error' => '请求体 JSON 编码失败：' . json_last_error_msg(),
                    'errno' => -1
                );
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $apiKey
                ),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => (int) $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'Typecho-AIContentSummary/1.8.2',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ));

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = (int) curl_errno($ch);
            $error = $errno ? curl_error($ch) : '';

            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }
            unset($ch);

            if ($errno) {
                return array(
                    'success' => false,
                    'httpCode' => 0,
                    'body' => '',
                    'error' => $error,
                    'errno' => $errno
                );
            }

            return array(
                'success' => true,
                'httpCode' => $httpCode,
                'body' => (string) $body,
                'error' => '',
                'errno' => 0
            );
        }

        /**
         * 执行一次连通性测试，返回可直接 JSON 化的结果
         *
         * @param array $cfg 插件配置（数组）
         * @return array
         */
        public static function runTest($cfg)
        {
            $apiUrl = trim(self::opt($cfg, 'apiUrl'));
            $apiKey = trim(self::opt($cfg, 'keyValue'));
            $model = trim(self::opt($cfg, 'modelName', self::DEFAULT_MODEL));

            if ($apiUrl === '' || $apiKey === '') {
                return array(
                    'ok' => false,
                    'msg' => 'API 地址或 API KEY 为空（改完配置记得点页面底部「保存设置」再测试）',
                    'stage' => 'config'
                );
            }

            if (!preg_match('#^https?://#i', $apiUrl)) {
                return array(
                    'ok' => false,
                    'msg' => 'API 地址必须以 http:// 或 https:// 开头',
                    'stage' => 'config'
                );
            }

            if ($model === '') {
                $model = self::DEFAULT_MODEL;
            }

            $endpoint = self::buildEndpoint($apiUrl);
            $payload = self::buildPayload(
                $model,
                array(
                    array('role' => 'user', 'content' => '只回复四个字：测试成功')
                ),
                array('max_tokens' => 128)
            );

            $start = microtime(true);
            $result = self::postChat($endpoint, $apiKey, $payload, 30);
            $cost = (int) round((microtime(true) - $start) * 1000);

            if (!$result['success']) {
                return array(
                    'ok' => false,
                    'msg' => '网络层请求失败：' . $result['error'],
                    'stage' => 'network',
                    'endpoint' => $endpoint,
                    'cost' => $cost . 'ms',
                    'hint' => self::curlHint($result['errno'])
                );
            }

            $body = (string) $result['body'];
            $json = json_decode($body, true);
            $reply = '';
            $finish = '';

            if (is_array($json) && isset($json['choices'][0]['message']['content'])) {
                $reply = trim((string) $json['choices'][0]['message']['content']);
            }
            if (is_array($json) && isset($json['choices'][0]['finish_reason'])) {
                $finish = (string) $json['choices'][0]['finish_reason'];
            }

            // 思考型模型（Kimi K3 / o 系等）可能把 max_tokens 全用在推理上，
            // 内容为空但请求其实已经成功了，这种情况不该报「测试未通过」。
            if ($result['httpCode'] == 200 && $reply === '' && $finish !== '') {
                return array(
                    'ok' => true,
                    'msg' => $finish === 'length'
                        ? 'API 连通正常（回复被 max_tokens 截断，正文较短时不影响摘要生成）'
                        : 'API 连通正常（模型未返回正文，finish_reason=' . $finish . '）',
                    'stage' => 'ok',
                    'endpoint' => $endpoint,
                    'model' => $model,
                    'reply' => '（空）',
                    'httpCode' => $result['httpCode'],
                    'temperature' => self::isFixedTemperature($model) ? '默认（该模型不支持自定义）'
                        : (isset($result['adjusted']) ? '已自动回退：' . $result['adjusted'] : '0'),
                    'cost' => $cost . 'ms'
                );
            }

            if ($result['httpCode'] != 200 || $reply === '') {
                $errMsg = '';
                if (is_array($json)) {
                    $errMsg = (string) (isset($json['error']['message']) ? $json['error']['message']
                        : (isset($json['message']) ? $json['message'] : ''));
                }

                return array(
                    'ok' => false,
                    'msg' => 'API 返回异常' . ($errMsg !== '' ? '：' . $errMsg : ''),
                    'stage' => 'api',
                    'httpCode' => $result['httpCode'],
                    'endpoint' => $endpoint,
                    'model' => $model,
                    'response' => self::cut($body, 800),
                    'cost' => $cost . 'ms',
                    'hint' => self::apiHint($result['httpCode'], $body, $model)
                );
            }

            return array(
                'ok' => true,
                'msg' => 'API 连通正常',
                'stage' => 'ok',
                'endpoint' => $endpoint,
                'model' => $model,
                'reply' => $reply,
                'httpCode' => $result['httpCode'],
                'temperature' => self::isFixedTemperature($model) ? '默认（该模型不支持自定义）'
                    : (isset($result['adjusted']) ? '已自动回退：' . $result['adjusted'] : '0'),
                'cost' => $cost . 'ms'
            );
        }

        /**
         * 常见 API 报错的人话解释
         *
         * @param int    $httpCode
         * @param string $body
         * @param string $model
         * @return string
         */
        public static function apiHint($httpCode, $body, $model = '')
        {
            $body = (string) $body;
            $httpCode = (int) $httpCode;

            if (self::isTemperatureError($httpCode, $body)) {
                return '该模型不接受自定义 temperature（如 Kimi K2 只允许 1），插件已自动回退，可重试；'
                    . '若仍报错请把模型名填成供应商文档里的完整名称。';
            }
            if (stripos($body, 'model_not_found') !== false || stripos($body, 'model not found') !== false
                || stripos($body, 'invalid model') !== false) {
                return '模型名不对：' . ($model !== '' ? '当前填的是 ' . $model . '，' : '')
                    . '请到供应商控制台复制准确的模型 ID（注意大小写与日期后缀）。';
            }
            if ($httpCode === 401 || $httpCode === 403) {
                return 'API KEY 无效或没有该模型的权限：检查 KEY 是否复制完整、是否过期、是否已充值。';
            }
            if ($httpCode === 404) {
                return '接口地址不对：一般应填 https://api.xxx.com/v1（插件会自动补 /chat/completions）。';
            }
            if ($httpCode === 429) {
                return '触发限流或余额不足：稍后重试，或检查账户额度。';
            }
            if ($httpCode === 400 && stripos($body, 'max_tokens') !== false) {
                return 'max_tokens 超出该模型上限，或该模型要求用 max_completion_tokens。';
            }
            if ($httpCode >= 500) {
                return '供应商服务端异常，通常稍后重试即可。';
            }

            return '';
        }
    }
}
