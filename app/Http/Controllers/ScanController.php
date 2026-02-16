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
                $matchedKey = $itemKeysLowerMap[$lowerFileKey] ?? $fileKey;

                // FIX 1: Force lowercase filename
                $lowercaseFilename = strtolower($originalName);

                // Store file with lowercase filename
                $storedPath = $file->storeAs("{$scanPath}/textures", $lowercaseFilename, 'public');
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
                'item_key' => 'required|string|regex:/^[A-Z0-9_]+$/',
                'texture' => 'required|file|mimes:png',
                'item_pool' => 'required|in:ALL_ITEM_POOL,OWN_RISK_ITEM_POOL',
            ], [
                'item_key.regex' => 'Item key must be uppercase letters, numbers, and underscores only (e.g., DIAMOND_SWORD)'
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

            // Force uppercase
            $itemKey = strtoupper($request->item_key);

            // Check dimensions
            $tempPath = $request->file('texture')->getRealPath();
            $size = getimagesize($tempPath);

            if (!$size || $size[0] !== 16 || $size[1] !== 16) {
                return response()->json([
                    'success' => false,
                    'error' => "Image must be exactly 16x16 pixels. Uploaded: {$size[0]}x{$size[1]}"
                ], 400);
            }

            // Store texture with lowercase filename
            $textureFilename = strtolower($itemKey) . '.png';
            $storedPath = $request->file('texture')->storeAs("{$scanPath}/textures", $textureFilename, 'public');

            // Update settings.py with proper formatting
            $this->addItemToSettings($disk, $scanPath, $itemKey, $request->item_pool);

            // Auto-generate label from key
            $itemLabel = $this->autoLabel($itemKey);

            // Update report
            $newItem = [
                'key' => $itemKey,
                'label' => $itemLabel,
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
                'item_key' => 'required|string|regex:/^[A-Z0-9_]+$/',
                'texture' => 'nullable|file|mimes:png',
                'item_pool' => 'required|in:ALL_ITEM_POOL,OWN_RISK_ITEM_POOL',
            ], [
                'item_key.regex' => 'Item key must be uppercase letters, numbers, and underscores only (e.g., DIAMOND_SWORD)'
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

            // Force uppercase
            $oldKey = strtoupper($request->old_key);
            $newKey = strtoupper($request->item_key);

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
                    $oldTexturePath = "{$scanPath}/textures/" . strtolower($oldKey) . ".png";
                    if ($disk->exists($oldTexturePath)) {
                        $disk->delete($oldTexturePath);
                    }
                }

                // Store new texture with lowercase filename
                $textureFilename = strtolower($newKey) . '.png';
                $storedPath = $request->file('texture')->storeAs("{$scanPath}/textures", $textureFilename, 'public');
                $textureUrl = asset('storage/' . $storedPath);
            } else if ($oldKey !== $newKey) {
                // Key changed but no new texture - rename the file
                $oldTexturePath = "{$scanPath}/textures/" . strtolower($oldKey) . ".png";
                $newTexturePath = "{$scanPath}/textures/" . strtolower($newKey) . ".png";

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

            // Auto-generate label from key
            $itemLabel = $this->autoLabel($newKey);

            // Update report
            foreach ($report['gallery'] as &$item) {
                if ($item['key'] === $oldKey) {
                    $item['key'] = $newKey;
                    $item['label'] = $itemLabel;
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
                // FIX 1: Delete texture file with lowercase filename
                $texturePath = "{$scanPath}/textures/" . strtolower($itemKey) . ".png";
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

    /**
     * FIX 2: Add item to settings.py with proper formatting (each item on its own line)
     * Prevents duplicate entries by checking case-insensitively
     */
    private function addItemToSettings($disk, $scanPath, $itemKey, $pool)
    {
        $settingsPath = "{$scanPath}/settings.py";
        $content = $disk->get($settingsPath);

        // Find the appropriate pool
        $poolPattern = "/{$pool}\s*=\s*\[(.*?)\]/s";

        if (preg_match($poolPattern, $content, $matches)) {
            $listContent = $matches[1];

            // Parse existing items
            preg_match_all('/"([^"]+)"/m', $listContent, $existingItems);
            $items = $existingItems[1];

            // Check if item already exists (case-insensitive)
            $itemExists = false;
            foreach ($items as $existingItem) {
                if (strcasecmp($existingItem, $itemKey) === 0) {
                    $itemExists = true;
                    break;
                }
            }

            if (!$itemExists) {
                $items[] = $itemKey;

                // Remove duplicates (case-insensitive)
                $uniqueItems = [];
                $lowerMap = [];
                foreach ($items as $item) {
                    $lowerKey = strtolower($item);
                    if (!isset($lowerMap[$lowerKey])) {
                        $uniqueItems[] = $item;
                        $lowerMap[$lowerKey] = true;
                    }
                }

                // Build new formatted list with each item on its own line
                $formattedItems = '';
                foreach ($uniqueItems as $item) {
                    $formattedItems .= "\n    \"{$item}\",";
                }

                $replacement = "{$pool} = [{$formattedItems}\n]";
                $content = preg_replace($poolPattern, $replacement, $content);

                $disk->put($settingsPath, $content);
            }
        }
    }

    /**
     * Update item key in settings.py (rename from oldKey to newKey and move to newPool)
     * Prevents duplicate entries
     */
    private function updateItemKeyInSettings($disk, $scanPath, $oldKey, $newKey, $newPool)
    {
        $settingsPath = "{$scanPath}/settings.py";
        $content = $disk->get($settingsPath);

        // Parse all pools and their items
        preg_match_all('/([A-Z_]+_POOL)\s*=\s*\[(.*?)\]/s', $content, $allPools, PREG_SET_ORDER);

        $poolsData = [];

        foreach ($allPools as $poolMatch) {
            $poolName = $poolMatch[1];
            $listContent = $poolMatch[2];

            // Extract all items from this pool
            preg_match_all('/"([^"]+)"/m', $listContent, $itemMatches);
            $items = array_filter($itemMatches[1]);

            // Remove old key from all pools (case-insensitive)
            $items = array_filter($items, function($item) use ($oldKey) {
                return strcasecmp($item, $oldKey) !== 0;
            });

            // Add new key only to the target pool
            if ($poolName === $newPool) {
                $items[] = $newKey;
            }

            $poolsData[$poolName] = array_values(array_unique($items));
        }

        // Rebuild the entire file
        foreach ($poolsData as $poolName => $items) {
            if (empty($items)) {
                $replacement = "{$poolName} = [\n]";
            } else {
                $formattedItems = '';
                foreach ($items as $item) {
                    $formattedItems .= "\n    \"{$item}\",";
                }
                $replacement = "{$poolName} = [{$formattedItems}\n]";
            }

            $pattern = '/' . preg_quote($poolName, '/') . '\s*=\s*\[.*?\]/s';
            $content = preg_replace($pattern, $replacement, $content);
        }

        $disk->put($settingsPath, $content);
    }

    /**
     * Move item to a different pool without changing the key
     * Prevents duplicate entries
     */
    private function updateItemPoolInSettings($disk, $scanPath, $itemKey, $newPool)
    {
        $settingsPath = "{$scanPath}/settings.py";
        $content = $disk->get($settingsPath);

        // Parse all pools and their items
        preg_match_all('/([A-Z_]+_POOL)\s*=\s*\[(.*?)\]/s', $content, $allPools, PREG_SET_ORDER);

        $poolsData = [];

        foreach ($allPools as $poolMatch) {
            $poolName = $poolMatch[1];
            $listContent = $poolMatch[2];

            // Extract all items from this pool
            preg_match_all('/"([^"]+)"/m', $listContent, $itemMatches);
            $items = array_filter($itemMatches[1]);

            // Remove item from all pools (case-insensitive)
            $items = array_filter($items, function($item) use ($itemKey) {
                return strcasecmp($item, $itemKey) !== 0;
            });

            // Add item only to the target pool
            if ($poolName === $newPool) {
                $items[] = $itemKey;
            }

            $poolsData[$poolName] = array_values(array_unique($items));
        }

        // Rebuild the entire file
        foreach ($poolsData as $poolName => $items) {
            if (empty($items)) {
                $replacement = "{$poolName} = [\n]";
            } else {
                $formattedItems = '';
                foreach ($items as $item) {
                    $formattedItems .= "\n    \"{$item}\",";
                }
                $replacement = "{$poolName} = [{$formattedItems}\n]";
            }

            $pattern = '/' . preg_quote($poolName, '/') . '\s*=\s*\[.*?\]/s';
            $content = preg_replace($pattern, $replacement, $content);
        }

        $disk->put($settingsPath, $content);
    }

    /**
     * Remove item from settings.py (from all pools)
     */
    private function removeItemFromSettings($disk, $scanPath, $itemKey)
    {
        $settingsPath = "{$scanPath}/settings.py";
        $content = $disk->get($settingsPath);

        // Parse all pools and rebuild without the item
        preg_match_all('/([A-Z_]+_POOL)\s*=\s*\[(.*?)\]/s', $content, $allPools, PREG_SET_ORDER);

        foreach ($allPools as $poolMatch) {
            $poolName = $poolMatch[1];
            $listContent = $poolMatch[2];

            // Extract all items
            preg_match_all('/"([^"]+)"/m', $listContent, $itemMatches);
            $items = $itemMatches[1];

            // Remove the target item (case-insensitive)
            $items = array_filter($items, function($item) use ($itemKey) {
                return strcasecmp($item, $itemKey) !== 0;
            });

            $items = array_values(array_unique($items));

            // Build formatted list
            if (empty($items)) {
                $replacement = "{$poolName} = [\n]";
            } else {
                $formattedItems = '';
                foreach ($items as $item) {
                    $formattedItems .= "\n    \"{$item}\",";
                }
                $replacement = "{$poolName} = [{$formattedItems}\n]";
            }

            // Replace the pool
            $pattern = '/' . preg_quote($poolName, '/') . '\s*=\s*\[.*?\]/s';
            $content = preg_replace($pattern, $replacement, $content);
        }

        $disk->put($settingsPath, $content);
    }

    /**
     * Parse settings.py file and extract all items from all pools
     */
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

        // Extract any other custom pools
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

    /**
     * Get all available pool names from settings.py
     */
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

    /**
     * Convert item key to human-readable label
     * Example: DIAMOND_SWORD -> Diamond Sword
     */
    private function autoLabel($key)
    {
        return ucwords(str_replace('_', ' ', strtolower($key)));
    }
}
