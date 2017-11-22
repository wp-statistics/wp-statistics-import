<?php

namespace {
	/**
	 * Class WP_Statistics
	 *
	 * This is the primary class for WP Statistics recording hits on the WordPress site.
	 * It is extended by the Hits class and the GEO_IP_Hits class.
	 * This class handles; visits, visitors and pages.
	 */
	class WP_Statistics {

		// Setup our protected, private and public variables.
		/**
		 * IP address of visitor
		 *
		 * @var bool|string
		 */
		public $ip = false;
		/**
		 * Hash of visitors IP address
		 *
		 * @var bool|string
		 */
		public $ip_hash = false;
		/**
		 * Agent of visitor browser
		 *
		 * @var string
		 */
		public $agent;
		/**
		 * a coefficient to record number of visits
		 *
		 * @var int
		 */
		public $coefficient = 1;
		/**
		 * Visitor User ID
		 *
		 * @var int
		 */
		public $user_id = 0;
		/**
		 * Plugin options (Recorded in database)
		 *
		 * @var array
		 */
		public $options = array();
		/**
		 * User Options
		 *
		 * @var array
		 */
		public $user_options = array();
		/**
		 * Menu Slugs
		 *
		 * @var array
		 */
		public $menu_slugs = array();

		/**
		 * Result of queries
		 *
		 * @var
		 */
		private $result;
		/**
		 * Historical data
		 *
		 * @var array
		 */
		private $historical = array();
		/**
		 * is user options loaded?
		 *
		 * @var bool
		 */
		private $user_options_loaded = false;
		/**
		 * Current request is feed?
		 *
		 * @var bool
		 */
		private $is_feed = false;
		/**
		 * Timezone offset
		 *
		 * @var int|mixed|void
		 */
		private $tz_offset = 0;
		/**
		 * Country Codes
		 *
		 * @var bool|string
		 */
		private $country_codes = false;
		/**
		 * Referrer
		 *
		 * @var bool
		 */
		private $referrer = false;

		/**
		 * Installed Version
		 *
		 * @var string
		 */
		public static $installed_version;
		/**
		 * Registry for plugin settings
		 *
		 * @var array
		 */
		public static $reg = array();
		/**
		 * Pages slugs
		 *
		 * @var array
		 */
		public static $page = array();

		/**
		 * __construct
		 * WP_Statistics constructor.
		 */
		public function __construct() {

			if ( ! isset( WP_Statistics::$reg['plugin-url'] ) ) {
				/**
				 * Plugin URL
				 */
				WP_Statistics::$reg['plugin-url'] = plugin_dir_url(WP_STATISTICS_MAIN_FILE);
				//define('WP_STATISTICS_PLUGIN_URL', plugin_dir_url(WP_STATISTICS_MAIN_FILE));
				/**
				 * Plugin DIR
				 */
				WP_Statistics::$reg['plugin-dir'] = plugin_dir_path(WP_STATISTICS_MAIN_FILE);
				//define('WP_STATISTICS_PLUGIN_DIR', plugin_dir_path(WP_STATISTICS_MAIN_FILE));
				/**
				 * Plugin Main File
				 */
				WP_Statistics::$reg['main-file'] = WP_STATISTICS_MAIN_FILE;
				/**
				 * WP Statistics Version
				 */

				if ( ! function_exists('get_plugin_data') ) {
					require( ABSPATH . 'wp-admin/includes/plugin.php' );
				}
				WP_Statistics::$reg['plugin-data'] = get_plugin_data(WP_STATISTICS_MAIN_FILE);
				WP_Statistics::$reg['version']     = WP_Statistics::$reg['plugin-data']['Version'];
				//define('WP_STATISTICS_VERSION', '12.1.3');

			}

			if ( get_option('timezone_string') ) {
				$this->tz_offset = timezone_offset_get(
					timezone_open(get_option('timezone_string')),
					new DateTime()
				);
			} elseif ( get_option('gmt_offset') ) {
				$this->tz_offset = get_option('gmt_offset') * 60 * 60;
			}

			$this->load_options();

			// Set the default co-efficient.
			$this->coefficient = $this->get_option('coefficient', 1);
			// Double check the co-efficient setting to make sure it's not been set to 0.
			if ( $this->coefficient <= 0 ) {
				$this->coefficient = 1;
			}

			$this->get_IP();

			if ( $this->get_option('hash_ips') == true ) {
				$this->ip_hash = '#hash#' . sha1($this->ip . $_SERVER['HTTP_USER_AGENT']);
			}

		}

		/**
		 * Run when plugin loads
		 */
		public function run() {
			global $WP_Statistics;
			// Autoload composer
			require( WP_Statistics::$reg['plugin-dir'] . 'includes/vendor/autoload.php' );

			// define an autoload method to automatically load classes in /includes/classes
			spl_autoload_register(array( $this, 'autoload' ));

			// Add init actions.
			// For the main init we're going to set our priority to 9 to execute before most plugins
			// so we can export data before and set the headers without
			// worrying about bugs in other plugins that output text and don't allow us to set the headers.
			add_action('init', array( $this, 'init' ), 9);

			// Load the rest of the required files for our global functions,
			// online user tracking and hit tracking.
			if ( ! function_exists('wp_statistics_useronline') ) {
				include WP_Statistics::$reg['plugin-dir'] . 'includes/functions/functions.php';
			}

			$this->agent = $this->get_UserAgent();

			$WP_Statistics = $this;

			add_action('wp_ajax_wp_statistics_log_visit', 'WP_Statistics::log_visit');
			add_action('wp_ajax_nopriv_wp_statistics_log_visit', 'WP_Statistics::log_visit');

			if ( is_admin() ) {
				// JUST ADMIN AREA
				new \WP_Statistics_Admin;
			} else {
				add_action('wp_footer', 'WP_Statistics::add_ajax_script', 1000);
				// JUST FRONTEND AREA
				//new \WP_Statistics_Frontend;
			}

			add_action('widgets_init', 'WP_Statistics::widget');
			add_shortcode('wpstatistics', 'WP_Statistics_Shortcode::shortcodes');

		}

		static function add_ajax_script() {
			$nonce    = wp_create_nonce('wp-statistics-ajax-nonce');
			$ajax_url = admin_url('admin-ajax.php');
			?>
			<script>
				jQuery(document).ready(function ($) {
					var data = {
						'action': 'wp_statistics_log_visit',
						'nonce': '<?php echo $nonce;?>',
					};
					jQuery.post('<?php echo $ajax_url; ?>', data, function (response) {
						alert('Got this from the server: ' + response);
					});
				});
			</script>
			<?php
		}

		static function log_visit() {
			check_ajax_referer('wp-statistics-ajax-nonce', 'nonce');
			new \WP_Statistics_Frontend;
			wp_die();
		}

		/**
		 * Autoload classes of the plugin
		 *
		 * @param string $class Class name
		 */
		public function autoload( $class ) {
			if ( ! class_exists($class) && // This check is for performance of loading plugin classes
			     substr($class, 0, 14) === 'WP_Statistics_'
			) {
				$lower_class_name = str_replace('_', '-', strtolower($class));
				$class_full_path  = WP_Statistics::$reg['plugin-dir'] .
				                    'includes/classes/class-' .
				                    $lower_class_name .
				                    '.php';
				if ( file_exists($class_full_path) ) {
					require $class_full_path;
				}
			}
		}

		/**
		 * Loads the init code.
		 */
		public function init() {
			load_plugin_textdomain('wp-statistics', false, WP_Statistics::$reg['plugin-dir'] . 'languages');
		}

		/**
		 * loads the options from WordPress,
		 */
		public function load_options() {
			$this->options = get_option('wp_statistics');

			if ( ! is_array($this->options) ) {
				$this->user_options = array();
			}
		}

		/**
		 * Registers Widget
		 */
		static function widget() {
			register_widget('WP_Statistics_Widget');
		}

		/**
		 * Loads the user options from WordPress.
		 * It is NOT called during the class constructor.
		 *
		 * @param bool|false $force
		 */
		public function load_user_options( $force = false ) {
			if ( $this->user_options_loaded == true && $force != true ) {
				return;
			}

			if ( $this->user_id == 0 ) {
				$this->user_id = get_current_user_id();
			}

			// Not sure why, but get_user_meta() is returning an array or array's unless $single is set to true.
			$this->user_options = get_user_meta($this->user_id, 'wp_statistics', true);

			if ( ! is_array($this->user_options) ) {
				$this->user_options = array();
			}

			$this->user_options_loaded = true;
		}

		/**
		 * mimics WordPress's get_option() function but uses the array instead of individual options.
		 *
		 * @param      $option
		 * @param null $default
		 *
		 * @return bool|null
		 */
		public function get_option( $option, $default = null ) {
			// If no options array exists, return FALSE.
			if ( ! is_array($this->options) ) {
				return false;
			}

			// if the option isn't set yet, return the $default if it exists, otherwise FALSE.
			if ( ! array_key_exists($option, $this->options) ) {
				if ( isset( $default ) ) {
					return $default;
				} else {
					return false;
				}
			}

			// Return the option.
			return $this->options[ $option ];
		}

		/**
		 * mimics WordPress's get_user_meta() function
		 * But uses the array instead of individual options.
		 *
		 * @param      $option
		 * @param null $default
		 *
		 * @return bool|null
		 */
		public function get_user_option( $option, $default = null ) {
			// If the user id has not been set or no options array exists, return FALSE.
			if ( $this->user_id == 0 ) {
				return false;
			}
			if ( ! is_array($this->user_options) ) {
				return false;
			}

			// if the option isn't set yet, return the $default if it exists, otherwise FALSE.
			if ( ! array_key_exists($option, $this->user_options) ) {
				if ( isset( $default ) ) {
					return $default;
				} else {
					return false;
				}
			}

			// Return the option.
			return $this->user_options[ $option ];
		}

		/**
		 * Mimics WordPress's update_option() function
		 * But uses the array instead of individual options.
		 *
		 * @param $option
		 * @param $value
		 */
		public function update_option( $option, $value ) {
			// Store the value in the array.
			$this->options[ $option ] = $value;

			// Write the array to the database.
			update_option('wp_statistics', $this->options);
		}

		/**
		 * Mimics WordPress's update_user_meta() function
		 * But uses the array instead of individual options.
		 *
		 * @param $option
		 * @param $value
		 *
		 * @return bool
		 */
		public function update_user_option( $option, $value ) {
			// If the user id has not been set return FALSE.
			if ( $this->user_id == 0 ) {
				return false;
			}

			// Store the value in the array.
			$this->user_options[ $option ] = $value;

			// Write the array to the database.
			update_user_meta($this->user_id, 'wp_statistics', $this->user_options);
		}

		/**
		 * This function is similar to update_option,
		 * but it only stores the option in the array.
		 * This save some writing to the database if you have multiple values to update.
		 *
		 * @param $option
		 * @param $value
		 */
		public function store_option( $option, $value ) {
			$this->options[ $option ] = $value;
		}

		/**
		 * This function is similar to update_user_option,
		 * but it only stores the option in the array.
		 * This save some writing to the database if you have multiple values to update.
		 *
		 * @param $option
		 * @param $value
		 *
		 * @return bool
		 */
		public function store_user_option( $option, $value ) {
			// If the user id has not been set return FALSE.
			if ( $this->user_id == 0 ) {
				return false;
			}

			$this->user_options[ $option ] = $value;
		}

		/**
		 * Saves the current options array to the database.
		 */
		public function save_options() {
			update_option('wp_statistics', $this->options);
		}

		/**
		 * Saves the current user options array to the database.
		 *
		 * @return bool
		 */
		public function save_user_options() {
			if ( $this->user_id == 0 ) {
				return false;
			}

			update_user_meta($this->user_id, 'wp_statistics', $this->user_options);
		}

		/**
		 * Check to see if an option is currently set or not.
		 *
		 * @param $option
		 *
		 * @return bool
		 */
		public function isset_option( $option ) {
			if ( ! is_array($this->options) ) {
				return false;
			}

			return array_key_exists($option, $this->options);
		}

		/**
		 * check to see if a user option is currently set or not.
		 *
		 * @param $option
		 *
		 * @return bool
		 */
		public function isset_user_option( $option ) {
			if ( $this->user_id == 0 ) {
				return false;
			}
			if ( ! is_array($this->user_options) ) {
				return false;
			}

			return array_key_exists($option, $this->user_options);
		}

		/**
		 * During installation of WP Statistics some initial data needs to be loaded
		 * in to the database so errors are not displayed.
		 * This function will add some initial data if the tables are empty.
		 */
		public function Primary_Values() {
			global $wpdb;

			$this->result = $wpdb->query("SELECT * FROM {$wpdb->prefix}statistics_useronline");

			if ( ! $this->result ) {

				$wpdb->insert(
					$wpdb->prefix . "statistics_useronline",
					array(
						'ip'        => $this->get_IP(),
						'timestamp' => $this->Current_Date('U'),
						'date'      => $this->Current_Date(),
						'referred'  => $this->get_Referred(),
						'agent'     => $this->agent['browser'],
						'platform'  => $this->agent['platform'],
						'version'   => $this->agent['version'],
					)
				);
			}

			$this->result = $wpdb->query("SELECT * FROM {$wpdb->prefix}statistics_visit");

			if ( ! $this->result ) {

				$wpdb->insert(
					$wpdb->prefix . "statistics_visit",
					array(
						'last_visit'   => $this->Current_Date(),
						'last_counter' => $this->Current_date('Y-m-d'),
						'visit'        => 1,
					)
				);
			}

			$this->result = $wpdb->query("SELECT * FROM {$wpdb->prefix}statistics_visitor");

			if ( ! $this->result ) {

				$wpdb->insert(
					$wpdb->prefix . "statistics_visitor",
					array(
						'last_counter' => $this->Current_date('Y-m-d'),
						'referred'     => $this->get_Referred(),
						'agent'        => $this->agent['browser'],
						'platform'     => $this->agent['platform'],
						'version'      => $this->agent['version'],
						'ip'           => $this->get_IP(),
						'location'     => '000',
					)
				);
			}
		}

		/**
		 * During installation of WP Statistics some initial options need to be set.
		 * This function will save a set of default options for the plugin.
		 *
		 * @return array
		 */
		public function Default_Options() {
			$options = array();

			if ( ! isset( $wps_robotarray ) ) {
				// Get the robots list, we'll use this for both upgrades and new installs.
				include( WP_Statistics::$reg['plugin-dir'] . 'includes/robotslist.php' );
			}

			$options['robotlist'] = trim($wps_robotslist);

			// By default, on new installs, use the new search table.
			$options['search_converted'] = 1;

			// If this is a first time install or an upgrade and we've added options, set some intelligent defaults.
			$options['geoip']                 = false;
			$options['browscap']              = false;
			$options['useronline']            = true;
			$options['visits']                = true;
			$options['visitors']              = true;
			$options['pages']                 = true;
			$options['check_online']          = '30';
			$options['menu_bar']              = false;
			$options['coefficient']           = '1';
			$options['stats_report']          = false;
			$options['time_report']           = 'daily';
			$options['send_report']           = 'mail';
			$options['content_report']        = '';
			$options['update_geoip']          = true;
			$options['store_ua']              = false;
			$options['robotlist']             = $wps_robotslist;
			$options['exclude_administrator'] = true;
			$options['disable_se_clearch']    = true;
			$options['disable_se_ask']        = true;
			$options['map_type']              = 'jqvmap';

			$options['force_robot_update'] = true;

			return $options;
		}

		/**
		 * Processes a string that represents an IP address and returns
		 * either FALSE if it's invalid or a valid IP4 address.
		 *
		 * @param $ip
		 *
		 * @return bool|string
		 */
		private function get_ip_value( $ip ) {
			// Reject anything that's not a string.
			if ( ! is_string($ip) ) {
				return false;
			}

			// Trim off any spaces.
			$ip = trim($ip);

			// Process IPv4 and v6 addresses separately.
			if ( $this->isValidIPv6($ip) ) {
				// Reject any IPv6 addresses if IPv6 is not compiled in to this version of PHP.
				if ( ! defined('AF_INET6') ) {
					return false;
				}
			} else {
				// Trim off any port values that exist.
				if ( strstr($ip, ':') !== false ) {
					$temp = explode(':', $ip);
					$ip   = $temp[0];
				}

				// Check to make sure the http header is actually an IP address and not some kind of SQL injection attack.
				$long = ip2long($ip);

				// ip2long returns either -1 or FALSE if it is not a valid IP address depending on the PHP version, so check for both.
				if ( $long == -1 || $long === false ) {
					return false;
				}
			}

			// If the ip address is blank, reject it.
			if ( $ip == '' ) {
				return false;
			}

			// We're got a real IP address, return it.
			return $ip;

		}

		/**
		 * Returns the current IP address of the remote client.
		 *
		 * @return bool|string
		 */
		public function get_IP() {

			// Check to see if we've already retrieved the IP address and if so return the last result.
			if ( $this->ip !== false ) {
				return $this->ip;
			}

			// By default we use the remote address the server has.
			if ( array_key_exists('REMOTE_ADDR', $_SERVER) ) {
				$temp_ip = $this->get_ip_value($_SERVER['REMOTE_ADDR']);
			} else {
				$temp_ip = '127.0.0.1';
			}

			if ( false !== $temp_ip ) {
				$this->ip = $temp_ip;
			}

			/* Check to see if any of the HTTP headers are set to identify the remote user.
			 * These often give better results as they can identify the remote user even through firewalls etc,
			 * but are sometimes used in SQL injection attacks.
			 *
			 * We only want to take the first one we find, so search them in order and break when we find the first
			 * one.
			 *
			 */
			$envs = array(
				'HTTP_CLIENT_IP',
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_FORWARDED',
				'HTTP_FORWARDED_FOR',
				'HTTP_FORWARDED',
			);

			foreach ( $envs as $env ) {
				$temp_ip = $this->get_ip_value(getenv($env));

				if ( false !== $temp_ip ) {
					$this->ip = $temp_ip;

					break;
				}
			}

			// If no valid ip address has been found, use 127.0.0.1 (aka localhost).
			if ( false === $this->ip ) {
				$this->ip = '127.0.0.1';
			}

			return $this->ip;
		}

		/**
		 * Validate an IPv6 IP address
		 *
		 * @param  string $ip
		 *
		 * @return boolean - true/false
		 */
		private function isValidIPv6( $ip ) {
			if ( false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ) {
				return false;
			} else {
				return true;
			}
		}

		/**
		 * Calls the user agent parsing code.
		 *
		 * @return array|\string[]
		 */
		public function get_UserAgent() {

			// Parse the agent stirng.
			try {
				$agent = parse_user_agent();
			} catch ( Exception $e ) {
				$agent = array(
					'browser'  => _x('Unknown', 'Browser', 'wp-statistics'),
					'platform' => _x('Unknown', 'Platform', 'wp-statistics'),
					'version'  => _x('Unknown', 'Version', 'wp-statistics'),
				);
			}

			// null isn't a very good default, so set it to Unknown instead.
			if ( $agent['browser'] == null ) {
				$agent['browser'] = _x('Unknown', 'Browser', 'wp-statistics');
			}
			if ( $agent['platform'] == null ) {
				$agent['platform'] = _x('Unknown', 'Platform', 'wp-statistics');
			}
			if ( $agent['version'] == null ) {
				$agent['version'] = _x('Unknown', 'Version', 'wp-statistics');
			}

			// Uncommon browsers often have some extra cruft, like brackets, http:// and other strings that we can strip out.
			$strip_strings = array( '"', "'", '(', ')', ';', ':', '/', '[', ']', '{', '}', 'http' );
			foreach ( $agent as $key => $value ) {
				$agent[ $key ] = str_replace($strip_strings, '', $agent[ $key ]);
			}

			return $agent;
		}

		/**
		 * return the referrer link for the current user.
		 *
		 * @param bool|false $default_referrer
		 *
		 * @return array|bool|string|void
		 */
		public function get_Referred( $default_referrer = false ) {

			if ( $this->referrer !== false ) {
				return $this->referrer;
			}

			$this->referrer = '';

			if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
				$this->referrer = $_SERVER['HTTP_REFERER'];
			}
			if ( $default_referrer ) {
				$this->referrer = $default_referrer;
			}

			$this->referrer = esc_sql(strip_tags($this->referrer));

			if ( ! $this->referrer ) {
				$this->referrer = get_bloginfo('url');
			}

			if ( $this->get_option('addsearchwords', false) ) {
				// Check to see if this is a search engine referrer
				$SEInfo = $this->Search_Engine_Info($this->referrer);

				if ( is_array($SEInfo) ) {
					// If we're a known SE, check the query string
					if ( $SEInfo['tag'] != '' ) {
						$result = $this->Search_Engine_QueryString($this->referrer);

						// If there were no search words, let's add the page title
						if ( $result == '' || $result == 'No search query found!' ) {
							$result = wp_title('', false);
							if ( $result != '' ) {
								$this->referrer = esc_url(
									add_query_arg(
										$SEInfo['querykey'],
										urlencode('~"' . $result . '"'),
										$this->referrer
									)
								);
							}
						}
					}
				}
			}

			return $this->referrer;
		}

		/**
		 * Returns a date string in the desired format with a passed in timestamp.
		 *
		 * @param $format
		 * @param $timestamp
		 *
		 * @return bool|string
		 */
		public function Local_Date( $format, $timestamp ) {
			return date($format, $timestamp + $this->tz_offset);
		}

		// Returns a date string in the desired format.
		/**
		 * @param string $format
		 * @param null   $strtotime
		 * @param null   $relative
		 *
		 * @return bool|string
		 */
		public function Current_Date( $format = 'Y-m-d H:i:s', $strtotime = null, $relative = null ) {

			if ( $strtotime ) {
				if ( $relative ) {
					return date($format, strtotime("{$strtotime} day", $relative) + $this->tz_offset);
				} else {
					return date($format, strtotime("{$strtotime} day") + $this->tz_offset);
				}
			} else {
				return date($format, time() + $this->tz_offset);
			}
		}

		/**
		 * Returns a date string in the desired format.
		 *
		 * @param string $format
		 * @param null   $strtotime
		 * @param null   $relative
		 *
		 * @return bool|string
		 */
		public function Real_Current_Date( $format = 'Y-m-d H:i:s', $strtotime = null, $relative = null ) {

			if ( $strtotime ) {
				if ( $relative ) {
					return date($format, strtotime("{$strtotime} day", $relative));
				} else {
					return date($format, strtotime("{$strtotime} day"));
				}
			} else {
				return date($format, time());
			}
		}

		/**
		 * Returns an internationalized date string in the desired format.
		 *
		 * @param string $format
		 * @param null   $strtotime
		 * @param string $day
		 *
		 * @return string
		 */
		public function Current_Date_i18n( $format = 'Y-m-d H:i:s', $strtotime = null, $day = ' day' ) {

			if ( $strtotime ) {
				return date_i18n($format, strtotime("{$strtotime}{$day}") + $this->tz_offset);
			} else {
				return date_i18n($format, time() + $this->tz_offset);
			}
		}

		/**
		 * Adds the timezone offset to the given time string
		 *
		 * @param $timestring
		 *
		 * @return int
		 */
		public function strtotimetz( $timestring ) {
			return strtotime($timestring) + $this->tz_offset;
		}

		/**
		 * Adds current time to timezone offset
		 *
		 * @return int
		 */
		public function timetz() {
			return time() + $this->tz_offset;
		}

		/**
		 * Checks to see if a search engine exists in the current list of search engines.
		 *
		 * @param      $search_engine_name
		 * @param null $search_engine
		 *
		 * @return int
		 */
		public function Check_Search_Engines( $search_engine_name, $search_engine = null ) {

			if ( strstr($search_engine, $search_engine_name) ) {
				return 1;
			}
		}

		/**
		 * Returns an array of information about a given search engine based on the url passed in.
		 * It is used in several places to get the SE icon or the sql query
		 * To select an individual SE from the database.
		 *
		 * @param bool|false $url
		 *
		 * @return array|bool
		 */
		public function Search_Engine_Info( $url = false ) {

			// If no URL was passed in, get the current referrer for the session.
			if ( ! $url ) {
				$url = isset( $_SERVER['HTTP_REFERER'] ) ? $this->get_Referred() : false;
			}

			// If there is no URL and no referrer, always return false.
			if ( $url == false ) {
				return false;
			}

			// Parse the URL in to it's component parts.
			$parts = parse_url($url);

			// Get the list of search engines we currently support.
			$search_engines = wp_statistics_searchengine_list();

			// Loop through the SE list until we find which search engine matches.
			foreach ( $search_engines as $key => $value ) {
				$search_regex = wp_statistics_searchengine_regex($key);

				preg_match('/' . $search_regex . '/', $parts['host'], $matches);

				if ( isset( $matches[1] ) ) {
					// Return the first matched SE.
					return $value;
				}
			}

			// If no SE matched, return some defaults.
			return array(
				'name'         => _x('Unknown', 'Search Engine', 'wp-statistics'),
				'tag'          => '',
				'sqlpattern'   => '',
				'regexpattern' => '',
				'querykey'     => 'q',
				'image'        => 'unknown.png',
			);
		}

		/**
		 * Returns an array of information about a given search engine based on the url passed in.
		 * It is used in several places to get the SE icon or the sql query
		 * to select an individual SE from the database.
		 *
		 * @param bool|false $engine
		 *
		 * @return array|bool
		 */
		public function Search_Engine_Info_By_Engine( $engine = false ) {

			// If there is no URL and no referrer, always return false.
			if ( $engine == false ) {
				return false;
			}

			// Get the list of search engines we currently support.
			$search_engines = wp_statistics_searchengine_list();

			if ( array_key_exists($engine, $search_engines) ) {
				return $search_engines[ $engine ];
			}

			// If no SE matched, return some defaults.
			return array(
				'name'         => _x('Unknown', 'Search Engine', 'wp-statistics'),
				'tag'          => '',
				'sqlpattern'   => '',
				'regexpattern' => '',
				'querykey'     => 'q',
				'image'        => 'unknown.png',
			);
		}

		/**
		 * Parses a URL from a referrer and return the search query words used.
		 *
		 * @param bool|false $url
		 *
		 * @return bool|string
		 */
		public function Search_Engine_QueryString( $url = false ) {

			// If no URL was passed in, get the current referrer for the session.
			if ( ! $url ) {
				$url = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : false;
			}

			// If there is no URL and no referrer, always return false.
			if ( $url == false ) {
				return false;
			}

			// Parse the URL in to it's component parts.
			$parts = parse_url($url);

			// Check to see if there is a query component in the URL (everything after the ?).  If there isn't one
			// set an empty array so we don't get errors later.
			if ( array_key_exists('query', $parts) ) {
				parse_str($parts['query'], $query);
			} else {
				$query = array();
			}

			// Get the list of search engines we currently support.
			$search_engines = wp_statistics_searchengine_list();

			// Loop through the SE list until we find which search engine matches.
			foreach ( $search_engines as $key => $value ) {
				$search_regex = wp_statistics_searchengine_regex($key);

				preg_match('/' . $search_regex . '/', $parts['host'], $matches);

				if ( isset( $matches[1] ) ) {
					// Check to see if the query key the SE uses exists in the query part of the URL.
					if ( array_key_exists($search_engines[ $key ]['querykey'], $query) ) {
						$words = strip_tags($query[ $search_engines[ $key ]['querykey'] ]);
					} else {
						$words = '';
					}

					// If no words were found, return a pleasant default.
					if ( $words == '' ) {
						$words = 'No search query found!';
					}

					return $words;
				}
			}

			// We should never actually get to this point, but let's make sure we return something
			// just in case something goes terribly wrong.
			return 'No search query found!';
		}

		/**
		 * Get historical data
		 *
		 * @param        $type
		 * @param string $id
		 *
		 * @return int|null|string
		 */
		public function Get_Historical_Data( $type, $id = '' ) {
			global $wpdb;

			$count = 0;

			switch ( $type ) {
				case 'visitors':
					if ( array_key_exists('visitors', $this->historical) ) {
						return $this->historical['visitors'];
					} else {
						$result
							= $wpdb->get_var(
							"SELECT value FROM {$wpdb->prefix}statistics_historical WHERE category = 'visitors'"
						);
						if ( $result > $count ) {
							$count = $result;
						}
						$this->historical['visitors'] = $count;
					}

				break;
				case 'visits':
					if ( array_key_exists('visits', $this->historical) ) {
						return $this->historical['visits'];
					} else {
						$result
							= $wpdb->get_var(
							"SELECT value FROM {$wpdb->prefix}statistics_historical WHERE category = 'visits'"
						);
						if ( $result > $count ) {
							$count = $result;
						}
						$this->historical['visits'] = $count;
					}

				break;
				case 'uri':
					if ( array_key_exists($id, $this->historical) ) {
						return $this->historical[ $id ];
					} else {
						$result
							= $wpdb->get_var(
							$wpdb->prepare(
								"SELECT value FROM {$wpdb->prefix}statistics_historical WHERE category = 'uri' AND uri = %s",
								$id
							)
						);
						if ( $result > $count ) {
							$count = $result;
						}
						$this->historical[ $id ] = $count;
					}

				break;
				case 'page':
					if ( array_key_exists($id, $this->historical) ) {
						return $this->historical[ $id ];
					} else {
						$result
							= $wpdb->get_var(
							$wpdb->prepare(
								"SELECT value FROM {$wpdb->prefix}statistics_historical WHERE category = 'uri' AND page_id = %d",
								$id
							)
						);
						if ( $result > $count ) {
							$count = $result;
						}
						$this->historical[ $id ] = $count;
					}

				break;
			}

			return $count;
		}

		/**
		 * Get country codes
		 *
		 * @return array|bool|string
		 */
		public function get_country_codes() {
			if ( $this->country_codes == false ) {
				$ISOCountryCode = array();
				include( WP_Statistics::$reg['plugin-dir'] . "includes/functions/country-codes.php" );
				$this->country_codes = $ISOCountryCode;
			}

			return $this->country_codes;
		}

		/**
		 * Returns an array of site id's
		 *
		 * @return array
		 */
		public function get_wp_sites_list() {
			GLOBAL $wp_version;

			$site_list = array();

			// wp_get_sites() is deprecated in 4.6 or above and replaced with get_sites().
			if ( version_compare($wp_version, '4.6', '>=') ) {
				$sites = get_sites();

				foreach ( $sites as $site ) {
					$site_list[] = $site->blog_id;
				}
			} else {
				$sites = wp_get_sites();

				foreach ( $sites as $site ) {
					$site_list[] = $site['blog_id'];
				}
			}

			return $site_list;
		}

		/**
		 * Sanitizes the referrer
		 *
		 * @param     $referrer
		 * @param int $length
		 *
		 * @return string
		 */
		public function html_sanitize_referrer( $referrer, $length = -1 ) {
			$referrer = trim($referrer);

			if ( 'data:' == strtolower(substr($referrer, 0, 5)) ) {
				$referrer = 'http://127.0.0.1';
			}

			if ( 'javascript:' == strtolower(substr($referrer, 0, 11)) ) {
				$referrer = 'http://127.0.0.1';
			}

			if ( $length > 0 ) {
				$referrer = substr($referrer, 0, $length);
			}

			return htmlentities($referrer, ENT_QUOTES);
		}

		/**
		 * Get referrer link
		 *
		 * @param     $referrer
		 * @param int $length
		 *
		 * @return string
		 */
		public function get_referrer_link( $referrer, $length = -1 ) {
			$html_referrer = $this->html_sanitize_referrer($referrer);

			if ( $length > 0 && strlen($referrer) > $length ) {
				$html_referrer_limited = $this->html_sanitize_referrer($referrer, $length);
				$eplises               = '[...]';
			} else {
				$html_referrer_limited = $html_referrer;
				$eplises               = '';
			}

			return "<a href='{$html_referrer}'><div class='dashicons dashicons-admin-links'></div>{$html_referrer_limited}{$eplises}</a>";
		}

	}
}