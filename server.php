<?php
/**
 * AI Gateway - Ollama & vLLM 流式代理 (纯 PHP，无协程依赖)
 */

// 配置
define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://localhost:11434');
define('VLLM_URL', getenv('VLLM_URL') ?: 'http://localhost:8000');
define('PORT', (int)(getenv('PORT') ?: 9501));

// 错误处理
set_error_handler(function($severity, $message, $file, $line) {
    error_log("Error: $message at $file:$line");
});

// 日志
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

$logFile = __DIR__ . '/logs/server.log';

function logMsg(string $msg): void {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " $msg\n", FILE_APPEND);
}

logMsg("Server starting on port " . PORT);

// 创建 TCP 服务器
$server = stream_socket_server("tcp://0.0.0.0:" . PORT, $errno, $errstr);

if (!$server) {
    die("创建服务器失败: $errstr ($errno)\n");
}

logMsg("Server started on http://localhost:" . PORT);

echo "🚀 AI Gateway 启动成功\n";
echo "📍 访问: http://localhost:" . PORT . "\n";
echo "按 Ctrl+C 停止\n\n";

while ($client = @stream_socket_accept($server, 300)) {
    handleClient($client);
}

function handleClient($client): void {
    // 读取请求行
    $requestLine = fgets($client);
    if (!$requestLine) {
        fclose($client);
        return;
    }
    
    // 解析请求
    preg_match('/^(\w+)\s+(\S+)\s+HTTP/', $requestLine, $matches);
    $method = $matches[1] ?? 'GET';
    $path = $matches[2] ?? '/';
    
    // 读取请求头
    $headers = [];
    $contentLength = 0;
    while (($line = fgets($client)) !== false) {
        $line = trim($line);
        if ($line === '') break;
        if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $m)) {
            $contentLength = (int)$m[1];
        }
        if (preg_match('/^([^:]+):\s*(.+)/', $line, $m)) {
            $headers[strtolower($m[1])] = $m[2];
        }
    }
    
    // 读取请求体
    $body = '';
    if ($contentLength > 0) {
        $body = fread($client, $contentLength);
    }
    
    // CORS
    $cors = "Access-Control-Allow-Origin: *\r\n"
          . "Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n"
          . "Access-Control-Allow-Headers: Content-Type\r\n";
    
    // 处理请求
    try {
        if ($method === 'OPTIONS') {
            sendResponse($client, 204, $cors, '');
            fclose($client);
            return;
        }
        
        switch ($path) {
            case '/':
            case '/index.html':
                serveIndex($client, $cors);
                break;
                
            case '/api/chat/stream':
                handleStreamChat($client, $cors, $body);
                break;
                
            case '/api/models':
                handleModels($client, $cors, $path);
                break;
                
            case '/health':
                sendResponse($client, 200, $cors, json_encode(['status' => 'ok']));
                break;
                
            default:
                sendResponse($client, 404, $cors, 'Not Found');
        }
    } catch (Throwable $e) {
        logMsg("Error: " . $e->getMessage());
        sendResponse($client, 500, $cors, json_encode(['error' => $e->getMessage()]));
    }
    
    fclose($client);
}

function sendResponse($client, int $code, string $cors, string $body, array $extraHeaders = []): void {
    $codes = [200 => 'OK', 204 => 'No Content', 400 => 'Bad Request', 404 => 'Not Found', 500 => 'Internal Server Error'];
    
    $headers = "HTTP/1.1 $code {$codes[$code]}\r\n";
    $headers .= "Content-Type: application/json\r\n";
    $headers .= "Content-Length: " . strlen($body) . "\r\n";
    $headers .= "Connection: close\r\n";
    $headers .= $cors;
    
    foreach ($extraHeaders as $k => $v) {
        $headers .= "$k: $v\r\n";
    }
    
    $headers .= "\r\n";
    
    fwrite($client, $headers . $body);
}

function sendSSEHeaders($client, string $cors): void {
    $headers = "HTTP/1.1 200 OK\r\n";
    $headers .= "Content-Type: text/event-stream\r\n";
    $headers .= "Cache-Control: no-cache\r\n";
    $headers .= "Connection: close\r\n";
    $headers .= $cors;
    $headers .= "\r\n";
    fwrite($client, $headers);
}

/**
 * 流式聊天
 */
function handleStreamChat($client, string $cors, string $body): void
{
    $data = json_decode($body, true);
    
    if (!$data || !isset($data['messages'])) {
        sendResponse($client, 400, $cors, json_encode(['error' => 'Invalid request']));
        return;
    }
    
    $provider = $data['provider'] ?? 'ollama';
    $model = $data['model'] ?? 'llama3.2';
    $messages = $data['messages'];
    
    logMsg("Stream request: provider=$provider, model=$model");
    
    if ($provider === 'ollama') {
        streamOllama($client, $cors, $model, $messages);
    } else {
        streamVLLM($client, $cors, $model, $messages);
    }
}

/**
 * Ollama 流式输出
 */
function streamOllama($client, string $cors, string $model, array $messages): void
{
    $ollamaMessages = array_map(fn($m) => [
        'role' => $m['role'] ?? 'user',
        'content' => $m['content'] ?? '',
    ], $messages);
    
    $postData = json_encode([
        'model' => $model,
        'messages' => $ollamaMessages,
        'stream' => true,
    ]);
    
    // 解析 Ollama URL
    $parts = parse_url(OLLAMA_URL);
    $host = $parts['host'] ?? '127.0.0.1';
    $port = $parts['port'] ?? 80;
    $path = '/api/chat';
    
    logMsg("Connecting to Ollama: $host:$port");
    
    // 创建流式请求
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $postData,
            'timeout' => 300,
            'ignore_errors' => true,
        ]
    ]);
    
    $fp = @fopen("http://{$host}:{$port}{$path}", 'r', false, $ctx);
    
    if (!$fp) {
        logMsg("Cannot connect to Ollama at $host:$port");
        sendSSEHeaders($client, $cors);
        fwrite($client, "data: " . json_encode(['error' => 'Cannot connect to Ollama']) . "\n\n");
        fwrite($client, "data: [DONE]\n\n");
        fflush($client);
        return;
    }
    
    sendSSEHeaders($client, $cors);
    fflush($client);
    
    $buffer = '';
    
    // 读取流式响应
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) break;
        
        $buffer .= $chunk;
        
        // 处理每一行
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            
            $line = trim($line);
            if (strpos($line, 'data: ') === 0) {
                $json = substr($line, 6);
                
                if ($json === '[DONE]') {
                    fwrite($client, "data: [DONE]\n\n");
                    fflush($client);
                    fclose($fp);
                    return;
                }
                
                $data = json_decode($json, true);
                if ($data && isset($data['message']['content'])) {
                    $content = $data['message']['content'];
                    fwrite($client, "data: " . json_encode(['type' => 'content', 'content' => $content]) . "\n\n");
                    fflush($client);
                }
            }
        }
    }
    
    fwrite($client, "data: [DONE]\n\n");
    fflush($client);
    fclose($fp);
}

/**
 * vLLM 流式输出
 */
function streamVLLM($client, string $cors, string $model, array $messages): void
{
    // 合并消息
    $prompt = '';
    foreach ($messages as $msg) {
        $role = $msg['role'] ?? 'user';
        $content = $msg['content'] ?? '';
        $prompt .= "<|{$role}|>\n{$content}\n";
    }
    $prompt .= "<|assistant|>\n";
    
    $parts = parse_url(VLLM_URL);
    $host = $parts['host'] ?? '127.0.0.1';
    $port = $parts['port'] ?? 80;
    
    $postData = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true,
        'max_tokens' => 4096,
    ]);
    
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $postData,
            'timeout' => 300,
            'ignore_errors' => true,
        ]
    ]);
    
    $fp = @fopen("http://{$host}:{$port}/v1/completions", 'r', false, $ctx);
    
    if (!$fp) {
        logMsg("Cannot connect to vLLM at $host:$port");
        sendSSEHeaders($client, $cors);
        fwrite($client, "data: " . json_encode(['error' => 'Cannot connect to vLLM']) . "\n\n");
        fwrite($client, "data: [DONE]\n\n");
        fflush($client);
        return;
    }
    
    sendSSEHeaders($client, $cors);
    fflush($client);
    
    $buffer = '';
    
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) break;
        
        $buffer .= $chunk;
        
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            
            $line = trim($line);
            if (!empty($line) && strpos($line, 'data: ') === 0) {
                $json = substr($line, 6);
                
                if ($json === '[DONE]') {
                    fwrite($client, "data: [DONE]\n\n");
                    fflush($client);
                    fclose($fp);
                    return;
                }
                
                $data = json_decode($json, true);
                if ($data && isset($data['choices'][0]['text'])) {
                    $content = $data['choices'][0]['text'];
                    fwrite($client, "data: " . json_encode(['type' => 'content', 'content' => $content]) . "\n\n");
                    fflush($client);
                }
            }
        }
    }
    
    fwrite($client, "data: [DONE]\n\n");
    fflush($client);
    fclose($fp);
}

/**
 * 模型列表
 */
function handleModels($client, string $cors, string $uri): void
{
    // 从 URI 解析查询参数
    $query = parse_url($uri, PHP_URL_QUERY) ?: '';
    parse_str($query, $params);
    $provider = $params['provider'] ?? 'ollama';
    
    logMsg("Fetching models from: $provider");
    
    if ($provider === 'ollama') {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $result = @file_get_contents(OLLAMA_URL . '/api/tags', false, $ctx);
        $data = json_decode($result, true) ?? [];
        $models = array_map(fn($m) => ['name' => $m['name'], 'provider' => 'ollama'], $data['models'] ?? []);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $result = @file_get_contents(VLLM_URL . '/v1/models', false, $ctx);
        $data = json_decode($result, true) ?? [];
        $models = array_map(fn($m) => ['name' => $m['id'], 'provider' => 'vllm'], $data['data'] ?? []);
    }
    
    sendResponse($client, 200, $cors, json_encode(['models' => $models]));
}

/**
 * 前端页面
 */
function serveIndex($client, string $cors): void
{
    $html = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Gateway</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: #0f0f1a; color: #fff; min-height: 100vh; }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 20px 0; border-bottom: 1px solid #333; margin-bottom: 20px; }
        h1 { font-size: 24px; background: linear-gradient(135deg, #667eea, #764ba2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .config { display: flex; gap: 12px; margin-bottom: 20px; }
        select, button { padding: 10px 16px; border-radius: 8px; border: none; font-size: 14px; cursor: pointer; }
        select { background: #1a1a2e; color: #fff; border: 1px solid #333; min-width: 150px; }
        button { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        .chat-box { background: #1a1a2e; border-radius: 12px; height: 500px; overflow-y: auto; padding: 20px; margin-bottom: 20px; }
        .message { margin-bottom: 16px; padding: 12px 16px; border-radius: 12px; max-width: 85%; line-height: 1.6; word-break: break-word; }
        .message.user { background: #667eea; margin-left: auto; }
        .message.assistant { background: #2d2d44; }
        .input-area { display: flex; gap: 12px; }
        textarea { flex: 1; padding: 14px; border-radius: 12px; background: #1a1a2e; border: 1px solid #333; color: #fff; font-size: 14px; resize: none; min-height: 56px; }
        textarea:focus { outline: none; border-color: #667eea; }
        .loading { display: inline-block; width: 18px; height: 18px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .status { font-size: 12px; color: #888; margin-top: 10px; min-height: 18px; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>AI Gateway</h1>
            <div class="config">
                <select id="provider">
                    <option value="ollama">Ollama</option>
                    <option value="vllm">vLLM</option>
                </select>
                <select id="model"><option>加载中...</option></select>
                <button onclick="loadModels()">刷新</button>
            </div>
        </header>
        
        <div class="chat-box" id="chatBox"></div>
        
        <div class="input-area">
            <textarea id="input" placeholder="输入消息 (Enter 发送)" rows="1"></textarea>
            <button id="sendBtn" onclick="send()">发送</button>
        </div>
        <div class="status" id="status"></div>
    </div>

    <script>
        const chatBox = document.getElementById('chatBox');
        const input = document.getElementById('input');
        const sendBtn = document.getElementById('sendBtn');
        const status = document.getElementById('status');
        const provider = document.getElementById('provider');
        const model = document.getElementById('model');
        
        let messages = [];
        let loading = false;

        async function loadModels() {
            status.textContent = '加载模型...';
            try {
                const res = await fetch('/api/models?provider=' + provider.value);
                const data = await res.json();
                model.innerHTML = data.models.map(m => 
                    '<option value="' + m.name + '">' + m.name + '</option>'
                ).join('');
                status.textContent = '已加载 ' + data.models.length + ' 个模型';
            } catch (e) {
                status.textContent = '加载失败: ' + e.message;
            }
        }

        input.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 200) + 'px';
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                send();
            }
        });

        provider.addEventListener('change', loadModels);

        function addMsg(role, content) {
            const div = document.createElement('div');
            div.className = 'message ' + role;
            div.innerHTML = content.replace(/\n/g, '<br>');
            chatBox.appendChild(div);
            chatBox.scrollTop = chatBox.scrollHeight;
            return div;
        }

        async function send() {
            const text = input.value.trim();
            if (!text || loading) return;

            messages.push({ role: 'user', content: text });
            addMsg('user', text);
            input.value = '';
            input.style.height = 'auto';

            loading = true;
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<span class="loading"></span>';

            const aiDiv = addMsg('assistant', '');
            let full = '';

            try {
                const res = await fetch('/api/chat/stream', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        provider: provider.value,
                        model: model.value,
                        messages: messages
                    })
                });

                const reader = res.body.getReader();
                const decoder = new TextDecoder();

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    const chunk = decoder.decode(value);
                    const lines = chunk.split('\n');

                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            const data = line.slice(6);
                            if (data === '[DONE]') break;
                            try {
                                const p = JSON.parse(data);
                                if (p.content) {
                                    full += p.content;
                                    aiDiv.innerHTML = full.replace(/\n/g, '<br>');
                                    chatBox.scrollTop = chatBox.scrollHeight;
                                }
                            } catch {}
                        }
                    }
                }

                messages.push({ role: 'assistant', content: full });
            } catch (e) {
                aiDiv.innerHTML = '<span style="color:#ff6b6b">错误: ' + e.message + '</span>';
            } finally {
                loading = false;
                sendBtn.disabled = false;
                sendBtn.innerHTML = '发送';
            }
        }

        loadModels();
    </script>
</body>
</html>
HTML;
    
    $headers = "HTTP/1.1 200 OK\r\n";
    $headers .= "Content-Type: text/html; charset=utf-8\r\n";
    $headers .= "Content-Length: " . strlen($html) . "\r\n";
    $headers .= "Connection: close\r\n";
    $headers .= "Access-Control-Allow-Origin: *\r\n";
    $headers .= "\r\n";
    
    fwrite($client, $headers . $html);
}
