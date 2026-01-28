<?php
/**
 * Diagnostic Test for VendorShippingOrchestrator
 * 
 * Access via: /wp-content/plugins/zerohold-smart-shipping/test-orchestrator.php
 */

// Load WordPress
require_once '../../../wp-load.php';

echo "<h1>VendorShippingOrchestrator Diagnostic Test</h1>";

// Test 1: Check if class exists
echo "<h2>Test 1: Class Existence</h2>";
if (class_exists('\\Zerohold\\Shipping\\Core\\VendorShippingOrchestrator')) {
    echo "✅ Class exists<br>";
} else {
    echo "❌ Class does NOT exist<br>";
    echo "Autoloader may have failed.<br>";
}

// Test 2: Try to instantiate
echo "<h2>Test 2: Instantiation</h2>";
try {
    $orchestrator = new \Zerohold\Shipping\Core\VendorShippingOrchestrator();
    echo "✅ Successfully instantiated<br>";
    echo "Class methods: <pre>" . print_r(get_class_methods($orchestrator), true) . "</pre>";
} catch (\Throwable $e) {
    echo "❌ FATAL ERROR during instantiation:<br>";
    echo "<strong>Message:</strong> " . $e->getMessage() . "<br>";
    echo "<strong>File:</strong> " . $e->getFile() . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br>";
    echo "<strong>Trace:</strong><pre>" . $e->getTraceAsString() . "</pre>";
}

// Test 3: Check VendorActions
echo "<h2>Test 3: VendorActions AJAX Handler</h2>";
if (class_exists('\\Zerohold\\Shipping\\Vendor\\VendorActions')) {
    echo "✅ VendorActions class exists<br>";
    
    // Check if AJAX hook is registered
    global $wp_filter;
    if (isset($wp_filter['wp_ajax_zh_generate_label'])) {
        echo "✅ AJAX hook 'zh_generate_label' is registered<br>";
    } else {
        echo "❌ AJAX hook 'zh_generate_label' is NOT registered<br>";
    }
} else {
    echo "❌ VendorActions class does NOT exist<br>";
}

echo "<hr><p><strong>Test Complete</strong></p>";
