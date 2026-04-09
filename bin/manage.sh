#!/bin/bash
# AI Gateway 服务管理脚本

WORKSPACE="$(cd "$(dirname "$0")" && pwd)"
PID_FILE="$WORKSPACE/ai-gateway.pid"
PORT=${PORT:-9501}

start() {
    if [ -f "$PID_FILE" ] && kill -0 "$(cat $PID_FILE)" 2>/dev/null; then
        echo "AI Gateway 已在运行 (PID: $(cat $PID_FILE))"
        return 1
    fi
    
    echo "🚀 启动 AI Gateway..."
    cd "$WORKSPACE"
    php server.php > /dev/null 2>&1 &
    echo $! > "$PID_FILE"
    sleep 1
    
    if curl -s "http://localhost:$PORT/health" > /dev/null; then
        echo "✅ AI Gateway 已启动 (PID: $(cat $PID_FILE), 端口: $PORT)"
    else
        echo "❌ 启动失败，请检查日志"
        rm -f "$PID_FILE"
        return 1
    fi
}

stop() {
    if [ ! -f "$PID_FILE" ]; then
        echo "AI Gateway 未运行"
        return 1
    fi
    
    PID=$(cat "$PID_FILE")
    if kill -0 "$PID" 2>/dev/null; then
        echo "🛑 停止 AI Gateway (PID: $PID)..."
        kill "$PID"
        rm -f "$PID_FILE"
        echo "✅ 已停止"
    else
        echo "进程已不存在，清理 PID 文件"
        rm -f "$PID_FILE"
    fi
}

restart() {
    stop
    sleep 1
    start
}

status() {
    if [ -f "$PID_FILE" ] && kill -0 "$(cat $PID_FILE)" 2>/dev/null; then
        echo "✅ AI Gateway 运行中 (PID: $(cat $PID_FILE), 端口: $PORT)"
        curl -s "http://localhost:$PORT/health"
    else
        echo "❌ AI Gateway 未运行"
        return 1
    fi
}

logs() {
    tail -f "$WORKSPACE/logs/server.log"
}

case "$1" in
    start)   start ;;
    stop)    stop ;;
    restart) restart ;;
    status)  status ;;
    logs)    logs ;;
    *)
        echo "用法: $0 {start|stop|restart|status|logs}"
        exit 1
        ;;
esac
