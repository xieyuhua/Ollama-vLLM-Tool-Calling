# AI Gateway - Ollama & vLLM 流式前端

基于纯 PHP 实现的流式 AI 对话前端，支持 Ollama 和 vLLM。

## 特性

- 🌊 **流式输出** - 实时显示 AI 生成内容
- 🔄 **双后端支持** - Ollama / vLLM 统一接口
- ⚡ **零依赖** - 仅需 PHP，无需 Swoole/Composer
- 🎨 **美观界面** - 深色主题现代化 UI

## 运行

```bash
# 直接运行
php server.php

# 或指定端口
set PORT=8080 && php server.php
```

访问 **http://localhost:9501**

## 环境变量

| 变量 | 默认值 | 说明 |
|------|--------|------|
| OLLAMA_URL | http://localhost:11434 | Ollama 地址 |
| VLLM_URL | http://localhost:8000 | vLLM 地址 |
| PORT | 9501 | 服务端口 |

## API

```
POST /api/chat/stream  - 流式聊天
GET  /api/models       - 模型列表
GET  /health           - 健康检查
```

## 文件结构

```
├── server.php    # 唯一文件 (PHP + HTML + JS)
├── bin/
│   ├── manage.bat    # Windows 管理脚本
│   └── manage.sh    # Linux 管理脚本
└── logs/            # 日志目录
```
