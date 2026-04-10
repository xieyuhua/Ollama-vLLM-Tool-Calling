# AI Gateway - Ollama & vLLM Tool Calling 前端

基于 PHP Swoole 实现的流式 AI 对话系统，支持 Tool Calling 功能。

## 功能特性

- **流式输出** - 实时显示 AI 生成内容，无需等待完整响应
- **双后端支持** - 统一接口支持 Ollama 和 vLLM
- **Tool Calling** - AI 可调用自定义工具函数
- **Function RAG** - 基于 Qdrant 向量数据库的智能函数检索
- **Think 标签** - 支持显示 AI 思考过程
- **深色主题** - 现代化深色 UI 设计
- **响应式布局** - 支持 PC、平板、手机自适应
- **零前端依赖** - 纯原生 HTML/CSS/JS

## 系统要求

- PHP 7.4+
- Swoole 扩展
- Ollama 或 vLLM 服务
- Qdrant 服务 (可选，用于函数检索)

## 快速开始

```bash
# 1. 启动 Ollama (或其他后端)
ollama serve

# 2. 启动服务器
php server.php

# 3. 访问
http://localhost:9501
```

## 配置

通过环境变量配置：

| 变量 | 默认值 | 说明 |
|------|--------|------|
| OLLAMA_URL | http://localhost:11434 | Ollama 服务地址 |
| VLLM_URL | http://localhost:8000 | vLLM 服务地址 |
| PORT | 9501 | HTTP 服务端口 |
| QDRANT_HOST | localhost | Qdrant 服务地址 |
| QDRANT_PORT | 6333 | Qdrant 服务端口 |
| EMBEDDING_URL | http://localhost:11434/api/embeddings | 向量化服务地址 |
| EMBEDDING_MODEL | nomic-embed-text | 向量化模型 |
| USE_QDRANT | true | 是否启用 Qdrant 函数检索 |

```bash
# 示例：自定义配置
set OLLAMA_URL=http://192.168.1.100:11434
set PORT=8080
php server.php
```

## Qdrant 函数检索 (Function RAG)

启用后，系统会根据用户查询自动检索最相关的工具函数，而不是将所有工具传给模型。

### 启动 Qdrant

```bash
# 使用 Docker 启动 Qdrant
docker run -p 6333:6333 -p 6334:6334 qdrant/qdrant
```

### 索引工具函数

启动服务器后，调用索引接口：

```bash
curl -X POST http://localhost:9501/api/qdrant/index
```

### 测试检索

```bash
curl -X POST http://localhost:9501/api/qdrant/search \
  -H "Content-Type: application/json" \
  -d '{"query": "天气查询", "limit": 3}'
```

## API 接口

### 流式聊天
```
POST /api/chat/stream
Content-Type: application/json

{
  "provider": "ollama",      // 或 "vllm"
  "model": "llama3.2",
  "messages": [
    {"role": "user", "content": "你好"}
  ],
  "tools": true              // 是否启用工具调用
}
```

### 模型列表
```
GET /api/models?provider=ollama
```

### 健康检查
```
GET /health
```

### 工具列表
```
GET /api/tools
```

## 扩展工具

在 `tools/` 目录下添加 PHP 文件即可扩展工具：

```php
<?php
// tools/calculator.php
return [
    'definition' => [
        'type' => 'function',
        'function' => [
            'name' => 'calculator',
            'description' => '执行数学计算',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'expression' => [
                        'type' => 'string',
                        'description' => '数学表达式，如：2+3*5'
                    ]
                ],
                'required' => ['expression']
            ]
        ]
    ],
    'execute' => function($args) {
        $expr = $args['expression'];
        eval('$result = ' . $expr . ';');
        return ['success' => true, 'result' => "$expr = $result"];
    }
];
```

## 项目结构

```
├── server.php        # 主服务器 (PHP + HTML + JS)
├── tools/
│   ├── loader.php     # 工具加载器
│   ├── calculate.php # 计算器工具
│   ├── get_time.php  # 时间工具
│   ├── get_weather.php # 天气工具
│   └── search_web.php  # 搜索工具
├── bin/
│   ├── manage.bat    # Windows 管理脚本
│   └── manage.sh     # Linux 管理脚本
└── logs/             # 日志目录
```

## 工作流程

1. 用户发送消息
2. 服务器转发至 Ollama/vLLM
3. AI 判断是否需要调用工具
4. 如果需要：
   - 发送 tool_call 事件
   - 执行工具获取结果
   - 将结果反馈给 AI
   - AI 生成最终回复
5. 流式返回给前端显示

## 浏览器兼容性

- Chrome 80+
- Firefox 75+
- Safari 13.1+
- Edge 80+

## License

MIT
