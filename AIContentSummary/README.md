# AIContentSummary

一个用于 Typecho 的 AI 文章摘要插件：在文章发布后自动调用 AI 接口，根据正文生成摘要并保存为自定义字段，前台列表/摘要输出时优先展示该摘要；后台提供摘要管理面板，可逐条编辑、清空、生成。

- **版本**：2.1.0（PHP 8.4 / 思考型模型兼容 + 吸收上游 2.0 能力）
- **原作者**：Rockytkg（[Rockytkg/AIContentSummary](https://github.com/Rockytkg/AIContentSummary)）
- **修改者**：Andrew8102
- **兼容环境**：PHP 7.4 ~ 8.4、Typecho 1.2 / 1.3

> **本目录是修改版（Fork）。** 原始项目遵循 Apache License 2.0，原项目版权归 Rockytkg 所有；
> 本分支基于原项目修改，修改内容已在各文件头显著标注，修改部分同样以 Apache-2.0 发布。
> 详细差异见仓库根目录的 [README.md](../README.md)。

---

## 文件结构

```
AIContentSummary/
├── Plugin.php               插件主体（钩子、配置表单、生成逻辑）
├── Action.php               后台摘要管理动作（生成 / 保存 / 清空）
├── Api.php                  公共 API 逻辑（温度策略、token 预算、响应解析）※必须上传
├── test.php                 连通性测试接口（独立文件，不经路由）※必须上传
├── regen.php                历史文章批量补生成接口 ※必须上传
├── template/
│   └── summaries.php        后台「摘要管理」面板
└── README.md
```

## 安装

1. 将整个 `AIContentSummary` 文件夹上传到 `usr/plugins/`；
2. 后台 → 控制台 → 插件 → 启用 **AIContentSummary**；
3. 设置 → 插件 → AIContentSummary，填写模型名、API Key、API 地址，保存后点「测试 API 连通性」。

> **从 1.x 升级**：先停用插件，删除旧目录再上传新版（旧版残留的 `Action.php` 会被覆盖，无需额外处理）。
> **从上游 2.0.0 迁移过来**：直接覆盖上传即可，配置项会自动识别上游的键名（见下表）。

## 配置项

| 界面名称 | 配置键 | 默认 | 说明 |
| --- | --- | --- | --- |
| 模型名称 | `modelName` | `gpt-3.5-turbo-16k` | 也识别上游 2.0 的 `modelName` |
| API KEY | `keyValue` | 空 | 也识别上游 2.0 的 `apiKey` |
| API 地址 | `apiUrl` | 空 | 带不带 `/v1` 均可，插件自动补全 |
| 摘要最大长度 | `maxLength` | `100` | 也识别上游的 `summaryLength` |
| 发布时自动生成摘要 | `enableAuto` | `1` | 也识别上游的 `finishPublishSummary` |
| 系统提示词 | `prompt` | 空（用内置） | 填写后完全替代内置提示词 |
| 正文前置摘要 | `outputSummaryInHeader` | `0` | 开启后正文顶部渲染模板 |
| 前置摘要模板 | `summaryTemplate` | `<aside class="ai-content-summary">…</aside>` | 必须含 `{summary}` |

字段名配置 `fieldName` 也一并识别，默认 `content`（与 1.x 保持一致，旧文章摘要不会失效）。

### 常见服务配置

| 服务 | API 地址 | 模型名 |
| --- | --- | --- |
| Kimi | `https://api.moonshot.cn/v1` | `kimi-k3.1-pro` |
| DeepSeek | `https://api.deepseek.com/v1` | `deepseek-chat` |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |

**建议用非思考型模型生成摘要**：摘要任务不需要推理，`deepseek-chat` 通常 1~3 秒返回；`kimi-k3` 这类思考型模型会先推理几千字，慢且贵（本版已做兼容，推理预算与兜底提取都到位）。

## 使用方法

- **自动生成**：发布文章后自动生成，写入自定义字段（默认 `content`）。
- **跳过已有**：已有摘要的文章再次保存会跳过，不重复调接口、不覆盖手工修订。
- **后台管理**：后台左侧「摘要管理」面板可查看全部文章的摘要，点摘要文字就地编辑，也可单篇或批量生成。
- **编辑页手填**：文章编辑页的自定义字段区有「AI 摘要」，手填即视为已有摘要，跳过自动生成。
- **正文前置**：开启「正文前置摘要」后，正文顶部按模板渲染摘要，默认关闭。
- **前台输出**：`<?php $this->fields->content(); ?>`

## 排查问题

插件目录下的 `debug.log` 会记录关键节点：

```
[2026-10-30 10:20:01] 跳过生成：cid=128 已有摘要，不再调用接口
[2026-10-30 10:21:12] 生成成功：cid=130 摘要长度=96
```

## 开源协议

本插件基于原版 AIContentSummary（作者 Rockytkg）修改，遵循相同许可 Apache License 2.0。
原项目地址：<https://github.com/Rockytkg/AIContentSummary>
修改者：Andrew8102
