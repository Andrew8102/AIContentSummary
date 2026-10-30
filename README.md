# AIContentSummary（PHP 8.4 / Kimi K2·K3 兼容版 · 2.1.0）

AIContentSummary 是一个用于通过调用 AI 接口，根据文章内容生成摘要的 Typecho 插件。

> **⚠️ 本仓库是修改版（Fork）**
>
> - 原始项目：[Rockytkg/AIContentSummary](https://github.com/Rockytkg/AIContentSummary)
> - 原作者：Rockytkg，遵循 **Apache License 2.0**
> - 本分支由 [Andrew](https://moechu.cn)（GitHub: [Andrew8102](https://github.com/Andrew8102)）修改，针对 **PHP 8.4** 与 **Kimi K2/K3 等思考型模型**做了兼容修复，并在 2.1.0 吸收上游 2.0 的功能
> - 原项目版权归 Rockytkg 所有；本分支的修改同样以 Apache-2.0 发布
>
> 使用问题、Bug 反馈欢迎到 [moechu.cn](https://moechu.cn) 留言，或在 [Issues](https://github.com/Andrew8102/AIContentSummary/issues) 提出。

## 为什么有这个分支

上游 2.0.0 在以下场景会直接失败，本分支逐一修复：

| 问题 | 现象 | 原因 |
| --- | --- | --- |
| temperature 被拒 | `400 invalid temperature: only 1 is allowed for this model` | 请求体写死 `temperature => 0`，而 Kimi K2/K3、OpenAI o 系、gpt-5 系只接受默认值 1 |
| 思考型模型无正文 | `模型没有返回正文（finish_reason=length）` | `max_tokens` 按摘要字数给（100 字 → 400），推理阶段就把额度用光，正文没 token |
| 保存文章白屏 | 后台空白页、无「已经发布」提示 | 旧版发布钩子里调用了 `fastcgi_finish_request()`，提前结束响应 |
| 从不生成摘要 | 发布后一直没有摘要 | `isset($obj->cid)` 对 Typecho 魔术属性恒为 `false`，cid 被判成 0 直接 return |
| 没有摘要管理 | 只能一篇篇进编辑页改 | 上游 1.x 缺少后台管理；2.0 补上了，但生成逻辑仍有上面的坑 |
| PHP 8.4 报错 | 除零、`array_key_exists(false)`、隐式可空参数等 | 旧代码未适配 PHP 8.x |

## 版本演进

| 版本 | 内容 |
| --- | --- |
| 1.7 | 测试接口改为独立文件 `test.php`，摆脱 Typecho 路由表；API 逻辑抽到 `Api.php` |
| 1.7.1 | 固定 temperature 模型适配（Kimi / o 系 / gpt-5） |
| 1.8 | 修复白屏（去掉 `fastcgi_finish_request`）与「从不生成摘要」（`isset($obj->cid)` 恒 false） |
| 1.8.1 | 已有摘要跳过，不再重复消耗额度 |
| 1.8.2 | 思考型模型 `max_tokens` 预算 + 截断放大重试 + `reasoning_content` 兜底提取 |
| **2.1.0** | **吸收上游 2.0：代码结构对齐（命名空间 + `Action.php` + `template/`）、后台摘要管理面板、正文前置摘要与模板、自定义提示词、编辑页字段；配置键双名兼容** |

## 2.1.0 与上游 2.0.0 的差异

**结构已经对齐**，方便以后跟上游同步：

```
上游 2.0.0                          本分支 2.1.0
├── Plugin.php                      ├── Plugin.php    插件主体（钩子 + 配置 + 生成）
├── Action.php                      ├── Action.php    后台动作（生成/保存/清空）
├── template/summaries.php          ├── template/summaries.php  摘要管理面板
└── LICENSE                         ├── Api.php       公共 API 逻辑（温度/预算/解析）★新增
                                    ├── test.php      连通性测试（独立文件，不经路由）★新增
                                    ├── regen.php     历史文章批量补生成 ★新增
                                    └── README.md
```

**保留了上游的优点**：

- 后台「摘要管理」面板：列表展示、逐条就地编辑、清空、单篇生成、批量生成（串行防限流）
- 正文前置摘要（`contentEx`）＋ 可配置模板与 `{summary}` 占位符
- 自定义系统提示词
- 文章编辑页的「AI 摘要」字段
- 命名空间与 `Action` / `template` 的标准插件结构

**在上游基础上修的**：

- `temperature => 0` 写死 → 按模型判断，思考型/新模型不传
- 没有 `max_tokens` → 按模型类型给预算（思考型 4096~32768），截断自动放大 4 倍重试，仍失败从推理内容兜底提取
- 发布钩子无 try/catch → 整个钩子包 try/catch，异常只写日志，绝不影响保存与跳转
- 无连通性测试 → 新增 `test.php`，绕开路由表，失败时指出卡在哪一步（config / network / api / token / bootstrap）
- 无批量补生成 → 新增 `regen.php`，设置页一键扫描补生成
- 配置键与 1.x 不同 → 双名兼容，从任意方向升级都不丢配置

## 安装

Typecho >= 1.2，PHP 7.4 ~ 8.4

1. 下载 [Releases](https://github.com/Andrew8102/AIContentSummary/releases) 里的 `AIContentSummary_x.y.z.zip`
2. 解压后得到 `AIContentSummary/` 目录，**整个目录**上传到 `usr/plugins/`
3. 后台 → 控制台 → 插件 → 启用 AIContentSummary
4. 进入插件设置，填写模型名、API Key、API 地址，点「测试 API 连通性」

> **从 1.x 或上游 2.0 升级**：先停用插件，删除旧目录，再上传新版。**务必删掉旧的 `Action.php` 与 `template/` 目录后再覆盖**，避免残留旧路由与旧模板。

### 配置示例

| 服务 | API 地址 | 模型名 |
| --- | --- | --- |
| Kimi | `https://api.moonshot.cn/v1` | `kimi-k3.1-pro` |
| DeepSeek | `https://api.deepseek.com/v1` | `deepseek-chat` |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |

API 地址带不带 `/v1` 都可以，插件会自动补全。

**建议用非思考型模型生成摘要**：摘要任务不需要推理，`deepseek-chat` 通常 1~3 秒返回；`kimi-k3` 这类思考型模型会先推理几千字，慢且贵（虽然本分支已做兼容）。

## 使用方法

1. 写文章时无需手动填摘要，发布后自动生成，写入自定义字段（默认 `content`）
2. 已有摘要的文章再次保存会跳过，不重复调接口
3. **后台「摘要管理」面板**：查看全部文章摘要，点摘要文字就地编辑；也可单篇生成或勾选后批量生成
4. 想让某篇文章重新生成：在管理面板点「生成摘要」，或清空摘要字段再保存
5. 开启「正文前置摘要」可在正文顶部渲染摘要（默认关闭）
6. 前端输出参考：`<?php $this->fields->content(); ?>`

## 排查问题

插件目录下的 `debug.log` 会记录每个关键节点：

```
[2026-10-30 10:20:01] 跳过生成：cid=128 已有摘要，不再调用接口
[2026-10-30 10:21:12] 生成成功：cid=130 摘要长度=96
```

## 关于本分支作者

**Andrew** — [moechu.cn](https://moechu.cn) ｜ [GitHub @Andrew8102](https://github.com/Andrew8102)

这个分支起因于自己站点从 Typecho 1.1 + PHP 7.4 升级到 1.3 + PHP 8.4，一路踩完上面的坑后整理而来。
遇到问题欢迎到 [moechu.cn](https://moechu.cn) 留言，或在 [Issues](https://github.com/Andrew8102/AIContentSummary/issues) 提出。

## 许可证

[Apache License 2.0](LICENSE)。原项目版权归 Rockytkg 所有，本分支修改部分版权归 Andrew（[moechu.cn](https://moechu.cn)）所有。

## 致谢

原始插件由 [Rockytkg](https://github.com/Rockytkg) 开发。如果这个插件帮到你，也请去给原项目点个 Star。
