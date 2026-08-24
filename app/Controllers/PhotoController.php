<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Photo;
use RuntimeException;

class PhotoController
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        $photos = Photo::all();
        $usedIds = array_map(static fn ($p) => (int) $p['id'], $photos);
        View::output(View::render('photos/gallery', [
            'title' => 'Photo Gallery',
            'photos' => $photos,
            'variety' => (string) Request::get('variety', ''),
            'canManage' => Auth::canModify(),
            'usedIds' => $usedIds,
        ]));
    }

    public function upload(): void
    {
        $user = Auth::requireLogin();
        $variety = (string) Request::post('variety', '');
        $files = $_FILES['photos'] ?? [];
        if (!is_array($files['name'] ?? null)) {
            $files = ['name' => [$files['name'] ?? ''], 'type' => [$files['type'] ?? ''],
                'tmp_name' => [$files['tmp_name'] ?? ''], 'error' => [$files['error'] ?? UPLOAD_ERR_NO_FILE],
                'size' => [$files['size'] ?? 0]];
        }
        if (count(array_filter($files['error'], static fn ($e) => $e === UPLOAD_ERR_OK)) === 0) {
            Auth::flash('error', 'No photos were uploaded.');
            Response::redirect('/photos');
        }
        try {
            $created = Photo::upload($files, $variety, $user);
            Auth::flash('success', count($created) . ' photo(s) added to the gallery.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/photos');
    }

    public function delete(string $id): void
    {
        $user = Auth::requireLogin();
        try {
            Photo::delete((int) $id, $user);
            Auth::flash('success', 'Photo deleted.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/photos');
    }
}
