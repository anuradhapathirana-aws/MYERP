<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /**
     * Display map: group_key => [label, resources => resource_key => [label, actions]].
     * Permission names are "{action}_{resource_key}". Actions are rendered as
     * matrix columns; a resource simply omits actions it does not support.
     * Keep in sync with Database\Seeders\RolesAndPermissionsSeeder.
     */
    private const GROUPS = [
        'inventory_master' => [
            'label'     => 'Inventory · Master Data',
            'resources' => [
                'products'         => ['label' => 'Products',         'actions' => ['view', 'create', 'edit', 'delete']],
                'categories'       => ['label' => 'Categories',       'actions' => ['view', 'create', 'edit', 'delete']],
                'unit_categories'  => ['label' => 'Unit Categories',  'actions' => ['view', 'create', 'edit', 'delete']],
                'unit_types'       => ['label' => 'Unit Types',       'actions' => ['view', 'create', 'edit', 'delete']],
                'unit_conversions' => ['label' => 'Unit Conversions', 'actions' => ['view', 'edit']],
                'supplier_masters' => ['label' => 'Suppliers',        'actions' => ['view', 'create', 'edit', 'delete']],
                'customer_masters' => ['label' => 'Customers',        'actions' => ['view', 'create', 'edit', 'delete']],
                'sales_channels'   => ['label' => 'Sales Channels',   'actions' => ['view', 'create', 'edit', 'delete']],
                'industries'       => ['label' => 'Industries',       'actions' => ['view', 'create', 'edit', 'delete']],
                'companies'        => ['label' => 'Companies',        'actions' => ['view', 'create', 'edit', 'delete']],
                'locations'        => ['label' => 'Locations',        'actions' => ['view', 'create', 'edit', 'delete']],
                'attribute_types'  => ['label' => 'Attribute Types',  'actions' => ['view', 'create', 'edit', 'delete']],
                'attributes'       => ['label' => 'Attributes',       'actions' => ['view', 'create', 'edit', 'delete']],
                'store_types'      => ['label' => 'Store Types',      'actions' => ['view', 'create', 'edit', 'delete']],
                'stores'           => ['label' => 'Stores',           'actions' => ['view', 'create', 'edit', 'delete']],
                'drivers'          => ['label' => 'Drivers',          'actions' => ['view', 'create', 'edit', 'delete']],
                'vehicle_masters'  => ['label' => 'Vehicles',         'actions' => ['view', 'create', 'edit', 'delete']],
                'payment_modes'    => ['label' => 'Payment Modes',    'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],
        'inventory_txn' => [
            'label'     => 'Inventory · Transactions',
            'resources' => [
                'purchase_requests'     => ['label' => 'Purchase Requests',     'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'purchase_orders'       => ['label' => 'Purchase Orders',       'actions' => ['view', 'create', 'edit', 'delete']],
                'grns'                  => ['label' => 'Goods Received Notes',   'actions' => ['view', 'create', 'edit', 'delete', 'confirm']],
                'costings'              => ['label' => 'Costings',              'actions' => ['view', 'create', 'edit', 'delete', 'confirm']],
                'costing_expense_types' => ['label' => 'Costing Expense Types', 'actions' => ['manage']],
                'sales_orders'          => ['label' => 'Sales Orders',          'actions' => ['view', 'create', 'edit', 'delete']],
                'delivery_orders'       => ['label' => 'Delivery Orders',       'actions' => ['view', 'create', 'edit', 'delete']],
                'invoices'              => ['label' => 'Invoices',              'actions' => ['view', 'create', 'edit', 'delete']],
                'supplier_payments'     => ['label' => 'Supplier Payments',     'actions' => ['view', 'create', 'edit', 'delete', 'confirm']],
                'supplier_credit_notes' => ['label' => 'Supplier Credit Notes', 'actions' => ['view']],
                'reports'               => ['label' => 'Reports',               'actions' => ['view']],
            ],
        ],
        'administration' => [
            'label'     => 'Administration',
            'resources' => [
                'users' => ['label' => 'Users', 'actions' => ['view', 'create', 'edit', 'delete']],
                'roles' => ['label' => 'Roles & Permissions', 'actions' => ['manage']],
            ],
        ],
    ];

    /** Return all existing permissions grouped by module → resource → action. */
    public function index(): JsonResponse
    {
        $existing = Permission::pluck('name')->flip();

        $grouped = [];

        foreach (self::GROUPS as $groupKey => $group) {
            $resources = [];

            foreach ($group['resources'] as $resourceKey => $resource) {
                $actions     = [];
                $permissions = [];

                foreach ($resource['actions'] as $action) {
                    $name = "{$action}_{$resourceKey}";
                    if ($existing->has($name)) {
                        $actions[]     = $action;
                        $permissions[] = $name;
                    }
                }

                if (! empty($permissions)) {
                    $resources[$resourceKey] = [
                        'label'       => $resource['label'],
                        'actions'     => $actions,
                        'permissions' => $permissions,
                    ];
                }
            }

            if (! empty($resources)) {
                $grouped[$groupKey] = [
                    'label'     => $group['label'],
                    'resources' => $resources,
                ];
            }
        }

        return response()->json(['data' => $grouped]);
    }
}
