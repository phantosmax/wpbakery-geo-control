<?php
/**
 * Plugin Name: WPBakery Geo Control
 * Plugin URI: https://zettagrid.com
 * Description: Add country-based visibility controls to all WPBakery Page Builder elements
 * Version: 1.0.0
 * Author: Zettagrid
 * Author URI: https://zettagrid.com
 * License: GPL v2 or later
 * Text Domain: wpbakery-geo-control
 * Requires: Visual Composer/WPBakery Page Builder
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WPBakery_Geo_Control {
    
    private static $instance = null;
    private $cache_duration = 86400; // 24 hours
    
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new WPBakery_Geo_Control();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Check if WPBakery is active
        add_action('plugins_loaded', array($this, 'init'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add admin notice if WPBakery is not active
        add_action('admin_notices', array($this, 'wpbakery_check'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        if (!defined('WPB_VC_VERSION')) {
            return;
        }
        
        // Add parameters to all existing elements
        add_action('vc_after_init', array($this, 'add_geo_params_to_all_elements'));
        
        // Filter element output
        add_filter('vc_shortcode_output', array($this, 'filter_element_output'), 10, 3);
    }
    
    /**
     * Check if WPBakery is active
     */
    public function wpbakery_check() {
        if (!defined('WPB_VC_VERSION')) {
            ?>
            <div class="notice notice-warning">
                <p><strong>WPBakery Geo Control:</strong> This plugin requires WPBakery Page Builder to be installed and activated.</p>
            </div>
            <?php
        }
    }
    
    /**
     * Add geo parameters to all WPBakery elements
     */
    public function add_geo_params_to_all_elements() {
        if (!function_exists('vc_map')) {
            return;
        }
        
        // Get all registered shortcodes
        $shortcodes = WPBMap::getAllShortCodes();
        
        if (empty($shortcodes)) {
            return;
        }
        
        // Parameters to add
        $params = array(
            array(
                'type' => 'textfield',
                'heading' => 'Show for Countries',
                'param_name' => 'geo_show_countries',
                'description' => 'Show this element ONLY for these countries. Enter country codes separated by commas (e.g., AU,NZ,SG). Leave empty to show for all countries.',
                'group' => 'Geo Targeting',
            ),
            array(
                'type' => 'textfield',
                'heading' => 'Hide for Countries',
                'param_name' => 'geo_hide_countries',
                'description' => 'Hide this element for these countries. Enter country codes separated by commas (e.g., US,GB). Leave empty to not hide for any countries.',
                'group' => 'Geo Targeting',
            ),
            array(
                'type' => 'dropdown',
                'heading' => 'Geo Targeting Mode',
                'param_name' => 'geo_mode',
                'value' => array(
                    'Show/Hide (Default)' => 'default',
                    'Show Only' => 'show_only',
                    'Hide Only' => 'hide_only',
                ),
                'std' => 'default',
                'description' => 'Default: Both fields work together. Show Only: Only "Show for Countries" is used. Hide Only: Only "Hide for Countries" is used.',
                'group' => 'Geo Targeting',
            ),
        );
        
        // Add parameters to each shortcode
        foreach ($shortcodes as $tag => $shortcode) {
            if (function_exists('vc_add_params')) {
                vc_add_params($tag, $params);
            }
        }
    }
    
    /**
     * Filter element output based on geo targeting
     */
    public function filter_element_output($output, $obj, $attr) {
        // Check if geo targeting is set
        $show_countries = isset($attr['geo_show_countries']) ? $attr['geo_show_countries'] : '';
        $hide_countries = isset($attr['geo_hide_countries']) ? $attr['geo_hide_countries'] : '';
        $geo_mode = isset($attr['geo_mode']) ? $attr['geo_mode'] : 'default';
        
        // If no geo targeting is set, return original output
        if (empty($show_countries) && empty($hide_countries)) {
            return $output;
        }
        
        // Get visitor country
        $visitor_country = $this->get_visitor_country();
        
        // Process based on mode
        switch ($geo_mode) {
            case 'show_only':
                // Only use show countries
                if (!empty($show_countries)) {
                    $allowed_countries = array_map('trim', array_map('strtoupper', explode(',', $show_countries)));
                    if (!in_array($visitor_country, $allowed_countries)) {
                        return ''; // Hide element
                    }
                }
                break;
                
            case 'hide_only':
                // Only use hide countries
                if (!empty($hide_countries)) {
                    $blocked_countries = array_map('trim', array_map('strtoupper', explode(',', $hide_countries)));
                    if (in_array($visitor_country, $blocked_countries)) {
                        return ''; // Hide element
                    }
                }
                break;
                
            case 'default':
            default:
                // Check show countries first
                if (!empty($show_countries)) {
                    $allowed_countries = array_map('trim', array_map('strtoupper', explode(',', $show_countries)));
                    if (!in_array($visitor_country, $allowed_countries)) {
                        return ''; // Hide element
                    }
                }
                
                // Then check hide countries
                if (!empty($hide_countries)) {
                    $blocked_countries = array_map('trim', array_map('strtoupper', explode(',', $hide_countries)));
                    if (in_array($visitor_country, $blocked_countries)) {
                        return ''; // Hide element
                    }
                }
                break;
        }
        
        return $output;
    }
    
    /**
     * Get visitor's country code
     */
    public function get_visitor_country() {
        // Check if we have a cached country code
        $transient_key = 'wgc_country_' . $this->get_visitor_ip();
        $cached_country = get_transient($transient_key);
        
        if ($cached_country !== false) {
            return $cached_country;
        }
        
        // Get IP address
        $ip = $this->get_visitor_ip();
        
        // Check for local/private IP
        if ($this->is_local_ip($ip)) {
            // Use default country from settings or 'AU'
            $country = get_option('wgc_default_country', 'AU');
            set_transient($transient_key, $country, $this->cache_duration);
            return $country;
        }
        
        // Get geolocation service
        $service = get_option('wgc_geo_service', 'ip-api');
        
        $country = false;
        
        switch ($service) {
            case 'ip-api':
                $country = $this->get_country_from_ip_api($ip);
                break;
            case 'ipapi':
                $country = $this->get_country_from_ipapi($ip);
                break;
            case 'ipinfo':
                $country = $this->get_country_from_ipinfo($ip);
                break;
        }
        
        // Fallback to default if API fails
        if (!$country) {
            $country = get_option('wgc_default_country', 'AU');
        }
        
        // Cache the result
        set_transient($transient_key, $country, $this->cache_duration);
        
        return $country;
    }
    
    /**
     * Get visitor IP address
     */
    private function get_visitor_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return $ip;
    }
    
    /**
     * Check if IP is local/private
     */
    private function is_local_ip($ip) {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get country from ip-api.com (free, no key required)
     */
    private function get_country_from_ip_api($ip) {
        $response = wp_remote_get("http://ip-api.com/json/{$ip}?fields=countryCode", array('timeout' => 5));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['countryCode']) ? $data['countryCode'] : false;
    }
    
    /**
     * Get country from ipapi.co (free tier available)
     */
    private function get_country_from_ipapi($ip) {
        $response = wp_remote_get("https://ipapi.co/{$ip}/country/", array('timeout' => 5));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $country = wp_remote_retrieve_body($response);
        
        return !empty($country) ? trim($country) : false;
    }
    
    /**
     * Get country from ipinfo.io (free tier available)
     */
    private function get_country_from_ipinfo($ip) {
        $response = wp_remote_get("https://ipinfo.io/{$ip}/country", array('timeout' => 5));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $country = wp_remote_retrieve_body($response);
        
        return !empty($country) ? trim($country) : false;
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'WPBakery Geo Control Settings',
            'WPBakery Geo Control',
            'manage_options',
            'wpbakery-geo-control',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wgc_settings', 'wgc_geo_service');
        register_setting('wgc_settings', 'wgc_default_country');
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>WPBakery Geo Control Settings</h1>
            
            <?php if (!defined('WPB_VC_VERSION')) : ?>
                <div class="notice notice-error">
                    <p><strong>WPBakery Page Builder is not active!</strong> This plugin requires WPBakery Page Builder to function.</p>
                </div>
            <?php else : ?>
                <div class="notice notice-success">
                    <p><strong>WPBakery Page Builder detected!</strong> Version: <?php echo WPB_VC_VERSION; ?></p>
                </div>
            <?php endif; ?>
            
            <div class="notice notice-info">
                <p><strong>Current Visitor Country:</strong> <?php echo esc_html($this->get_visitor_country()); ?></p>
                <p><strong>Current IP:</strong> <?php echo esc_html($this->get_visitor_ip()); ?></p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('wgc_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Geolocation Service</th>
                        <td>
                            <?php $service = get_option('wgc_geo_service', 'ip-api'); ?>
                            <select name="wgc_geo_service">
                                <option value="ip-api" <?php selected($service, 'ip-api'); ?>>ip-api.com (Free, No Key Required)</option>
                                <option value="ipapi" <?php selected($service, 'ipapi'); ?>>ipapi.co (Free Tier)</option>
                                <option value="ipinfo" <?php selected($service, 'ipinfo'); ?>>ipinfo.io (Free Tier)</option>
                            </select>
                            <p class="description">Choose which geolocation service to use.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Default Country</th>
                        <td>
                            <input type="text" name="wgc_default_country" value="<?php echo esc_attr(get_option('wgc_default_country', 'AU')); ?>" maxlength="2" style="width: 60px; text-transform: uppercase;">
                            <p class="description">Default country code (2 letters, e.g., AU) used for local/development environments.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>How to Use</h2>
            
            <p>When editing any WPBakery element, you'll now see a new tab called <strong>"Geo Targeting"</strong> with the following options:</p>
            
            <h3>1. Show for Countries</h3>
            <p>Enter country codes (comma-separated) to show the element ONLY for visitors from those countries.</p>
            <p><strong>Example:</strong> <code>AU,NZ,SG</code> - Element only shows for Australia, New Zealand, and Singapore visitors.</p>
            
            <h3>2. Hide for Countries</h3>
            <p>Enter country codes (comma-separated) to hide the element for visitors from those countries.</p>
            <p><strong>Example:</strong> <code>US,GB</code> - Element is hidden for US and UK visitors.</p>
            
            <h3>3. Geo Targeting Mode</h3>
            <ul>
                <li><strong>Show/Hide (Default):</strong> Both "Show" and "Hide" fields work together</li>
                <li><strong>Show Only:</strong> Only "Show for Countries" is used (ignore "Hide" field)</li>
                <li><strong>Hide Only:</strong> Only "Hide for Countries" is used (ignore "Show" field)</li>
            </ul>
            
            <h3>Common Country Codes</h3>
            <ul style="columns: 3;">
                <li><strong>AU</strong> - Australia</li>
                <li><strong>NZ</strong> - New Zealand</li>
                <li><strong>SG</strong> - Singapore</li>
                <li><strong>MY</strong> - Malaysia</li>
                <li><strong>ID</strong> - Indonesia</li>
                <li><strong>TH</strong> - Thailand</li>
                <li><strong>US</strong> - United States</li>
                <li><strong>GB</strong> - United Kingdom</li>
                <li><strong>CA</strong> - Canada</li>
                <li><strong>JP</strong> - Japan</li>
                <li><strong>CN</strong> - China</li>
                <li><strong>IN</strong> - India</li>
                <li><strong>DE</strong> - Germany</li>
                <li><strong>FR</strong> - France</li>
                <li><strong>ES</strong> - Spain</li>
            </ul>
            <p><a href="https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2" target="_blank">See full list of country codes →</a></p>
            
            <h3>Use Cases</h3>
            
            <h4>Example 1: Region-Specific Pricing Tables</h4>
            <p>Create multiple pricing tables and show different ones based on visitor location:</p>
            <ul>
                <li>Pricing Table 1: Show for Countries = <code>AU,NZ</code></li>
                <li>Pricing Table 2: Show for Countries = <code>SG,MY,ID,TH</code></li>
                <li>Pricing Table 3: Show for Countries = <code>US,CA</code></li>
            </ul>
            
            <h4>Example 2: Compliance Messages</h4>
            <p>Show GDPR notice only for European visitors:</p>
            <ul>
                <li>Text Block: Show for Countries = <code>GB,DE,FR,ES,IT</code></li>
            </ul>
            
            <h4>Example 3: Regional Contact Information</h4>
            <p>Show different contact details based on location:</p>
            <ul>
                <li>Contact Section 1: Show for Countries = <code>AU</code></li>
                <li>Contact Section 2: Show for Countries = <code>SG</code></li>
                <li>Contact Section 3: Show for Countries = <code>NZ</code></li>
            </ul>
            
            <h3>Clear Cache</h3>
            <p>Country detection results are cached for 24 hours. To clear the cache:</p>
            <button type="button" class="button" onclick="if(confirm('Clear all geo-location cache?')) { location.href='<?php echo admin_url('options-general.php?page=wpbakery-geo-control&clear_cache=1'); ?>'; }">Clear Cache</button>
            
            <?php
            if (isset($_GET['clear_cache']) && $_GET['clear_cache'] == '1') {
                global $wpdb;
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wgc_country_%' OR option_name LIKE '_transient_timeout_wgc_country_%'");
                echo '<div class="notice notice-success"><p>Cache cleared successfully!</p></div>';
            }
            ?>
        </div>
        <?php
    }
}

// Initialize the plugin
WPBakery_Geo_Control::getInstance();
