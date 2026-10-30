# AIContentSummary

一个用于 Typecho 的 AI 文章摘要插件：在文章发布后自动调用 AI 接口，根据正文生成摘要，并作为自定义字段（`content`）保存；前台列表/摘要输出时优先展示该摘要。

- **作者**：Joinliu
- **版本**：1.8.2
- **官网**：https://letanml.xyz
- **兼容环境**：PHP 7.4 ~ 8.4、Typecho 1.2 / 1.3

---

## 功能特性

- 文章发布完成后自动调用 AI 接口生成摘要，写入自定义字段 `content`。
- 前台输出摘要（`excerpt`）时，优先使用 AI 生成的摘要内容，并自动按最大长度截断。
- 后台「设置 → 插件」内置 **API 连通性测试** 按钮，填好配置即可一键验证。
- 可开关「发布时自动生成摘要」，关闭后仅手动测试触发，不影响既有摘要。
- 兼容旧版（v1.4）的字段名与配置项，**已生成摘要的文章无需重新生成即可继续显示**。

---

## 安装

1. 下载插件，将整个 `AIContentSummary` 文件夹上传到 Typecho 的 `usr/plugins/` 目录下。
   ```
   usr/plugins/AIContentSummary/Plugin.php
   usr/plugins/AIContentSummary/Api.php       ← 1.7 新增，公共 API 逻辑，必须上传
   usr/plugins/AIContentSummary/test.php      ← 1.7 新增，测试接口（独立文件），必须上传
   usr/plugins/AIContentSummary/regen.php     ← 1.8 新增，历史文章补生成接口，必须上传
   usr/plugins/AIContentSummary/README.md
   ```
2. 登录后台，进入「控制台 → 插件」，找到 **AIContentSummary**，点击「启用」。
3. 进入「设置 → 插件 → AIContentSummary」填写配置并保存。

> 如果你是从旧版（v1.4）升级：直接覆盖原 `AIContentSummary` 文件夹即可，字段名与配置项保持一致，旧文章摘要不受影响。

---

## 配置说明

| 配置项 | 说明 | 默认值 |
| --- | --- | --- |
| 模型名称 | 用于生成摘要的模型名（如 `gpt-3.5-turbo-16k`、`deepseek-chat`、`kimi-k3.1-pro`） | `gpt-3.5-turbo-16k` |
| API KEY | 调用 AI 接口所用的 API Key | 空 |
| API 地址 | 接口地址，需包含 `http://` 或 `https://`；带不带 `/v1` 都可以，插件会自动补全 `/chat/completions` | 空 |
| 摘要最大长度 | 输出摘要的最大文字数量 | `100` |
| 发布时自动生成摘要 | 开启后发布文章自动生成摘要；关闭则仅手动测试 | 开启 |

配置保存后，点击页面底部的「**测试 API 连通性**」按钮，返回 `ok: true` 即表示配置正确。

**API 地址填写示例**：
- 正确：`https://api.openai.com`、`https://api.openai.com/v1`
- 正确：`https://api.moonshot.cn/v1`（Kimi）
- 正确：`https://api.deepseek.com/v1`、`https://your-proxy.example.com`
- 也可以直接填完整的 `https://xxx/v1/chat/completions`，插件会原样使用

---

## 使用说明

- 启用并配置后，**新发布的文章**会在发布完成后自动生成摘要。
- 前台调用 `$post->excerpt` / `<?php $post->excerpt(); ?>` 时，会优先展示 AI 摘要；若某篇文章还没有摘要字段，则回退到 Typecho 默认的摘要逻辑。
- 想修改某篇文章的摘要？直接在后台该文章的「自定义字段」里编辑名为 `content` 的字段即可，插件会优先读取它。

---

## 兼容性说明（v1.5 相较 v1.4）

- **PHP 7.4 兼容**：不使用任何 PHP 8.0+ 语法（无 `match`、union type、`nullsafe`、`mixed` 返回类型）。
- **Typecho 1.3 兼容**：沿用 1.2/1.3 均支持的路由（`Helper::addRoute`）、`excerptEx` 与 `finishPublish` 钩子、`table.fields` 直写库方式。
- **向下兼容字段与配置**：自定义字段名 `content`、配置项 `modelName/keyValue/apiUrl/maxLength` 全部沿用旧版，旧文章摘要无缝衔接。
- **修复 bug**：修正了旧版 `callApi()` 中对正文使用 `addslashes()` 后再 `json_encode()` 导致的双重转义问题，模型收到的正文不再带有多余反斜杠。
- **结构优化**：将 curl 调用收拢为统一 `httpPost()` 封装，网络错误与 HTTP 错误分开处理，重试逻辑更清晰。
- **新增开关**：「发布时自动生成摘要」可临时关闭自动生成，无需卸载插件。

---

## 常见问题

**Q：保存文章后后台白屏，而且文章没有生成摘要？（1.8 已修）**
A：两个独立 bug，都在 1.8 修掉了：

1. **白屏**：`onFinishPublish` 里调用了 `fastcgi_finish_request()`。这个钩子在 Typecho 里是「发布完成 → 设置提示 → 跳转后台列表」**中间**执行的，在这里提前结束响应，后面的 `Notice::set()` 和 `$this->response->redirect()` 全部发不出去，浏览器只收到一个空白 200。现在已删除该调用，也删掉了会丢提示的 `session_write_close()`。
2. **从不生成摘要**：取 cid 用的是 `isset($obj->cid)`。Typecho 的 Widget 属性是**魔术属性**，而且没有实现 `__isset`，所以 `isset()` 恒为 `false` → cid 判成 0 → 方法直接 return，摘要逻辑从未执行过。现在改为直接读取 + `try/catch`，并加了 `theId` / `id` 兜底。

另外，整个钩子现在被 `try/catch` 包住，任何异常只会写日志，**不会影响保存和跳转**。若仍然失败，看 `usr/plugins/AIContentSummary/debug.log`，里面会写清是「跳过（cid=0/正文为空）」「API 返回原文」还是「异常」。

**Q：历史文章没有摘要，要不要一篇篇重新发布？**
A：不用。插件设置页底部有「扫描并补生成（最多 50 篇）」，只处理已发布但没有 `content` 字段的文章，逐篇串行调用接口，页面保持打开即可，过程有进度和失败原因。也可以直接请求 `usr/plugins/AIContentSummary/regen.php`。

**Q：测试报 400 —— `invalid temperature: only 1 is allowed for this model`？**
A：1.7 起已内置适配：Kimi / Moonshot 全系（`kimi-k3.1-pro`、`kimi-k3`、`kimi-k2.x` 等）、各类 thinking/reasoning 模型、OpenAI o 系与 gpt-5 系不接受自定义采样温度，插件对这些模型**不发送 `temperature`**（交给服务端默认值 1）；其余模型仍发送 `temperature: 0` 以保证摘要稳定。
若你填的是其它厂商的同类模型而不在名单里，插件还会自动回退：先用 `temperature=1` 重试，再退到完全不传，无需手动改代码。

**Q：测试按钮返回「API 地址或 KEY 为空」？**
A：请先点击页面底部的「保存设置」再点测试，配置未保存时插件读不到值。

**Q：旧文章的摘要为什么不显示？**
A：插件只读取名为 `content` 的自定义字段。若旧文章从未生成过摘要，自然没有该字段，会回退到默认摘要；如需补生成，可重新发布一次（开关为开启状态时）。

**Q：生成摘要很慢 / 超时？**
A：插件在发布后会先返回响应再后台执行，并设置了最长 300 秒执行时间。若接口响应慢，请检查 API 地址与网络，或在 `callApi()` 中调整超时与重试参数。

**Q：能否用于独立部署的兼容 OpenAI 接口（如本地模型、第三方代理）？**
A：可以。只要接口兼容 `POST /v1/chat/completions` 的 OpenAI 格式，并支持 `Authorization: Bearer <key>` 鉴权即可。

---

## 开源协议

本插件基于原版 AIContentSummary v1.4（作者 Joinliu）重写，遵循相同许可。


---

## 1.6 更新说明

1. **修复「API 连通性测试」永远失败的问题**
   旧版把测试路由指向了 `AIContentSummary_Plugin`（插件类，不是 Widget）。
   Typecho 1.3 的 `Router::dispatch()` 内部是 `Widget::widget($route['widget'])`，
   而该方法返回类型被声明为 `: Typecho\Widget`，传入非 Widget 类会直接抛 `TypeError`（致命 500），
   同时它还会调用 `$widget->execute()`（插件类没有此方法）。
   所以浏览器只会拿到一个错误页，`r.json()` 解析失败，控制台看起来像 CORS 问题。
   现改为官方约定的独立 Widget：`AIContentSummary_Action`（新增 `Action.php`）。

2. **测试请求不再跨域**
   前端只取路由的 **path**（如 `/aicontentsummary/test`），以「当前后台页面同源」发起请求，
   无论站点是否配置了 CDN、`siteUrl` 是 http 还是 https，都不会触发 CORS。

3. **修正 Typecho Config 的取值陷阱**
   `Config` 继承 `stdClass`，但值存在内部数组里，`isset()` / `??` 判断魔术属性**永远为 false**。
   旧版在读取 `maxLength` 时若配置为空，会把摘要截成 `"..."`；新版改用直接取值 + 显式兜底。

4. **PHP 8.4 兼容性收尾**
   - 异常捕获改为 `\Throwable`（原来只 catch `Exception`，捕获不到 `TypeError` 等 `Error`）；
   - 所有配置取用都做 `null` / 空字符串兜底，避免 8.1+ 的 "Passing null to non-nullable" 弃用告警；
   - `json_encode` 增加 `JSON_INVALID_UTF8_SUBSTITUTE`，正文含异常字节时不会整包失败；
   - `curl_close()` 仅在 PHP < 8.0 调用（8.0 起已是 no-op）。

5. **配置页会自动修正历史路由**
   打开插件设置页时会检测路由指向，若仍是旧的插件类会自动重写为 `AIContentSummary_Action`，
   无需手动「停用再启用」。


---

## 1.7 更新说明（重要）

### 为什么测试按钮之前必然失败

1.5 / 1.6 把测试地址注册成了 Typecho 路由，而**路由表是存在数据库里的**。
插件升级后，旧路由（`widget` 指向插件类 `AIContentSummary_Plugin`）不会自动消失：
请求进来后 `Router::dispatch()` 会执行 `Widget::widget()`，接着调用 `$widget->execute()`，
而插件类根本没有 `execute()` 方法，于是直接抛：

```
Call to undefined method AIContentSummary_Plugin::execute()
```

前端拿到的是 Typecho 的错误页（HTML），`JSON.parse` 自然失败，看起来很像跨域（CORS）。

### 1.7 的做法：不再依赖路由

测试接口改成插件目录下的**独立文件** `test.php`，直接访问：

```
POST /usr/plugins/AIContentSummary/test.php
```

- 不经过路由表，**不受遗留路由影响**；
- 不需要重新启用插件，上传即用；
- 自带轻量引导：向上查找 `config.inc.php`，只读 `table.options` 里本插件的配置，不启动完整 Typecho；
- 始终返回 JSON（HTTP 一律 200，避免 nginx `error_page` 把 4xx/5xx 的 body 换成 HTML 错误页）；
- 用 API KEY 派生的令牌校验，避免接口被陌生请求拿去刷额度；
- 会指出失败环节（`config` / `network` / `api` / `token` / `bootstrap`），并给出 curl 错误码对应的建议。

同时 `activate()` / 打开设置页时会**自动清理**数据库里遗留的错误路由。

### 升级步骤

1. 上传 `Plugin.php`、`Api.php`、`test.php` 三个文件（覆盖到 `usr/plugins/AIContentSummary/`）；
2. 删除旧的 `Action.php`（1.7 不再使用）；
3. 清 OPcache（宝塔 → PHP 管理 → 重启）；
4. 打开插件设置页 → 保存一次 → 点「测试 API 连通性」。

> 顺带一提：用浏览器直接 **GET** 打开 `.../usr/plugins/AIContentSummary/test.php`，
> 如果返回 `{"ok":false,...,"stage":"method"}`，说明文件能正常执行（这是最快的连通性自检）；
> 如果返回 404 / 403 / HTML，则是文件没传到位，或主机禁止直接访问 `usr/plugins` 下的 PHP。

### PHP 8.4 相关

- 全部代码在 PHP 8.4.11 下 `php -l` 通过，并做过真实请求测试；
- 配置读取不再依赖 `isset()` / `??` 判断魔术属性（Typecho 的 `Config` 继承 `stdClass` 但值在内部数组，那样判断永远为 false）；
- 异常捕获统一用 `\Throwable`（`TypeError` 属于 `Error`，`catch (Exception)` 抓不到）。

### 1.7.1 补充：temperature 兼容（Kimi K2/K3 报 400）

**现象**：

```
API 返回异常：invalid temperature: only 1 is allowed for this model
HTTP 状态：400
接口地址：https://api.moonshot.cn/v1/chat/completions
```

**原因**：插件旧逻辑固定发送 `temperature: 0`（为了让摘要稳定可复现）。
但 Kimi 的 K2 / K3 系列（以及 OpenAI o 系、gpt-5 系、各类 thinking 模型）**不接受自定义采样温度**，只允许默认值 1，
收到 0 就直接 400。这不是 KEY 或地址的问题，换模型名一样会报。

**处理**（已内置，无需配置）：

1. `Api::isFixedTemperature()` 判断模型是否属于「只接受默认温度」的类型：命中 `kimi` / `moonshot` / `thinking` / `reasoning` 关键词，或去掉 vendor 前缀后以 `o1`~`o9`、`gpt-5` 开头 → **完全不发 `temperature`**；
2. 其余模型保持 `temperature: 0`，摘要风格不变；
3. 兜底：`Api::postChat()` 若真的收到 `temperature` 相关 400/422，会先改成 `1` 重试，再退到完全不传，最多两次，自动完成；
4. 测试结果里会多一行 `temperature：默认（该模型不支持自定义）` 或 `已自动回退：temperature=1`，便于确认走的是哪条路径；
5. 测试请求的 `max_tokens` 从 20 提到 128：思考型模型可能把额度全用在推理上导致正文为空，现在这种情况判定为「连通正常」而不是失败。

### 1.8 更新说明：白屏 + 不生成摘要

**两个 bug 的根因**

| 现象 | 根因 | 位置 |
| --- | --- | --- |
| 保存文章后后台白屏、没有「已经发布」提示 | `fastcgi_finish_request()` 在「发布完成 → 提示 → 跳转」中间提前结束响应，`redirect()` 发不出去 | `onFinishPublish()` |
| 文章永远没有 AI 摘要 | `isset($obj->cid)` 对 Typecho 魔术属性恒为 `false` → cid=0 → 直接 return | `onFinishPublish()` |

**1.8 的改动**

- 删除 `fastcgi_finish_request()` 与 `session_write_close()`，恢复 Typecho 的正常跳转与提示；
- cid 改为直接读取（`try/catch` + `theId`/`id` 兜底），并把原因写进 `debug.log`；
- 钩子整体 `try/catch`，异常只记日志，绝不影响保存；
- 正文先清洗再送模型：去掉 `<!--markdown-->`、`<script>/<style>`、HTML 标签，`&nbsp;` 归一，限 8000 字；
- 摘要请求带 `max_tokens`（`maxLength × 4`，256~2048），思考型模型也能正常出文；
- API 4xx 不再无意义重试（原逻辑会白等 1 秒再来一次），网络错误仍重试一次；
- 新增 `regen.php` + 设置页按钮，可给历史文章批量补摘要；
- 失败时后台会弹红色提示，并写 `debug.log`。

**验证方式（本地 PHP 8.4.11）**

- 用「有 `__get`、没有 `__isset`」的桩类复现了 `isset($obj->cid) === false`，确认旧写法必然失效；
- 钩子在无配置环境下调用不抛异常、原样返回 `$contents`，并正确写入日志与后台提示；
- `prepareText` 去标签/去脚本/`&nbsp;` 归一/限长均通过；
- `regen.php` 列表只挑出缺摘要的文章、错误令牌 403、文章不存在、API 不可达四种分支均返回结构化 JSON；
- 端到端跑通「regen → 假接口 → 写入 table.fields」，`content` 字段成功落库，请求体确认不含 `temperature`、正文已去 HTML。

### 1.8.1 更新说明：已有摘要不再重复调接口

**问题**：1.8 之前，只要保存文章（包括只是改个错别字再保存）就会调用一次接口。既白花额度，也会把手工改好的摘要覆盖掉。

**现在的行为**

| 文章状态 | 保存时行为 |
| --- | --- |
| 自定义字段 `content` 有内容 | **直接跳过**，不调用接口，字段值保持原样 |
| `content` 为空字符串，或没有该字段 | 正常调用接口生成 |
| 命中跳过时 | `debug.log` 写一行「跳过生成：cid=xx 已有摘要（n 字），不再调用接口」 |

**怎么让某篇文章重新生成**

1. 打开文章编辑页，滚到底部「自定义字段」，把 `content` 的内容清空（或直接点右侧删除该字段），再保存 —— 字段为空即视为没有摘要，会重新生成；
2. 或者到插件设置页点「扫描并补生成」，它同样只处理缺摘要的文章。

**说明**

- 判断依据是数据库里的字段值，而 Typecho 的发布流程是「先写自定义字段 → 再触发 finishPublish 钩子」，所以你在编辑页手填、清空、删除字段，钩子读到的都是刚保存的最新值，不会出现「刚清空又被判定为有」的情况；
- 读取字段出错时按「没有摘要」处理，宁可多生成一次，也不会因为查库异常把摘要功能整个卡死。

**验证**：用本地 Typecho 1.3 + SQLite 跑了三种状态（有摘要 / 空字符串 / 无字段）——
有摘要时接口请求数保持 0、字段值未被覆盖、日志写明跳过；空字符串与无字段两种都正常生成并落库，9 项断言全过。

### 1.8.2 更新说明：思考型模型返回空正文（finish_reason=length）

**现象**（批量补生成时）

```
✘ 添加swap内存解决c++: internal compiler error...：模型没有返回正文（finish_reason=length）
   {"message":{"content":"","reasoning_content":"The user wants me to act as a text summarizer..."}}
✔ 有趣的web项目（15674ms）
```

**根因**：`kimi-k3` 是**思考型模型**，回答前先长篇推理。而插件按「摘要字数 × 4」给 `max_tokens`（100 字 → 400），
推理阶段就把额度用光了，正文 `content` 一个 token 都没轮到，`finish_reason` 直接是 `length`。
不是 KEY、不是网络、也不是温度参数——是预算给少了。

**1.8.2 的三层处理**

| 层级 | 做法 |
| --- | --- |
| 1. 给足预算 | `Api::isThinkingModel()` 识别思考型模型（kimi-k3 / kimi-k2 / thinking / reasoning / o 系 / gpt-5 系），`maxTokensFor()` 给 4096~32768；普通模型仍是 `字数 × 4` |
| 2. 被截断就放大 | 正文空 + `finish_reason=length` 时，`max_tokens` 自动放大 4 倍再请求一次（最多两轮） |
| 3. 还不行就捞草稿 | 从 `reasoning_content` 里保守提取中文段落作为摘要草稿：剔除英文思考过程、指令复述、列表标记，只留成句的中文，然后落库——至少不用整篇手工补 |

彻底提取不到时，报错会直接给建议：换非思考型模型（`deepseek-chat`、`kimi-k2.6`）或调小摘要长度。

**仍然建议**：摘要这种任务不需要推理能力，用非思考型模型又快又省钱（15674ms → 通常 1~3s）。
思考型模型只是留了兜底，不建议长期用。

**验证**

- 用模拟 kimi-k3 的假接口（推理烧掉预算就返回空 content + finish_reason=length）复现了三层链路：
  - 预算充足：一次成功，`max_tokens=4896`；
  - 预算不足：`512 → 被截断 → 自动放大到 8192 → 成功`（假接口日志可见两次请求）；
  - 永远截断：从 `reasoning_content` 提取出中文草稿并落库，不再直接报错。
- 21 项单元断言（模型识别、预算计算、reasoning 解析、提取过滤）+ 原 14 项钩子回归 + 9 项跳过回归，全部通过。
