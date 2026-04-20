<template>
  <div>
    <Head title="Knowledge Base" />

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-8">
          <h1 class="text-2xl font-semibold text-gray-900">Knowledge Base</h1>
          <p class="mt-2 text-gray-600">Technical documentation and guides for the CaptivePortal system.</p>
        </div>

        <!-- Filters and Search -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-2">
              <label for="search" class="block text-sm font-medium text-gray-700">Search</label>
              <input
                id="search"
                v-model="filters.search"
                type="text"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="Search articles..."
                @input="debouncedSearch"
              />
            </div>
            
            <div>
              <label for="category" class="block text-sm font-medium text-gray-700">Category</label>
              <select
                id="category"
                v-model="filters.category"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                @change="applyFilters"
              >
                <option value="">All Categories</option>
                <option v-for="(label, key) in categories" :key="key" :value="key">
                  {{ label }}
                </option>
              </select>
            </div>

            <div>
              <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
              <select
                id="status"
                v-model="filters.status"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                @change="applyFilters"
              >
                <option value="">All Status</option>
                <option value="published">Published</option>
                <option value="draft">Draft</option>
              </select>
            </div>
          </div>

          <div class="mt-4 flex justify-between items-center">
            <div class="text-sm text-gray-500">
              Showing {{ articles.data.length }} of {{ articles.total }} articles
            </div>
            <Link
              :href="route('admin.knowledge-base.create')"
              class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
            >
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              New Article
            </Link>
          </div>
        </div>

        <!-- Articles List -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
          <div class="divide-y divide-gray-200">
            <div v-for="article in articles.data" :key="article.id" class="p-6 hover:bg-gray-50">
              <div class="flex items-start justify-between">
                <div class="flex-1">
                  <div class="flex items-center space-x-2">
                    <Link
                      :href="route('admin.knowledge-base.show', article)"
                      class="text-lg font-medium text-gray-900 hover:text-indigo-600"
                    >
                      {{ article.title }}
                    </Link>
                    <span
                      v-if="article.is_featured"
                      class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800"
                    >
                      Featured
                    </span>
                    <span
                      :class="{
                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium': true,
                        'bg-green-100 text-green-800': article.is_published,
                        'bg-gray-100 text-gray-800': !article.is_published
                      }"
                    >
                      {{ article.is_published ? 'Published' : 'Draft' }}
                    </span>
                  </div>
                  
                  <p class="mt-1 text-sm text-gray-500">{{ article.excerpt }}</p>
                  
                  <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                    <span>{{ categories[article.category] }}</span>
                    <span>·</span>
                    <span>{{ article.views }} views</span>
                    <span>·</span>
                    <span>{{ article.reading_time }} min read</span>
                    <span>·</span>
                    <span>Updated {{ formatDate(article.updated_at) }}</span>
                  </div>

                  <div v-if="article.tags && article.tags.length > 0" class="mt-2">
                    <span
                      v-for="tag in article.tags"
                      :key="tag"
                      class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 mr-2"
                    >
                      {{ tag }}
                    </span>
                  </div>
                </div>

                <div class="ml-4 flex space-x-2">
                  <Link
                    :href="route('admin.knowledge-base.edit', article)"
                    class="text-indigo-600 hover:text-indigo-900"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </Link>
                  <button
                    @click="deleteArticle(article)"
                    class="text-red-600 hover:text-red-900"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div v-if="articles.data.length === 0" class="p-6 text-center text-gray-500">
            No articles found.
          </div>
        </div>

        <!-- Pagination -->
        <div v-if="articles.links" class="mt-6">
          <Pagination :links="articles.links" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { debounce } from 'lodash'
import Pagination from '@/Shared/Pagination.vue'
import { formatDate } from '@/Shared/formatters'

const props = defineProps({
  articles: Object,
  filters: Object,
  categories: Object,
})

const filters = ref({ ...props.filters })

const debouncedSearch = debounce(() => {
  applyFilters()
}, 300)

function applyFilters() {
  router.get(route('admin.knowledge-base.index'), filters.value, {
    preserveState: true,
    preserveScroll: true,
  })
}

function deleteArticle(article) {
  if (confirm('Are you sure you want to delete this article?')) {
    router.delete(route('admin.knowledge-base.destroy', article), {
      preserveScroll: true,
    })
  }
}
</script>
