<?php
namespace Translink\Controllers;

use Translink\Request;
use Translink\Response;

class DocsController
{
    public function openapi(Request $req, Response $res): Response
    {
        $baseUrl = defined('SITE_URL') ? SITE_URL : 'http://localhost:8000';

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Translink GPS File Library API',
                'version' => '1.0.0',
                'description' => 'Enterprise-grade REST API for managing GPS device configuration files, firmware, manuals, and software.',
                'contact' => [
                    'name' => 'Translink Support',
                    'email' => 'support@translink.et',
                ],
            ],
            'servers' => [
                ['url' => $baseUrl . '/api/v1', 'description' => 'API v1'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => false],
                            'message' => ['type' => 'string'],
                            'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'Brand' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'slug' => ['type' => 'string'],
                            'description' => ['type' => 'string', 'nullable' => true],
                            'icon' => ['type' => 'string'],
                            'color' => ['type' => 'string'],
                            'model_count' => ['type' => 'integer'],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'File' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'type' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'file_path' => ['type' => 'string'],
                            'file_size' => ['type' => 'integer'],
                            'version' => ['type' => 'string', 'nullable' => true],
                            'download_count' => ['type' => 'integer'],
                            'brand_name' => ['type' => 'string', 'nullable' => true],
                            'model_name' => ['type' => 'string', 'nullable' => true],
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'Pagination' => [
                        'type' => 'object',
                        'properties' => [
                            'total' => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                            'current_page' => ['type' => 'integer'],
                            'last_page' => ['type' => 'integer'],
                            'has_more' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
            'security' => [['bearerAuth' => []]],
            'paths' => [
                '/auth/login' => [
                    'post' => [
                        'tags' => ['Authentication'],
                        'summary' => 'Login',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'username' => ['type' => 'string'],
                                        'password' => ['type' => 'string'],
                                    ],
                                    'required' => ['username', 'password'],
                                ],
                            ]],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Login successful'],
                            '401' => ['description' => 'Invalid credentials'],
                        ],
                    ],
                ],
                '/auth/register' => [
                    'post' => [
                        'tags' => ['Authentication'],
                        'summary' => 'Register new user',
                        'security' => [],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'username' => ['type' => 'string'],
                                        'password' => ['type' => 'string'],
                                        'email' => ['type' => 'string', 'format' => 'email'],
                                    ],
                                    'required' => ['username', 'password'],
                                ],
                            ]],
                        ],
                        'responses' => [
                            '201' => ['description' => 'User registered'],
                            '409' => ['description' => 'Username/email taken'],
                        ],
                    ],
                ],
                '/auth/me' => [
                    'get' => [
                        'tags' => ['Authentication'],
                        'summary' => 'Get current user profile',
                        'responses' => [
                            '200' => ['description' => 'User profile'],
                            '401' => ['description' => 'Not authenticated'],
                        ],
                    ],
                ],
                '/brands' => [
                    'get' => [
                        'tags' => ['Brands'],
                        'summary' => 'List all brands',
                        'security' => [],
                        'responses' => [
                            '200' => [
                                'description' => 'Brand list',
                                'content' => ['application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Brand']],
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Brands'],
                        'summary' => 'Create a brand (admin)',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'slug' => ['type' => 'string'],
                                        'description' => ['type' => 'string'],
                                    ],
                                    'required' => ['name', 'slug'],
                                ],
                            ]],
                        ],
                        'responses' => [
                            '201' => ['description' => 'Brand created'],
                            '403' => ['description' => 'Forbidden'],
                        ],
                    ],
                ],
                '/brands/{slug}' => [
                    'get' => [
                        'tags' => ['Brands'],
                        'summary' => 'Get brand by slug',
                        'security' => [],
                        'parameters' => [
                            ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Brand details'],
                            '404' => ['description' => 'Not found'],
                        ],
                    ],
                ],
                '/brands/{slug}/models' => [
                    'get' => [
                        'tags' => ['Models'],
                        'summary' => 'List models for a brand',
                        'security' => [],
                        'parameters' => [
                            ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => ['200' => ['description' => 'Model list']],
                    ],
                ],
                '/files/{type}' => [
                    'get' => [
                        'tags' => ['Files'],
                        'summary' => 'List files by type',
                        'security' => [],
                        'parameters' => [
                            ['name' => 'type', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['config', 'firmware', 'manual', 'software']]],
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                            ['name' => 'brand_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                            ['name' => 'model_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                            ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ],
                        'responses' => ['200' => ['description' => 'Paginated file list']],
                    ],
                ],
                '/files/{type}/{id}/download' => [
                    'get' => [
                        'tags' => ['Files'],
                        'summary' => 'Download a file',
                        'security' => [],
                        'parameters' => [
                            ['name' => 'type', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'File download or redirect'],
                            '404' => ['description' => 'Not found'],
                        ],
                    ],
                ],
                '/search' => [
                    'get' => [
                        'tags' => ['Search'],
                        'summary' => 'Full-text search across all file types',
                        'security' => [],
                        'parameters' => [
                            ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => ['200' => ['description' => 'Search results']],
                    ],
                ],
                '/stats' => [
                    'get' => [
                        'tags' => ['Stats'],
                        'summary' => 'System statistics',
                        'security' => [],
                        'responses' => ['200' => ['description' => 'Statistics']],
                    ],
                ],
                '/admin/users' => [
                    'get' => [
                        'tags' => ['Admin'],
                        'summary' => 'List users (admin)',
                        'responses' => ['200' => ['description' => 'User list']],
                    ],
                    'post' => [
                        'tags' => ['Admin'],
                        'summary' => 'Create user (admin)',
                        'responses' => ['201' => ['description' => 'User created']],
                    ],
                ],
                '/admin/activity' => [
                    'get' => [
                        'tags' => ['Admin'],
                        'summary' => 'View activity log (admin)',
                        'parameters' => [
                            ['name' => 'action', 'in' => 'query', 'schema' => ['type' => 'string']],
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => ['200' => ['description' => 'Activity log']],
                    ],
                ],
                '/admin/health' => [
                    'get' => [
                        'tags' => ['Admin'],
                        'summary' => 'System health check',
                        'security' => [['bearerAuth' => []]],
                        'responses' => ['200' => ['description' => 'Health status']],
                    ],
                ],
            ],
        ];

        return $res->success($spec);
    }
}
