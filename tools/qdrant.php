<?php
/**
 * Qdrant 向量数据库集成
 * 用于函数检索增强 (Function RAG)
 */

// Qdrant 配置
define('QDRANT_HOST', getenv('QDRANT_HOST') ?: 'localhost');
define('QDRANT_PORT', getenv('QDRANT_PORT') ?: 6333);
define('QDRANT_COLLECTION', 'functions');
define('EMBEDDING_URL', getenv('EMBEDDING_URL') ?: 'http://localhost:11434/api/embeddings');
define('EMBEDDING_MODEL', getenv('EMBEDDING_MODEL') ?: 'nomic-embed-text');

/**
 * 获取向量化服务
 */
function getEmbeddingClient() {
    return new class {
        public function encode($text) {
            $postData = json_encode([
                'model' => EMBEDDING_MODEL,
                'prompt' => $text
            ]);
            
            $ch = curl_init(EMBEDDING_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                return null;
            }
            
            $data = json_decode($response, true);
            return $data['embedding'] ?? null;
        }
    };
}

/**
 * Qdrant 客户端
 */
class QdrantClient {
    private $baseUrl;
    
    public function __construct($host = QDRANT_HOST, $port = QDRANT_PORT) {
        $this->baseUrl = "http://{$host}:{$port}";
    }
    
    /**
     * 创建集合
     */
    public function createCollection($name, $vectorSize = 768, $distance = 'Cosine') {
        $data = [
            'vectors' => [
                'size' => $vectorSize,
                'distance' => $distance
            ]
        ];
        
        return $this->request('PUT', "/collections/{$name}", $data);
    }
    
    /**
     * 删除集合
     */
    public function deleteCollection($name) {
        return $this->request('DELETE', "/collections/{$name}");
    }
    
    /**
     * 检查集合是否存在
     */
    public function collectionExists($name) {
        $result = $this->request('GET', "/collections/{$name}");
        return $result !== null && isset($result['result']);
    }
    
    /**
     * 插入向量
     */
    public function upsert($collection, $points) {
        return $this->request('PUT', "/collections/{$collection}/points", [
            'points' => $points
        ]);
    }
    
    /**
     * 搜索相似向量
     */
    public function search($collection, $vector, $limit = 5, $scoreThreshold = 0.5) {
        $result = $this->request('POST', "/collections/{$collection}/points/search", [
            'vector' => $vector,
            'limit' => $limit,
            'score_threshold' => $scoreThreshold,
            'with_payload' => true
        ]);
        
        return $result['result'] ?? [];
    }
    
    /**
     * 删除所有点
     */
    public function deleteAllPoints($collection) {
        return $this->request('POST', "/collections/{$collection}/points/delete", [
            'filter' => new stdClass(),
            'wait' => true
        ]);
    }
    
    /**
     * 获取集合信息
     */
    public function getCollectionInfo($collection) {
        return $this->request('GET', "/collections/{$collection}");
    }
    
    private function request($method, $path, $data = null) {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400 && $httpCode !== 404) {
            error_log("Qdrant request failed: HTTP {$httpCode} - {$response}");
            return null;
        }
        
        if ($httpCode === 404) {
            return null;
        }
        
        return json_decode($response, true);
    }
}

/**
 * 函数向量管理器
 */
class FunctionVectorStore {
    private $qdrant;
    private $embedding;
    
    public function __construct() {
        $this->qdrant = new QdrantClient();
        $this->embedding = getEmbeddingClient();
    }
    
    /**
     * 初始化集合
     */
    public function initCollection($vectorSize = 768) {
        if (!$this->qdrant->collectionExists(QDRANT_COLLECTION)) {
            return $this->qdrant->createCollection(QDRANT_COLLECTION, $vectorSize);
        }
        return true;
    }
    
    /**
     * 生成函数文本描述
     */
    private function functionToText($funcDef) {
        $func = $funcDef['function'];
        $name = $func['name'];
        $desc = $func['description'];
        $params = '';
        
        if (isset($func['parameters']['properties'])) {
            $props = $func['parameters']['properties'];
            $required = $func['parameters']['required'] ?? [];
            $paramList = [];
            
            foreach ($props as $pName => $pInfo) {
                $requiredMark = in_array($pName, $required) ? '(必填)' : '(可选)';
                $type = $pInfo['type'] ?? 'any';
                $desc = $pInfo['description'] ?? '';
                $paramList[] = "{$pName}: {$type} {$requiredMark} - {$desc}";
            }
            
            $params = "\n参数:\n" . implode("\n", $paramList);
        }
        
        return "函数名: {$name}\n功能描述: {$desc}{$params}";
    }
    
    /**
     * 索引所有函数
     */
    public function indexFunctions($toolDefinitions) {
        $this->initCollection();
        
        $points = [];
        $id = 1;
        
        foreach ($toolDefinitions as $def) {
            $func = $def['function'];
            $name = $func['name'];
            $text = $this->functionToText($def);
            
            // 生成向量
            $vector = $this->embedding->encode($text);
            if (!$vector) {
                error_log("Failed to embed function: {$name}");
                continue;
            }
            
            $points[] = [
                'id' => $id,
                'vector' => $vector,
                'payload' => [
                    'name' => $name,
                    'definition' => $def
                ]
            ];
            
            $id++;
        }
        
        if (!empty($points)) {
            return $this->qdrant->upsert(QDRANT_COLLECTION, $points);
        }
        
        return false;
    }
    
    /**
     * 根据查询检索相关函数
     */
    public function retrieveFunctions($query, $limit = 5, $scoreThreshold = 0.5) {
        $vector = $this->embedding->encode($query);
        if (!$vector) {
            error_log("Failed to embed query: {$query}");
            return [];
        }
        
        $results = $this->qdrant->search(QDRANT_COLLECTION, $vector, $limit, $scoreThreshold);
        
        $functions = [];
        foreach ($results as $result) {
            $functions[] = [
                'name' => $result['payload']['name'],
                'definition' => $result['payload']['definition'],
                'score' => $result['score']
            ];
        }
        
        return $functions;
    }
    
    /**
     * 重新索引所有函数
     */
    public function reindexAll($toolDefinitions) {
        $this->qdrant->deleteAllPoints(QDRANT_COLLECTION);
        return $this->indexFunctions($toolDefinitions);
    }
    
    /**
     * 获取向量维度
     */
    public function getVectorSize() {
        $client = getEmbeddingClient();
        $testVector = $client->encode("test");
        return $testVector ? count($testVector) : 768;
    }
}

/**
 * 获取/初始化函数向量存储
 */
function getFunctionVectorStore() {
    static $store = null;
    if ($store === null) {
        $store = new FunctionVectorStore();
    }
    return $store;
}

/**
 * 根据用户查询检索相关函数
 */
function retrieveRelevantFunctions($query, $limit = 3) {
    $store = getFunctionVectorStore();
    return $store->retrieveFunctions($query, $limit, 0.3);
}
