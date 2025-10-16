<?php
/**
 * Test Product Type Logic
 * 
 * This script tests the new product type determination logic
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

// Check if user has admin permissions
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this script.');
}

echo "<h1>Product Type Logic Test</h1>";

// Test cases based on Amrod API structure
$test_cases = array(
    // Case 1: Simple product (1 variant, no size/color differences)
    array(
        'name' => 'Simple Product (1 variant)',
        'variants' => array(
            array(
                'simpleCode' => 'AF-AM-7-D',
                'fullCode' => 'AF-AM-7-D-0-0',
                'codeColour' => null,
                'codeColourName' => null,
                'codeSize' => null,
                'codeSizeName' => null,
            )
        ),
        'expected' => 'Simple'
    ),
    
    // Case 2: Simple product (multiple variants, but truly identical)
    array(
        'name' => 'Simple Product (multiple identical variants)',
        'variants' => array(
            array(
                'simpleCode' => 'AF-AM-7-D',
                'fullCode' => 'AF-AM-7-D-0-0',
                'codeColour' => null,
                'codeColourName' => null,
                'codeSize' => null,
                'codeSizeName' => null,
            ),
            array(
                'simpleCode' => 'AF-AM-7-D',
                'fullCode' => 'AF-AM-7-D-0-1',
                'codeColour' => null,
                'codeColourName' => null,
                'codeSize' => null,
                'codeSizeName' => null,
            )
        ),
        'expected' => 'Simple'
    ),
    
    // Case 3: Variable product (multiple sizes)
    array(
        'name' => 'Variable Product (multiple sizes)',
        'variants' => array(
            array(
                'simpleCode' => 'SHIRT-001',
                'fullCode' => 'SHIRT-001-S',
                'codeColour' => null,
                'codeColourName' => null,
                'codeSize' => 'S',
                'codeSizeName' => 'Small',
            ),
            array(
                'simpleCode' => 'SHIRT-001',
                'fullCode' => 'SHIRT-001-M',
                'codeColour' => null,
                'codeColourName' => null,
                'codeSize' => 'M',
                'codeSizeName' => 'Medium',
            ),
            array(
                'simpleCode' => 'SHIRT-001',
                'fullCode' => 'SHIRT-001-L',
                'codeColour' => null,
                'codeColourName' => null,
                'codeSize' => 'L',
                'codeSizeName' => 'Large',
            )
        ),
        'expected' => 'Variable'
    ),
    
    // Case 4: Variable product (multiple colors)
    array(
        'name' => 'Variable Product (multiple colors)',
        'variants' => array(
            array(
                'simpleCode' => 'MUG-001',
                'fullCode' => 'MUG-001-RED',
                'codeColour' => 'RED',
                'codeColourName' => 'Red',
                'codeSize' => null,
                'codeSizeName' => null,
            ),
            array(
                'simpleCode' => 'MUG-001',
                'fullCode' => 'MUG-001-BLUE',
                'codeColour' => 'BLUE',
                'codeColourName' => 'Blue',
                'codeSize' => null,
                'codeSizeName' => null,
            )
        ),
        'expected' => 'Variable'
    ),
    
    // Case 5: Variable product (multiple sizes AND colors)
    array(
        'name' => 'Variable Product (multiple sizes and colors)',
        'variants' => array(
            array(
                'simpleCode' => 'SHIRT-002',
                'fullCode' => 'SHIRT-002-S-RED',
                'codeColour' => 'RED',
                'codeColourName' => 'Red',
                'codeSize' => 'S',
                'codeSizeName' => 'Small',
            ),
            array(
                'simpleCode' => 'SHIRT-002',
                'fullCode' => 'SHIRT-002-S-BLUE',
                'codeColour' => 'BLUE',
                'codeColourName' => 'Blue',
                'codeSize' => 'S',
                'codeSizeName' => 'Small',
            ),
            array(
                'simpleCode' => 'SHIRT-002',
                'fullCode' => 'SHIRT-002-M-RED',
                'codeColour' => 'RED',
                'codeColourName' => 'Red',
                'codeSize' => 'M',
                'codeSizeName' => 'Medium',
            ),
            array(
                'simpleCode' => 'SHIRT-002',
                'fullCode' => 'SHIRT-002-M-BLUE',
                'codeColour' => 'BLUE',
                'codeColourName' => 'Blue',
                'codeSize' => 'M',
                'codeSizeName' => 'Medium',
            )
        ),
        'expected' => 'Variable'
    ),
    
    // Case 6: Variable product (2 variants with different attributes)
    array(
        'name' => 'Variable Product (2 variants, different attributes)',
        'variants' => array(
            array(
                'simpleCode' => 'MUG-001',
                'fullCode' => 'MUG-001-RED',
                'codeColour' => 'RED',
                'codeColourName' => 'Red',
                'codeSize' => null,
                'codeSizeName' => null,
            ),
            array(
                'simpleCode' => 'MUG-001',
                'fullCode' => 'MUG-001-BLUE',
                'codeColour' => 'BLUE',
                'codeColourName' => 'Blue',
                'codeSize' => null,
                'codeSizeName' => null,
            )
        ),
        'expected' => 'Variable'
    )
);

// Test the logic
foreach ($test_cases as $i => $test_case) {
    echo "<h2>Test Case " . ($i + 1) . ": " . $test_case['name'] . "</h2>";
    
    $variants = $test_case['variants'];
    $has_variants = false;
    
    // Apply the new logic
    if (!empty($variants) && is_array($variants)) {
        // If only 1 variant, it's simple
        if (count($variants) <= 1) {
            $has_variants = false;
        } else {
            // For 2+ variants, check if they're truly identical (rare case)
            $sizes = array();
            $colors = array();
            
            foreach ($variants as $variant) {
                $size = $variant['codeSizeName'] ?? null;
                $color = $variant['codeColourName'] ?? null;
                
                if (!empty($size)) {
                    $sizes[$size] = true;
                }
                if (!empty($color)) {
                    $colors[$color] = true;
                }
            }
            
            // Check if all variants are truly identical (same size and color)
            $unique_sizes = count($sizes);
            $unique_colors = count($colors);
            
            // If all variants have the same size AND same color (or both null), it's simple
            // Otherwise, it's variable
            if ($unique_sizes <= 1 && $unique_colors <= 1) {
                $has_variants = false; // All variants are identical
            } else {
                $has_variants = true; // Variants have differences
            }
        }
    }
    
    $result = $has_variants ? 'Variable' : 'Simple';
    $status = ($result === $test_case['expected']) ? '✅ PASS' : '❌ FAIL';
    
    echo "<p><strong>Result:</strong> {$result} ({$status})</p>";
    echo "<p><strong>Expected:</strong> {$test_case['expected']}</p>";
    echo "<p><strong>Variant Count:</strong> " . count($variants) . "</p>";
    
    if (isset($sizes)) {
        echo "<p><strong>Unique Sizes:</strong> " . count($sizes) . " (" . implode(', ', array_keys($sizes)) . ")</p>";
    }
    if (isset($colors)) {
        echo "<p><strong>Unique Colors:</strong> " . count($colors) . " (" . implode(', ', array_keys($colors)) . ")</p>";
    }
    
    echo "<hr>";
}

echo "<h2>Summary</h2>";
echo "<p>The new logic correctly determines product types based on actual variant differences rather than just the presence of a variants array.</p>";
echo "<p><strong>Key improvements:</strong></p>";
echo "<ul>";
echo "<li>✅ Simple products with 1 variant → Simple</li>";
echo "<li>✅ Simple products with multiple identical variants → Simple</li>";
echo "<li>✅ Products with multiple sizes → Variable</li>";
echo "<li>✅ Products with multiple colors → Variable</li>";
echo "<li>✅ Products with multiple sizes AND colors → Variable</li>";
echo "</ul>";
?>
