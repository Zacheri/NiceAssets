<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Department;
use App\Models\Person;
use RuntimeException;

class PersonController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::output(View::render('admin/persons/index', [
            'title' => 'Persons',
            'persons' => Person::all(),
        ]));
    }

    public function create(): void
    {
        Auth::requireLogin();
        $this->renderForm(null, 'New Person');
    }

    public function store(): void
    {
        Auth::requireLogin();
        try {
            Person::create(Request::post('person') ?? []);
            Auth::flash('success', 'Person added.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/persons');
    }

    public function edit(string $id): void
    {
        Auth::requireLogin();
        $person = Person::find((int) $id);
        if ($person === null) {
            Response::notFound('Person not found.');
        }
        $this->renderForm($person, 'Edit Person');
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        try {
            Person::update((int) $id, Request::post('person') ?? []);
            Auth::flash('success', 'Person updated.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/persons');
    }

    public function toggle(string $id): void
    {
        Auth::requireLogin();
        try {
            $terminated = Person::toggleTerminated((int) $id);
            $name = Person::find((int) $id)['full_name'] ?? 'Person';
            Auth::flash('success', $terminated ? $name . ' marked as terminated.' : $name . ' reinstated.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/persons');
    }

    public function delete(string $id): void
    {
        Auth::requireLogin();
        try {
            Person::delete((int) $id);
            Auth::flash('success', 'Person deleted.');
        } catch (RuntimeException $e) {
            Auth::flash('error', $e->getMessage());
        }
        Response::redirect('/admin/persons');
    }

    private function renderForm(?array $person, string $title): void
    {
        View::output(View::render('admin/persons/form', [
            'title' => $title,
            'person' => $person,
            'departments' => Department::all(),
        ]));
    }
}
