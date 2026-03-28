<?php
/**
 * Category Mega Menu Shortcode Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Category_Menu {
    
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_shortcode('brandflow_mega_menu', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Clear transient cache when product categories are modified
        add_action('created_product_cat', array($this, 'clear_menu_cache'));
        add_action('edited_product_cat', array($this, 'clear_menu_cache'));
        add_action('delete_product_cat', array($this, 'clear_menu_cache'));
    }

    public function clear_menu_cache() {
        delete_transient('bf_mega_menu_standard');
        delete_transient('bf_mega_menu_accordion');
        delete_transient('brandflow_mega_menu_html'); // clean up old cache
    }

    public function enqueue_assets() {
        wp_register_style('brandflow-mega-menu', BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/css/category-menu.css', array(), BYTEMASH_WOO_SYNC_VERSION);
        wp_register_script('brandflow-mega-menu', BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/js/category-menu.js', array('jquery'), BYTEMASH_WOO_SYNC_VERSION, true);
    }

    private function group_terms_by_name($terms) {
        $grouped = array();
        foreach ($terms as $term) {
            if (!isset($grouped[$term->name])) {
                $grouped[$term->name] = array(
                    'ids' => array(),
                    'terms' => array(),
                    'name' => $term->name
                );
            }
            $grouped[$term->name]['ids'][] = $term->term_id;
            $grouped[$term->name]['terms'][] = $term;
        }
        ksort($grouped);
        return $grouped;
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'layout' => 'standard' // Defaults to standard, can be 'accordion' for sidebars
        ), $atts, 'brandflow_mega_menu');

        wp_enqueue_style('brandflow-mega-menu');
        wp_enqueue_script('brandflow-mega-menu');

        // Use a distinct cache key for each layout mode
        $cache_key = 'bf_mega_menu_' . sanitize_key($atts['layout']);
        $cached_menu = get_transient($cache_key);
        if (false !== $cached_menu) {
            return $cached_menu;
        }

        // Fetch ALL categories in a single query to prevent N+1 query performance issues
        $all_categories = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC'
        ));

        if (is_wp_error($all_categories) || empty($all_categories)) {
            return '<p>No categories found.</p>';
        }

        // Build a hierarchy array in memory
        $terms_by_parent = array();
        foreach ($all_categories as $term) {
            if ($term->slug === 'uncategorized') {
                continue;
            }
            $terms_by_parent[$term->parent][] = $term; // index by parent_id
        }

        if (empty($terms_by_parent[0])) {
            return '<p>No top-level categories found.</p>';
        }

        $top_level_grouped = $this->group_terms_by_name($terms_by_parent[0]);
        $mode_class = $atts['layout'] === 'accordion' ? 'bf-accordion-mode' : '';

        ob_start();
        ?>
        <div class="brandflow-mega-menu-container <?php echo esc_attr($mode_class); ?>">
            <?php if ($atts['layout'] !== 'accordion'): ?>
            <div class="bf-mobile-toggle">
                <div class="bf-hamburger"><span></span><span></span><span></span></div>
                <div class="bf-mobile-text">Categories</div>
            </div>
            <?php endif; ?>
            <nav class="brandflow-mega-menu">
                <ul class="bf-menu-level-1">
                    <?php foreach ($top_level_grouped as $category_name => $top_group) : 
                        $children = array();
                        foreach ($top_group['ids'] as $id) {
                            if (isset($terms_by_parent[$id])) {
                                $children = array_merge($children, $terms_by_parent[$id]);
                            }
                        }
                        $children_grouped = $this->group_terms_by_name($children);
                        $has_children = !empty($children_grouped);
                        
                        $top_link = count($top_group['ids']) > 1 ? site_url('/shop/?bf_cat_group=' . $top_group['ids'][0]) : get_term_link($top_group['terms'][0]);
                    ?>
                        <li class="bf-menu-item <?php echo $has_children ? 'bf-has-dropdown' : ''; ?>">
                            <div class="bf-menu-item-header">
                                <a href="<?php echo esc_url($top_link); ?>">
                                    <?php echo esc_html($category_name); ?>
                                </a>
                                <?php if ($has_children): ?>
                                    <span class="bf-toggle-btn">
                                        <svg viewBox="0 0 24 24" class="bf-dropdown-icon" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($has_children): ?>
                                <div class="bf-mega-dropdown">
                                    <div class="bf-mega-dropdown-inner">
                                        <?php foreach ($children_grouped as $child_name => $child_group) : 
                                            $grandchildren = array();
                                            foreach ($child_group['ids'] as $id) {
                                                if (isset($terms_by_parent[$id])) {
                                                    $grandchildren = array_merge($grandchildren, $terms_by_parent[$id]);
                                                }
                                            }
                                            $grandchildren_grouped = $this->group_terms_by_name($grandchildren);
                                            $has_grandchildren = !empty($grandchildren_grouped);
                                            
                                            $child_link = count($child_group['ids']) > 1 ? site_url('/shop/?bf_cat_group=' . $child_group['ids'][0]) : get_term_link($child_group['terms'][0]);
                                        ?>
                                            <div class="bf-mega-column">
                                                <h4 class="bf-column-title">
                                                    <a href="<?php echo esc_url($child_link); ?>"><?php echo esc_html($child_name); ?></a>
                                                </h4>
                                                <?php if ($has_grandchildren): ?>
                                                    <ul class="bf-menu-level-3">
                                                        <?php foreach ($grandchildren_grouped as $grandchild_name => $grandchild_group) : 
                                                            $grandchild_link = count($grandchild_group['ids']) > 1 ? site_url('/shop/?bf_cat_group=' . $grandchild_group['ids'][0]) : get_term_link($grandchild_group['terms'][0]);
                                                        ?>
                                                            <li><a href="<?php echo esc_url($grandchild_link); ?>"><?php echo esc_html($grandchild_name); ?></a></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </div>
        <?php
        $html = ob_get_clean();
        
        // Cache the layout-specific HTML for 24 hours
        set_transient($cache_key, $html, DAY_IN_SECONDS);
        
        return $html;
    }
}
