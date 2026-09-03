<?php

declare(strict_types=1);

return [
    // Auth
    ['method' => 'GET',  'path' => '/',                    'controller' => 'Dashboard', 'action' => 'index'],
    ['method' => 'GET',  'path' => '/login',               'controller' => 'Auth',      'action' => 'login',     'auth' => false],
    ['method' => 'POST', 'path' => '/login',               'controller' => 'Auth',      'action' => 'loginPost', 'auth' => false],
    ['method' => 'POST', 'path' => '/logout',              'controller' => 'Auth',      'action' => 'logout',    'auth' => false],
    ['method' => 'GET',  'path' => '/healthz',             'controller' => 'System',    'action' => 'healthz', 'auth' => false],

    // Assets
    ['method' => 'GET',  'path' => '/assets',                    'controller' => 'Asset', 'action' => 'index'],
    ['method' => 'GET',  'path' => '/assets/new',                'controller' => 'Asset', 'action' => 'create',  'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets',                    'controller' => 'Asset', 'action' => 'store',   'roles' => ['admin', 'department_manager']],
    ['method' => 'GET',  'path' => '/assets/{id}',               'controller' => 'Asset', 'action' => 'show'],
    ['method' => 'GET',  'path' => '/assets/{id}/edit',          'controller' => 'Asset', 'action' => 'edit',    'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}',               'controller' => 'Asset', 'action' => 'update',  'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/check-out',     'controller' => 'Asset', 'action' => 'checkOut', 'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/check-in',      'controller' => 'Asset', 'action' => 'checkIn',  'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/transfer',      'controller' => 'Asset', 'action' => 'transfer', 'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/repair',        'controller' => 'Asset', 'action' => 'repair',   'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/broken',        'controller' => 'Asset', 'action' => 'broken',   'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/lost',          'controller' => 'Asset', 'action' => 'lost',     'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/dispose',       'controller' => 'Asset', 'action' => 'dispose',  'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/sell',          'controller' => 'Asset', 'action' => 'sell',     'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/donate',        'controller' => 'Asset', 'action' => 'donate',   'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/replicate',     'controller' => 'Asset', 'action' => 'replicate','roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/email',         'controller' => 'Asset', 'action' => 'email'],
    ['method' => 'POST', 'path' => '/assets/{id}/delete',        'controller' => 'Asset', 'action' => 'delete',   'roles' => ['admin']],
    ['method' => 'GET',  'path' => '/assets/{id}/sheet',         'controller' => 'Asset', 'action' => 'sheet'],
    ['method' => 'GET',  'path' => '/assets/{id}/qr',            'controller' => 'Asset', 'action' => 'qr'],

    // Photos
    ['method' => 'GET',  'path' => '/photos',                    'controller' => 'Photo',   'action' => 'index'],
    ['method' => 'POST', 'path' => '/photos/upload',             'controller' => 'Photo',   'action' => 'upload',  'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/photos/{id}/delete',        'controller' => 'Photo',   'action' => 'delete',  'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/assets/{id}/photos/assign',  'controller' => 'Asset',   'action' => 'assignPhoto',  'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/photos/unassign','controller' => 'Asset',   'action' => 'unassignPhoto','roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/assets/{id}/photos/thumbnail','controller' => 'Asset',  'action' => 'setThumbnail', 'roles' => ['admin', 'department_manager']],

    // Work Orders
    ['method' => 'GET',  'path' => '/work-orders',               'controller' => 'WorkOrder', 'action' => 'index'],
    ['method' => 'POST', 'path' => '/work-orders',               'controller' => 'WorkOrder', 'action' => 'store',   'roles' => ['admin', 'department_manager']],
    ['method' => 'POST', 'path' => '/work-orders/{id}/complete', 'controller' => 'WorkOrder', 'action' => 'complete','roles' => ['admin', 'department_manager']],

    // Reports
    ['method' => 'GET', 'path' => '/reports',                'controller' => 'Report', 'action' => 'index'],
    ['method' => 'GET', 'path' => '/reports/weekly',         'controller' => 'Report', 'action' => 'weekly'],
    ['method' => 'GET', 'path' => '/reports/weekly/generate','controller' => 'Report', 'action' => 'weeklyGenerate'],
    ['method' => 'GET', 'path' => '/reports/download/{runId}','controller' => 'Report', 'action' => 'download'],
    ['method' => 'GET', 'path' => '/reports/export/{type}',  'controller' => 'Report', 'action' => 'export'],
    ['method' => 'GET', 'path' => '/reports/{type}',         'controller' => 'Report', 'action' => 'run'],

    // Preferences
    ['method' => 'POST', 'path' => '/prefs',                  'controller' => 'Pref',   'action' => 'store'],

    // AI Assistant
    ['method' => 'GET',  'path' => '/assistant',           'controller' => 'Assistant', 'action' => 'index'],
    ['method' => 'GET',  'path' => '/assistant/state',     'controller' => 'Assistant', 'action' => 'state'],
    ['method' => 'POST', 'path' => '/assistant/chat',      'controller' => 'Assistant', 'action' => 'chat'],
    ['method' => 'POST', 'path' => '/assistant/confirm',   'controller' => 'Assistant', 'action' => 'confirm'],
    ['method' => 'POST', 'path' => '/assistant/cancel',    'controller' => 'Assistant', 'action' => 'cancel'],
    ['method' => 'POST', 'path' => '/assistant/clear',     'controller' => 'Assistant', 'action' => 'clear'],

    // Admin
    ['method' => 'GET',  'path' => '/admin',                     'controller' => 'Admin', 'action' => 'index',       'roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/users',               'controller' => 'Admin', 'action' => 'users',         'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/users',               'controller' => 'Admin', 'action' => 'userStore',     'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/users/{id}',          'controller' => 'Admin', 'action' => 'userUpdate',    'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/users/{id}/delete',   'controller' => 'Admin', 'action' => 'userDelete',    'roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/persons',             'controller' => 'Person', 'action' => 'index',        'roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/persons/new',         'controller' => 'Person', 'action' => 'create',       'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/persons',             'controller' => 'Person', 'action' => 'store',        'roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/persons/{id}/edit',   'controller' => 'Person', 'action' => 'edit',         'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/persons/{id}',        'controller' => 'Person', 'action' => 'update',       'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/persons/{id}/toggle', 'controller' => 'Person', 'action' => 'toggle',       'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/persons/{id}/delete', 'controller' => 'Person', 'action' => 'delete',       'roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/departments',         'controller' => 'Admin', 'action' => 'departments',   'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/departments',         'controller' => 'Admin', 'action' => 'deptStore',     'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/departments/{id}/delete', 'controller' => 'Admin', 'action' => 'deptDelete','roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/sites',               'controller' => 'Admin', 'action' => 'sites',         'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/sites',               'controller' => 'Admin', 'action' => 'siteStore',     'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/sites/{id}/delete',   'controller' => 'Admin', 'action' => 'siteDelete',    'roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/locations',           'controller' => 'Admin', 'action' => 'locations',     'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/locations',           'controller' => 'Admin', 'action' => 'locationStore', 'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/locations/{id}/delete', 'controller' => 'Admin', 'action' => 'locationDelete','roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/categories',          'controller' => 'Admin', 'action' => 'categories',    'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/categories',          'controller' => 'Admin', 'action' => 'categoryStore', 'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/categories/{id}',     'controller' => 'Admin', 'action' => 'categoryUpdate', 'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/categories/{id}/delete', 'controller' => 'Admin', 'action' => 'categoryDelete','roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/settings',            'controller' => 'Admin', 'action' => 'settings',      'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/settings',            'controller' => 'Admin', 'action' => 'settingsUpdate','roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/audit',               'controller' => 'Admin', 'action' => 'audit',         'roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/backups',             'controller' => 'Admin', 'action' => 'backups',       'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/backups/run',         'controller' => 'Admin', 'action' => 'backupRun',     'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/backups/{id}/restore','controller' => 'Admin', 'action' => 'backupRestore', 'roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/backups/{id}/download','controller' => 'Admin', 'action' => 'backupDownload','roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/system',              'controller' => 'Admin', 'action' => 'system',        'roles' => ['admin']],
    ['method' => 'GET',  'path' => '/admin/llm/state',     'controller' => 'Admin', 'action' => 'llmState',   'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/select',    'controller' => 'Admin', 'action' => 'llmSelect',  'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/start',     'controller' => 'Admin', 'action' => 'llmStart',   'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/stop',      'controller' => 'Admin', 'action' => 'llmStop',    'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/config',    'controller' => 'Admin', 'action' => 'llmConfig',  'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/upload',   'controller' => 'Admin', 'action' => 'llmUpload',  'roles' => ['admin']],
    ['method' => 'POST', 'path' => '/admin/llm/delete',   'controller' => 'Admin', 'action' => 'llmDelete',  'roles' => ['admin']],
];
