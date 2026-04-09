<?php
/**
 * 工具加载器
 * 每个工具一个文件，包含定义和执行函数
 */

$toolDefinitions = [];
$toolExecutors = [];

function loadTools()
{
    global $toolDefinitions, $toolExecutors;
    $toolDir = __DIR__;
    
    $files = glob($toolDir . '/*.php');
    foreach ($files as $file) {
        if (basename($file) === 'loader.php') continue;
        
        $tool = include($file);
        if (is_array($tool) && isset($tool['definition']) && isset($tool['execute'])) {
            $name = $tool['definition']['function']['name'];
            $toolDefinitions[] = $tool['definition'];
            $toolExecutors[$name] = $tool['execute'];
        }
    }
    
    return $toolDefinitions;
}

/**
 * 执行工具
 */
function executeTool($name, $args)
{
    global $toolExecutors;
    if (isset($toolExecutors[$name]) && is_callable($toolExecutors[$name])) {
        return $toolExecutors[$name]($args);
    }
    return ['success' => false, 'error' => '未知工具: ' . $name];
}
