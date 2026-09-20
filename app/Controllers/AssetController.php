<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Asset;
use App\Models\Audit;
use App\Models\Category;
use App\Models\Department;
use App\Models\Location;
use App\Models\Photo;
use App\Models\Person;
use App\Models\Site;
use App\Models\UserPref;
use App\Services\AlertEngine;
use App\Services\Barcode;
use App\Services\Export;
use App\Services\Replicate;
use RuntimeException;

class AssetController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        $filters = [
            'q' => Request::get('q', ''),
            'category_id' => Request::int('category_id'),
            'department_id' => Request::int('department_id'),
            'site_id' => Request::int('site_id'),
            'location_id' => Request::int('location_id'),
            'status' => (string) Request::get('status', ''),
            'brand' => Request::get('brand', ''),
            'model' => Request::get('model', ''),
            'assigned_person_id' => Request::int('assigned_person_id'),
            'purchased_from' => Request::get('purchased_from', ''),
            'purchased_to' => Request::get('purchased_to', ''),
            'warranty_from' => Request::get('warranty_from', ''),
            'warranty_to' => Request::get('warranty_to', ''),
            'overdue_only' => Request::bool('overdue_only'),
            'sort' => (string) Request::get('sort', 'newest'),
        ];
        $page = max(1, Request::int('page', 1));
        $perPage = min((int) Config::get('limits.per_page_max'), max(6, UserPref::get((int) $user['id'], 'per_page', (int) Config::get('limits.per_page'))));

        $result = Asset::search($filters, $page, $perPage, $user);
        $alerts = AlertEngine::dashboard($user);

        View::output(View::render('assets/index', [
            'title' => 'Assets',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'perPage' => $result['per_page'],
            'filters' => $filters,
            'categories' => Category::active(),
            'departments' => Department::active(),
            'sites' => Site::active(),
            'locationsBySite' => Location::activeBySite(),
            'persons' => Person::all(),
            'statuses' => Asset::STATUSES,
            'cols' => (int) UserPref::get((int) $user['id'], 'grid_cols', 3),
            'important_count' => count(array_filter($alerts, static fn ($a) => $a['severity'] === 'important')),
        ]));
    }

    public function create(): void
    {
        $user = Auth::requireLogin();
        $this->renderForm($user, null, 'New Asset');
    }

    public function store(): void
    {
        $user = Auth::requireLogin();
        try {
            $id = Asset::store(Request::post('asset') ?? [], $user);
            $this->linkPhotos((int) $user['id'], $id);
            Auth::flash('success', 'Asset created.');
            Response::redirect('/assets/' . $id);
        } catch (RuntimeException $e) {
            $this->formError('New Asset', $e->getMessage());
        }
    }

    public function show(string $id): void
    {
        $user = Auth::requireLogin();
        $asset = Asset::find((int) $id, $user);
        if ($asset === null) {
            Response::notFound('Asset not found.');
        }
        $alerts = AlertEngine::dashboard($user);
        View::output(View::render('assets/show', [
            'title' => $asset['asset_tag'],
            'asset' => $asset,
            'departments' => Department::active(),
            'galleryPhotos' => Photo::all('asset'),
            'canModify' => Auth::canModify(),
            'isAdmin' => Auth::isAdmin(),
            'important_count' => count(array_filter($alerts, static fn ($a) => $a['severity'] === 'important')),
        ]));
    }

    public function edit(string $id): void
    {
        $user = Auth::requireLogin();
        $asset = Asset::find((int) $id, $user);
        if ($asset === null) {
            Response::notFound('Asset not found.');
        }
        $this->renderForm($user, $asset, 'Edit Asset');
    }

    public function update(string $id): void
    {
        $user = Auth::requireLogin();
        $asset = Asset::find((int) $id, $user);
        if ($asset === null) {
            Response::notFound('Asset not found.');
        }
        try {
            Asset::update((int) $id, Request::post('asset') ?? [], $user);
            $this->linkPhotos((int) $user['id'], (int) $id);
            Auth::flash('success', 'Asset updated.');
            Response::redirect('/assets/' . $id);
        } catch (RuntimeException $e) {
            $this->formError('Edit Asset', $e->getMessage());
        }
    }

    private function renderForm(array $user, ?array $asset, string $title): void
    {
        View::output(View::render('assets/form', [
            'title' => $title,
            'asset' => $asset,
            'suggested_tag' => $asset ? $asset['asset_tag'] : Asset::suggestTag(),
            'categories' => Category::active(),
            'departments' => Department::active(),
            'sites' => Site::active(),
            'locationsBySite' => Location::activeBySite(),
            'photos' => Photo::all('asset'),
            'selectedPhotos' => $asset ? Asset::photosFor((int) $asset['id']) : [],
            'manager' => $user['role_name'] === 'department_manager',
        ]));
    }

    private function formError(string $title, string $message): void
    {
        Auth::flash('error', $message);
        Response::back();
    }

    private function requireAsset(string $id, array $user): ?array
    {
        $asset = Asset::find((int) $id, $user);
        if ($asset === null) {
            Response::notFound('Asset not found.');
        }
        return $asset;
    }

    public function checkOut(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        try {
            Asset::setStatus((int) $id, 'checked_out', [
                'assigned_to_person_id' => Request::int('assigned_to_person_id'),
                'assigned_to_department_id' => Request::int('assigned_to_department_id'),
                'due_date' => (string) Request::post('due_date', ''),
            ], $user);
            Auth::flash('success', 'Asset checked out.');
            Response::redirect('/assets/' . $id);
        } catch (RuntimeException $e) {
            $this->formError('Check out', $e->getMessage());
        }
    }

    public function checkIn(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        try {
            Asset::setStatus((int) $id, 'available', [], $user);
            Auth::flash('success', 'Asset checked in and available in stock.');
            Response::redirect('/assets/' . $id);
        } catch (RuntimeException $e) {
            $this->formError('Check in', $e->getMessage());
        }
    }

    public function transfer(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        try {
            Asset::transfer((int) $id, Request::int('to_person_id'), $user);
            Auth::flash('success', 'Asset transferred.');
            Response::redirect('/assets/' . $id);
        } catch (RuntimeException $e) {
            $this->formError('Transfer', $e->getMessage());
        }
    }

    public function repair(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        try {
            Asset::setStatus((int) $id, 'in_repair', [
                'summary' => (string) Request::post('summary', ''),
                'details' => (string) Request::post('details', ''),
            ], $user);
            Auth::flash('success', 'Asset sent to repair. Work order created.');
            Response::redirect('/assets/' . $id);
        } catch (RuntimeException $e) {
            $this->formError('Repair', $e->getMessage());
        }
    }

    public function broken(string $id): void
    {
        $this->reasonAction($id, 'broken', 'Asset marked as broken.');
    }

    public function lost(string $id): void
    {
        $this->reasonAction($id, 'lost', 'Asset marked as lost.');
    }

    private function reasonAction(string $id, string $status, string $success): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        try {
            Asset::setStatus((int) $id, $status, [
                'status_reason' => (string) Request::post('status_reason', ''),
            ], $user);
            Auth::flash('success', $success);
            Response::redirect('/assets/' . $id);
        } catch (RuntimeException $e) {
            $this->formError('Mark ' . $status, $e->getMessage());
        }
    }

    public function dispose(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        try {
            Asset::setStatus((int) $id, 'disposed', [
                'disposal_location' => (string) Request::post('disposal_location', ''),
                'disposal_date' => (string) Request::post('disposal_date', ''),
                'disposal_remaining_cost' => (string) Request::post('disposal_remaining_cost', ''),
                'status_reason' => (string) Request::post('status_reason', ''),
            ], $user);
            Auth::flash('success', 'Asset recorded as disposed.');
            Response::redirect('/assets/' . $id);
        } catch (RuntimeException $e) {
            $this->formError('Dispose', $e->getMessage());
        }
    }

    public function sell(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        try {
            Asset::setStatus((int) $id, 'sold', [
                'sold_to' => (string) Request::post('sold_to', ''),
                'sold_price' => (string) Request::post('sold_price', ''),
                'sold_date' => (string) Request::post('sold_date', ''),
            ], $user);
            Auth::flash('success', 'Asset recorded as sold.');
            Response::redirect('/assets/' . $id);
        } catch (RuntimeException $e) {
            $this->formError('Sell', $e->getMessage());
        }
    }

    public function donate(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        try {
            Asset::setStatus((int) $id, 'donated', [
                'donated_to' => (string) Request::post('donated_to', ''),
                'donated_value' => (string) Request::post('donated_value', ''),
                'donated_date' => (string) Request::post('donated_date', ''),
            ], $user);
            Auth::flash('success', 'Asset recorded as donated.');
            Response::redirect('/assets/' . $id);
        } catch (RuntimeException $e) {
            $this->formError('Donate', $e->getMessage());
        }
    }

    public function replicate(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        try {
            $newId = Replicate::replicate((int) $id, $user);
            $tag = Database::fetchColumn('SELECT asset_tag FROM assets WHERE id = :id', ['id' => $newId]);
            Auth::flash('success', 'Asset replicated as ' . $tag . '.');
            Response::redirect('/assets/' . $newId);
        } catch (RuntimeException $e) {
            $this->formError('Replicate', $e->getMessage());
        }
    }

    public function email(string $id): void
    {
        $user = Auth::requireLogin();
        $asset = $this->requireAsset($id, $user);
        $to = trim((string) Request::post('to', ''));
        $subject = trim((string) Request::post('subject', ''));
        $message = trim((string) Request::post('message', ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Auth::flash('error', 'A valid recipient email is required.');
            Response::back();
        }
        $body = $message !== ''
            ? $message
            : "Asset summary for {$asset['asset_tag']}\n\n"
                . "Brand: {$asset['brand']}\nModel: {$asset['model_number']}\n"
                . "Serial: {$asset['serial_number']}\nStatus: " . status_label($asset['status']) . "\n"
                . "Purchase cost: " . money($asset['purchase_cost']) . "\n"
                . "Warranty: " . date_fmt($asset['warranty_expiration']) . "\n";
        $html = '<pre style="font-family:monospace;font-size:13px;white-space:pre-wrap">' . e($body) . '</pre>';
        $sent = Mailer::send($to, '', $subject !== '' ? $subject : 'Asset ' . $asset['asset_tag'], $html);
        if ($sent) {
            Audit::log('asset.email', 'asset', (string) $id, ['asset_tag' => $asset['asset_tag'], 'to' => $to]);
            Auth::flash('success', 'Email sent to ' . $to . '.');
        } else {
            Auth::flash('error', 'Email could not be sent. Check the mail configuration in Admin → Settings.');
        }
        Response::redirect('/assets/' . $id);
    }

    public function delete(string $id): void
    {
        $user = Auth::requireLogin();
        $asset = $this->requireAsset($id, $user);
        try {
            Asset::delete((int) $id, $user);
            Auth::flash('success', 'Asset ' . $asset['asset_tag'] . ' deleted.');
            Response::redirect('/assets');
        } catch (RuntimeException $e) {
            $this->formError('Delete', $e->getMessage());
        }
    }

    public function sheet(string $id): void
    {
        $user = Auth::requireLogin();
        $asset = $this->requireAsset($id, $user);
        $qrFile = Barcode::qr((string) $asset['asset_tag']);
        $file = Export::assetSheet($asset, $qrFile);
        Audit::log('asset.sheet', 'asset', (string) $id, ['asset_tag' => $asset['asset_tag']]);
        Response::file($file, 'asset_' . $asset['asset_tag'] . '.pdf', 'application/pdf');
    }

    public function qr(string $id): void
    {
        $user = Auth::requireLogin();
        $asset = $this->requireAsset($id, $user);
        $file = Barcode::qr((string) $asset['asset_tag'], 600);
        Response::file($file, $asset['asset_tag'] . '_qr.png', 'image/png');
    }

    public function assignPhoto(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        $photoId = Request::int('photo_id');
        $photo = Photo::find($photoId);
        if ($photo === null) {
            Auth::flash('error', 'Photo not found.');
            Response::back();
        }
        $exists = Database::fetchOne('SELECT 1 FROM asset_photos WHERE asset_id = :a AND photo_id = :p', ['a' => $id, 'p' => $photoId]);
        if ($exists === null) {
            $maxPos = (int) Database::fetchColumn('SELECT COALESCE(MAX(position), -1) FROM asset_photos WHERE asset_id = :a', ['a' => $id]);
            $hasThumb = Database::fetchOne('SELECT 1 FROM asset_photos WHERE asset_id = :a AND is_thumbnail = true', ['a' => $id]) !== null;
            Database::execute(
                'INSERT INTO asset_photos (asset_id, photo_id, position, is_thumbnail) VALUES (:a, :p, :pos, :t)
                 ON CONFLICT DO NOTHING',
                ['a' => $id, 'p' => $photoId, 'pos' => $maxPos + 1, 't' => $hasThumb ? 0 : 1]
            );
            Audit::log('asset.photo', 'asset', (string) $id, ['action' => 'assign', 'photo' => $photo['filename']]);
        }
        Auth::flash('success', 'Photo linked to asset.');
        Response::redirect('/assets/' . $id);
    }

    public function unassignPhoto(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        $photoId = Request::int('photo_id');
        Database::execute('DELETE FROM asset_photos WHERE asset_id = :a AND photo_id = :p', ['a' => $id, 'p' => $photoId]);
        Database::execute(
            'UPDATE asset_photos ap SET is_thumbnail = true
             WHERE ap.asset_id = :a AND ap.photo_id = (
                 SELECT ap2.photo_id FROM asset_photos ap2 WHERE ap2.asset_id = :a2
                 ORDER BY ap2.position ASC LIMIT 1)',
            ['a' => $id, 'a2' => $id]
        );
        Audit::log('asset.photo', 'asset', (string) $id, ['action' => 'unassign', 'photo_id' => $photoId]);
        Response::redirect('/assets/' . $id);
    }

    public function setThumbnail(string $id): void
    {
        $user = Auth::requireLogin();
        $this->requireAsset($id, $user);
        $photoId = Request::int('photo_id');
        Database::execute('UPDATE asset_photos SET is_thumbnail = false WHERE asset_id = :a', ['a' => $id]);
        Database::execute(
            'UPDATE asset_photos SET is_thumbnail = true, position = 0 WHERE asset_id = :a AND photo_id = :p',
            ['a' => $id, 'p' => $photoId]
        );
        Audit::log('asset.photo', 'asset', (string) $id, ['action' => 'thumbnail', 'photo_id' => $photoId]);
        Response::redirect('/assets/' . $id);
    }

    private function linkPhotos(int $userId, int $assetId): void
    {
        $photoIds = array_map('intval', (array) (Request::post('photo_ids') ?? []));
        $i = 0;
        foreach ($photoIds as $photoId) {
            if ($photoId <= 0) {
                continue;
            }
            Database::execute(
                'INSERT INTO asset_photos (asset_id, photo_id, position, is_thumbnail)
                 VALUES (:a, :p, :pos, :t) ON CONFLICT DO NOTHING',
                ['a' => $assetId, 'p' => $photoId, 'pos' => $i, 't' => $i === 0 ? 1 : 0]
            );
            $i++;
        }
    }
}
