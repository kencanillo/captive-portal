<template>
  <div>
    <Head title="Create Knowledge Base Article" />

    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-8">
          <h1 class="text-2xl font-semibold text-gray-900">Create Knowledge Base Article</h1>
          <p class="mt-2 text-gray-600">Write a new technical article for the knowledge base.</p>
        </div>

        <form @submit.prevent="submit">
          <div class="space-y-6">
            <!-- Title -->
            <div>
              <label for="title" class="block text-sm font-medium text-gray-700">Title</label>
              <input
                id="title"
                v-model="form.title"
                type="text"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                :class="{ 'border-red-500': form.errors.title }"
                required
              />
              <div v-if="form.errors.title" class="mt-1 text-sm text-red-600">
                {{ form.errors.title }}
              </div>
            </div>

            <!-- Slug -->
            <div>
              <label for="slug" class="block text-sm font-medium text-gray-700">URL Slug</label>
              <input
                id="slug"
                v-model="form.slug"
                type="text"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                :class="{ 'border-red-500': form.errors.slug }"
                placeholder="auto-generated-from-title"
              />
              <div v-if="form.errors.slug" class="mt-1 text-sm text-red-600">
                {{ form.errors.slug }}
              </div>
              <p class="mt-1 text-sm text-gray-500">Leave empty to auto-generate from title.</p>
            </div>

            <!-- Category -->
            <div>
              <label for="category" class="block text-sm font-medium text-gray-700">Category</label>
              <select
                id="category"
                v-model="form.category"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                :class="{ 'border-red-500': form.errors.category }"
                required
              >
                <option value="">Select a category</option>
                <option v-for="(label, key) in categories" :key="key" :value="key">
                  {{ label }}
                </option>
              </select>
              <div v-if="form.errors.category" class="mt-1 text-sm text-red-600">
                {{ form.errors.category }}
              </div>
            </div>

            <!-- Excerpt -->
            <div>
              <label for="excerpt" class="block text-sm font-medium text-gray-700">Excerpt</label>
              <textarea
                id="excerpt"
                v-model="form.excerpt"
                rows="3"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                :class="{ 'border-red-500': form.errors.excerpt }"
                placeholder="Brief description of the article (optional)"
              ></textarea>
              <div v-if="form.errors.excerpt" class="mt-1 text-sm text-red-600">
                {{ form.errors.excerpt }}
              </div>
              <p class="mt-1 text-sm text-gray-500">Leave empty to auto-generate from content.</p>
            </div>

            <!-- Content -->
            <div>
              <label for="content" class="block text-sm font-medium text-gray-700">Content</label>
              <textarea
                id="content"
                v-model="form.content"
                rows="20"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono"
                :class="{ 'border-red-500': form.errors.content }"
                placeholder="Write your article content here. You can use Markdown syntax."
                required
              ></textarea>
              <div v-if="form.errors.content" class="mt-1 text-sm text-red-600">
                {{ form.errors.content }}
              </div>
              <p class="mt-1 text-sm text-gray-500">
                You can use Markdown syntax. Supports headers, lists, code blocks, etc.
              </p>
            </div>

            <!-- Tags -->
            <div>
              <label for="tags" class="block text-sm font-medium text-gray-700">Tags</label>
              <input
                id="tags"
                v-model="tagInput"
                type="text"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                placeholder="Enter tags separated by commas"
                @input="updateTags"
              />
              <div v-if="form.tags && form.tags.length > 0" class="mt-2">
                <span
                  v-for="tag in form.tags"
                  :key="tag"
                  class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 mr-2 mb-2"
                >
                  {{ tag }}
                  <button
                    type="button"
                    @click="removeTag(tag)"
                    class="ml-1 text-gray-500 hover:text-gray-700"
                  >
                    ×
                  </button>
                </span>
              </div>
            </div>

            <!-- Options -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div class="flex items-center">
                <input
                  id="is_published"
                  v-model="form.is_published"
                  type="checkbox"
                  class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                />
                <label for="is_published" class="ml-2 block text-sm text-gray-900">
                  Publish immediately
                </label>
              </div>

              <div class="flex items-center">
                <input
                  id="is_featured"
                  v-model="form.is_featured"
                  type="checkbox"
                  class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                />
                <label for="is_featured" class="ml-2 block text-sm text-gray-900">
                  Featured article
                </label>
              </div>

              <div>
                <label for="sort_order" class="block text-sm font-medium text-gray-700">Sort Order</label>
                <input
                  id="sort_order"
                  v-model="form.sort_order"
                  type="number"
                  min="0"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  :class="{ 'border-red-500': form.errors.sort_order }"
                />
                <div v-if="form.errors.sort_order" class="mt-1 text-sm text-red-600">
                  {{ form.errors.sort_order }}
                </div>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div class="mt-8 flex justify-end space-x-4">
            <Link
              :href="route('admin.knowledge-base.index')"
              class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
            >
              Cancel
            </Link>
            <button
              type="submit"
              class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
              :disabled="form.processing"
            >
              {{ form.is_published ? 'Publish Article' : 'Save Draft' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'

const props = defineProps({
  categories: Object,
})

const tagInput = ref('')

const form = useForm({
  title: '',
  slug: '',
  excerpt: '',
  content: '',
  category: '',
  tags: [],
  is_published: false,
  is_featured: false,
  sort_order: 0,
})

function updateTags() {
  const tags = tagInput.value
    .split(',')
    .map(tag => tag.trim())
    .filter(tag => tag.length > 0)
  form.tags = tags
}

function removeTag(tag) {
  form.tags = form.tags.filter(t => t !== tag)
  tagInput.value = form.tags.join(', ')
}

function submit() {
  form.post(route('admin.knowledge-base.store'))
}
</script>
