<?php
/**
 * URL Helper Functions
 * Provides clean URL generation and management
 */

/**
 * Generate clean URL without .php extension
 * @param string $path The path to convert (with or without .php)
 * @param array $params Optional query parameters
 * @return string Clean URL
 */
function cleanUrl($path, $params = []) {
    // Remove .php extension if present
    $cleanPath = preg_replace('/\.php$/', '', $path);
    
    // Build query string if parameters provided
    $queryString = '';
    if (!empty($params)) {
        $queryString = '?' . http_build_query($params);
    }
    
    return $cleanPath . $queryString;
}

/**
 * Generate absolute clean URL
 * @param string $path The path to convert
 * @param array $params Optional query parameters
 * @return string Absolute clean URL
 */
function absoluteCleanUrl($path, $params = []) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    
    // Remove trailing slash from base path if present
    $basePath = rtrim($basePath, '/');
    
    return $protocol . '://' . $host . $basePath . '/' . cleanUrl($path, $params);
}

/**
 * Redirect to clean URL
 * @param string $path The path to redirect to
 * @param array $params Optional query parameters
 * @param int $statusCode HTTP status code (default: 302)
 */
function redirectToCleanUrl($path, $params = [], $statusCode = 302) {
    $url = cleanUrl($path, $params);
    header("Location: $url", true, $statusCode);
    exit();
}

/**
 * Get current clean URL
 * @return string Current clean URL
 */
function getCurrentCleanUrl() {
    $currentPath = $_SERVER['REQUEST_URI'];
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    
    // Remove .php extension from path
    $cleanPath = preg_replace('/\.php/', '', $currentPath);
    
    return $cleanPath;
}

/**
 * Check if current request is for a PHP file directly
 * @return bool True if accessing .php directly
 */
function isDirectPhpAccess() {
    return strpos($_SERVER['REQUEST_URI'], '.php') !== false;
}

/**
 * Force redirect from .php to clean URL if accessed directly
 */
function enforceCleanUrls() {
    if (isDirectPhpAccess()) {
        $cleanPath = preg_replace('/\.php/', '', $_SERVER['REQUEST_URI']);
        header("Location: $cleanPath", true, 301);
        exit();
    }
}

/**
 * Generate navigation menu with clean URLs
 * @param array $menuItems Array of menu items with 'path', 'label', 'icon'
 * @param string $currentPath Current active path
 * @return string HTML for navigation menu
 */
function generateCleanNavigation($menuItems, $currentPath = '') {
    $html = '';
    $currentClean = preg_replace('/\.php$/', '', $currentPath);
    
    foreach ($menuItems as $item) {
        $cleanPath = cleanUrl($item['path']);
        $isActive = ($cleanPath === $currentClean) ? 'active' : '';
        $icon = isset($item['icon']) ? '<i class="' . $item['icon'] . ' me-2"></i>' : '';
        
        $html .= '<a class="nav-link ' . $isActive . '" href="' . $cleanPath . '">';
        $html .= $icon . $item['label'];
        $html .= '</a>' . "\n";
    }
    
    return $html;
}

/**
 * Update form action to use clean URL
 * @param string $action Original action path
 * @return string Clean action URL
 */
function cleanFormAction($action = '') {
    if (empty($action)) {
        // Use current page without .php extension
        $action = preg_replace('/\.php$/', '', basename($_SERVER['PHP_SELF']));
    } else {
        $action = cleanUrl($action);
    }
    
    return $action;
}

/**
 * Generate breadcrumb navigation with clean URLs
 * @param array $breadcrumbs Array of breadcrumb items
 * @return string HTML for breadcrumb navigation
 */
function generateCleanBreadcrumbs($breadcrumbs) {
    $html = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
    
    $count = count($breadcrumbs);
    foreach ($breadcrumbs as $index => $crumb) {
        $isLast = ($index === $count - 1);
        
        if ($isLast) {
            $html .= '<li class="breadcrumb-item active" aria-current="page">' . $crumb['label'] . '</li>';
        } else {
            $cleanPath = cleanUrl($crumb['path']);
            $html .= '<li class="breadcrumb-item"><a href="' . $cleanPath . '">' . $crumb['label'] . '</a></li>';
        }
    }
    
    $html .= '</ol></nav>';
    return $html;
}

/**
 * Update all .php links in HTML content to clean URLs
 * @param string $html HTML content
 * @return string Updated HTML with clean URLs
 */
function convertHtmlToCleanUrls($html) {
    // Convert href attributes
    $html = preg_replace('/href="([^"]*?)\.php([^"]*?)"/i', 'href="$1$2"', $html);
    
    // Convert action attributes
    $html = preg_replace('/action="([^"]*?)\.php([^"]*?)"/i', 'action="$1$2"', $html);
    
    // Convert JavaScript redirects
    $html = preg_replace('/location\.href\s*=\s*[\'"]([^\'"]*?)\.php([^\'"]*?)[\'"]/i', 'location.href = \'$1$2\'', $html);
    
    return $html;
}
