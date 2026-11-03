<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        // get categories
        $categories = Category::query()
            ->when(request()->search, function ($categories) {
                $categories->where('name', 'like', '%'.request()->search.'%');
            })
            ->when(
                config('warehouse.is_warehouse'),
                fn ($categories) => $categories->withCount('products')->orderBy('name'),
                fn ($categories) => $categories->latest()
            )
            ->paginate($this->perPage())
            ->withQueryString();

        // return inertia
        return Inertia::render('Dashboard/Categories/Index', [
            'categories' => $categories,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        return Inertia::render('Dashboard/Categories/Create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        if (config('warehouse.is_warehouse')) {
            $request->validate([
                'name' => 'required|string|max:100',
            ], [
                'name.required' => 'ใส่ชื่อหมวด',
            ]);

            Category::create([
                'image' => 'rm.png',
                'name' => trim((string) $request->name),
                'description' => trim((string) $request->name),
            ]);

            return to_route('categories.index');
        }

        /**
         * validate
         */
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png|max:2048',
            'name' => 'required',
            'description' => 'required',
        ]);

        // upload image
        $image = $request->file('image');
        $image->storeAs('public/category', $image->hashName());

        // create category
        Category::create([
            'image' => $image->hashName(),
            'name' => $request->name,
            'description' => $request->description,
        ]);

        // redirect
        return to_route('categories.index');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit(Category $category)
    {
        return Inertia::render('Dashboard/Categories/Edit', [
            'category' => $category,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, Category $category)
    {
        if (config('warehouse.is_warehouse') && ! $request->hasFile('image')) {
            $request->validate([
                'name' => 'required|string|max:100',
            ], [
                'name.required' => 'ใส่ชื่อหมวด',
            ]);

            $category->update([
                'name' => trim((string) $request->name),
            ]);

            return to_route('categories.index');
        }

        /**
         * validate
         */
        $request->validate([
            'name' => 'required',
            'description' => 'required',
        ]);

        // check image update
        if ($request->file('image')) {

            // remove old image
            Storage::disk('local')->delete('public/category/'.basename($category->image));

            // upload new image
            $image = $request->file('image');
            $image->storeAs('public/category', $image->hashName());

            // update category with new image
            $category->update([
                'image' => $image->hashName(),
                'name' => $request->name,
                'description' => $request->description,
            ]);
        }

        // update category without image
        $category->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        // redirect
        return to_route('categories.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        // find by ID
        $category = Category::findOrFail($id);

        // remove image
        Storage::disk('local')->delete('public/category/'.basename($category->image));

        // delete
        $category->delete();

        // redirect
        return to_route('categories.index');
    }
}
