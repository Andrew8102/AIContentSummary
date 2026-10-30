# AIContentSummary（PHP 8.4 / Kimi K2·K3 兼容版）

AIContentSummary 是一个用于通过调用 AI 接口，根据文章内容生成摘要的 Typecho 插件。

> **⚠️ 本仓库是修改版（Fork）**
>
> - 原始项目：[Rockytkg/AIContentSummary](https://github.com/Rockytkg/AIContentSummary)
> - 原作者：Rockytkg，遵循 **Apache License 2.0**
> - 本分支由 [Andrew](https://moechu.cn)（GitHub: [Andrew8102](https://github.com/Andrew8102)）修改，针对 **PHP 8.4** 与 **Kimi K2/K3 等思考型模型**做了兼容修复
> - 修改的文件：`AIContentSummary/Plugin.php`、`AIContentSummary/Api.php`、`AIContentSummary/test.php`、`AIContentSummary/regen.php`
> - 原项目版权归 Rockytkg 所有；本分支的修改同样以 Apache-2.0 发布
>
> 使用问题、Bug 反馈欢迎到 [moechu.cn](https://moechu.cn) 留言，或在 [Issues](https://github.com/Andrew8102/AIContentSummary/issues) 提出。

## 为什么有这个分支

上游 2.0.0 在以下场景会直接失败，本分支逐一修复：

| 问题 | 现象 | 原因 |
| --- | --- | --- |
| temperature 被拒 | `400 invalid temperature: only 1 is allowed for this model` | 请求体写死 `temperature => 0`，而 Kimi K2/K3、OpenAI o 系、gpt-5 系只接受默认值 1 |
| 思考型模型无正文 | `模型没有返回正文（finish_reason=length）` | `max_tokens` 按摘要字数给（100 字 → 400），推理阶段就把额度用光，正文没 token |
| 保存文章白屏 | 后台空白页、无「已经发布」提示 | 发布钩子里调用了 `fastcgi_finish_request()`，提前结束响应，后续跳转与提示发不出去 |
| 从不生成摘要 | 发布后一直没有摘要 | `isset($obj->cid)` 对 Typecho 魔术属性恒为 `false`，cid 被判成 0 直接 return |
| PHP 8.4 报错 | 除零、`array_key_exists(false)`、隐式可空参数等 | 旧代码未适配 PHP 8.x |

## 本分支与原版的差异

**文件结构变了**（不与上游 2.0.0 兼容，请勿混用文件）：

```
原版 2.0.0                         本分支 1.8.2
├── Plugin.php                     ├── Plugin.php   插件主体
├── Action.php                     ├── Api.php      公共 API 逻辑（温度/预算/解析）
├── template/summaries.php         ├── test.php     连通性测试接口（独立文件，不依赖路由）
└── LICENSE                        ├── regen.php    历史文章批量补生成
                                   └── README.md
```

**功能变化**：

- 删除 `Action.php` 与独立摘要管理页，摘要管理改到设置页底部（测试 + 批量补生成）
- 新增 `Api.php`：温度策略、`max_tokens` 预算、响应解析集中一处，插件与接口共用
- 新增 `test.php`：直接请求插件目录下的文件，**不经过 Typecho 路由表**，规避路由残留与 CORS
- 新增 `regen.php`：为历史文章批量补摘要

**与上游相同的设计**（从 1.8 起就有）：已有摘要的文章不会被重复调用接口，避免浪费额度、避免覆盖手工修订。

## 安装方法

Typecho >= 1.2，PHP 7.4 ~ 8.4

1. 下载 [Releases](https://github.com/Andrew8102/AIContentSummary/releases) 里的 `AIContentSummary_x.y.z.zip`
2. 解压后得到 `AIContentSummary/` 目录，**整个目录**上传到 `usr/plugins/`
3. 后台 → 控制台 → 插件 → 启用 AIContentSummary
4. 进入插件设置，填写模型名、API Key、API 地址，点「测试 API 连通性」

> **从原版升级**：请先停用插件，删除旧目录，再上传新版。**务必删掉旧的 `Action.php` 与 `template/` 目录**，否则会残留旧路由。

### 配置示例

| 服务 | API 地址 | 模型名 |
| --- | --- | --- |
| Kimi | `https://api.moonshot.cn/v1` | `kimi-k3.1-pro` |
| DeepSeek | `https://api.deepseek.com/v1` | `deepseek-chat` |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |

API 地址带不带 `/v1` 都可以，插件会自动补全。

**建议用非思考型模型生成摘要**：摘要任务不需要推理，`deepseek-chat` 通常 1~3 秒返回；`kimi-k3` 这类思考型模型会先推理几千字，慢且贵（虽然本分支已做兼容）。

## 使用方法

1. 写文章时无需手动填摘要，发布后自动生成，写入自定义字段 `content`
2. 已有摘要的文章再次保存会跳过，不重复调接口
3. 想让某篇文章重新生成：编辑页底部「自定义字段」清空 `content` 再保存，或用设置页的「扫描并补生成」
4. 前端输出参考：`<?php $this->fields->content(); ?>`

## 排查问题

插件目录下的 `debug.log` 会记录每个关键节点：

```
[2026-10-29 16:30:01] 跳过生成：cid=128 已有摘要（96 字），不再调用接口
[2026-10-29 16:31:12] API 200 但正文为空：model=kimi-k3 finish=length max_tokens=8192 reasoning长度=4210 兜底提取=有
```

## 关于本分支作者

**Andrew** — [moechu.cn](https://moechu.cn) ｜ [GitHub @Andrew8102](https://github.com/Andrew8102)

这个分支起因于自己站点从 Typecho 1.1 + PHP 7.4 升级到 1.3 + PHP 8.4，一路踩完上面的坑后整理而来。
遇到问题欢迎到 [moechu.cn](https://moechu.cn) 留言，或在 [Issues](https://github.com/Andrew8102/AIContentSummary/issues) 提出。

## 许可证

[Apache License 2.0](LICENSE)。原项目版权归 Rockytkg 所有，本分支修改部分版权归 Andrew（[moechu.cn](https://moechu.cn)）所有。

## 致谢

原始插件由 [Rockytkg](https://github.com/Rockytkg) 开发。如果这个插件帮到你，也请去给原项目点个 Star。
