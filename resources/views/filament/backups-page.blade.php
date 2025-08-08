@php($logs = \Dgtlss\Capsule\Models\BackupLog::orderByDesc('created_at')->limit(50)->get())
<div class="p-6 space-y-4">
  <div class="text-xl font-semibold">Capsule Backups</div>
  <div class="text-sm text-gray-600">Last success age: @php($age=\Dgtlss\Capsule\Health\Checks\BackupHealthCheck::lastSuccessAgeDays()) {{ $age===null?'none':$age.' day(s)' }} • Recent failures: {{ \Dgtlss\Capsule\Health\Checks\BackupHealthCheck::recentFailuresCount() }} • Usage: @php($usage=\Dgtlss\Capsule\Health\Checks\BackupHealthCheck::storageUsageBytes()) {{ number_format($usage/1024/1024,2) }} MB</div>
  <table class="w-full text-sm">
    <thead>
      <tr class="text-left border-b">
        <th class="py-2">Date</th>
        <th class="py-2">Status</th>
        <th class="py-2">Size</th>
        <th class="py-2">Path</th>
        <th class="py-2">Actions</th>
      </tr>
    </thead>
    <tbody>
      @foreach($logs as $log)
        <tr class="border-b">
          <td class="py-2">{{ $log->created_at }}</td>
          <td class="py-2">{{ ucfirst($log->status) }}</td>
          <td class="py-2">{{ $log->formattedFileSize }}</td>
          <td class="py-2 truncate max-w-[320px]" title="{{ $log->file_path }}">{{ $log->file_path }}</td>
          <td class="py-2">
            @if($log->status === 'success')
              <button onclick="alert('Inspect via CLI: php artisan capsule:inspect {{ $log->id }}')" class="px-2 py-1 bg-gray-700 text-white rounded">Inspect</button>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
