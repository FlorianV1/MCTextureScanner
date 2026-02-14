<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan Results - {{ $report['scan_id'] }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
</head>
<body class="bg-gray-900 min-h-screen p-6">
<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-6 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <h1 class="text-3xl font-bold text-white">Texture Scanner</h1>
        </div>
        <div class="flex gap-3">
            <button onclick="exportFiles()" class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-6 rounded-lg transition">
                📦 Export All
            </button>
            <a href="{{ route('upload') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded-lg transition">
                ➕ New Scan
            </a>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-7 gap-4 mb-8">
        <div class="bg-gray-800 rounded-lg shadow-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Total Items</div>
            <div class="text-2xl font-bold text-white">{{ $report['summary']['total_items'] }}</div>
        </div>
        <div class="bg-gray-800 rounded-lg shadow-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Total Textures</div>
            <div class="text-2xl font-bold text-white">{{ $report['summary']['total_textures'] }}</div>
        </div>
        <div class="bg-gray-800 rounded-lg shadow-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Complete</div>
            <div class="text-2xl font-bold text-green-400" id="completeCount">0</div>
        </div>
        <div class="bg-gray-800 rounded-lg shadow-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Missing Textures</div>
            <div class="text-2xl font-bold {{ $report['summary']['missing_textures'] > 0 ? 'text-red-400' : 'text-green-400' }}">
                {{ $report['summary']['missing_textures'] }}
            </div>
        </div>
        <div class="bg-gray-800 rounded-lg shadow-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Missing Names</div>
            <div class="text-2xl font-bold {{ $report['summary']['missing_names'] > 0 ? 'text-orange-400' : 'text-green-400' }}">
                {{ $report['summary']['missing_names'] }}
            </div>
        </div>
        <div class="bg-gray-800 rounded-lg shadow-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Wrong Size</div>
            <div class="text-2xl font-bold {{ $report['summary']['wrong_size'] > 0 ? 'text-yellow-400' : 'text-green-400' }}">
                {{ $report['summary']['wrong_size'] }}
            </div>
        </div>
        <div class="bg-gray-800 rounded-lg shadow-lg p-4 border border-gray-700">
            <div class="text-sm text-gray-400">Duplicates</div>
            <div class="text-2xl font-bold {{ $report['summary']['duplicates'] > 0 ? 'text-purple-400' : 'text-green-400' }}">
                {{ $report['summary']['duplicates'] }}
            </div>
        </div>
    </div>

    <!-- Add Texture Button -->
    <div class="mb-6">
        <button onclick="showAddTextureModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition">
            ➕ Add New Texture
        </button>
    </div>

    <!-- Filters -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-4 mb-6 border border-gray-700">
        <div class="flex flex-wrap gap-4 items-center">
            <input
                type="text"
                id="searchBox"
                placeholder="Search by key or label..."
                class="flex-1 min-w-[200px] px-4 py-2 bg-gray-900 border border-gray-700 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
            <label class="flex items-center gap-2 cursor-pointer text-gray-300">
                <input type="checkbox" id="filterComplete" class="filter-checkbox">
                <span class="text-sm">✅ Complete</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer text-gray-300">
                <input type="checkbox" id="filterMissingTexture" class="filter-checkbox">
                <span class="text-sm">🖼️ Missing Texture</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer text-gray-300">
                <input type="checkbox" id="filterMissingName" class="filter-checkbox">
                <span class="text-sm">🏷️ Missing Name</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer text-gray-300">
                <input type="checkbox" id="filterWrongSize" class="filter-checkbox">
                <span class="text-sm">📏 Wrong Size</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer text-gray-300">
                <input type="checkbox" id="filterDuplicate" class="filter-checkbox">
                <span class="text-sm">👥 Duplicates</span>
            </label>
        </div>
    </div>

    <!-- Gallery Grid -->
    <div id="gallery" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-6">
        @foreach ($report['gallery'] as $item)
            <div class="gallery-item bg-gray-800 rounded-lg shadow-lg p-5 flex flex-col items-center border border-gray-700 hover:border-blue-500 transition group"
                 data-key="{{ strtolower($item['key']) }}"
                 data-label="{{ strtolower($item['label'] ?? '') }}"
                 data-missing-texture="{{ $item['missing_texture'] ? 'true' : 'false' }}"
                 data-missing-name="{{ $item['missing_name'] ? 'true' : 'false' }}"
                 data-wrong-size="{{ $item['wrong_size'] ? 'true' : 'false' }}"
                 data-duplicate="{{ $item['duplicate'] ? 'true' : 'false' }}"
                 data-actual-key="{{ $item['key'] }}"
                 data-texture-url="{{ $item['texture_url'] ?? '' }}">

                <!-- Image - 128x128 (nice middle size) -->
                <div class="w-40 h-40 mb-3 flex items-center justify-center bg-gray-900 rounded-lg border-2 border-gray-700 relative group/img">
                    @if ($item['texture_url'])
                        <img src="{{ $item['texture_url'] }}" alt="{{ $item['key'] }}" class="pixelated" style="width: 128px; height: 128px; image-rendering: pixelated;">
                        <!-- Download button appears on hover -->
                        <button onclick="downloadImage('{{ $item['texture_url'] }}', '{{ $item['key'] }}.png')"
                                class="absolute top-2 right-2 bg-blue-600 hover:bg-blue-700 text-white p-1.5 rounded-lg opacity-0 group-hover/img:opacity-100 transition shadow-lg"
                                title="Download image">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                            </svg>
                        </button>
                    @else
                        <svg class="w-20 h-20 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    @endif
                </div>

                <!-- Label -->
                <div class="text-sm font-semibold text-center text-white mb-2 break-words w-full">
                    @if ($item['missing_name'])
                        <span class="text-orange-400 italic">⚠️ No Name Set</span>
                    @else
                        {{ $item['label'] }}
                    @endif
                </div>

                <!-- Key -->
                <div class="text-xs text-gray-400 text-center mb-3 font-mono break-all w-full">
                    {{ $item['key'] }}
                </div>

                <!-- Badges -->
                <div class="flex flex-wrap gap-1.5 justify-center mb-3">
                    @if (!$item['missing_texture'] && !$item['missing_name'] && !$item['wrong_size'] && !$item['duplicate'])
                        <span class="px-2.5 py-1 bg-green-900 text-green-300 text-xs rounded border border-green-700 font-medium">✅ Complete</span>
                    @endif
                    @if ($item['missing_texture'])
                        <span class="px-2.5 py-1 bg-red-900 text-red-300 text-xs rounded border border-red-700 font-medium">No Texture</span>
                    @endif
                    @if ($item['missing_name'])
                        <span class="px-2.5 py-1 bg-orange-900 text-orange-300 text-xs rounded border border-orange-700 font-medium">No Name</span>
                    @endif
                    @if ($item['wrong_size'])
                        <span class="px-2.5 py-1 bg-yellow-900 text-yellow-300 text-xs rounded border border-yellow-700 font-medium">
                                {{ $item['wrong_size_info']['width'] }}×{{ $item['wrong_size_info']['height'] }}
                            </span>
                    @endif
                    @if ($item['duplicate'])
                        <span class="px-2.5 py-1 bg-purple-900 text-purple-300 text-xs rounded border border-purple-700 font-medium">Duplicate</span>
                    @endif
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition">
                    <button onclick='editItem(@json($item))' class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs rounded-lg transition font-medium">
                        ✏️ Edit
                    </button>
                    <button onclick="deleteItem('{{ $item['key'] }}')" class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs rounded-lg transition font-medium">
                        🗑️ Delete
                    </button>
                </div>
            </div>
        @endforeach
    </div>
</div>

<!-- Add Texture Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 p-4">
    <div class="bg-gray-800 rounded-lg shadow-2xl p-8 max-w-md w-full border border-gray-700">
        <h2 class="text-2xl font-bold text-white mb-6">Add New Texture</h2>
        <form id="addForm" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="scan_id" value="{{ $report['scan_id'] }}">

            <div class="mb-4">
                <label class="block text-gray-300 font-semibold mb-2">Item Key (UPPERCASE_WITH_UNDERSCORES)</label>
                <input type="text" name="item_key" required
                       placeholder="DIAMOND_SWORD"
                       class="w-full px-4 py-2 bg-gray-900 border border-gray-700 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                       oninput="this.value = this.value.toUpperCase()">
            </div>

            <div class="mb-4">
                <label class="block text-gray-300 font-semibold mb-2">Display Label</label>
                <input type="text" name="item_label" required
                       placeholder="Diamond Sword"
                       class="w-full px-4 py-2 bg-gray-900 border border-gray-700 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="mb-4">
                <label class="block text-gray-300 font-semibold mb-2">Texture File (PNG, 16x16)</label>
                <input type="file" name="texture" accept=".png" required
                       class="w-full px-4 py-2 bg-gray-900 border border-gray-700 text-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="mb-6">
                <label class="block text-gray-300 font-semibold mb-2">Item Pool</label>
                <select name="item_pool" required
                        class="w-full px-4 py-2 bg-gray-900 border border-gray-700 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="ALL_ITEM_POOL">Normal Items (ALL_ITEM_POOL)</option>
                    <option value="OWN_RISK_ITEM_POOL">Own Risk Items (OWN_RISK_ITEM_POOL)</option>
                </select>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition">
                    Add Texture
                </button>
                <button type="button" onclick="closeAddModal()" class="flex-1 bg-gray-700 hover:bg-gray-600 text-white font-bold py-3 px-4 rounded-lg transition">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 p-4">
    <div class="bg-gray-800 rounded-lg shadow-2xl p-8 max-w-md w-full border border-gray-700">
        <h2 class="text-2xl font-bold text-white mb-6">Edit Item</h2>
        <form id="editForm" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="scan_id" value="{{ $report['scan_id'] }}">
            <input type="hidden" name="old_key" id="editOldKey">

            <div class="mb-4">
                <label class="block text-gray-300 font-semibold mb-2">Item Key</label>
                <input type="text" name="item_key" id="editKey" required
                       class="w-full px-4 py-2 bg-gray-900 border border-gray-700 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                       oninput="this.value = this.value.toUpperCase()">
            </div>

            <div class="mb-4">
                <label class="block text-gray-300 font-semibold mb-2">Display Label</label>
                <input type="text" name="item_label" id="editLabel" required
                       class="w-full px-4 py-2 bg-gray-900 border border-gray-700 text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="mb-4">
                <label class="block text-gray-300 font-semibold mb-2">Replace Texture (optional)</label>
                <input type="file" name="texture" accept=".png"
                       class="w-full px-4 py-2 bg-gray-900 border border-gray-700 text-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p class="text-xs text-gray-500 mt-1">Leave empty to keep current texture</p>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition">
                    Save Changes
                </button>
                <button type="button" onclick="closeEditModal()" class="flex-1 bg-gray-700 hover:bg-gray-600 text-white font-bold py-3 px-4 rounded-lg transition">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    .pixelated {
        image-rendering: pixelated;
        image-rendering: -moz-crisp-edges;
        image-rendering: crisp-edges;
    }
</style>

<script>
    const scanId = '{{ $report['scan_id'] }}';
    const searchBox = document.getElementById('searchBox');
    const checkboxes = document.querySelectorAll('.filter-checkbox');
    const galleryItems = document.querySelectorAll('.gallery-item');

    function downloadImage(url, filename) {
        // Convert filename to lowercase
        const lowercaseFilename = filename.toLowerCase();

        fetch(url)
            .then(response => response.blob())
            .then(blob => {
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = lowercaseFilename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(link.href);
            })
            .catch(err => console.error('Download failed:', err));
    }

    // Calculate complete count
    function updateCompleteCount() {
        let complete = 0;
        galleryItems.forEach(item => {
            if (item.dataset.missingTexture === 'false' &&
                item.dataset.missingName === 'false' &&
                item.dataset.wrongSize === 'false' &&
                item.dataset.duplicate === 'false') {
                complete++;
            }
        });
        document.getElementById('completeCount').textContent = complete;
    }
    updateCompleteCount();

    function applyFilters() {
        const searchTerm = searchBox.value.toLowerCase();
        const filters = {
            complete: document.getElementById('filterComplete').checked,
            missingTexture: document.getElementById('filterMissingTexture').checked,
            missingName: document.getElementById('filterMissingName').checked,
            wrongSize: document.getElementById('filterWrongSize').checked,
            duplicate: document.getElementById('filterDuplicate').checked,
        };

        const anyFilterActive = Object.values(filters).some(v => v);

        galleryItems.forEach(item => {
            const key = item.dataset.key;
            const label = item.dataset.label;
            const matchesSearch = !searchTerm || key.includes(searchTerm) || label.includes(searchTerm);

            let matchesFilters = true;
            if (anyFilterActive) {
                const isComplete = item.dataset.missingTexture === 'false' &&
                    item.dataset.missingName === 'false' &&
                    item.dataset.wrongSize === 'false' &&
                    item.dataset.duplicate === 'false';

                matchesFilters = (
                    (filters.complete && isComplete) ||
                    (filters.missingTexture && item.dataset.missingTexture === 'true') ||
                    (filters.missingName && item.dataset.missingName === 'true') ||
                    (filters.wrongSize && item.dataset.wrongSize === 'true') ||
                    (filters.duplicate && item.dataset.duplicate === 'true')
                );
            }

            item.style.display = (matchesSearch && matchesFilters) ? 'flex' : 'none';
        });
    }

    searchBox.addEventListener('input', applyFilters);
    checkboxes.forEach(cb => cb.addEventListener('change', applyFilters));

    // Dark mode toggle
    function toggleDarkMode() {
        document.documentElement.classList.toggle('dark');
        const icon = document.getElementById('darkModeIcon');
        icon.textContent = document.documentElement.classList.contains('dark') ? '☀️' : '🌙';
    }

    // Add texture modal
    function showAddTextureModal() {
        document.getElementById('addModal').classList.remove('hidden');
    }

    function closeAddModal() {
        document.getElementById('addModal').classList.add('hidden');
        document.getElementById('addForm').reset();
    }

    document.getElementById('addForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);

        try {
            const response = await fetch('/scan/add-texture', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: formData
            });

            const result = await response.json();
            if (result.success) {
                location.reload();
            } else {
                alert('Error: ' + result.error);
            }
        } catch (error) {
            alert('Failed to add texture: ' + error.message);
        }
    });

    // Edit modal
    function editItem(item) {
        document.getElementById('editOldKey').value = item.key;
        document.getElementById('editKey').value = item.key;
        document.getElementById('editLabel').value = item.label || '';
        document.getElementById('editModal').classList.remove('hidden');
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.add('hidden');
        document.getElementById('editForm').reset();
    }

    document.getElementById('editForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);

        try {
            const response = await fetch('/scan/edit-texture', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: formData
            });

            const result = await response.json();
            if (result.success) {
                location.reload();
            } else {
                alert('Error: ' + result.error);
            }
        } catch (error) {
            alert('Failed to edit texture: ' + error.message);
        }
    });

    // Delete item
    async function deleteItem(key) {
        if (!confirm(`Delete ${key}?`)) return;

        try {
            const response = await fetch('/scan/delete-texture', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ scan_id: scanId, item_key: key })
            });

            const result = await response.json();
            if (result.success) {
                location.reload();
            } else {
                alert('Error: ' + result.error);
            }
        } catch (error) {
            alert('Failed to delete texture: ' + error.message);
        }
    }

    // Export all files
    function exportFiles() {
        window.location.href = `/scan/${scanId}/export`;
    }
</script>
</body>
</html>
