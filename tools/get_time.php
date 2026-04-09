<?php
/**
 * 工具 - get_time
 */

return [
    // 工具定义
    'definition' => [
        'type' => 'function',
        'function' => [
            'name' => 'get_time',
            'description' => '获取当前时间',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'format' => [
                        'type' => 'string',
                        'description' => '时间格式，如：Y-m-d H:i:s'
                    ]
                ]
            ]
        ]
    ],
    // 执行函数
    'execute' => function($args) {
        $format = isset($args['format']) ? $args['format'] : 'Y-m-d H:i:s';
        return [
            'success' => true,
            'result' => date($format)
        ];
    }
];
