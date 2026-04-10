<?php
/**
 * AI Gateway - Ollama & vLLM 流式代理 (支持 Tool Calling + Qdrant RAG)
 * PHP 7.4+
 */

define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://localhost:11434');
define('VLLM_URL', getenv('VLLM_URL') ?: 'http://localhost:8000');
define('PORT', (int)(getenv('PORT') ?: 9501));
define('USE_QDRANT', getenv('USE_QDRANT') !== 'false'); // 默认启用 Qdrant 检索
define('MAX_RETRIEVE_TOOLS', 3); // 最多检索的工具数量
define('VECTORSIZE', 1024);
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

// 加载 Qdrant 向量库 (如果可用)
$qdrantAvailable = false;
if (USE_QDRANT) {
    try {
        require_once __DIR__ . '/tools/qdrant.php';
        $qdrantAvailable = true;
        // 初始化向量索引
        $store = getFunctionVectorStore();
        $store->initCollection(VECTORSIZE);
    } catch (Exception $e) {
        error_log("Qdrant init failed: " . $e->getMessage());
    }
}

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
            case '/api/qdrant/index':
                // 索引所有工具到 Qdrant
                handleQdrantIndex($request, $response, $tools);
                break;
            case '/api/qdrant/search':
                // 测试检索
                handleQdrantSearch($request, $response);
                break;
            case '/functions':
                // Qdrant 函数搜索页面
                serveFunctionsPage($response, $tools);
                break;
            case '/health':
                $response->end(json_encode([
                    'status' => 'ok',
                    'qdrant' => $qdrantAvailable ? 'connected' : 'disabled'
                ]));
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
    global $qdrantAvailable, $tools;
    
    $data = json_decode($request->getContent(), true);
    
    // 调试日志
    error_log("=== Chat Request ===");
    error_log("tools enabled: " . ($data['tools'] ?? 'not set'));
    error_log("messages count: " . count($data['messages'] ?? []));
    foreach ($data['messages'] ?? [] as $i => $m) {
        $role = $m['role'] ?? 'unknown';
        $hasToolCalls = isset($m['tool_calls']) ? 'yes' : 'no';
        $content = isset($m['content']) ? substr($m['content'], 0, 50) : '';
        error_log("msg[$i] role=$role tool_calls=$hasToolCalls content=$content");
    }
    
    if (!$data || !isset($data['messages'])) {
        $response->status(400);
        $response->end(json_encode(['error' => 'Invalid request']));
        return;
    }
    
    $provider = isset($data['provider']) ? $data['provider'] : 'ollama';
    $model = isset($data['model']) ? $data['model'] : 'llama3.2';
    $messages = $data['messages'];
    $streamTools = isset($data['tools']) ? (bool)$data['tools'] : true;
    
    // 如果工具开关关闭，不传递任何工具
    $selectedTools = [];
    if ($streamTools) {
        // 如果启用了 Qdrant 且可用，从用户消息中检索相关函数
        if ($qdrantAvailable) {
            // 获取用户最新的消息
            $userQuery = '';
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (isset($messages[$i]['role']) && $messages[$i]['role'] === 'user') {
                    $userQuery = $messages[$i]['content'];
                    break;
                }
            }
            
            if ($userQuery) {
                try {
                    $retrievedTools = retrieveRelevantFunctions($userQuery, MAX_RETRIEVE_TOOLS);
                    if (!empty($retrievedTools)) {
                        $selectedTools = array_column($retrievedTools, 'definition');
                        $retrievedNames = implode(', ', array_column($retrievedTools, 'name'));
                        error_log("Qdrant retrieved: {$retrievedNames}");
                    } else {
                        // Qdrant 没有检索到结果，使用所有工具作为后备
                        $selectedTools = $tools;
                    }
                } catch (Exception $e) {
                    error_log("Qdrant retrieve failed: " . $e->getMessage());
                    $selectedTools = $tools;
                }
            } else {
                $selectedTools = $tools;
            }
        } else {
            // 没有启用 Qdrant，使用所有工具
            $selectedTools = $tools;
        }
    }
    
    $response->header('Content-Type', 'text/event-stream');
    $response->header('Cache-Control', 'no-cache');
    $response->header('X-Accel-Buffering', 'no');
    
    if ($provider === 'ollama') {
        streamOllamaWithTools($response, $model, $messages, $selectedTools, $tools);
    } else {
        streamVLLMWithTools($response, $model, $messages, $selectedTools, $tools);
    }
}

function streamOllamaWithTools($response, $model, $messages, $selectedTools, $allTools)
{
    // 如果用户关闭了工具开关 ($selectedTools 为空)，不传递任何工具
    $activeTools = !empty($selectedTools) ? $selectedTools : [];
    
    $ollamaMessages = [];
    foreach ($messages as $m) {
        $role = isset($m['role']) ? $m['role'] : 'user';
        $content = isset($m['content']) ? $m['content'] : '';
        
        if ($role === 'tool') {
            // 工具结果消息
            $toolMsg = [
                'role' => 'tool',
                'content' => is_string($m['content']) ? $m['content'] : json_encode($m['content'])
            ];
            if (isset($m['name'])) {
                $toolMsg['name'] = $m['name'];
            }
            if (isset($m['tool_call_id'])) {
                $toolMsg['tool_call_id'] = $m['tool_call_id'];
            }
            $ollamaMessages[] = $toolMsg;
        } elseif (isset($m['tool_calls']) && !empty($m['tool_calls'])) {
            // 带有工具调用的 assistant 消息
            $ollamaMessages[] = [
                'role' => 'assistant',
                'content' => $content ?: '',
                'tool_calls' => $m['tool_calls']
            ];
        } else {
            // 普通消息
            $ollamaMessages[] = [
                'role' => $role,
                'content' => $content
            ];
        }
    }
    
    // 调试日志
    error_log("=== Ollama Messages ===");
    error_log("activeTools count: " . count($activeTools));
    foreach ($ollamaMessages as $i => $m) {
        $role = $m['role'] ?? 'unknown';
        $hasToolCalls = isset($m['tool_calls']) ? 'yes' : 'no';
        $content = isset($m['content']) ? substr($m['content'], 0, 100) : '';
        error_log("ollamaMsg[$i] role=$role tool_calls=$hasToolCalls content=$content");
    }
    
    $maxIterations = 10;
    $iteration = 0;
    $usedTools = [];
    $fullContent = '';
    
    while ($iteration < $maxIterations) {
        $iteration++;
        $hasToolCall = null; // 重置工具调用标志
        
        $postData = json_encode([
            'model' => $model,
            'messages' => $ollamaMessages,
            'stream' => true,
            'tools' => $activeTools,
        ]);
        
        $ch = curl_init(OLLAMA_URL . '/api/chat');
        $lineBuffer = '';
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($response, &$lineBuffer, &$ollamaMessages, &$usedTools, &$fullContent, &$hasToolCall, $iteration, $maxIterations, $activeTools, $model) {
                // 实时处理每个字符
                for ($i = 0; $i < strlen($chunk); $i++) {
                    $char = $chunk[$i];
                    $lineBuffer .= $char;
                    
                    if ($char === "\n") {
                        $line = trim($lineBuffer);
                        $lineBuffer = '';
                        
                        $json = substr($line, 6);
                        if ($json === '[DONE]' || $json === '') continue;
                        
                        $data = json_decode($line, true);
                        if (!$data || !isset($data['message'])) continue;
                        
                        if (isset($data['message']['tool_calls']) && !empty($data['message']['tool_calls'])) {
                            $hasToolCall = true; // 标记有工具调用
                            $toolCalls = $data['message']['tool_calls'];
                            
                            // 如果之前有累积的 content，先保存为 assistant 消息
                            if (!empty($fullContent)) {
                                $ollamaMessages[] = [
                                    'role' => 'assistant',
                                    'content' => $fullContent
                                ];
                                $fullContent = ''; // 清空，准备接收后续内容
                            }
                            
                            $response->write("event: tool_call\ndata: " . json_encode([
                                'type' => 'tool_call',
                                'calls' => $toolCalls
                            ]) . "\n\n");
                            
                            foreach ($toolCalls as $call) {
                                $func = isset($call['function']) ? $call['function'] : $call;
                                $name = $func['name'];
                                $args = $func['arguments'];
                                if (is_string($args)) {
                                    $args = json_decode($args, true) ?: [];
                                }
                                
                                $result = executeTool($name, $args);
                                $usedTools[] = ['name' => $name, 'args' => $args, 'result' => $result];
                                
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
                            
                        } elseif (isset($data['message']['content'])) {
                            $content = $data['message']['content'];
                            if ($content !== '') {
                                $fullContent .= $content;
                                $response->write("event: content\ndata: " . json_encode([
                                    'type' => 'content',
                                    'content' => $content
                                ]) . "\n\n");
                            }
                        }
                    }
                }
                $data = json_decode($lineBuffer, true);
                if(isset($data['error'])) {
                    $response->write("event: content\ndata: " . json_encode([
                        'type' => 'content',
                        'content' => $data['error']
                    ]) . "\n\n");
                }

                return strlen($chunk);
            },
            CURLOPT_TIMEOUT => 300,
        ]);
        
        @curl_exec($ch);
        
        if (curl_errno($ch)) {
            $response->write("event: error\ndata: " . json_encode(['error' => curl_error($ch)]) . "\n\n");
            $response->end();
            curl_close($ch);
            return;
        }
        curl_close($ch);
        
        // 判断当前响应是否有工具调用
        // 如果有工具调用，继续循环；如果没有，结束
        if (empty($hasToolCall)) {
            // 没有新的工具调用，结束
            // 注意：工具调用和结果已在循环中通过 SSE 发送给前端，不需要额外输出摘要
            if ($fullContent) {
                $ollamaMessages[] = ['role' => 'assistant', 'content' => $fullContent];
            }
            break;
        }
        
        $fullContent = '';
        $hasToolCall = null; // 重置
    }
    
    $response->write("event: done\ndata: [DONE]\n\n");
    $response->end();
}

function streamVLLMWithTools($response, $model, $messages, $selectedTools, $allTools)
{
    // 如果用户关闭了工具开关 ($selectedTools 为空)，不传递任何工具
    $activeTools = !empty($selectedTools) ? $selectedTools : [];
    
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
        'tools' => $activeTools,
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


/**
 * Qdrant 向量索引处理
 */
function handleQdrantIndex($request, $response, $tools)
{
    global $qdrantAvailable;
    
    $response->header('Content-Type', 'application/json');
    
    if (!$qdrantAvailable) {
        $response->end(json_encode(['success' => false, 'error' => 'Qdrant is not available']));
        return;
    }
    
    try {
        $store = getFunctionVectorStore();
        $result = $store->indexFunctions($tools);
        $response->end(json_encode([
            'success' => true,
            'message' => 'Indexed ' . count($tools) . ' tools',
            'collection' => QDRANT_COLLECTION
        ]));
    } catch (Exception $e) {
        $response->end(json_encode(['success' => false, 'error' => $e->getMessage()]));
    }
}

/**
 * Qdrant 检索测试
 */
function handleQdrantSearch($request, $response)
{
    global $qdrantAvailable;
    
    $data = json_decode($request->getContent(), true);
    $query = isset($data['query']) ? $data['query'] : '';
    $limit = isset($data['limit']) ? (int)$data['limit'] : 3;
    
    $response->header('Content-Type', 'application/json');
    
    if (!$qdrantAvailable) {
        $response->end(json_encode(['success' => false, 'error' => 'Qdrant is not available']));
        return;
    }
    
    if (!$query) {
        $response->end(json_encode(['success' => false, 'error' => 'Query is required']));
        return;
    }
    
    try {
        $results = retrieveRelevantFunctions($query, $limit);
        $response->end(json_encode([
            'success' => true,
            'query' => $query,
            'results' => $results
        ]));
    } catch (Exception $e) {
        $response->end(json_encode(['success' => false, 'error' => $e->getMessage()]));
    }
}

/**
 * 函数搜索页面
 */
function serveFunctionsPage($response, $tools)
{
    global $qdrantAvailable;
    
    $toolsJson = json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $qdrantStatus = $qdrantAvailable ? 'true' : 'false';
    
    $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Function Search - AI Gateway</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f0f1a; color: #fff; min-height: 100vh; }
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; border-bottom: 1px solid #333; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        h1 { font-size: 20px; background: linear-gradient(135deg, #667eea, #764ba2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-links { display: flex; gap: 16px; }
        .nav-links a { color: #667eea; text-decoration: none; font-size: 14px; }
        .nav-links a:hover { text-decoration: underline; }
        
        .search-section { background: #1a1a2e; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
        .search-section h2 { font-size: 16px; margin-bottom: 16px; color: #888; }
        .search-box { display: flex; gap: 12px; }
        .search-box input { flex: 1; padding: 14px 18px; border-radius: 10px; background: #0f172a; border: 1px solid #333; color: #fff; font-size: 15px; }
        .search-box input:focus { outline: none; border-color: #667eea; }
        .search-box button { padding: 14px 28px; border-radius: 10px; background: linear-gradient(135deg, #667eea, #764ba2); border: none; color: #fff; font-size: 15px; cursor: pointer; }
        .search-box button:hover { opacity: 0.9; }
        .search-box button:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .status { margin-top: 12px; font-size: 13px; color: #888; }
        .status.error { color: #ef4444; }
        .status.success { color: #22c55e; }
        
        .results-section { background: #1a1a2e; border-radius: 12px; padding: 20px; }
        .results-section h2 { font-size: 16px; margin-bottom: 16px; color: #888; }
        .result-item { background: #0f172a; border-radius: 10px; padding: 16px; margin-bottom: 12px; border-left: 3px solid #667eea; }
        .result-item:last-child { margin-bottom: 0; }
        .result-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .result-name { color: #667eea; font-weight: bold; font-size: 15px; }
        .result-score { background: #1e3a5f; padding: 4px 10px; border-radius: 20px; font-size: 12px; color: #3b82f6; }
        .result-desc { color: #94a3b8; font-size: 14px; margin-bottom: 10px; line-height: 1.5; }
        .result-params { background: #1e293b; padding: 10px 14px; border-radius: 6px; font-family: monospace; font-size: 12px; color: #86efac; }
        .result-params-title { color: #64748b; font-size: 11px; margin-bottom: 6px; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #64748b; }
        .empty-state-icon { font-size: 48px; margin-bottom: 16px; }
        .empty-state-text { font-size: 14px; }
        
        .tools-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-top: 24px; }
        .tool-card { background: #0f172a; border-radius: 10px; padding: 14px; border-left: 3px solid #22c55e; }
        .tool-card h3 { color: #22c55e; font-size: 14px; margin-bottom: 6px; }
        .tool-card p { color: #64748b; font-size: 12px; line-height: 1.4; }
        
        @media (max-width: 768px) {
            .container { padding: 12px; }
            .search-box { flex-direction: column; }
            .tools-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Function Search</h1>
            <div class="nav-links">
                <a href="/">Chat</a>
                <a href="/functions">Functions</a>
            </div>
        </header>
        
        <div class="search-section">
            <h2>Search Functions</h2>
            <div class="search-box">
                <input type="text" id="queryInput" placeholder="Enter your query (e.g., weather, calculate, time)..." onkeypress="if(event.key===\'Enter\')search()">
                <button id="searchBtn" onclick="search()">Search</button>
            </div>
            <div class="status" id="status"></div>
        </div>
        
        <div class="results-section">
            <h2>Search Results</h2>
            <div id="results">
                <div class="empty-state">
                    <div class="empty-state-icon">&#128269;</div>
                    <div class="empty-state-text">Enter a query to search for relevant functions</div>
                </div>
            </div>
        </div>
        
        <div class="results-section" style="margin-top: 20px;">
            <h2>All Available Functions</h2>
            <div class="tools-grid" id="allTools"></div>
        </div>
    </div>

    <script>
        var allTools = " . $toolsJson . ";
        var qdrantAvailable = " . $qdrantStatus . ";
        
        function escapeHtml(text) {
            var div = document.createElement("div");
            div.textContent = text;
            return div.innerHTML;
        }
        
        function renderTools() {
            var container = document.getElementById("allTools");
            var html = "";
            for (var i = 0; i < allTools.length; i++) {
                var tool = allTools[i].function;
                html += \'<div class="tool-card">\';
                html += \'<h3>\' + escapeHtml(tool.name) + \'</h3>\';
                html += \'<p>\' + escapeHtml(tool.description) + \'</p>\';
                html += \'</div>\';
            }
            if (html === "") {
                html = \'<div class="empty-state"><div class="empty-state-icon">&#9888;</div><div class="empty-state-text">No functions available</div></div>\';
            }
            container.innerHTML = html;
        }
        
        function search() {
            var query = document.getElementById("queryInput").value.trim();
            if (!query) return;
            
            var btn = document.getElementById("searchBtn");
            var status = document.getElementById("status");
            var results = document.getElementById("results");
            
            btn.disabled = true;
            status.textContent = "Searching...";
            status.className = "status";
            
            fetch("/api/qdrant/search", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ query: query, limit: 5 })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                btn.disabled = false;
                
                if (!data.success) {
                    status.textContent = "Search failed: " + (data.error || "Unknown error");
                    status.className = "status error";
                    return;
                }
                
                status.textContent = "Found " + data.results.length + " functions (Qdrant: " + qdrantAvailable + ")";
                status.className = "status success";
                
                if (data.results.length === 0) {
                    results.innerHTML = \'<div class="empty-state"><div class="empty-state-icon">&#128269;</div><div class="empty-state-text">No matching functions found</div></div>\';
                    return;
                }
                
                var html = "";
                for (var i = 0; i < data.results.length; i++) {
                    var item = data.results[i];
                    var func = item.definition.function;
                    var score = (item.score * 100).toFixed(1);
                    
                    html += \'<div class="result-item">\';
                    html += \'<div class="result-header">\';
                    html += \'<span class="result-name">\' + escapeHtml(func.name) + \'</span>\';
                    html += \'<span class="result-score">\' + score + \'% match</span>\';
                    html += \'</div>\';
                    html += \'<div class="result-desc">\' + escapeHtml(func.description) + \'</div>\';
                    
                    if (func.parameters && func.parameters.properties) {
                        var params = func.parameters;
                        var required = params.required || [];
                        var paramList = [];
                        
                        for (var pname in params.properties) {
                            var p = params.properties[pname];
                            var req = required.indexOf(pname) !== -1 ? "(required)" : "(optional)";
                            paramList.push(escapeHtml(pname) + ": " + escapeHtml(p.type || "any") + " " + req);
                        }
                        
                        if (paramList.length > 0) {
                            html += \'<div class="result-params-title">Parameters:</div>\';
                            html += \'<div class="result-params">\' + paramList.join("\\n") + \'</div>\';
                        }
                    }
                    
                    html += \'</div>\';
                }
                
                results.innerHTML = html;
            })
            .catch(function(e) {
                btn.disabled = false;
                status.textContent = "Error: " + e.message;
                status.className = "status error";
            });
        }
        
        renderTools();
    </script>
</body>
</html>';
    
    $response->header('Content-Type', 'text/html; charset=utf-8');
    $response->end($html);
}

function serveIndex($response, $tools)
{
    // 使用 JSON_HEX_TAG 转义 < 和 >，防止 </script> 等破坏 HTML
    $toolsJson = json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $toolsEnabled = count($tools) > 0 ? 'ON' : 'OFF';
    $toolsList = [];
    foreach ($tools as $t) {
        $toolsList[] = '<li class="tool-item" data-name="' . htmlspecialchars($t['function']['name'], ENT_QUOTES) . '">' 
            . '<span class="tool-icon">&#x1F527;</span>' 
            . '<span class="tool-name">' . htmlspecialchars($t['function']['name'], ENT_QUOTES) . '</span>'
            . '<span class="tool-desc">' . htmlspecialchars($t['function']['description'], ENT_QUOTES) . '</span>'
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
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f0f1a; color: #fff; min-height: 100vh; }
        .container { max-width: 900px; margin: 0 auto; padding: 16px; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; border-bottom: 1px solid #333; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
        h1 { font-size: 20px; background: linear-gradient(135deg, #667eea, #764ba2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .config { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
        select, button { padding: 8px 14px; border-radius: 8px; border: none; font-size: 14px; cursor: pointer; }
        select { background: #1a1a2e; color: #fff; border: 1px solid #333; min-width: 120px; }
        button { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; white-space: nowrap; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        button.tool-btn { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .chat-box { background: #1a1a2e; border-radius: 12px; height: calc(100vh - 280px); min-height: 300px; max-height: 500px; overflow-y: auto; padding: 16px; margin-bottom: 16px; }
        .message { margin-bottom: 12px; padding: 10px 14px; border-radius: 12px; max-width: 85%; line-height: 1.6; word-break: break-word; white-space: pre-wrap; }
        .message.user { background: #667eea; margin-left: auto; }
        .message.assistant { background: #2d2d44; }
        .message.tool-call { background: #1e3a5f; border-left: 3px solid #3b82f6; }
        .message.tool-result { background: #1a2e1a; border-left: 3px solid #22c55e; font-size: 13px; }
        .tool-call-box { background: #0f172a; padding: 8px 12px; border-radius: 8px; margin-bottom: 8px; }
        .tool-call-name { color: #3b82f6; font-weight: bold; }
        .tool-call-args { background: #1e293b; padding: 6px; border-radius: 4px; margin-top: 4px; font-family: monospace; font-size: 11px; white-space: pre-wrap; color: #94a3b8; overflow-x: auto; }
        .tool-result-box { background: #14532d; padding: 8px 12px; border-radius: 8px; }
        .tool-result-label { color: #22c55e; font-weight: bold; font-size: 11px; margin-bottom: 4px; }
        .think-box { background: #1e2433; border: 1px solid #3b4566; border-left: 3px solid #f59e0b; padding: 8px 12px; margin: 8px 0; border-radius: 6px; font-size: 12px; color: #a0aec0; cursor: pointer; transition: all 0.2s; }
        .think-box:hover { border-color: #f59e0b; background: #252b3d; }
        .think-box.collapsed .think-content { display: none; }
        .think-label { display: flex; align-items: center; gap: 6px; font-size: 11px; color: #f59e0b; font-weight: 600; }
        .think-label::before { content: "🤔"; font-size: 14px; }
        .think-label::after { content: "▼"; font-size: 10px; margin-left: auto; transition: transform 0.2s; }
        .think-box.collapsed .think-label::after { transform: rotate(-90deg); }
        .think-content { margin-top: 8px; padding-top: 8px; border-top: 1px dashed #3b4566; white-space: pre-wrap; line-height: 1.5; max-height: 300px; overflow-y: auto; }
        .input-area { display: flex; gap: 10px; align-items: flex-end; }
        textarea { flex: 1; padding: 12px; border-radius: 12px; background: #1a1a2e; border: 1px solid #333; color: #fff; font-size: 14px; resize: none; min-height: 48px; max-height: 150px; }
        textarea:focus { outline: none; border-color: #667eea; }
        .send-btn { min-width: 70px; height: 48px; }
        .loading { display: inline-block; width: 16px; height: 16px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .status { font-size: 12px; color: #888; margin-top: 8px; min-height: 18px; }
        .tools-panel { background: #1a1a2e; border-radius: 12px; padding: 14px; margin-bottom: 16px; }
        .tools-panel h3 { font-size: 13px; margin-bottom: 10px; color: #888; }
        .tools-list { list-style: none; display: flex; flex-wrap: wrap; gap: 8px; }
        .tool-item { background: #0f172a; padding: 6px 10px; border-radius: 6px; font-size: 11px; display: flex; align-items: center; gap: 4px; }
        .tool-name { color: #3b82f6; font-weight: bold; }
        .tool-desc { color: #64748b; display: none; }
        
        /* 移动端适配 */
        @media (max-width: 768px) {
            .container { padding: 12px; }
            h1 { font-size: 18px; }
            header { padding: 12px 0; }
            .config { gap: 8px; }
            select, button { padding: 8px 10px; font-size: 13px; }
            select { min-width: 100px; }
            .chat-box { 
                height: calc(100vh - 320px); 
                min-height: 250px;
                padding: 12px;
                border-radius: 8px;
            }
            .message { max-width: 92%; padding: 8px 12px; }
            .tool-call-args { font-size: 10px; }
            .input-area { gap: 8px; }
            textarea { padding: 10px; min-height: 44px; font-size: 14px; }
            .send-btn { min-width: 60px; height: 44px; padding: 8px 12px; }
            .tools-panel { padding: 10px; }
        }
        
        /* 小屏幕手机 */
        @media (max-width: 480px) {
            .container { padding: 8px; }
            header { flex-direction: column; align-items: stretch; gap: 8px; }
            h1 { text-align: center; font-size: 16px; }
            .config { justify-content: center; }
            .config select, .config button { flex: 1; min-width: 0; }
            .chat-box { 
                height: calc(100vh - 360px); 
                border-radius: 6px;
            }
            .message { max-width: 95%; font-size: 13px; }
            .think-box { font-size: 11px; padding: 4px 8px; }
            .tools-panel h3 { font-size: 12px; }
            .tool-item { font-size: 10px; padding: 4px 8px; }
            textarea { font-size: 14px; }
        }
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
        
        var tools = " . $toolsJson . ";

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
                                
                                // 根据 event 类型或 p.type 处理事件
                                if (event === "tool_call" || p.type === "tool_call") {
                                    // 显示工具调用
                                    if (p.calls) {
                                        addToolCall(p.calls);
                                        // 将工具调用添加到消息历史（用于后续请求）
                                        messages.push({
                                            role: "assistant",
                                            content: "",
                                            tool_calls: p.calls
                                        });
                                        status.textContent = "执行工具中...";
                                    }
                                    event = ""; // 重置事件状态
                                } else if (event === "tool_result" || p.type === "tool_result") {
                                    // 显示工具结果
                                    if (p.result) {
                                        addToolResult(p.result, p.result.success !== false);
                                        // 将工具结果添加到消息历史（用于后续请求）
                                        messages.push({
                                            role: "tool",
                                            content: JSON.stringify(p.result.result || p.result),
                                            name: p.name
                                        });
                                    }
                                    event = ""; // 重置事件状态
                                    status.textContent = "润色中...";
                                } else if (p.type === "content") {
                                    // 流式显示内容
                                    fullContent += p.content;
                                    if (!assistantMsgDiv) {
                                        assistantMsgDiv = addMsg("assistant", fullContent);
                                    } else {
                                        // 检查是否有完整的 think 标签
                                        var thinkMatch = fullContent.match(/<think>([\s\S]*?)<\/think>/);
                                        if (thinkMatch) {
                                            // 有 think 标签，只显示 think 盒子里的内容
                                            var afterThink = fullContent.split("</think>")[1] || "";
                                            var thinkContent = thinkMatch[1];
                                            
                                            var html = "";
                                            // 只有 think 内容非空才显示盒子（可折叠）
                                            if (thinkContent.trim()) {
                                                html += "<div class=\"think-box\" onclick=\"this.classList.toggle(\'collapsed\')\">" +
                                                    "<span class=\"think-label\">Thinking</span>" +
                                                    "<div class=\"think-content\">" + escapeHtml(thinkContent) + "</div></div>";
                                            }
                                            // 渲染 think 后的内容（去除开头换行，合并多余换行）
                                            if (afterThink.trim()) {
                                                var cleanAfter = afterThink.replace(/^\n+/, "").replace(/\n{2,}/g, "\n");
                                                if (cleanAfter.trim()) {
                                                    html += escapeHtml(cleanAfter).replace(/\n/g, "<br>");
                                                }
                                            }
                                            assistantMsgDiv.innerHTML = html;
                                        } else {
                                            // 没有 think 标签：去除开头换行，合并连续换行
                                            var cleanContent = fullContent.replace(/^\n+/, "").replace(/\n{2,}/g, "\n");
                                            assistantMsgDiv.innerHTML = escapeHtml(cleanContent).replace(/\n/g, "<br>");
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
