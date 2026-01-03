<?php
require 'vendor/autoload.php';

use Google\Cloud\Storage\StorageClient;

/**
 * CloudManager - Handles Google Cloud Storage operations
 * 
 * This class manages file uploads to Google Cloud Storage,
 * including Gemini API responses.
 */
class CloudManager {
    private $storage;
    private $bucket;
    private $bucketName;

    /**
     * Constructor - Initializes the Storage Client and Bucket
     * 
     * Automatically picks up GOOGLE_APPLICATION_CREDENTIALS from environment
     * 
     * @throws Exception if bucket name is not configured
     */
    public function __construct() {
        // Automatically picks up GOOGLE_APPLICATION_CREDENTIALS from env
        $this->storage = new StorageClient();
        
        $this->bucketName = getenv('CLOUD_STORAGE_BUCKET');
        if (!$this->bucketName) {
            throw new Exception("CLOUD_STORAGE_BUCKET environment variable is not set");
        }
        
        $this->bucket = $this->storage->bucket($this->bucketName);
    }

    /**
     * Upload Gemini API response to Cloud Storage
     * 
     * @param string $text The text content from Gemini API
     * @param string $filename The filename (without extension)
     * @param string $folder Optional folder path (default: 'gemini_logs/')
     * @return array Response with status and message
     */
    public function uploadGeminiResponse($text, $filename, $folder = 'gemini_logs/') {
        try {
            // Add timestamp to filename for uniqueness
            $timestamp = date('Y-m-d_H-i-s');
            $fullFilename = $folder . $filename . '_' . $timestamp . '.txt';
            
            $this->bucket->upload($text, [
                'name' => $fullFilename,
                'metadata' => [
                    'contentType' => 'text/plain',
                    'source' => 'gemini-api',
                    'uploadedAt' => date('c')
                ]
            ]);
            
            return [
                'status' => 'success',
                'message' => "File uploaded successfully to Cloud Storage",
                'filename' => $fullFilename,
                'bucket' => $this->bucketName
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Upload any file to Cloud Storage
     * 
     * @param string $content The content to upload
     * @param string $filename The full filename with path
     * @param array $metadata Optional metadata
     * @return array Response with status and message
     */
    public function uploadFile($content, $filename, $metadata = []) {
        try {
            $options = ['name' => $filename];
            
            if (!empty($metadata)) {
                $options['metadata'] = $metadata;
            }
            
            $this->bucket->upload($content, $options);
            
            return [
                'status' => 'success',
                'message' => "File uploaded successfully",
                'filename' => $filename,
                'bucket' => $this->bucketName
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * List files in a specific folder
     * 
     * @param string $prefix Folder prefix
     * @return array List of files
     */
    public function listFiles($prefix = '') {
        try {
            $options = [];
            if ($prefix) {
                $options['prefix'] = $prefix;
            }
            
            $objects = $this->bucket->objects($options);
            $files = [];
            
            foreach ($objects as $object) {
                $files[] = [
                    'name' => $object->name(),
                    'size' => $object->info()['size'],
                    'updated' => $object->info()['updated']
                ];
            }
            
            return [
                'status' => 'success',
                'files' => $files
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Download a file from Cloud Storage
     * 
     * @param string $filename The file to download
     * @return array Response with status and content
     */
    public function downloadFile($filename) {
        try {
            $object = $this->bucket->object($filename);
            $content = $object->downloadAsString();
            
            return [
                'status' => 'success',
                'content' => $content,
                'filename' => $filename
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Delete a file from Cloud Storage
     * 
     * @param string $filename The file to delete
     * @return array Response with status
     */
    public function deleteFile($filename) {
        try {
            $object = $this->bucket->object($filename);
            $object->delete();
            
            return [
                'status' => 'success',
                'message' => "File deleted successfully",
                'filename' => $filename
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Search files by name pattern
     * 
     * @param string $pattern Search pattern (supports wildcards)
     * @param string $folder Optional folder prefix
     * @return array Response with matching files
     */
    public function searchFiles($pattern, $folder = '') {
        try {
            $options = [];
            if ($folder) {
                $options['prefix'] = $folder;
            }
            
            $objects = $this->bucket->objects($options);
            $matchedFiles = [];
            
            // Convert wildcard pattern to regex
            $regexPattern = str_replace(['*', '?'], ['.*', '.'], $pattern);
            $regexPattern = '/^' . $regexPattern . '$/i';
            
            foreach ($objects as $object) {
                $name = $object->name();
                $basename = basename($name);
                
                if (preg_match($regexPattern, $basename) || strpos($basename, $pattern) !== false) {
                    $matchedFiles[] = [
                        'name' => $name,
                        'basename' => $basename,
                        'size' => $object->info()['size'],
                        'updated' => $object->info()['updated'],
                        'contentType' => $object->info()['contentType'] ?? 'unknown'
                    ];
                }
            }
            
            return [
                'status' => 'success',
                'pattern' => $pattern,
                'count' => count($matchedFiles),
                'files' => $matchedFiles
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Download multiple files at once (for RAG context)
     * 
     * @param array $filenames Array of filenames to download
     * @return array Response with file contents
     */
    public function downloadMultiple($filenames) {
        try {
            $contents = [];
            $errors = [];
            
            foreach ($filenames as $filename) {
                try {
                    $object = $this->bucket->object($filename);
                    $content = $object->downloadAsString();
                    $contents[$filename] = $content;
                } catch (Exception $e) {
                    $errors[$filename] = $e->getMessage();
                }
            }
            
            return [
                'status' => count($errors) === 0 ? 'success' : 'partial',
                'files' => $contents,
                'errors' => $errors,
                'downloaded' => count($contents),
                'failed' => count($errors)
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get file metadata without downloading
     * 
     * @param string $filename The file to get metadata for
     * @return array Response with metadata
     */
    public function getFileMetadata($filename) {
        try {
            $object = $this->bucket->object($filename);
            $info = $object->info();
            
            return [
                'status' => 'success',
                'metadata' => [
                    'name' => $info['name'],
                    'size' => $info['size'],
                    'contentType' => $info['contentType'] ?? 'unknown',
                    'created' => $info['timeCreated'],
                    'updated' => $info['updated'],
                    'md5Hash' => $info['md5Hash'] ?? null,
                    'metadata' => $info['metadata'] ?? []
                ]
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}

