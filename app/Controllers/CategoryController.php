<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Session;
use App\Models\Category;
use App\Services\Audit;

final class CategoryController extends Controller
{
    public function index(): void
    {
        $categories = new Category();

        $this->view('pages/categories', [
            'title' => 'Categories',
            'nav' => 'categories',
            'pageTitle' => 'Categories',
            'pageMeta' => 'Typed, so an income category can never be chosen on an expense',
            'expenseTree' => $categories->tree('expense'),
            'incomeTree' => $categories->tree('income'),
            'expenseParents' => $categories->parentsFor('expense'),
            'incomeParents' => $categories->parentsFor('income'),
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate([
            'name' => 'required|max:80',
            'type' => 'required|in:expense,income',
            'parent_id' => 'nullable|int',
            'sort_order' => 'nullable|int|between:0,9999',
        ], '/categories');

        $categories = new Category();
        $parentId = $clean['parent_id'] === null ? null : (int) $clean['parent_id'];

        if ($parentId !== null) {
            $parent = $categories->find($parentId);

            if ($parent === null) {
                Session::flash('error', 'That parent category no longer exists.');
                Http::redirect('/categories');
            }

            // One level only. A subcategory of a subcategory makes reports
            // ambiguous and the transaction forms unreadable.
            if ($parent['parent_id'] !== null) {
                Session::flash('error', 'Subcategories cannot themselves have subcategories.');
                Http::redirect('/categories');
            }

            // A child under a parent of a different type would be selectable on
            // the wrong form entirely.
            if ((string) $parent['type'] !== (string) $clean['type']) {
                Session::flash('error', 'A subcategory must have the same type as its parent.');
                Http::redirect('/categories');
            }
        }

        if ($categories->exists((string) $clean['name'], (string) $clean['type'], $parentId)) {
            Session::set('_old', $_POST);
            Session::flash('errors', ['name' => ['That category already exists here.']]);
            Session::flash('error', 'That category already exists.');
            Http::redirect('/categories');
        }

        $categoryData = [
            'name' => $clean['name'],
            'type' => $clean['type'],
            'parent_id' => $parentId,
            'is_active' => 1,
            'sort_order' => (int) ($clean['sort_order'] ?? 500),
        ];
        $id = $categories->create($categoryData);
        Audit::record('category.created', 'categories', $id, null, $categoryData);

        Session::flash('success', $clean['name'] . ' added.');
        Http::redirect('/categories');
    }

    /**
     * Toggle active. Categories are never deleted — a past transaction must
     * keep the category it was filed under, or historical reports change
     * retroactively.
     *
     * @param array<string,string> $params
     */
    public function toggle(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $categories = new Category();
        $category = $categories->find($id);

        if ($category === null) {
            Http::abort(404);
        }

        $nowActive = (int) $category['is_active'] === 1 ? 0 : 1;
        $categories->updateById($id, ['is_active' => $nowActive]);
        Audit::record(
            'category.toggled',
            'categories',
            $id,
            ['is_active' => $category['is_active']],
            ['is_active' => $nowActive]
        );

        // Deactivating a parent hides its children from selection too, so they
        // follow it rather than being left orphaned but selectable.
        if ($category['parent_id'] === null) {
            $categories->setChildrenActive($id, $nowActive);
        }

        Session::flash(
            'success',
            $category['name'] . ($nowActive === 1 ? ' is active again.' : ' is no longer available for new entries.')
        );
        Http::redirect('/categories');
    }
}
