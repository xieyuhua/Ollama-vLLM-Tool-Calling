<?php
/**
 * 工具 - get_weather
 */

return [
    // 工具定义
    'definition' => [
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'description' => '获取指定城市的天气信息',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'city' => [
                        'type' => 'string',
                        'description' => '城市名称，如：北京、上海'
                    ],
                    'unit' => [
                        'type' => 'string',
                        'enum' => ['celsius', 'fahrenheit'],
                        'description' => '温度单位，默认 celsius'
                    ]
                ],
                'required' => ['city']
            ]
        ]
    ],
    // 执行函数
    'execute' => function($args) {
        $city = isset($args['city']) ? $args['city'] : '未知';
        $unit = isset($args['unit']) ? $args['unit'] : 'celsius';
        $temp = rand(15, 30);
        if ($unit === 'fahrenheit') {
            $temp = round($temp * 9/5 + 32);
        }
        return [
            'success' => true,
            'result' => [
                'city' => $city,
                'weather' => ['晴', '多云', '小雨'][rand(0, 2)],
                'temperature' => $temp,
                'unit' => $unit,
                'humidity' => rand(40, 80)
            ]
        ];
    }
];
