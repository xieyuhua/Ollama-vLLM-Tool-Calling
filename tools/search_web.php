<?php
/**
 * 工具 - search_web
 */

return [
    // 工具定义
    'definition' => [
        'type' => 'function',
        'function' => [
            'name' => 'search_web',
            'description' => '搜索互联网获取信息',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => '搜索关键词'
                    ]
                ],
                'required' => ['query']
            ]
        ]
    ],
    // 执行函数
    'execute' => function($args) {
        return [
            'success' => true,
            'result' => [
                'query' => isset($args['query']) ? $args['query'] : '',
                'results' => [
                    '找到约 1000 万条结果',
                    '1. 相关链接 A - 简要描述...',
                    '2. 相关链接 B - 简要描述...',
                    '3. 相关链接 C - 简要描述...'
                ]
            ]
        ];
    }
];
