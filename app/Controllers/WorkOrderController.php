<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Asset;
use App\Models\WorkOrder;
use RuntimeException;

class WorkOrderController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        $orders = WorkOrder::all([
            'status' => (string) Request::get('status', ''),
            'q' => (string) Request::get('q', ''),
        ]);
        View::output(View::render('work_orders/index', [
            'title' => 'Work Orders',
            'orders' => $orders,
            'status' => (string) Request::get('status', ''),
            'q' => (string) Request::get('q', ''),
            'canModify' => Auth::canModify(),
            'assets' => $this->selectableAssets($user),
        ]));
    }

    public function store(): void
    {
        $user = Auth::requireLogin();
        try {
            WorkOrder::store(Request::post('wo') ?? [], $user);
            Auth::flash('success', 'Work order created.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/work-orders');
    }

    public function complete(string $id): void
    {
        $user = Auth::requireLogin();
        try {
            WorkOrder::complete((int) $id, $user);
            Auth::flash('success', 'Work order completed. Asset returned to stock.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/work-orders');
    }

    private function selectableAssets(array $user): array
    {
        return Asset::search(['q' => ''], 1, 500, $user)['rows'];
    }
}
