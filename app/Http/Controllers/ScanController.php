<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class ScanController extends Controller
{
    public function index()
    {
        return view('upload');
    }

    public function scan(Request $request)
    {
        try {
            $request->validate([
                'textures' => 'required|array',
                'textures.*' => 'required|file|mimes:png',
                'settings_py' => 'required|file',
            ]);

            $scanId = Str::uuid()->toString();
            $scanPath = "scans/{$scanId}";

            // Use public disk instead
            $disk = Storage::disk('public');

            // 1. Parse settings.py
            $settingsPy = $request->file('settings_py');
            $disk->put("{$scanPath}/settings.py", file_get_contents($settingsPy->getRealPath()));

            $items = $this->parseSettingsPy($settingsPy->getRealPath());

            // Create case-insensitive lookup map
            $itemKeysLowerMap = [];
            foreach ($items as $key => $label) {
                $itemKeysLowerMap[strtolower($key)] = $key; // map lowercase -> original
            }

            // 2. Index textures
            $textures = [];
            $duplicates = [];
            $wrongSize = [];

            foreach ($request->file('textures') as $file) {
                $originalName = $file->getClientOriginalName();

                // Skip non-PNG files
                if (pathinfo($originalName, PATHINFO_EXTENSION) !== 'png') {
                    continue;
                }

                // Extract key from filename (without extension)
                $fileKey = pathinfo($originalName, PATHINFO_FILENAME);

                // Try to match with settings.py (case-insensitive)
                $lowerFileKey = strtolower($fileKey);
                $matchedKey = $itemKeysLowerMap[$lowerFileKey] ?? $fileKey; // Keep original case from filename

                // Store file using public disk with ORIGINAL filename (preserve case)
                $storedPath = $file->storeAs("{$scanPath}/textures", $originalName, 'public');
                $fullPath = $disk->path($storedPath);

                // Check for duplicates
                if (isset($textures[$matchedKey])) {
                    $duplicates[] = $matchedKey;
                }

                $textures[$matchedKey] = $storedPath;

                // Check dimensions
                $size = @getimagesize($fullPath);
                if ($size && ($size[0] !== 16 || $size[1] !== 16)) {
                    $wrongSize[$matchedKey] = [
                        'width' => $size[0],
                        'height' => $size[1],
                    ];
                }
            }

            $textureKeys = array_keys($textures);
            $itemKeys = array_keys($items);

            // 3. Compare lists
            $texturesMissingNames = array_diff($textureKeys, $itemKeys);
            $namesMissingTextures = array_diff($itemKeys, $textureKeys);

            // 4. Build gallery model
            $allKeys = array_unique(array_merge($itemKeys, $textureKeys));
            $gallery = [];

            foreach ($allKeys as $key) {
                $hasTexture = isset($textures[$key]);
                $hasName = isset($items[$key]);
                $isWrongSize = isset($wrongSize[$key]);
                $isDuplicate = in_array($key, $duplicates);

                $hasProblem = !$hasTexture || !$hasName || $isWrongSize || $isDuplicate;

                $gallery[] = [
                    'key' => $key,
                    'label' => $items[$key] ?? $this->autoLabel($key),
                    'texture_url' => $hasTexture ? asset('storage/' . $textures[$key]) : null,
                    'missing_texture' => !$hasTexture,
                    'missing_name' => !$hasName,
                    'wrong_size' => $isWrongSize,
                    'wrong_size_info' => $wrongSize[$key] ?? null,
                    'duplicate' => $isDuplicate,
                    'has_problem' => $hasProblem,
                ];
            }

            // Sort: problems first, then alphabetical
            usort($gallery, function ($a, $b) {
                if ($a['has_problem'] !== $b['has_problem']) {
                    return $b['has_problem'] <=> $a['has_problem'];
                }
                return strcasecmp($a['key'], $b['key']);
            });

            // 5. Save report
            $report = [
                'scan_id' => $scanId,
                'summary' => [
                    'total_items' => count($itemKeys),
                    'total_textures' => count($textureKeys),
                    'missing_textures' => count($namesMissingTextures),
                    'missing_names' => count($texturesMissingNames),
                    'wrong_size' => count($wrongSize),
                    'duplicates' => count(array_unique($duplicates)),
                ],
                'gallery' => $gallery,
            ];

            $disk->put("{$scanPath}/report.json", json_encode($report, JSON_PRETTY_PRINT));

            // Handle AJAX request
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'redirect' => route('results', ['id' => $scanId])
                ]);
            }

            return redirect()->route('results', ['id' => $scanId]);

        } catch (\Exception $e) {
            \Log::error('Scan error: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'trace' => config('app.debug') ? $e->getTraceAsString() : null
                ], 500);
            }

            return back()->withErrors(['error' => 'Scan failed: ' . $e->getMessage()]);
        }
    }

    public function results($id)
    {
        $disk = Storage::disk('public');
        $reportPath = "scans/{$id}/report.json";

        if (!$disk->exists($reportPath)) {
            abort(404, 'Scan not found');
        }

        $report = json_decode($disk->get($reportPath), true);

        // Get available pools from settings.py
        $settingsPath = $disk->path("scans/{$id}/settings.py");
        $availablePools = file_exists($settingsPath)
            ? $this->getAvailablePools($settingsPath)
            : ['ALL_ITEM_POOL', 'OWN_RISK_ITEM_POOL'];

        return view('results', [
            'report' => $report,
            'availablePools' => $availablePools
        ]);
    }

    public function viewSettings($id)
    {
        $disk = Storage::disk('public');
        $settingsPath = "scans/{$id}/settings.py";

        if (!$disk->exists($settingsPath)) {
            return response()->json(['success' => false, 'error' => 'Settings file not found'], 404);
        }

        $content = $disk->get($settingsPath);

        return response()->json(['success' => true, 'content' => $content]);
    }

    public function addTexture(Request $request)
    {
        try {
            $request->validate([
                'scan_id' => 'required|string',
                'item_key' => 'required|string',
                'item_label' => 'required|string',
                'texture' => 'required|file|mimes:png',
                'item_pool' => 'required|in:ALL_ITEM_POOL,OWN_RISK_ITEM_POOL',
            ]);

            $scanId = $request->scan_id;
            $scanPath = "scans/{$scanId}";
            $disk = Storage::disk('public');

            // Get current report
            $reportPath = "{$scanPath}/report.json";
            if (!$disk->exists($reportPath)) {
                return response()->json(['success' => false, 'error' => 'Scan not found'], 404);
            }

            $report = json_decode($disk->get($reportPath), true);

            // Check dimensions
            $tempPath = $request->file('texture')->getRealPath();
            $size = getimagesize($tempPath);

            if (!$size || $size[0] !== 16 || $size[1] !== 16) {
                return response()->json([
                    'success' => false,
                    'error' => "Image must be exactly 16x16 pixels. Uploaded: {$size[0]}x{$size[1]}"
                ], 400);
            }

            // Store texture preserving original case
            $itemKey = $request->item_key; // Keep original case, don't force uppercase
            $textureFilename = $itemKey . '.png';
            $storedPath = $request->file('texture')->storeAs("{$scanPath}/textures", $textureFilename, 'public');

            // Update settings.py
            $this->addItemToSettings($disk, $scanPath, $itemKey, $request->item_pool);

            // Update report
            $newItem = [
                'key' => $itemKey,
                'label' => $request->item_label,
                'texture_url' => asset('storage/' . $storedPath),
                'missing_texture' => false,
                'missing_name' => false,
                'wrong_size' => false,
                'wrong_size_info' => null,
                'duplicate' => false,
                'has_problem' => false,
            ];

            // Remove old entry if exists and add new one
            $report['gallery'] = array_filter($report['gallery'], function($item) use ($itemKey) {
                return $item['key'] !== $itemKey;
            });
            $report['gallery'][] = $newItem;

            // Update summary
            $report['summary']['total_items']++;
            $report['summary']['total_textures']++;

            // Save updated report
            $disk->put($reportPath, json_encode($report, JSON_PRETTY_PRINT));

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            \Log::error('Add texture error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function editTexture(Request $request)
    {
        try {
            $request->validate([
                'scan_id' => 'required|string',
                'old_key' => 'required|string',
                'item_key' => 'required|string',
                'item_label' => 'required|string',
                'texture' => 'nullable|file|mimes:png',
                'item_pool' => 'required|in:ALL_ITEM_POOL,OWN_RISK_ITEM_POOL',
            ]);

            $scanId = $request->scan_id;
            $scanPath = "scans/{$scanId}";
            $disk = Storage::disk('public');

            // Get current report
            $reportPath = "{$scanPath}/report.json";
            if (!$disk->exists($reportPath)) {
                return response()->json(['success' => false, 'error' => 'Scan not found'], 404);
            }

            $report = json_decode($disk->get($reportPath), true);

            $oldKey = $request->old_key;
            $newKey = $request->item_key;

            // If texture is uploaded, validate and save it
            $textureUrl = null;
            if ($request->hasFile('texture')) {
                $tempPath = $request->file('texture')->getRealPath();
                $size = getimagesize($tempPath);

                if (!$size || $size[0] !== 16 || $size[1] !== 16) {
                    return response()->json([
                        'success' => false,
                        'error' => "Image must be exactly 16x16 pixels. Uploaded: {$size[0]}x{$size[1]}"
                    ], 400);
                }

                // Delete old texture if key changed
                if ($oldKey !== $newKey) {
                    $oldTexturePath = "{$scanPath}/textures/{$oldKey}.png";
                    if ($disk->exists($oldTexturePath)) {
                        $disk->delete($oldTexturePath);
                    }
                }

                // Store new texture
                $textureFilename = $newKey . '.png';
                $storedPath = $request->file('texture')->storeAs("{$scanPath}/textures", $textureFilename, 'public');
                $textureUrl = asset('storage/' . $storedPath);
            } else if ($oldKey !== $newKey) {
                // Key changed but no new texture - rename the file
                $oldTexturePath = "{$scanPath}/textures/{$oldKey}.png";
                $newTexturePath = "{$scanPath}/textures/{$newKey}.png";

                if ($disk->exists($oldTexturePath)) {
                    $disk->move($oldTexturePath, $newTexturePath);
                    $textureUrl = asset('storage/' . $newTexturePath);
                }
            }

            // Update settings.py if key changed or pool changed
            if ($oldKey !== $newKey) {
                $this->updateItemKeyInSettings($disk, $scanPath, $oldKey, $newKey, $request->item_pool);
            } else {
                // Just update the pool if key didn't change
                $this->updateItemPoolInSettings($disk, $scanPath, $newKey, $request->item_pool);
            }

            // Update report
            foreach ($report['gallery'] as &$item) {
                if ($item['key'] === $oldKey) {
                    $item['key'] = $newKey;
                    $item['label'] = $request->item_label;
                    if ($textureUrl) {
                        $item['texture_url'] = $textureUrl;
                    }
                    break;
                }
            }

            // Save updated report
            $disk->put($reportPath, json_encode($report, JSON_PRETTY_PRINT));

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            \Log::error('Edit texture error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function bulkAddMissing(Request $request)
    {
        try {
            $request->validate([
                'scan_id' => 'required|string',
                'item_pool' => 'required|in:ALL_ITEM_POOL,OWN_RISK_ITEM_POOL',
            ]);

            $scanId = $request->scan_id;
            $scanPath = "scans/{$scanId}";
            $disk = Storage::disk('public');

            // Get current report
            $reportPath = "{$scanPath}/report.json";
            if (!$disk->exists($reportPath)) {
                return response()->json(['success' => false, 'error' => 'Scan not found'], 404);
            }

            $report = json_decode($disk->get($reportPath), true);

            // Find all items with missing names (not in settings.py)
            $addedCount = 0;
            foreach ($report['gallery'] as &$item) {
                if ($item['missing_name'] && !$item['missing_texture']) {
                    // Add to settings.py
                    $this->addItemToSettings($disk, $scanPath, $item['key'], $request->item_pool);

                    // Update item in report
                    $item['missing_name'] = false;
                    $item['has_problem'] = $item['missing_texture'] || $item['wrong_size'] || $item['duplicate'];

                    $addedCount++;
                }
            }

            // Update summary
            $report['summary']['total_items'] += $addedCount;
            $report['summary']['missing_names'] = max(0, $report['summary']['missing_names'] - $addedCount);

            // Save updated report
            $disk->put($reportPath, json_encode($report, JSON_PRETTY_PRINT));

            return response()->json([
                'success' => true,
                'added_count' => $addedCount,
                'updated_items' => array_values(array_filter($report['gallery'], function($item) {
                    return !$item['missing_name'];
                }))
            ]);

        } catch (\Exception $e) {
            \Log::error('Bulk add error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function deleteTextures(Request $request)
    {
        try {
            $request->validate([
                'scan_id' => 'required|string',
                'item_keys' => 'required|array',
                'item_keys.*' => 'required|string',
            ]);

            $scanId = $request->scan_id;
            $scanPath = "scans/{$scanId}";
            $disk = Storage::disk('public');

            // Get current report
            $reportPath = "{$scanPath}/report.json";
            if (!$disk->exists($reportPath)) {
                return response()->json(['success' => false, 'error' => 'Scan not found'], 404);
            }

            $report = json_decode($disk->get($reportPath), true);
            $itemKeys = $request->item_keys;
            $deletedCount = 0;

            foreach ($itemKeys as $itemKey) {
                // Delete texture file
                $texturePath = "{$scanPath}/textures/{$itemKey}.png";
                if ($disk->exists($texturePath)) {
                    $disk->delete($texturePath);
                    $deletedCount++;
                }

                // Remove from settings.py
                $this->removeItemFromSettings($disk, $scanPath, $itemKey);

                // Update report
                $report['gallery'] = array_values(array_filter($report['gallery'], function($item) use ($itemKey) {
                    return $item['key'] !== $itemKey;
                }));
            }

            // Update summary
            $report['summary']['total_items'] = max(0, $report['summary']['total_items'] - $deletedCount);
            $report['summary']['total_textures'] = max(0, $report['summary']['total_textures'] - $deletedCount);

            // Save updated report
            $disk->put($reportPath, json_encode($report, JSON_PRETTY_PRINT));

            return response()->json(['success' => true, 'deleted_count' => $deletedCount]);

        } catch (\Exception $e) {
            \Log::error('Delete textures error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function export($id)
    {
        try {
            $scanPath = "scans/{$id}";
            $disk = Storage::disk('public');

            if (!$disk->exists("{$scanPath}/report.json")) {
                abort(404, 'Scan not found');
            }

            // Create a temporary zip file
            $zipFileName = "texture_export_{$id}.zip";
            $zipPath = storage_path("app/public/{$zipFileName}");

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \Exception('Could not create zip file');
            }

            // Add settings.py
            $settingsPath = $disk->path("{$scanPath}/settings.py");
            if (file_exists($settingsPath)) {
                $zip->addFile($settingsPath, 'settings.py');
            }

            // Add all textures
            $texturesPath = $disk->path("{$scanPath}/textures");
            if (is_dir($texturesPath)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($texturesPath),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = 'textures/' . basename($filePath);
                        $zip->addFile($filePath, $relativePath);
                    }
                }
            }

            $zip->close();

            // Download and delete
            return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            \Log::error('Export error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Export failed: ' . $e->getMessage()]);
        }
    }

    private function addItemToSettings($disk, $scanPath, $itemKey, $pool)
    {
        $settingsPath = "{$scanPath}/settings.py";
        $content = $disk->get($settingsPath);

        // Find the appropriate pool and add the item
        $poolPattern = "/{$pool}\s*=\s*\[(.*?)\]/s";

        if (preg_match($poolPattern, $content, $matches)) {
            $listContent = $matches[1];

            // Check if item already exists
            if (strpos($listContent, '"' . $itemKey . '"') === false) {
                // Add the new item before the closing bracket
                $newItem = '    "' . $itemKey . '",';
                $replacement = $pool . ' = [' . $listContent . "\n" . $newItem . "\n]";
                $content = preg_replace($poolPattern, $replacement, $content);

                $disk->put($settingsPath, $content);
            }
        }
    }

    private function updateItemKeyInSettings($disk, $scanPath, $oldKey, $newKey, $newPool)
    {
        $settingsPath = "{$scanPath}/settings.py";
        $content = $disk->get($settingsPath);

        // Remove old key from both pools
        $content = preg_replace('/\s*"' . preg_quote($oldKey, '/') . '",?\n?/', '', $content);

        // Add new key to the specified pool
        $poolPattern = "/{$newPool}\s*=\s*\[(.*?)\]/s";
        if (preg_match($poolPattern, $content, $matches)) {
            $listContent = $matches[1];
            $newItem = '    "' . $newKey . '",';
            $replacement = $newPool . ' = [' . $listContent . "\n" . $newItem . "\n]";
            $content = preg_replace($poolPattern, $replacement, $content);
        }

        $disk->put($settingsPath, $content);
    }

    private function updateItemPoolInSettings($disk, $scanPath, $itemKey, $newPool)
    {
        $settingsPath = "{$scanPath}/settings.py";
        $content = $disk->get($settingsPath);

        // Check if item exists in the other pool
        $pools = ['ALL_ITEM_POOL', 'OWN_RISK_ITEM_POOL'];
        $currentPool = null;

        foreach ($pools as $pool) {
            if (preg_match("/{$pool}\s*=\s*\[(.*?)\]/s", $content, $matches)) {
                if (strpos($matches[1], '"' . $itemKey . '"') !== false) {
                    $currentPool = $pool;
                    break;
                }
            }
        }

        // If already in the correct pool, do nothing
        if ($currentPool === $newPool) {
            return;
        }

        // Remove from current pool and add to new pool
        if ($currentPool) {
            $content = preg_replace('/\s*"' . preg_quote($itemKey, '/') . '",?\n?/', '', $content);
        }

        // Add to new pool
        $poolPattern = "/{$newPool}\s*=\s*\[(.*?)\]/s";
        if (preg_match($poolPattern, $content, $matches)) {
            $listContent = $matches[1];
            if (strpos($listContent, '"' . $itemKey . '"') === false) {
                $newItem = '    "' . $itemKey . '",';
                $replacement = $newPool . ' = [' . $listContent . "\n" . $newItem . "\n]";
                $content = preg_replace($poolPattern, $replacement, $content);
            }
        }

        $disk->put($settingsPath, $content);
    }

    private function removeItemFromSettings($disk, $scanPath, $itemKey)
    {
        $settingsPath = "{$scanPath}/settings.py";
        $content = $disk->get($settingsPath);

        // Remove the item from both pools
        $content = preg_replace('/\s*"' . preg_quote($itemKey, '/') . '",?\n?/', '', $content);

        $disk->put($settingsPath, $content);
    }

    private function parseSettingsPy($filePath)
    {
        $content = file_get_contents($filePath);
        $items = [];

        // Extract ALL_ITEM_POOL
        if (preg_match('/ALL_ITEM_POOL\s*=\s*\[(.*?)\]/s', $content, $matches)) {
            $listContent = $matches[1];
            preg_match_all('/"([^"]+)"/m', $listContent, $stringMatches);

            foreach ($stringMatches[1] as $itemName) {
                $itemName = trim($itemName);
                $items[$itemName] = $this->autoLabel($itemName);
            }
        }

        // Extract OWN_RISK_ITEM_POOL
        if (preg_match('/OWN_RISK_ITEM_POOL\s*=\s*\[(.*?)\]/s', $content, $matches)) {
            $listContent = $matches[1];
            preg_match_all('/"([^"]+)"/m', $listContent, $stringMatches);

            foreach ($stringMatches[1] as $itemName) {
                $itemName = trim($itemName);
                $items[$itemName] = $this->autoLabel($itemName);
            }
        }

        // Extract any other custom pools (e.g., VERSION_SPECIFIC_POOL, SHELF_POOL, etc.)
        // Pattern: ANYTHING_POOL = [...]
        preg_match_all('/([A-Z_]+_POOL)\s*=\s*\[(.*?)\]/s', $content, $allPools, PREG_SET_ORDER);

        foreach ($allPools as $poolMatch) {
            $poolName = $poolMatch[1];
            // Skip if already processed
            if ($poolName === 'ALL_ITEM_POOL' || $poolName === 'OWN_RISK_ITEM_POOL') {
                continue;
            }

            $listContent = $poolMatch[2];
            preg_match_all('/"([^"]+)"/m', $listContent, $stringMatches);

            foreach ($stringMatches[1] as $itemName) {
                $itemName = trim($itemName);
                $items[$itemName] = $this->autoLabel($itemName);
            }
        }

        return $items;
    }

    private function getAvailablePools($filePath)
    {
        $content = file_get_contents($filePath);
        $pools = [];

        // Find all pool names
        preg_match_all('/([A-Z_]+_POOL)\s*=\s*\[/s', $content, $matches);

        if (!empty($matches[1])) {
            $pools = array_unique($matches[1]);
        }

        // Ensure default pools are always present
        if (!in_array('ALL_ITEM_POOL', $pools)) {
            $pools[] = 'ALL_ITEM_POOL';
        }
        if (!in_array('OWN_RISK_ITEM_POOL', $pools)) {
            $pools[] = 'OWN_RISK_ITEM_POOL';
        }

        return $pools;
    }

    private function autoLabel($key)
    {
        // Convert DIAMOND_SWORD -> Diamond Sword
        return ucwords(str_replace('_', ' ', strtolower($key)));
    }
}
