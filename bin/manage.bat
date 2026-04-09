@echo off
:: AI Gateway 服务管理脚本 (Windows)

setlocal enabledelayedexpansion

set "WORKSPACE=%~dp0.."
set "PID_FILE=%WORKSPACE%\ai-gateway.pid"
set "PORT=%PORT:=9501%"

if "%1"=="" (
    echo 用法: manage.bat {start^|stop^|restart^|status^|logs}
    exit /b 1
)

if "%1"=="start" (
    if exist "%PID_FILE%" (
        set /p PID=<"%PID_FILE%"
        tasklist /FI "PID eq %PID%" | find /I "%PID%" >nul
        if !errorlevel!==0 (
            echo AI Gateway 已在运行 (PID: %PID%)
            exit /b 1
        )
    )
    
    echo 启动 AI Gateway...
    cd /d "%WORKSPACE%"
    start /B php server.php > nul 2>^&1
    
    :: 获取新进程 PID (简化处理)
    timeout /t 2 /nobreak >nul
    echo AI Gateway 启动中...
    curl -s "http://localhost:%PORT%/health" >nul 2>^&1
    if !errorlevel!==0 (
        echo AI Gateway 已启动 ^(端口: %PORT%^)
    ) else (
        echo 启动中，请稍后使用 status 查看状态
    )
    exit /b 0
)

if "%1"=="stop" (
    if exist "%PID_FILE%" (
        set /p PID=<"%PID_FILE%"
        taskkill /F /PID %PID% >nul 2>^&1
        del "%PID_FILE%"
        echo 已停止
    ) else (
        echo AI Gateway 未运行
    )
    exit /b 0
)

if "%1"=="restart" (
    call "%~f0" stop
    timeout /t 1 /nobreak >nul
    call "%~f0" start
    exit /b 0
)

if "%1"=="status" (
    curl -s "http://localhost:%PORT%/health"
    if !errorlevel!==0 (
        echo.
        echo AI Gateway 运行中 ^(端口: %PORT%^)
    ) else (
        echo AI Gateway 未运行
        exit /b 1
    )
    exit /b 0
)

if "%1"=="logs" (
    if exist "%WORKSPACE%\logs\server.log" (
        type "%WORKSPACE%\logs\server.log"
    ) else (
        echo 暂无日志文件
    )
    exit /b 0
)

echo 未知命令: %1
echo 用法: manage.bat {start^|stop^|restart^|status^|logs}
