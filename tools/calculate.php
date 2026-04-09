<?php
/**
 * 工具 - calculate
 */

return [
    // 工具定义
    'definition' => [
        'type' => 'function',
        'function' => [
            'name' => 'calculate',
            'description' => '执行数学计算',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'expression' => [
                        'type' => 'string',
                        'description' => '数学表达式，如：2+3*5'
                    ]
                ],
                'required' => ['expression']
            ]
        ]
    ],
    // 执行函数
    'execute' => function($args) {
        $expr = isset($args['expression']) ? $args['expression'] : '';
        if (preg_match('/^[\d\+\-\*\/\.\(\)\s]+$/', $expr)) {
            eval('$result = ' . $expr . ';');
            return ['success' => true, 'result' => $expr . ' = ' . $result];
        }
        return ['success' => false, 'error' => '无效的表达式'];
    }
];
