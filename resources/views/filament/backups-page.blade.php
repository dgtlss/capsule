@php
    $health = $this->health;
    $backups = $this->backups;
@endphp

<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Capsule Backups</h2>
    </div>

    {{-- Health Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-sm text-gray-500 dark:text-gray-400">Last Success</div>
            <div class="text-lg font-semibold {{ $health['age'] === null ? 'text-red-600' : ($health['age'] > 2 ? 'text-yellow-600' : 'text-green-600') }}">
                {{ $health['age'] === null ? 'Never' : $health['age'] . ' day(s) ago' }}
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-sm text-gray-500 dark:text-gray-400">Recent Failures (7d)</div>
            <div class="text-lg font-semibold {{ $health['failures'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                {{ $health['failures'] }}
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-sm text-gray-500 dark:text-gray-400">Storage Usage</div>
            <div class="text-lg font-semibold text-gray-900 dark:text-white">{{ $health['usage'] }}</div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3">
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="Search by path or tag..."
            class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
        />
        <select
            wire:model.live="statusFilter"
            class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
        >
            <option value="">All Statuses</option>
            <option value="success">Success</option>
            <option value="failed">Failed</option>
            <option value="running">Running</option>
        </select>
    </div>

    {{-- Table --}}
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 uppercase text-xs">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Tag</th>
                    <th class="px-4 py-3">Size</th>
                    <th class="px-4 py-3">Duration</th>
                    <th class="px-4 py-3">Path</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($backups as $log)
                    <tr class="bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800">
                        <td class="px-4 py-3 font-mono text-gray-900 dark:text-gray-100">{{ $log->id }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $log->created_at->format('M d, Y H:i') }}</td>
                        <td class="px-4 py-3">
                            @if($log->status === 'success')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Success</span>
                            @elseif($log->status === 'failed')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">Failed</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">Running</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $log->tag ?? '-' }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $log->formattedFileSize }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                            @if($log->duration)
                                {{ $log->duration }}s
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400 truncate max-w-[240px]" title="{{ $log->file_path }}">
                            {{ $log->file_path ?? '-' }}
                        </td>
                        <td class="px-4 py-3 space-x-1">
                            @if($log->status === 'success')
                                <code class="text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">
                                    capsule:inspect {{ $log->id }}
                                </code>
                            @endif
                            @if($log->status === 'failed' && $log->error_message)
                                <span class="text-xs text-red-500" title="{{ $log->error_message }}">
                                    {{ \Illuminate\Support\Str::limit($log->error_message, 40) }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-gray-400">No backups found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    @if($backups->hasPages())
        <div class="mt-4">
            {{ $backups->links() }}
        </div>
    @endif
</div>
