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
        $kind = 'asset';
        if (str_ends_with(Request::path(), '/photos/portraits')) {
            $kind = 'portrait';
        } else {
            $q = (string) Request::get('kind', '');
            if ($q === 'asset' || $q === 'portrait') {
                $kind = $q;
            }
        }
        $photos = Photo::all($kind);
        $usedIds = array_map(static fn ($p) => (int) $p['id'], $photos);
        View::output(View::render('photos/gallery', [
            'title' => $kind === 'portrait' ? 'Portraits' : 'Photo Gallery',
            'nav' => $kind === 'portrait' ? 'photos/portraits' : 'photos',
            'photos' => $photos,
            'kind' => $kind,
            'variety' => (string) Request::get('variety', ''),
            'canManage' => Auth::canModify(),
            'usedIds' => $usedIds,
        ]));
    }

    public function upload(): void
    {
        $user = Auth::requireLogin();
        $variety = (string) Request::post('variety', '');
        $kind = (string) Request::post('kind', 'asset');
        if ($kind !== 'asset' && $kind !== 'portrait') {
            $kind = 'asset';
        }
        $files = $_FILES['photos'] ?? [];
        if (!is_array($files['name'] ?? null)) {
            $files = ['name' => [$files['name'] ?? ''], 'type' => [$files['type'] ?? ''],
                'tmp_name' => [$files['tmp_name'] ?? ''], 'error' => [$files['error'] ?? UPLOAD_ERR_NO_FILE],
                'size' => [$files['size'] ?? 0]];
        }
        if (count(array_filter($files['error'], static fn ($e) => $e === UPLOAD_ERR_OK)) === 0) {
            Auth::flash('error', 'No photos were uploaded.');
            Response::redirect($kind === 'portrait' ? '/photos/portraits' : '/photos');
        }
        try {
            $created = Photo::upload($files, $variety, $user, $kind);
            Auth::flash('success', count($created) . ($kind === 'portrait' ? ' portrait(s) added.' : ' photo(s) added to the gallery.'));
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect($kind === 'portrait' ? '/photos/portraits' : '/photos');
    }

    public function delete(string $id): void
    {
        $user = Auth::requireLogin();
        $photo = Photo::find((int) $id);
        $kind = $photo !== null && $photo['kind'] === 'portrait' ? 'portrait' : 'asset';
        try {
            Photo::delete((int) $id, $user);
            Auth::flash('success', $kind === 'portrait' ? 'Portrait deleted.' : 'Photo deleted.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect($kind === 'portrait' ? '/photos/portraits' : '/photos');
    }
}
