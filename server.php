<?php
/**
 * AI Gateway - Ollama & vLLM 流式代理 (支持 Tool Calling)
 * PHP 7.4+
 */

define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://localhost:11434');
define('VLLM_URL', getenv('VLLM_URL') ?: 'http://localhost:8000');
define('PORT', (int)(getenv('PORT') ?: 9501));

// 日志目录
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

$http = new Swoole\Http\Server('0.0.0.0', PORT);

$http->set([
    'worker_num' => 1,
    'daemonize' => false,
    'log_file' => __DIR__ . '/logs/server.log',
    'enable_coroutine' => false,
]);

// 加载工具
require_once __DIR__ . '/tools/loader.php';
$tools = loadTools();

$http->on('Request', function ($request, $response) use ($tools) {
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
            case '/api/chat':
                handleChat($request, $response);
                break;
            case '/api/tools':
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode(['tools' => $tools]));
                break;
            case '/api/execute':
                handleExecute($request, $response);
                break;
            case '/api/models':
                handleModels($request, $response);
                break;
            case '/health':
                $response->end(json_encode(['status' => 'ok']));
                break;
            case '/':
            case '/index.html':
                serveIndex($response, $tools);
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

function handleExecute($request, $response)
{
    $data = json_decode($request->getContent(), true);
    
    if (!$data || !isset($data['name']) || !isset($data['arguments'])) {
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['error' => 'Invalid request']));
        return;
    }
    
    $result = executeTool($data['name'], $data['arguments']);
    
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode($result));
}

function handleStreamChat($request, $response)
{
    $data = json_decode($request->getContent(), true);
    
    if (!$data || !isset($data['messages'])) {
        $response->status(400);
        $response->end(json_encode(['error' => 'Invalid request']));
        return;
    }
    
    $provider = isset($data['provider']) ? $data['provider'] : 'ollama';
    $model = isset($data['model']) ? $data['model'] : 'llama3.2';
    $messages = $data['messages'];
    $streamTools = isset($data['tools']) ? $data['tools'] : true;
    
    $response->header('Content-Type', 'text/event-stream');
    $response->header('Cache-Control', 'no-cache');
    $response->header('X-Accel-Buffering', 'no');
    
    if ($provider === 'ollama') {
        streamOllamaWithTools($response, $model, $messages, $streamTools);
    } else {
        streamVLLMWithTools($response, $model, $messages, $streamTools);
    }
}

function streamOllamaWithTools($response, $model, $messages, $useTools)
{
    $ollamaMessages = [];
    foreach ($messages as $m) {
        $role = isset($m['role']) ? $m['role'] : 'user';
        $content = isset($m['content']) ? $m['content'] : '';
        
        if ($role === 'tool' && isset($m['name'])) {
            $ollamaMessages[] = [
                'role' => 'tool',
                'content' => is_string($m['content']) ? $m['content'] : json_encode($m['content']),
                'name' => $m['name']
            ];
        } elseif ($role === 'tool') {
            $ollamaMessages[] = [
                'role' => 'tool',
                'content' => is_string($m['content']) ? $m['content'] : json_encode($m['content'])
            ];
        } else {
            $ollamaMessages[] = [
                'role' => $role,
                'content' => $content
            ];
        }
    }
    
    $tools = $useTools ? $GLOBALS['tools'] : [];
    $maxIterations = 10;
    $iteration = 0;
    $usedTools = []; // 记录使用的工具
    
    while ($iteration < $maxIterations) {
        $iteration++;
        
        $postData = json_encode([
            'model' => $model,
            'messages' => $ollamaMessages,
            'stream' => true,
            'tools' => $tools,
        ]);
        
        $ch = curl_init(OLLAMA_URL . '/api/chat');
        $buffer = '';
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer) {
                $buffer .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_TIMEOUT => 300,
        ]);
        
        curl_exec($ch);
        
        if (curl_errno($ch)) {
            $response->write("event: error\ndata: " . json_encode(['error' => curl_error($ch)]) . "\n\n");
            $response->end();
            curl_close($ch);
            return;
        }
        curl_close($ch);
        
        // 解析并流式输出 SSE 数据
        $lines = explode("\n", $buffer);
        $hasToolCall = false;
        $fullContent = '';
        
        foreach ($lines as $line) {

            $json = substr($line, 6);
            if ($json === '[DONE]' || $json === '') continue;
            
            $data = json_decode(trim($line), true);
            if (!$data) continue;
            
            if (isset($data['message']['tool_calls']) && !empty($data['message']['tool_calls'])) {
                $hasToolCall = true;
                $toolCalls = $data['message']['tool_calls'];
                
                // 流式发送工具调用
                $response->write("event: tool_call\ndata: " . json_encode([
                    'type' => 'tool_call',
                    'calls' => $toolCalls
                ]) . "\n\n");
                
                // 执行工具
                foreach ($toolCalls as $call) {
                    $func = isset($call['function']) ? $call['function'] : $call;
                    $name = $func['name'];
                    $args = $func['arguments'];
                    if (is_string($args)) {
                        $args = json_decode($args, true) ?: [];
                    }
                    
                    $result = executeTool($name, $args);
                    $usedTools[] = ['name' => $name, 'args' => $args, 'result' => $result];
                    
                    // 流式发送工具结果
                    $response->write("event: tool_result\ndata: " . json_encode([
                        'type' => 'tool_result',
                        'name' => $name,
                        'result' => $result
                    ]) . "\n\n");
                    
                    $ollamaMessages[] = [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [$call]
                    ];
                    $ollamaMessages[] = [
                        'role' => 'tool',
                        'content' => json_encode($result['result'] ?? $result),
                        'tool_call_id' => isset($call['id']) ? $call['id'] : uniqid('call_')
                    ];
                }
                
                break;
                
            } elseif (isset($data['message']['content'])) {
                $content = $data['message']['content'];
                if ($content !== '') {
                    $fullContent .= $content;
                    // 流式输出每个内容片段
                    $response->write("event: content\ndata: " . json_encode([
                        'type' => 'content',
                        'content' => $content
                    ]) . "\n\n");
                }
            } elseif (isset($data['error'])) {
                $response->write("event: error\ndata: " . json_encode([
                    'type' => 'content',
                    'content' => $data['error']
                ]) . "\n\n");
            }
        }
        
        if (!$hasToolCall) {
            // 最终回复，添加使用的工具说明
            if (!empty($usedTools)) {
                $toolSummary = "\n\n" . str_repeat("─", 30) . "\n";
                $toolSummary .= "📌 使用工具: ";
                $toolNames = array_column($usedTools, 'name');
                $toolSummary .= implode(", ", $toolNames);
                
                $response->write("event: content\ndata: " . json_encode([
                    'type' => 'content',
                    'content' => $toolSummary
                ]) . "\n\n");
            }
            
            $ollamaMessages[] = [
                'role' => 'assistant',
                'content' => $fullContent
            ];
            break;
        }
    }
    
    $response->write("event: done\ndata: [DONE]\n\n");
    $response->end();
}

function streamVLLMWithTools($response, $model, $messages, $useTools)
{
    $prompt = '';
    foreach ($messages as $msg) {
        $role = isset($msg['role']) ? $msg['role'] : 'user';
        $content = isset($msg['content']) ? $msg['content'] : '';
        
        if (isset($msg['tool_calls'])) {
            foreach ($msg['tool_calls'] as $call) {
                $func = $call['function'];
                $prompt .= "<|" . $role . "|>\n<|tool_calls|>\n<|name|>\n" . $func['name'] . "\n<|name|>\n<|arguments|>\n" . $func['arguments'] . "\n<|arguments|>\n";
            }
        } elseif (isset($msg['tool_call_id'])) {
            $result = isset($msg['content']) ? $msg['content'] : '';
            $callId = isset($msg['tool_call_id']) ? $msg['tool_call_id'] : '';
            $prompt .= "<|tool_response|>\n<|name|>\n" . $callId . "\n<|name|>\n" . $result . "\n<|tool_response|>\n";
        } else {
            $prompt .= "<|" . $role . "|>\n" . $content . "\n";
        }
    }
    $prompt .= "<|assistant|>\n";
    
    $postData = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true,
        'max_tokens' => 4096,
        'tools' => $useTools ? $GLOBALS['tools'] : [],
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
                
                $data = json_decode($line, true);
                if (isset($data['choices'][0]['text'])) {
                    $response->write("event: content\ndata: " . json_encode([
                        'type' => 'content',
                        'content' => $data['choices'][0]['text'],
                    ]) . "\n\n");
                }
                
            }

            $error = json_decode(trim($chunk), true);
            if(isset( $error['error'])){
                $response->write("event: content\ndata: " . json_encode([
                    'type' => 'content',
                    'content' => $error['error'],
                ]) . "\n\n");
            }

            return strlen($chunk);
        },
        CURLOPT_TIMEOUT => 300,
    ]);
    
    curl_exec($ch);
    if (curl_errno($ch)) {
        $response->write("event: error\ndata: " . json_encode(['error' => curl_error($ch)]) . "\n\n");
    }
    $response->write("event: done\ndata: [DONE]\n\n");
    $response->end();
    curl_close($ch);
}

function handleChat($request, $response)
{
    $data = json_decode($request->getContent(), true);
    
    if (!$data || !isset($data['messages'])) {
        $response->status(400);
        $response->end(json_encode(['error' => 'Invalid request']));
        return;
    }
    
    $provider = isset($data['provider']) ? $data['provider'] : 'ollama';
    $model = isset($data['model']) ? $data['model'] : 'llama3.2';
    $messages = $data['messages'];
    
    if ($provider === 'ollama') {
        $result = chatOllama($model, $messages);
    } else {
        $result = chatVLLM($model, $messages);
    }
    
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode($result));
}

function chatOllama($model, $messages)
{
    $ollamaMessages = [];
    foreach ($messages as $m) {
        $ollamaMessages[] = [
            'role' => isset($m['role']) ? $m['role'] : 'user',
            'content' => isset($m['content']) ? $m['content'] : '',
        ];
    }
    
    $postData = json_encode([
        'model' => $model,
        'messages' => $ollamaMessages,
        'stream' => false,
        'tools' => $GLOBALS['tools'],
    ]);
    
    $ch = curl_init(OLLAMA_URL . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($result, true) ?: [];
    return [
        'model' => $model,
        'content' => isset($data['message']['content']) ? $data['message']['content'] : '',
        'tool_calls' => isset($data['message']['tool_calls']) ? $data['message']['tool_calls'] : null,
    ];
}

function chatVLLM($model, $messages)
{
    $prompt = '';
    foreach ($messages as $msg) {
        $role = isset($msg['role']) ? $msg['role'] : 'user';
        $content = isset($msg['content']) ? $msg['content'] : '';
        $prompt .= "<|" . $role . "|>\n" . $content . "\n";
    }
    $prompt .= "<|assistant|>\n";
    
    $postData = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false,
        'max_tokens' => 4096,
    ]);
    
    $ch = curl_init(VLLM_URL . '/v1/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($result, true) ?: [];
    return [
        'model' => $model,
        'content' => isset($data['choices'][0]['text']) ? $data['choices'][0]['text'] : '',
    ];
}

function handleModels($request, $response)
{
    $provider = isset($request->get['provider']) ? $request->get['provider'] : 'ollama';
    
    if ($provider === 'ollama') {
        $result = httpGet(OLLAMA_URL . '/api/tags');
        $data = json_decode($result, true) ?: [];
        $models = [];
        foreach ($data['models'] ?? [] as $m) {
            $models[] = ['name' => $m['name'], 'provider' => 'ollama'];
        }
    } else {
        $result = httpGet(VLLM_URL . '/v1/models');
        $data = json_decode($result, true) ?: [];
        $models = [];
        foreach ($data['data'] ?? [] as $m) {
            $models[] = ['name' => $m['id'], 'provider' => 'vllm'];
        }
    }
    
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode(['models' => $models]));
}

function httpGet($url)
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

function serveIndex($response, $tools)
{
    $toolsJson = json_encode($tools);
    $toolsEnabled = count($tools) > 0 ? 'ON' : 'OFF';
    $toolsList = [];
    foreach ($tools as $t) {
        $toolsList[] = '<li class="tool-item" data-name="' . $t['function']['name'] . '">' 
            . '<span class="tool-icon">&#x1F527;</span>' 
            . '<span class="tool-name">' . $t['function']['name'] . '</span>'
            . '<span class="tool-desc">' . $t['function']['description'] . '</span>'
            . '</li>';
    }
    $toolsHtml = '<ul class="tools-list">' . implode('', $toolsList) . '</ul>';
    
    $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Gateway - Tool Calling</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: #0f0f1a; color: #fff; min-height: 100vh; }
        .container { max-width: 900px; margin: 0 auto; padding: 20px; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 20px 0; border-bottom: 1px solid #333; margin-bottom: 20px; }
        h1 { font-size: 24px; background: linear-gradient(135deg, #667eea, #764ba2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .config { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        select, button { padding: 10px 16px; border-radius: 8px; border: none; font-size: 14px; cursor: pointer; }
        select { background: #1a1a2e; color: #fff; border: 1px solid #333; min-width: 150px; }
        button { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        button.tool-btn { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .chat-box { background: #1a1a2e; border-radius: 12px; height: 450px; overflow-y: auto; padding: 20px; margin-bottom: 20px; }
        .message { margin-bottom: 16px; padding: 12px 16px; border-radius: 12px; max-width: 85%; line-height: 1.6; word-break: break-word; white-space: pre-wrap; }
        .message.user { background: #667eea; margin-left: auto; }
        .message.assistant { background: #2d2d44; }
        .message.tool-call { background: #1e3a5f; border-left: 3px solid #3b82f6; }
        .message.tool-result { background: #1a2e1a; border-left: 3px solid #22c55e; font-size: 13px; }
        .tool-call-box { background: #0f172a; padding: 10px 14px; border-radius: 8px; margin-bottom: 8px; }
        .tool-call-name { color: #3b82f6; font-weight: bold; }
        .tool-call-args { background: #1e293b; padding: 8px; border-radius: 4px; margin-top: 6px; font-family: monospace; font-size: 12px; white-space: pre-wrap; color: #94a3b8; }
        .tool-result-box { background: #14532d; padding: 10px 14px; border-radius: 8px; }
        .tool-result-label { color: #22c55e; font-weight: bold; font-size: 12px; margin-bottom: 4px; }
        .think-box { background: #252535; border-left: 3px solid #f59e0b; padding: 8px 12px; margin: 8px 0; border-radius: 0 6px 6px 0; font-size: 13px; color: #ccc; }
        .think-label { display: block; font-size: 11px; color: #f59e0b; margin-bottom: 4px; font-weight: bold; }
        .input-area { display: flex; gap: 12px; }
        textarea { flex: 1; padding: 14px; border-radius: 12px; background: #1a1a2e; border: 1px solid #333; color: #fff; font-size: 14px; resize: none; min-height: 56px; }
        textarea:focus { outline: none; border-color: #667eea; }
        .loading { display: inline-block; width: 18px; height: 18px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .status { font-size: 12px; color: #888; margin-top: 10px; min-height: 18px; }
        .tools-panel { background: #1a1a2e; border-radius: 12px; padding: 16px; margin-bottom: 20px; }
        .tools-panel h3 { font-size: 14px; margin-bottom: 12px; color: #888; }
        .tools-list { list-style: none; display: flex; flex-wrap: wrap; gap: 8px; }
        .tool-item { background: #0f172a; padding: 8px 12px; border-radius: 6px; font-size: 12px; display: flex; align-items: center; gap: 6px; }
        .tool-name { color: #3b82f6; font-weight: bold; }
        .tool-desc { color: #64748b; }
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
                <select id="model"><option>Loading...</option></select>
                <button onclick="loadModels()">Refresh</button>
                <button id="toolsToggleBtn" class="tool-btn" onclick="toggleTools()">Tools: ON</button>
            </div>
        </header>
        
        <div class="tools-panel" id="toolsPanel">
            <h3>Available Tools</h3>
            ' . $toolsHtml . '
        </div>
        
        <div class="chat-box" id="chatBox"></div>
        
        <div class="input-area">
            <textarea id="input" placeholder="Type message (Enter to send)" rows="1"></textarea>
            <button id="sendBtn" onclick="send()">Send</button>
        </div>
        <div class="status" id="status"></div>
    </div>

    <script>
        var chatBox = document.getElementById("chatBox");
        var input = document.getElementById("input");
        var sendBtn = document.getElementById("sendBtn");
        var status = document.getElementById("status");
        var provider = document.getElementById("provider");
        var model = document.getElementById("model");
        
        var messages = [];
        var loading = false;
        var useTools = true;
        
        var tools = ' . $toolsJson . ';

        function loadModels() {
            status.textContent = "Loading models...";
            fetch("/api/models?provider=" + provider.value)
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    var opts = [];
                    for (var i = 0; i < data.models.length; i++) {
                        opts.push("<option value=\"" + data.models[i].name + "\">" + data.models[i].name + "</option>");
                    }
                    model.innerHTML = opts.join("");
                    status.textContent = "Loaded " + data.models.length + " models";
                })
                .catch(function(e) {
                    status.textContent = "Load failed: " + e.message;
                });
        }

        function toggleTools() {
            useTools = !useTools;
            var btn = document.getElementById("toolsToggleBtn");
            btn.textContent = "Tools: " + (useTools ? "ON" : "OFF");
            btn.style.background = useTools 
                ? "linear-gradient(135deg, #22c55e, #16a34a)" 
                : "linear-gradient(135deg, #666, #444)";
            status.textContent = "Tools " + (useTools ? "enabled" : "disabled");
        }

        input.addEventListener("input", function() {
            this.style.height = "auto";
            this.style.height = Math.min(this.scrollHeight, 200) + "px";
        });

        input.addEventListener("keydown", function(e) {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                send();
            }
        });

        provider.addEventListener("change", loadModels);

        function escapeHtml(text) {
            var div = document.createElement("div");
            div.textContent = text;
            return div.innerHTML;
        }

        function addMsg(role, content, extraClass) {
            if (!extraClass) extraClass = "";
            var div = document.createElement("div");
            div.className = "message " + role + (extraClass ? " " + extraClass : "");
            
            if (content.indexOf("<think>") !== -1) {
                var parts = content.split(/(<think>[\s\S]*?<\/think>)/);
                var html = parts.map(function(part) {
                    if (part.indexOf("<think>") === 0) {
                        var thinkContent = part.replace(/<\/?think>/g, "");
                        return "<div class=\"think-box\"><span class=\"think-label\">Thinking...</span>" + escapeHtml(thinkContent) + "</div>";
                    }
                    return escapeHtml(part).replace(/\n/g, "<br>");
                }).join("");
                div.innerHTML = html;
            } else {
                div.innerHTML = escapeHtml(content).replace(/\n/g, "<br>");
            }
            
            chatBox.appendChild(div);
            chatBox.scrollTop = chatBox.scrollHeight;
            return div;
        }

        function addToolCall(calls) {
            var div = document.createElement("div");
            div.className = "message assistant tool-call";
            
            calls.forEach(function(call) {
                var func = call.function || call;
                var box = document.createElement("div");
                box.className = "tool-call-box";
                var args = typeof func.arguments === "string" ? func.arguments : JSON.stringify(func.arguments || {});
                box.innerHTML = "<span class=\"tool-call-name\">Tool: " + func.name + "</span>" +
                    "<div class=\"tool-call-args\">" + escapeHtml(args) + "</div>";
                div.appendChild(box);
            });
            
            chatBox.appendChild(div);
            chatBox.scrollTop = chatBox.scrollHeight;
            return div;
        }

        function addToolResult(result, success) {
            if (success === undefined) success = true;
            var div = document.createElement("div");
            div.className = "message assistant tool-result";
            var resultStr = typeof result === "object" ? JSON.stringify(result, null, 2) : String(result);
            var label = success ? "Result" : "Error";
            div.innerHTML = "<div class=\"tool-result-box\">" +
                "<div class=\"tool-result-label\">" + label + "</div>" +
                "<pre style=\"white-space: pre-wrap; font-size: 12px; color: #86efac;\">" + 
                escapeHtml(resultStr) + 
                "</pre></div>";
            chatBox.appendChild(div);
            chatBox.scrollTop = chatBox.scrollHeight;
            return div;
        }

        async function send() {
            var text = input.value.trim();
            if (!text || loading) return;

            messages.push({ role: "user", content: text });
            addMsg("user", text);
            input.value = "";
            input.style.height = "auto";

            loading = true;
            sendBtn.disabled = true;
            sendBtn.innerHTML = "<span class=\"loading\"></span>";
            status.textContent = "等待回复...";

            try {
                var res = await fetch("/api/chat/stream", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        provider: provider.value,
                        model: model.value,
                        messages: messages,
                        tools: useTools
                    })
                });

                var reader = res.body.getReader();
                var decoder = new TextDecoder();
                var fullContent = "";
                var buffer = "";
                var assistantMsgDiv = null;

                while (true) {
                    var result = await reader.read();
                    if (result.done) break;

                    var chunk = decoder.decode(result.value);
                    buffer += chunk;

                    var lines = buffer.split("\n");
                    buffer = lines.pop() || "";

                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i];
                        if (line.indexOf("event: ") === 0) {
                            var event = line.slice(7).trim();
                        } else if (line.indexOf("data: ") === 0) {
                            var data = line.slice(6).trim();
                            if (data === "[DONE]") {
                                break;
                            }
                            try {
                                var p = JSON.parse(data);
                                
                                if (p.type === "tool_call" && p.calls) {
                                    // 显示工具调用
                                    addToolCall(p.calls);
                                    status.textContent = "执行工具中...";
                                } else if (p.type === "tool_result") {
                                    // 显示工具结果
                                    addToolResult(p.result, p.result.success !== false);
                                    status.textContent = "润色中...";
                                } else if (p.type === "content") {
                                    // 流式显示内容
                                    fullContent += p.content;
                                    if (!assistantMsgDiv) {
                                        assistantMsgDiv = addMsg("assistant", fullContent);
                                    } else {
                                        if (fullContent.indexOf("<think>") !== -1) {
                                            var parts = fullContent.split(/(<think>[\s\S]*?<\/think>)/);
                                            var html = parts.map(function(part) {
                                                if (part.indexOf("<think>") === 0) {
                                                    var thinkContent = part.replace(/<\/?think>/g, "");
                                                    return "<div class=\"think-box\"><span class=\"think-label\">Thinking...</span>" + escapeHtml(thinkContent) + "</div>";
                                                }
                                                return escapeHtml(part).replace(/\n/g, "<br>");
                                            }).join("");
                                            assistantMsgDiv.innerHTML = html;
                                        } else {
                                            assistantMsgDiv.innerHTML = escapeHtml(fullContent).replace(/\n/g, "<br>");
                                        }
                                        chatBox.scrollTop = chatBox.scrollHeight;
                                    }
                                }
                            } catch (e) {}
                        }
                    }
                }

                if (fullContent) {
                    messages.push({ role: "assistant", content: fullContent });
                    status.textContent = "完成";
                }
            } catch (e) {
                addMsg("assistant", "Error: " + e.message);
                status.textContent = "错误";
            } finally {
                loading = false;
                sendBtn.disabled = false;
                sendBtn.innerHTML = "Send";
                status.textContent = "";
            }
        }

        loadModels();
    </script>
</body>
</html>';
    
    $response->header('Content-Type', 'text/html; charset=utf-8');
    $response->end($html);
}

echo "AI Gateway (Tool Calling) starting...\n";
echo "URL: http://localhost:" . PORT . "\n";
echo "Support: Ollama | vLLM | Tool Calling\n\n";

$http->start();
