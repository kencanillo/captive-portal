<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseArticle;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request): Response
    {
        $query = KnowledgeBaseArticle::with(['createdBy', 'updatedBy']);

        // Filter by category
        if ($category = $request->get('category')) {
            $query->byCategory($category);
        }

        // Search functionality
        if ($search = $request->get('search')) {
            $query->search($search);
        }

        // Filter by status
        if ($status = $request->get('status')) {
            if ($status === 'published') {
                $query->published();
            } elseif ($status === 'draft') {
                $query->where('is_published', false);
            }
        }

        $articles = $query->orderBy('sort_order')
            ->orderBy('updated_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/KnowledgeBase/Index', [
            'articles' => $articles,
            'filters' => [
                'category' => $category,
                'search' => $search,
                'status' => $status,
            ],
            'categories' => KnowledgeBaseArticle::getCategories(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/KnowledgeBase/Create', [
            'categories' => KnowledgeBaseArticle::getCategories(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:knowledge_base_articles,slug'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(KnowledgeBaseArticle::getCategories()))],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'is_published' => ['boolean'],
            'is_featured' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $article = KnowledgeBaseArticle::create([
            'title' => $validated['title'],
            'slug' => $validated['slug'] ?: Str::slug($validated['title']),
            'excerpt' => $validated['excerpt'],
            'content' => $validated['content'],
            'category' => $validated['category'],
            'tags' => $validated['tags'] ?? [],
            'is_published' => $validated['is_published'] ?? false,
            'is_featured' => $validated['is_featured'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.knowledge-base.show', $article)
            ->with('success', 'Knowledge base article created successfully.');
    }

    public function show(KnowledgeBaseArticle $article): Response
    {
        $article->load(['createdBy', 'updatedBy']);

        return Inertia::render('Admin/KnowledgeBase/Show', [
            'article' => $article,
            'categories' => KnowledgeBaseArticle::getCategories(),
        ]);
    }

    public function edit(KnowledgeBaseArticle $article): Response
    {
        return Inertia::render('Admin/KnowledgeBase/Edit', [
            'article' => $article,
            'categories' => KnowledgeBaseArticle::getCategories(),
        ]);
    }

    public function update(Request $request, KnowledgeBaseArticle $article): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:knowledge_base_articles,slug,' . $article->id],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(KnowledgeBaseArticle::getCategories()))],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'is_published' => ['boolean'],
            'is_featured' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $article->update([
            'title' => $validated['title'],
            'slug' => $validated['slug'] ?: Str::slug($validated['title']),
            'excerpt' => $validated['excerpt'],
            'content' => $validated['content'],
            'category' => $validated['category'],
            'tags' => $validated['tags'] ?? [],
            'is_published' => $validated['is_published'] ?? false,
            'is_featured' => $validated['is_featured'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.knowledge-base.show', $article)
            ->with('success', 'Knowledge base article updated successfully.');
    }

    public function publish(KnowledgeBaseArticle $article): RedirectResponse
    {
        $article->update([
            'is_published' => true,
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.knowledge-base.show', $article)
            ->with('success', 'Article published successfully.');
    }

    public function unpublish(KnowledgeBaseArticle $article): RedirectResponse
    {
        $article->update([
            'is_published' => false,
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.knowledge-base.show', $article)
            ->with('success', 'Article unpublished successfully.');
    }

    public function destroy(KnowledgeBaseArticle $article): RedirectResponse
    {
        $article->delete();

        return redirect()
            ->route('admin.knowledge-base.index')
            ->with('success', 'Knowledge base article deleted successfully.');
    }
}
