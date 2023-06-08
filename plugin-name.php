<?php

/**
 *
 * The plugin bootstrap file
 *
 * This file is responsible for starting the plugin using the main plugin class file.
 *
 * @since 0.0.1
 * @package Plugin_Name
 *
 * @wordpress-plugin
 * Plugin Name:     Plugin Name
 * Description:     This is a practice Test plugin created by Fainyer Montezuma
 * Version:         0.0.1
 * Author:          Fainyer Montezuma
 * Author URI:      https://github.com/Fainyer/
 * License:         GPL-2.0+
 * License URI:     http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:     plugin-name
 * Domain Path:     /lang
 */

if (!defined('ABSPATH')) {
	die('Direct access not permitted.');
}

if (!class_exists('plugin_name')) {

	/*
	 * main plugin_name class
	 *
	 * @class plugin_name
	 * @since 0.0.1
	 */





	class plugin_name
	{

		/*
		 * plugin_name plugin version
		 *
		 * @var string
		 */
		public $version = '4.7.5';

		/**
		 * The single instance of the class.
		 *
		 * @var plugin_name
		 * @since 0.0.1
		 */
		protected static $instance = null;

		/**
		 * Main plugin_name instance.
		 *
		 * @since 0.0.1
		 * @static
		 * @return plugin_name - main instance.
		 */
		public static function instance()
		{
			if (is_null(self::$instance)) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * plugin_name class constructor.
		 */
		public function __construct()
		{
			$this->load_plugin_textdomain();
			$this->define_constants();
			$this->includes();
			$this->define_actions();
			$this->define_menus();
			register_activation_hook(__FILE__, array($this, 'my_plugin_activation'));
			register_deactivation_hook(__FILE__, array($this, 'my_plugin_deactivation'));
		}

		public function load_plugin_textdomain()
		{
			load_plugin_textdomain('plugin-name', false, basename(dirname(__FILE__)) . '/lang/');
		}

		/**
		 * Include required core files
		 */
		public function includes()
		{
			// Load custom functions and hooks
			require_once __DIR__ . '/includes/includes.php';
		}

		/**
		 * Get the plugin path.
		 *
		 * @return string
		 */
		public function plugin_path()
		{
			return untrailingslashit(plugin_dir_path(__FILE__));
		}


		/**
		 * Define plugin_name constants
		 */
		private function define_constants()
		{
			define('PLUGIN_NAME_PLUGIN_FILE', __FILE__);
			define('PLUGIN_NAME_PLUGIN_BASENAME', plugin_basename(__FILE__));
			define('PLUGIN_NAME_VERSION', $this->version);
			define('PLUGIN_NAME_PATH', $this->plugin_path());
		}

		/**
		 * Define plugin_name actions
		 */
		public function define_actions()
		{
			add_action('wp_loaded', array($this, 'track_broken_links'));
		}

		/**
		 * Define plugin_name menus
		 */
		public function define_menus()
		{
			add_action('admin_menu', array($this, 'add_admin_menu'));
		}
		/**
		 * Create tables
		 */
		function my_plugin_activation()
		{
			global $wpdb;
			$charset_collate = $wpdb->get_charset_collate();
			$table_name = $wpdb->prefix . 'broken_link';
			$sql = 'CREATE TABLE IF NOT EXISTS ' . $table_name . ' (
				 `id` INT NOT NULL AUTO_INCREMENT,
				 `url` VARCHAR(200) NOT NULL,
				 `type` VARCHAR(200) NOT NULL,
				 `post_id` BIGINT(20) NOT NULL,
				 `last_checked` DATETIME DEFAULT NULL,
				 PRIMARY KEY (`id`))' . $charset_collate . ';';

			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($sql);
			update_option('checked_posts', array());
		}


		function my_plugin_deactivation()
		{
			global $wpdb;
			$table_name = $wpdb->prefix . 'broken_link';
			$wpdb->query("DROP TABLE IF EXISTS $table_name");
		}



		function track_broken_links()
		{
			global $wpdb;
			// Obtener todos los posts
			$posts = get_posts(array(
				'post_type' => 'post',  // Tipo de publicación a rastrear
				'post_status' => 'publish',  // Estado de la publicación
				'posts_per_page' => -1,  // Obtener todas las publicaciones
			));
			$checked_posts = get_option('checked_posts', array());

			// Recorrer los posts
			foreach ($posts as $post) {
				$post_id = $post->ID;
				if (in_array($post_id, $checked_posts)) {
					continue;
				}
				$post_content = $post->post_content;

				// Obtener todos los enlaces del contenido
				$pattern = '/<a\s[^>]*href=(\"??)([^\" >]*?)\\1[^>]*>(.*)<\/a>/siU';
				preg_match_all($pattern, $post_content, $matches);

				// Recorrer los enlaces encontrados
				foreach ($matches[2] as $url) {
					// Verificar si el enlace cumple alguna de las condiciones
					if ($this->is_insecure_link($url) || $this->is_unspecified_protocol($url) || $this->is_malformed_link($url) || $this->has_incorrect_status_code($url)) {
						// Registrar el enlace en la base de datos
						$table_name = $wpdb->prefix . 'broken_link';
						$wpdb->insert($table_name, array(
							'url' => $url,
							'type' => $this->get_error_type($url),
							'post_id' => $post_id,
							'last_checked' => current_time('mysql')
						));
					}
				}
				$checked_posts[] = $post_id;
				update_option('checked_posts', $checked_posts);

				// Revalidar los enlaces cada 4 días después de la primera detección
				$last_checked = $wpdb->get_var($wpdb->prepare("SELECT last_checked FROM $wpdb->prefix" . "broken_link WHERE post_id = %d", $post_id));
				if (!$last_checked || (strtotime($last_checked) <= strtotime('-1 days'))) {
					// Realizar la revalidación de los enlaces
					$broken_links = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->prefix" . "broken_link WHERE post_id = %d", $post_id));
					foreach ($broken_links as $broken_link) {
						$url = $broken_link->url;
						$is_fixed = $this->is_link_fixed($url); // Función para verificar si el enlace se ha solucionado

						// Actualizar el estado del enlace en la base de datos
						$wpdb->update($wpdb->prefix . 'broken_link', array('is_fixed' => $is_fixed), array('url' => $url));
					}

					// Actualizar la fecha de la última comprobación
					$wpdb->update($wpdb->prefix . 'broken_link', array('last_checked' => current_time('mysql')), array('post_id' => $post_id));
				}
			}
		}


		// Funciones auxiliares para verificar las condiciones de los enlaces
		function is_insecure_link($url)
		{
			return strpos($url, 'http://') === 0;
		}

		function is_unspecified_protocol($url)
		{
			return strpos($url, 'http://') === false && strpos($url, 'https://') === false;
		}

		function is_malformed_link($url)
		{
			return filter_var($url, FILTER_VALIDATE_URL) === false;
		}

		function has_incorrect_status_code($url)
		{
			$headers = get_headers($url);
			$status_code = substr($headers[0], 9, 3);
			return !in_array($status_code, array('200', '201', '202', '203', '204', '205', '206'));
		}

		function get_error_type($url)
		{
			if ($this->is_insecure_link($url)) {
				return 'Insecure Link';
			} elseif ($this->is_unspecified_protocol($url)) {
				return 'Unspecified Protocol';
			} elseif ($this->is_malformed_link($url)) {
				return 'Malformed Link';
			} elseif ($this->has_incorrect_status_code($url)) {
				return 'Incorrect Status Code';
			}
			return '';
		}


		/**
		 * Add menu pages
		 */
		public function add_admin_menu()
		{
			add_menu_page(
				__('Broken Links', 'plugin-name'), // Título de la página
				__('Broken Links', 'plugin-name'), // Nombre en el menú
				'manage_options', // Capacidad requerida para acceder a la página
				'broken-links', // Slug de la página
				array($this, 'render_admin_page'), // Función para renderizar la página
				'dashicons-admin-links
			);
			add_menu_page(
				'API Settings',
				'API Settings',
				'manage_options',
				'plugin-settings',
				array($this, 'plugin_settings_page_content'),
				'dashicons-admin-code'

			);
		}
		/**
		 * Render admin page content
		 */

		public function render_admin_page()
		{
			global $wpdb;
			$table_name = $wpdb->prefix . 'broken_link';

			// Obtener los registros agrupados de la base de datos
			$results = $wpdb->get_results("
				 SELECT url, type, GROUP_CONCAT(post_id SEPARATOR ',') AS post_ids, COUNT(*) AS count
				 FROM $table_name
				 GROUP BY url, type
			 ", ARRAY_A);
?>

			<div class="wrap">
				<h1><?php esc_html_e('Broken Links', 'plugin-name'); ?></h1>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e('URL', 'plugin-name'); ?></th>
							<th><?php esc_html_e('Estado', 'plugin-name'); ?></th>
							<th><?php esc_html_e('Enlaces a los Posts', 'plugin-name'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($results as $result) : ?>
							<?php
							$url = esc_html($result['url']);
							$type = esc_html($result['type']);
							$post_ids = explode(',', $result['post_ids']);
							$post_titles = array();
							// Obtener los títulos de las publicaciones asociadas al enlace
							foreach ($post_ids as $post_id) {
								$post_title = get_the_title($post_id);
								if ($post_title) {
									$post_titles[] = $post_title;
								}
							}
							$post_titles = array_unique($post_titles);
							?>
							<tr>
								<td><?php echo $url; ?></td>
								<td><?php echo $type; ?></td>
								<td>
									<?php foreach ($post_titles as $post_title) : ?>
										<?php
										$post_id = array_shift($post_ids);
										$post_edit_url = get_edit_post_link($post_id);
										if ($post_edit_url) {
											echo '<a href="' . esc_url($post_edit_url) . '">' . esc_html($post_title) . '</a>';
										} else {
											echo esc_html($post_title);
										}
										?>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

		<?php
		}

		/**
		 * Render admin page api content
		 */
		function plugin_settings_page_content()
		{
			// Verificar si se envió el formulario y actualizar la clave de autenticación
			if (isset($_POST['api_key'])) {
				$api_key = sanitize_text_field($_POST['api_key']);
				update_option('api_key', $api_key);
				echo '<div class="notice notice-success"><p>API key updated successfully.</p></div>';
			}
			$api_key = get_option('api_key');
		?>
			<div class="wrap">
				<h1>API Settings</h1>
				<form method="post" action="">
					<table class="form-table">
						<tr>
							<th scope="row">API Key</th>
							<td><input type="text" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text"></td>
						</tr>
					</table>
					<?php submit_button('Save Changes'); ?>
				</form>
			</div>
<?php
		}
	}

	$plugin_name = new plugin_name();
}
