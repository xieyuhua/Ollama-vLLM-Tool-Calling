name: ai-gateway
description: |
  AI Gateway 管理工具 - 用于管理 Ollama 和 vLLM 流式对话服务
  
  触发场景:
  - 启动/停止/重启 AI Gateway 服务
  - 查看服务状态和日志
  - 配置 Ollama 或 vLLM 后端
  - 部署 AI Gateway 到生产环境
  - 管理多个 AI 模型
  - 查看实时对话日志

triggers:
  - 启动 ai gateway
  - 停止 ai gateway
  - ai-gateway 状态
  - ai gateway 日志
  - 部署 ai-gateway
  - 管理 ollama
  - 管理 vllm

commands:
  start:
    description: 启动 AI Gateway 服务
    command: php server.php
    workingDirectory: "{{workspace}}"
    
  status:
    description: 检查服务状态
    command: curl -s http://localhost:9501/health || echo "服务未运行"
    
  logs:
    description: 查看服务日志
    command: tail -f logs/server.log
    workingDirectory: "{{workspace}}"
