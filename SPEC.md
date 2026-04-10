# AI Gateway 项目需求文档

## 1. 项目概述

### 项目名称
AI Gateway - Ollama & vLLM Tool Calling 前端

### 项目目标
构建一个轻量级的 AI 对话网关，支持流式输出和 Tool Calling 功能，允许 AI 模型调用自定义工具函数。

### 目标用户
- 开发者测试 AI 模型
- 需要 AI 调用外部工具的场景
- 希望快速搭建 AI 对话界面的用户

---

## 2. 功能需求

### 2.1 核心功能

#### 2.1.1 双后端支持
- **Ollama**: 通过 `/api/chat` 接口
- **vLLM**: 通过 `/v1/completions` 接口
- 支持后端切换
- 自动获取可用模型列表

#### 2.1.2 流式输出
- SSE (Server-Sent Events) 实时推送
- 前端逐字显示 AI 回复
- 支持中断连接

#### 2.1.3 Tool Calling
- 工具定义标准化 (OpenAI 格式)
- 自动执行工具调用
- 工具结果反馈给 AI
- 支持多轮工具调用

#### 2.1.4 Qdrant 函数检索 (Function RAG)
- 将工具函数存储到 Qdrant 向量数据库
- 根据用户查询动态检索相关函数
- 只向模型传递检索到的相关工具
- 支持向量索引管理和重新索引

#### 2.1.5 消息显示
- 用户消息右对齐显示
- AI 消息左对齐显示
- 工具调用高亮显示
- Think 标签内容折叠显示
- Think 为空时不显示

### 2.2 前端功能

| 功能 | 描述 | 优先级 |
|------|------|--------|
| 模型选择 | 下拉选择可用模型 | P0 |
| 消息发送 | Enter 发送，Shift+Enter 换行 | P0 |
| 工具开关 | 启用/禁用 Tool Calling | P1 |
| 工具面板 | 显示可用工具列表 | P1 |
| 思考过程 | 显示 AI 的 think 标签内容 | P2 |
| 滚动自动 | 新消息自动滚动到底部 | P1 |

### 2.3 后端 API

| 端点 | 方法 | 描述 |
|------|------|------|
| `/` | GET | 返回前端页面 |
| `/functions` | GET | 函数搜索页面 |
| `/api/chat/stream` | POST | 流式聊天接口 |
| `/api/models` | GET | 获取模型列表 |
| `/api/tools` | GET | 获取工具列表 |
| `/api/execute` | POST | 执行指定工具 |
| `/api/qdrant/index` | POST | 索引工具到 Qdrant |
| `/api/qdrant/search` | POST | 测试 Qdrant 检索 |
| `/health` | GET | 健康检查 |

---

## 3. 技术架构

### 3.1 技术栈
- **后端**: PHP 7.4+ / Swoole
- **前端**: 原生 HTML/CSS/JavaScript
- **通信**: SSE 流式传输
- **AI 接口**: Ollama API / vLLM OpenAI 兼容 API

### 3.2 系统架构图

```
┌─────────────┐      ┌─────────────┐      ┌─────────────┐
│   Browser   │ <--> │   PHP       │ <--> │  Ollama /  │
│   (HTML/JS) │ SSE  │   Swoole    │ HTTP │   vLLM      │
└─────────────┘      └──────┬──────┘      └─────────────┘
                           │
                           v
                    ┌─────────────┐
                    │   Tools/    │
                    │  Functions  │
                    └─────────────┘
```

### 3.3 数据流

```
User Input --> Frontend --> Backend --> AI Model
                ^                           |
                |                           v
                +------ SSE Stream ------ Tools
```

---

## 4. 配置需求

### 4.1 环境变量
```
OLLAMA_URL=http://localhost:11434  # Ollama 地址
VLLM_URL=http://localhost:8000     # vLLM 地址
PORT=9501                          # HTTP 端口
```

### 4.2 默认配置
```php
define('OLLAMA_URL', 'http://localhost:11434');
define('VLLM_URL', 'http://localhost:8000');
define('PORT', 9501);
```

---

## 5. 扩展性需求

### 5.1 工具扩展
- 工具文件放在 `tools/` 目录
- 每个工具一个 PHP 文件
- 返回标准工具定义格式
- 支持同步/异步执行

### 5.2 工具定义格式
```php
return [
    'definition' => [
        'type' => 'function',
        'function' => [
            'name' => 'tool_name',
            'description' => '工具描述',
            'parameters' => [
                'type' => 'object',
                'properties' => [...],
                'required' => [...]
            ]
        ]
    ],
    'execute' => function($args) {
        // 执行逻辑
        return ['success' => true, 'result' => ...];
    }
];
```

---

## 6. 性能需求

- 流式响应延迟 < 100ms
- 页面加载时间 < 2s
- 支持并发连接数 > 100
- 内存占用 < 128MB

---

## 7. 安全性需求

- CORS 跨域支持
- 输入内容转义
- JSON 输出格式化
- 错误信息脱敏

---

## 8. 兼容性需求

### 8.1 浏览器
- Chrome 80+
- Firefox 75+
- Safari 13.1+
- Edge 80+

### 8.2 PHP 版本
- PHP 7.4+
- PHP 8.0+

---

## 9. 验收标准

- [x] 服务正常启动
- [x] 能加载 Ollama/vLLM 模型列表
- [x] 能发送消息并获得 AI 回复
- [x] 流式输出正常工作
- [x] Tool Calling 功能正常
- [x] Think 标签正确显示
- [x] Think 为空时不显示
- [x] 工具面板正常显示
- [x] 多轮工具调用正常工作
- [x] HTML 安全转义正常
- [ ] 界面美观易用

---

## 10. 已知问题修复记录

### 10.1 Think 为空时显示空盒子
- **问题**: `<think></think>` 内容为空时，前端仍显示空的 think 盒子
- **修复**: 前端渲染时检查 think 内容是否为空，为空则不渲染盒子
- **修复日期**: 2026-04-10

### 10.2 Tool 只触发一次
- **问题**: 多轮对话时，第二次及后续无法触发工具
- **原因**: 当 AI 输出 thinking 后再调用工具时，thinking 内容未保存到消息历史
- **修复**: 检测到 tool_calls 时，先将之前累积的 content 保存为 assistant 消息
- **修复日期**: 2026-04-10

### 10.3 HTML 特殊字符导致页面解析错误
- **问题**: 工具描述中的 `</script>` 等字符破坏 HTML 结构
- **修复**: 使用 `JSON_HEX_TAG` 转义 JSON 中的 `<` 和 `>`，使用 `htmlspecialchars` 转义其他 HTML 特殊字符
- **修复日期**: 2026-04-10

### 10.4 Tools 开关关闭后仍调用函数
- **问题**: 关闭 Tools 开关后，函数调用仍然被触发
- **原因**: `$selectedTools` 初始值设为 `$tools`，未检查 `$streamTools` 状态
- **修复**: 当 `$streamTools` 为 false 时，`$selectedTools` 设为空数组
- **修复日期**: 2026-04-10

### 10.5 新增函数搜索页面
- **功能**: 添加 `/functions` 页面，支持 Qdrant 向量检索
- **内容**: 搜索框检索相关函数、显示所有可用函数列表
- **添加日期**: 2026-04-10
