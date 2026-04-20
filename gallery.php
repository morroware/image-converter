<?php
/**
 * Image Gallery Manager
 * View, manage, and delete optimized images
 * 
 * @version 1.0
 */

define('UPLOAD_DIR', __DIR__ . '/optimized/');

// Handle delete requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'delete') {
        $filename = basename($_POST['filename']);
        $filepath = UPLOAD_DIR . $filename;
        
        if (file_exists($filepath) && is_file($filepath)) {
            if (@unlink($filepath)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete file']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'File not found']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'delete_multiple') {
        $files = json_decode($_POST['files'], true);
        $deleted = 0;
        $failed = 0;
        
        foreach ($files as $filename) {
            $safeName = basename($filename);
            $filepath = UPLOAD_DIR . $safeName;
            
            if (file_exists($filepath) && is_file($filepath)) {
                if (@unlink($filepath)) {
                    $deleted++;
                } else {
                    $failed++;
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'deleted' => $deleted,
            'failed' => $failed
        ]);
        exit;
    }
    
    if ($_POST['action'] === 'get_files') {
        $files = [];
        $totalSize = 0;
        
        if (is_dir(UPLOAD_DIR)) {
            $items = scandir(UPLOAD_DIR);
            
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                
                $filepath = UPLOAD_DIR . $item;
                
                if (is_file($filepath)) {
                    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) continue;
                    
                    $size = filesize($filepath);
                    $totalSize += $size;
                    
                    $imageInfo = @getimagesize($filepath);
                    
                    $files[] = [
                        'filename' => $item,
                        'size' => $size,
                        'size_formatted' => formatBytes($size),
                        'width' => $imageInfo ? $imageInfo[0] : 0,
                        'height' => $imageInfo ? $imageInfo[1] : 0,
                        'format' => strtoupper($ext),
                        'modified' => filemtime($filepath),
                        'url' => 'optimized/' . $item
                    ];
                }
            }
        }
        
        // Sort by modified date (newest first)
        usort($files, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        echo json_encode([
            'success' => true,
            'files' => $files,
            'total_size' => $totalSize,
            'total_count' => count($files)
        ]);
        exit;
    }
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Gallery Manager</title>
    <style>
        :root {
            --bg-primary: #0a0e14;
            --bg-secondary: #12171f;
            --bg-tertiary: #1a1f29;
            --bg-elevated: #1e242e;
            --border-color: #2d3540;
            --border-hover: #3d4552;
            --text-primary: #e6edf3;
            --text-secondary: #9198a1;
            --text-tertiary: #6b7280;
            --accent-blue: #3b82f6;
            --accent-blue-hover: #2563eb;
            --accent-green: #10b981;
            --accent-purple: #8b5cf6;
            --accent-red: #ef4444;
            --accent-yellow: #f59e0b;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.5);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.6);
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes rotateGradient {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans', Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, var(--bg-primary) 0%, #0d1219 100%);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 80% 20%, rgba(59, 130, 246, 0.05) 0%, transparent 50%),
                        radial-gradient(circle at 20% 80%, rgba(139, 92, 246, 0.05) 0%, transparent 50%);
            animation: rotateGradient 20s linear infinite;
            pointer-events: none;
            z-index: 0;
        }
        
        .container {
            position: relative;
            z-index: 1;
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
        }
        
        .header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            animation: fadeInDown 0.6s ease-out;
        }
        
        .header h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(135deg, var(--accent-blue) 0%, var(--accent-purple) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .subtitle {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .header-right {
            display: flex;
            gap: 12px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-primary);
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn:hover {
            border-color: var(--accent-blue);
            background: rgba(88, 166, 255, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--accent-blue) 0%, #4a8fd8 100%);
            border-color: var(--accent-blue);
            color: white;
            box-shadow: 0 2px 8px rgba(88, 166, 255, 0.3);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #4a8fd8 0%, #3a7fb8 100%);
            box-shadow: 0 4px 12px rgba(88, 166, 255, 0.4);
        }
        
        .btn-back {
            background: linear-gradient(135deg, var(--accent-purple) 0%, #764ba2 100%);
            border: none;
            color: white;
            box-shadow: 0 2px 8px rgba(163, 113, 247, 0.3);
        }
        
        .btn-back:hover {
            background: linear-gradient(135deg, #764ba2 0%, #5a3a7f 100%);
            box-shadow: 0 4px 12px rgba(163, 113, 247, 0.4);
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background: var(--accent-red);
            border-color: var(--accent-red);
            color: white;
        }
        
        .btn-danger:hover {
            background: #ff6b6b;
        }
        
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(26, 31, 41, 0.7);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            border-left: 3px solid var(--accent-blue);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: fadeInUp 0.6s ease-out backwards;
            box-shadow: var(--shadow-md);
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
            border-left-color: var(--accent-purple);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .toolbar {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .toolbar-left {
            display: flex;
            gap: 12px;
            flex: 1;
        }
        
        .search-box {
            flex: 1;
            max-width: 400px;
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 10px 40px 10px 16px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 6px;
            font-size: 14px;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--accent-blue);
        }
        
        .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }
        
        .filter-group {
            display: flex;
            gap: 8px;
        }
        
        select {
            padding: 10px 16px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }
        
        select:focus {
            outline: none;
            border-color: var(--accent-blue);
        }
        
        .bulk-actions {
            display: none;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: rgba(88, 166, 255, 0.1);
            border: 1px solid var(--accent-blue);
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .bulk-actions.active {
            display: flex;
        }
        
        .bulk-info {
            flex: 1;
            font-size: 14px;
        }
        
        .gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .image-card {
            background: rgba(26, 31, 41, 0.7);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            box-shadow: var(--shadow-md);
            animation: fadeInUp 0.5s ease-out backwards;
        }
        
        .image-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(59, 130, 246, 0.3);
            border-color: var(--accent-blue);
        }
        
        .image-card.selected {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
        }
        
        .image-preview {
            width: 100%;
            aspect-ratio: 16/9;
            object-fit: cover;
            background: var(--bg-tertiary);
            cursor: pointer;
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .image-card:hover .image-preview {
            transform: scale(1.08);
        }
        
        .image-checkbox {
            position: absolute;
            top: 12px;
            left: 12px;
            width: 24px;
            height: 24px;
            accent-color: var(--accent-blue);
            cursor: pointer;
            z-index: 10;
        }
        
        .image-info {
            padding: 16px;
        }
        
        .image-name {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .image-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 12px;
        }
        
        .meta-item {
            font-size: 12px;
            color: var(--text-secondary);
        }
        
        .meta-value {
            color: var(--text-primary);
            font-weight: 600;
        }
        
        .image-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-small {
            flex: 1;
            padding: 8px 12px;
            font-size: 12px;
            text-align: center;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-secondary);
        }
        
        .empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .empty-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
        }
        
        .modal-overlay.active {
            opacity: 1;
            pointer-events: all;
        }
        
        .modal {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        
        .image-modal {
            max-width: 90vw;
            max-height: 90vh;
            padding: 0;
            background: transparent;
            border: none;
        }
        
        .image-modal img {
            max-width: 100%;
            max-height: 90vh;
            display: block;
            border-radius: 8px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: 600;
        }
        
        .btn-close {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-size: 24px;
            cursor: pointer;
            padding: 4px 8px;
        }
        
        .btn-close:hover {
            color: var(--text-primary);
        }
        
        .modal-body {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        /* Toast */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .toast {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            min-width: 300px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(400px);
            animation: slideIn 0.3s forwards, slideOut 0.3s 2.7s forwards;
        }
        
        @keyframes slideIn {
            to { transform: translateX(0); }
        }
        
        @keyframes slideOut {
            to { transform: translateX(400px); }
        }
        
        .toast-icon {
            font-size: 20px;
        }
        
        .toast-content {
            flex: 1;
        }
        
        .toast-title {
            font-weight: 600;
            font-size: 14px;
        }
        
        .toast-message {
            font-size: 12px;
            color: var(--text-secondary);
        }
        
        .toast.success { border-left: 3px solid var(--accent-green); }
        .toast.error { border-left: 3px solid var(--accent-red); }
        
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--border-color);
            border-top-color: var(--accent-blue);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                max-width: 100%;
            }
            
            .gallery {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <h1>
                    <span>🖼️</span>
                    Image Gallery Manager
                </h1>
                <p class="subtitle">View and manage all optimized images</p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn btn-back">
                    <span>←</span>
                    <span>Back to Converter</span>
                </a>
                <button class="btn btn-primary" id="refreshBtn">
                    <span>🔄</span>
                    <span>Refresh</span>
                </button>
            </div>
        </div>
        
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-label">Total Images</div>
                <div class="stat-value" id="totalCount">0</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Size</div>
                <div class="stat-value" id="totalSize">0 MB</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Selected</div>
                <div class="stat-value" id="selectedCount">0</div>
            </div>
        </div>
        
        <div class="toolbar">
            <div class="toolbar-left">
                <div class="search-box">
                    <input type="text" class="search-input" id="searchInput" placeholder="Search images...">
                    <span class="search-icon">🔍</span>
                </div>
                <div class="filter-group">
                    <select id="formatFilter">
                        <option value="">All Formats</option>
                        <option value="WEBP">WebP</option>
                        <option value="AVIF">AVIF</option>
                        <option value="JPEG">JPEG</option>
                        <option value="JPG">JPG</option>
                        <option value="PNG">PNG</option>
                    </select>
                    <select id="sortBy">
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="largest">Largest First</option>
                        <option value="smallest">Smallest First</option>
                        <option value="name">Name A-Z</option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="bulk-actions" id="bulkActions">
            <div class="bulk-info">
                <strong id="bulkCount">0</strong> images selected
            </div>
            <button class="btn btn-small" id="selectAllBtn">Select All</button>
            <button class="btn btn-small" id="deselectAllBtn">Deselect All</button>
            <button class="btn btn-small btn-danger" id="deleteSelectedBtn">
                <span>🗑️</span>
                <span>Delete Selected</span>
            </button>
        </div>
        
        <div class="gallery" id="gallery"></div>
        
        <div class="empty-state" id="emptyState" style="display: none;">
            <div class="empty-icon">📂</div>
            <div class="empty-title">No images found</div>
            <div>Upload some images using the converter to see them here</div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Delete</h3>
                <button class="btn-close" onclick="closeModal('deleteModal')">×</button>
            </div>
            <div class="modal-body" id="deleteModalBody">
                Are you sure you want to delete this image?
            </div>
            <div class="modal-actions">
                <button class="btn" onclick="closeModal('deleteModal')">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>
    
    <!-- Image Preview Modal -->
    <div class="modal-overlay" id="previewModal" onclick="closeModal('previewModal')">
        <div class="image-modal">
            <img id="previewImage" src="" alt="Preview">
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <script>
        let allFiles = [];
        let filteredFiles = [];
        let selectedFiles = new Set();
        
        // Load files on page load
        document.addEventListener('DOMContentLoaded', loadFiles);
        
        // Event listeners
        document.getElementById('refreshBtn').addEventListener('click', loadFiles);
        document.getElementById('searchInput').addEventListener('input', filterAndRender);
        document.getElementById('formatFilter').addEventListener('change', filterAndRender);
        document.getElementById('sortBy').addEventListener('change', sortAndRender);
        document.getElementById('selectAllBtn').addEventListener('click', selectAll);
        document.getElementById('deselectAllBtn').addEventListener('click', deselectAll);
        document.getElementById('deleteSelectedBtn').addEventListener('click', deleteSelected);
        
        async function loadFiles() {
            const btn = document.getElementById('refreshBtn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<span class="spinner"></span><span>Loading...</span>';
            btn.disabled = true;
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_files');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    allFiles = data.files;
                    filteredFiles = [...allFiles];
                    selectedFiles.clear();
                    
                    updateStats(data.total_count, data.total_size);
                    sortAndRender();
                    
                    if (data.total_count === 0) {
                        showEmptyState();
                    }
                }
            } catch (error) {
                showToast('Error', 'Failed to load images', 'error');
            } finally {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        }
        
        function filterAndRender() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            const format = document.getElementById('formatFilter').value;
            
            filteredFiles = allFiles.filter(file => {
                const matchesSearch = file.filename.toLowerCase().includes(search);
                const matchesFormat = !format || file.format === format;
                return matchesSearch && matchesFormat;
            });
            
            sortAndRender();
        }
        
        function sortAndRender() {
            const sortBy = document.getElementById('sortBy').value;
            
            filteredFiles.sort((a, b) => {
                switch (sortBy) {
                    case 'newest':
                        return b.modified - a.modified;
                    case 'oldest':
                        return a.modified - b.modified;
                    case 'largest':
                        return b.size - a.size;
                    case 'smallest':
                        return a.size - b.size;
                    case 'name':
                        return a.filename.localeCompare(b.filename);
                    default:
                        return 0;
                }
            });
            
            renderGallery();
        }
        
        function renderGallery() {
            const gallery = document.getElementById('gallery');
            const emptyState = document.getElementById('emptyState');
            
            if (filteredFiles.length === 0) {
                gallery.style.display = 'none';
                emptyState.style.display = 'block';
                return;
            }
            
            gallery.style.display = 'grid';
            emptyState.style.display = 'none';
            
            gallery.innerHTML = filteredFiles.map(file => `
                <div class="image-card ${selectedFiles.has(file.filename) ? 'selected' : ''}" data-filename="${file.filename}">
                    <input type="checkbox" class="image-checkbox" 
                           ${selectedFiles.has(file.filename) ? 'checked' : ''}
                           onchange="toggleSelect('${file.filename}')">
                    <img src="${file.url}" alt="${file.filename}" class="image-preview" 
                         onclick="showPreview('${file.url}')">
                    <div class="image-info">
                        <div class="image-name" title="${file.filename}">${file.filename}</div>
                        <div class="image-meta">
                            <div class="meta-item">
                                <div>Format</div>
                                <div class="meta-value">${file.format}</div>
                            </div>
                            <div class="meta-item">
                                <div>Size</div>
                                <div class="meta-value">${file.size_formatted}</div>
                            </div>
                            <div class="meta-item">
                                <div>Dimensions</div>
                                <div class="meta-value">${file.width}×${file.height}</div>
                            </div>
                            <div class="meta-item">
                                <div>Modified</div>
                                <div class="meta-value">${formatDate(file.modified)}</div>
                            </div>
                        </div>
                        <div class="image-actions">
                            <a href="${file.url}" download="${file.filename}" class="btn btn-small">
                                ⬇️ Download
                            </a>
                            <button class="btn btn-small btn-danger" onclick="confirmDelete('${file.filename}')">
                                🗑️ Delete
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
            
            updateBulkActions();
        }
        
        function toggleSelect(filename) {
            if (selectedFiles.has(filename)) {
                selectedFiles.delete(filename);
            } else {
                selectedFiles.add(filename);
            }
            
            const card = document.querySelector(`[data-filename="${filename}"]`);
            if (card) {
                card.classList.toggle('selected');
            }
            
            updateBulkActions();
        }
        
        function selectAll() {
            filteredFiles.forEach(file => selectedFiles.add(file.filename));
            document.querySelectorAll('.image-checkbox').forEach(cb => cb.checked = true);
            document.querySelectorAll('.image-card').forEach(card => card.classList.add('selected'));
            updateBulkActions();
        }
        
        function deselectAll() {
            selectedFiles.clear();
            document.querySelectorAll('.image-checkbox').forEach(cb => cb.checked = false);
            document.querySelectorAll('.image-card').forEach(card => card.classList.remove('selected'));
            updateBulkActions();
        }
        
        function updateBulkActions() {
            const bulkActions = document.getElementById('bulkActions');
            const bulkCount = document.getElementById('bulkCount');
            const selectedCountEl = document.getElementById('selectedCount');
            
            const count = selectedFiles.size;
            bulkCount.textContent = count;
            selectedCountEl.textContent = count;
            
            if (count > 0) {
                bulkActions.classList.add('active');
            } else {
                bulkActions.classList.remove('active');
            }
        }
        
        function updateStats(count, size) {
            document.getElementById('totalCount').textContent = count;
            document.getElementById('totalSize').textContent = formatBytes(size);
        }
        
        function confirmDelete(filename) {
            const modal = document.getElementById('deleteModal');
            const body = document.getElementById('deleteModalBody');
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            
            body.textContent = `Are you sure you want to delete "${filename}"? This action cannot be undone.`;
            
            confirmBtn.onclick = () => deleteFile(filename);
            
            modal.classList.add('active');
        }
        
        async function deleteFile(filename) {
            closeModal('deleteModal');
            
            try {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('filename', filename);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showToast('Deleted', `${filename} has been deleted`, 'success');
                    selectedFiles.delete(filename);
                    await loadFiles();
                } else {
                    showToast('Error', data.error || 'Failed to delete file', 'error');
                }
            } catch (error) {
                showToast('Error', 'Failed to delete file', 'error');
            }
        }
        
        async function deleteSelected() {
            if (selectedFiles.size === 0) return;
            
            const modal = document.getElementById('deleteModal');
            const body = document.getElementById('deleteModalBody');
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            
            body.innerHTML = `
                Are you sure you want to delete <strong>${selectedFiles.size}</strong> images? 
                This action cannot be undone.
            `;
            
            confirmBtn.onclick = async () => {
                closeModal('deleteModal');
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete_multiple');
                    formData.append('files', JSON.stringify(Array.from(selectedFiles)));
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        showToast(
                            'Deleted', 
                            `${data.deleted} images deleted${data.failed > 0 ? `, ${data.failed} failed` : ''}`, 
                            'success'
                        );
                        selectedFiles.clear();
                        await loadFiles();
                    }
                } catch (error) {
                    showToast('Error', 'Failed to delete files', 'error');
                }
            };
            
            modal.classList.add('active');
        }
        
        function showPreview(url) {
            const modal = document.getElementById('previewModal');
            const img = document.getElementById('previewImage');
            img.src = url;
            modal.classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function showEmptyState() {
            document.getElementById('gallery').style.display = 'none';
            document.getElementById('emptyState').style.display = 'block';
        }
        
        function showToast(title, message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icon = type === 'success' ? '✓' : '✗';
            
            toast.innerHTML = `
                <span class="toast-icon">${icon}</span>
                <div class="toast-content">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
            `;
            
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        function formatBytes(bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            bytes = Math.max(bytes, 0);
            const pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024));
            const unit = Math.min(pow, units.length - 1);
            bytes /= Math.pow(1024, unit);
            return bytes.toFixed(2) + ' ' + units[unit];
        }
        
        function formatDate(timestamp) {
            const date = new Date(timestamp * 1000);
            const now = new Date();
            const diff = now - date;
            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            
            if (days === 0) return 'Today';
            if (days === 1) return 'Yesterday';
            if (days < 7) return `${days}d ago`;
            
            return date.toLocaleDateString();
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal('deleteModal');
                closeModal('previewModal');
            }
            
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                selectAll();
            }
        });
        
        // Make functions globally accessible
        window.toggleSelect = toggleSelect;
        window.confirmDelete = confirmDelete;
        window.showPreview = showPreview;
        window.closeModal = closeModal;
    </script>
</body>
</html>