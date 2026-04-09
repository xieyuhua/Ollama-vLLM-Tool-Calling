<?php
/**
 * AI Gateway - Ollama & vLLM 流式代理 (Swoole 版本)
 */

define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://localhost:11434');
define('VLLM_URL', getenv('VLLM_URL') ?: 'http://localhost:8000');
define('PORT', (int)(getenv('PORT') ?: 9501));

// 日志
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

$http = new Swoole\Http\Server('0.0.0.0', PORT);

$http->set([
    'worker_num' => 1,
    'daemonize' => false,
    'log_file' => __DIR__ . '/logs/server.log',
    'enable_coroutine' => false,  // 禁用协程，使用同步模式
]);

$http->on('Request', function ($request, $response) {
    $path = $request->server['request_uri'] ?? '/';
    $method = $request->server['request_method'] ?? 'GET';
    
    $response->header('Access-Control-Allow-Origin', '*');
    $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    $response->header('Access-Control-Allow-Headers', 'Content-Type');
    
    if ($method === 'OPTIONS') {
        $response->status(204);
        $response->end();
        return;
    }
    
    try {
        switch ($path) {
            case '/api/chat/stream':
                handleStreamChat($request, $response);
                break;
            case '/api/models':
                handleModels($request, $response);
                break;
            case '/health':
                $response->end(json_encode(['status' => 'ok']));
                break;
            case '/':
            case '/index.html':
                serveIndex($response);
                break;
            default:
                $response->status(404);
                $response->end('Not Found');
        }
    } catch (Throwable $e) {
        $response->status(500);
        $response->end(json_encode(['error' => $e->getMessage()]));
    }
});

function handleStreamChat($request, $response): void
{
    $data = json_decode($request->getContent(), true);
    
    if (!$data || !isset($data['messages'])) {
        $response->status(400);
        $response->end(json_encode(['error' => 'Invalid request']));
        return;
    }
    
    $provider = $data['provider'] ?? 'ollama';
    $model = $data['model'] ?? 'llama3.2';
    $messages = $data['messages'];
    
    $response->header('Content-Type', 'text/event-stream');
    $response->header('Cache-Control', 'no-cache');
    $response->header('X-Accel-Buffering', 'no');
    
    if ($provider === 'ollama') {
        streamOllama($response, $model, $messages);
    } else {
        streamVLLM($response, $model, $messages);
    }
}

function streamOllama($response, string $model, array $messages): void
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
    
    // 使用 curl 同步请求
    $ch = curl_init(OLLAMA_URL . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($response) {
            static $buffer = '';
            $buffer .= $chunk;
            
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = trim($line);
                var_dump($line);

                $data = json_decode($line, true);
                if ($data && isset($data['message']['content'])) {
                    $response->write("data: " . json_encode([
                        'type' => 'content',
                        'content' => $data['message']['content'],
                    ]) . "\n\n");
                }
                
            }
            return strlen($chunk);
        },
        CURLOPT_TIMEOUT => 300,
    ]);
    
    $result = curl_exec($ch);
    
    if ($result === false) {
        $error = curl_error($ch);
        $response->write("data: " . json_encode(['error' => $error]) . "\n\n");
    }
    
    $response->write("data: [DONE]\n\n");
    $response->end();
    curl_close($ch);
}

function streamVLLM($response, string $model, array $messages): void
{
    $prompt = '';
    foreach ($messages as $msg) {
        $role = $msg['role'] ?? 'user';
        $content = $msg['content'] ?? '';
        $prompt .= "<|{$role}|>\n{$content}\n";
    }
    $prompt .= "<|assistant|>\n";
    
    $postData = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true,
        'max_tokens' => 4096,
    ]);
    
    $ch = curl_init(VLLM_URL . '/v1/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($response) {
            static $buffer = '';
            $buffer .= $chunk;
            
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                
                $line = trim($line);
                $data = json_decode($line, true);
                if ($data && isset($data['choices'][0]['text'])) {
                    $response->write("data: " . json_encode([
                        'type' => 'content',
                        'content' => $data['choices'][0]['text'],
                    ]) . "\n\n");
                }
            }
            return strlen($chunk);
        },
        CURLOPT_TIMEOUT => 300,
    ]);
    
    $result = curl_exec($ch);
    
    if ($result === false) {
        $response->write("data: " . json_encode(['error' => curl_error($ch)]) . "\n\n");
    }
    
    $response->write("data: [DONE]\n\n");
    $response->end();
    curl_close($ch);
}

function handleModels($request, $response): void
{
    $provider = $request->get['provider'] ?? 'ollama';
    
    if ($provider === 'ollama') {
        $result = httpGet(OLLAMA_URL . '/api/tags');
        $data = json_decode($result, true) ?? [];
        $models = array_map(fn($m) => ['name' => $m['name'], 'provider' => 'ollama'], $data['models'] ?? []);
    } else {
        $result = httpGet(VLLM_URL . '/v1/models');
        $data = json_decode($result, true) ?? [];
        $models = array_map(fn($m) => ['name' => $m['id'], 'provider' => 'vllm'], $data['data'] ?? []);
    }
    
    $response->end(json_encode(['models' => $models]));
}

function httpGet(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result ?: '';
}

function serveIndex($response): void
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
    
    $response->header('Content-Type', 'text/html; charset=utf-8');
    $response->end($html);
}

echo "🚀 AI Gateway (Swoole) 启动中...\n";
echo "📍 访问: http://localhost:" . PORT . "\n";
echo "📦 支持: Ollama | vLLM\n\n";

$http->start();
