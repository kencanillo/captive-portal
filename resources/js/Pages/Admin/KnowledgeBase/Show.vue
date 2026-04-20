<template>
  <div>
    <Head :title="article.title" />

    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-8">
          <div class="flex items-center justify-between">
            <div>
              <h1 class="text-2xl font-semibold text-gray-900">{{ article.title }}</h1>
              <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                <span>{{ categories[article.category] }}</span>
                <span>·</span>
                <span>{{ article.views }} views</span>
                <span>·</span>
                <span>{{ article.reading_time }} min read</span>
                <span>·</span>
                <span>Created {{ formatDate(article.created_at) }}</span>
                <span>·</span>
                <span>Updated {{ formatDate(article.updated_at) }}</span>
              </div>
            </div>
            
            <div class="flex space-x-2">
              <Link
                :href="route('admin.knowledge-base.edit', article)"
                class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
                Edit
              </Link>
              
              <button
                v-if="article.is_published"
                @click="unpublish"
                class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                </svg>
                Unpublish
              </button>
              
              <button
                v-else
                @click="publish"
                class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Publish
              </button>
              
              <button
                @click="deleteArticle"
                class="inline-flex items-center px-3 py-2 border border-red-300 text-sm font-medium rounded-md text-red-700 bg-white hover:bg-red-50"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Delete
              </button>
            </div>
          </div>
        </div>

        <!-- Status Indicators -->
        <div class="mb-6 flex items-center space-x-4">
          <span
            v-if="article.is_featured"
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800"
          >
            Featured Article
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

        <!-- Tags -->
        <div v-if="article.tags && article.tags.length > 0" class="mb-6">
          <div class="flex flex-wrap gap-2">
            <span
              v-for="tag in article.tags"
              :key="tag"
              class="inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800"
            >
              {{ tag }}
            </span>
          </div>
        </div>

        <!-- Article Content -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
          <div class="p-8">
            <div class="prose prose-lg max-w-none">
              <div v-html="formattedContent"></div>
            </div>
          </div>
        </div>

        <!-- Meta Information -->
        <div class="mt-8 bg-gray-50 rounded-lg p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Article Information</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <dt class="text-sm font-medium text-gray-500">Created by</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ article.created_by?.name }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Last updated by</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ article.updated_by?.name || article.created_by?.name }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">URL Slug</dt>
              <dd class="mt-1 text-sm text-gray-900 font-mono">{{ article.slug }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Sort Order</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ article.sort_order }}</dd>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import { formatDate } from '@/Shared/formatters'
import { marked } from 'marked'

const props = defineProps({
  article: Object,
  categories: Object,
})

const formattedContent = marked(props.article.content || '')

function publish() {
  router.post(route('admin.knowledge-base.publish', props.article))
}

function unpublish() {
  router.post(route('admin.knowledge-base.unpublish', props.article))
}

function deleteArticle() {
  if (confirm('Are you sure you want to delete this article?')) {
    router.delete(route('admin.knowledge-base.destroy', props.article))
  }
}
</script>

<style>
.prose {
  @apply text-gray-900;
}

.prose h1 {
  @apply text-2xl font-bold mt-8 mb-4;
}

.prose h2 {
  @apply text-xl font-bold mt-6 mb-3;
}

.prose h3 {
  @apply text-lg font-bold mt-4 mb-2;
}

.prose p {
  @apply mb-4;
}

.prose ul {
  @apply list-disc list-inside mb-4;
}

.prose ol {
  @apply list-decimal list-inside mb-4;
}

.prose li {
  @apply mb-2;
}

.prose code {
  @apply bg-gray-100 text-gray-800 px-1 py-0.5 rounded text-sm;
}

.prose pre {
  @apply bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto mb-4;
}

.prose pre code {
  @apply bg-transparent text-inherit p-0;
}

.prose blockquote {
  @apply border-l-4 border-gray-300 pl-4 italic mb-4;
}

.prose table {
  @apply w-full border-collapse mb-4;
}

.prose th {
  @apply border border-gray-300 px-4 py-2 bg-gray-50 text-left font-medium;
}

.prose td {
  @apply border border-gray-300 px-4 py-2;
}
</style>
