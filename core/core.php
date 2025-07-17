<?php
/**
 * Simple function to replicate PHP 5 behaviour
 */
if ( ! function_exists( 'microtime_float' ) ) {
	function microtime_float() {
		list($usec, $sec) = explode( ' ', microtime() );
		return ( (float)$usec + (float)$sec);
	}
}

require BLC_DIRECTORY . '/includes/screen-options/screen-options.php';
require BLC_DIRECTORY . '/includes/screen-meta-links.php';
require BLC_DIRECTORY . '/includes/wp-mutex.php';
require BLC_DIRECTORY . '/includes/transactions-manager.php';

if (!class_exists('wsBrokenLinkChecker')) {

class wsBrokenLinkChecker {
	var $conf;
	var $loader;
	var $my_basename = '';

	var $db_version; 		//The required version of the plugin's DB schema.

	var $execution_start_time; 	//Used for a simple internal execution timer in start_timer()/execution_time()

	private $is_textdomain_loaded = false;

  /**
   * wsBrokenLinkChecker::wsBrokenLinkChecker()
   * Class constructor
   *
   * @param string $loader The fully qualified filename of the loader script that WP identifies as the "main" plugin file.
   * @param blcConfigurationManager $conf An instance of the configuration manager
   * @return void
   */
	function __construct ( $loader, $conf ) {
		$this->db_version = BLC_DATABASE_VERSION;

		$this->conf = $conf;
		$this->loader = $loader;
		$this->my_basename = plugin_basename( $this->loader );

		$this->load_language();

		//Unlike the activation hook, the deactivation callback *can* be registered in this file
		//because deactivation happens after this class has already been instantiated (durinng the
		//'init' action).
		register_deactivation_hook($loader, array($this, 'deactivation'));

		add_action('admin_menu', array($this,'admin_menu'));

		//Load jQuery on Dashboard pages (probably redundant as WP already does that)
		add_action('admin_print_scripts', array($this,'admin_print_scripts'));

		//The dashboard widget
		add_action('wp_dashboard_setup', array($this, 'hook_wp_dashboard_setup'));

		//AJAXy hooks
		add_action( 'wp_ajax_blc_full_status', array($this,'ajax_full_status') );
		add_action( 'wp_ajax_blc_dashboard_status', array($this,'ajax_dashboard_status') );
		add_action( 'wp_ajax_blc_work', array($this,'ajax_work') );
		add_action( 'wp_ajax_blc_discard', array($this,'ajax_discard') );
		add_action( 'wp_ajax_blc_edit', array($this,'ajax_edit') );
		add_action( 'wp_ajax_blc_link_details', array($this,'ajax_link_details') );
		add_action( 'wp_ajax_blc_unlink', array($this,'ajax_unlink') );
		add_action( 'wp_ajax_blc_recheck', array($this,'ajax_recheck') );
		add_action( 'wp_ajax_blc_deredirect', array($this,'ajax_deredirect') );
		add_action( 'wp_ajax_blc_current_load', array($this,'ajax_current_load') );

		add_action( 'wp_ajax_blc_dismiss', array($this, 'ajax_dismiss') );
		add_action( 'wp_ajax_blc_undismiss', array($this, 'ajax_undismiss') );

		//Add/remove Cron events
		$this->setup_cron_events();

		//Set hooks that listen for our Cron actions
		add_action( 'blc_cron_email_notifications', array( $this, 'maybe_send_email_notifications' ) );
		add_action( 'blc_cron_check_links', array( $this, 'cron_check_links' ) );
		add_action( 'blc_cron_database_maintenance', array( $this, 'database_maintenance' ) );

		//Set the footer hook that will call the worker function via AJAX.
		add_action( 'admin_footer', array( $this,'admin_footer' ) );
		//Add a "Screen Options" panel to the "Broken Links" page
		add_screen_options_panel(
			'blc-screen-options',
			'',
			array($this, 'screen_options_html'),
			'tools_page_view-broken-links',
			array($this, 'ajax_save_screen_options'),
			true
		);

		//Display an explanatory note on the "Tools -> Broken Links -> Warnings" page.
		add_action( 'admin_notices', array( $this, 'show_warnings_section_notice' ) );


	}

  /**
   * Output the script that runs the link monitor while the Dashboard is open.
   *
   * @return void
   */
	function admin_footer(){
		if ( !$this->conf->options['run_in_dashboard'] ){
			return;
		}
		$nonce = wp_create_nonce('blc_work');
		?>
		<!-- wsblc admin footer -->
		<script type='text/javascript'>
		(function($){

			//(Re)starts the background worker thread
			function blcDoWork(){
				$.post(
					"<?php echo admin_url('admin-ajax.php'); ?>",
					{
						'action' : 'blc_work',
						'_ajax_nonce' : '<?php echo esc_js($nonce); ?>'
					}
				);
			}
			//Call it the first time
			blcDoWork();

			//Then call it periodically every X seconds
			setInterval(blcDoWork, <?php echo (intval($this->conf->options['max_execution_time']) + 1 )*1000; ?>);

		})(jQuery);
		</script>
		<!-- /wsblc admin footer -->
		<?php
	}

  /**
   * Check if an URL matches the exclusion list.
   *
   * @param string $url
   * @return bool
   */
	function is_excluded($url){
		if (!is_array($this->conf->options['exclusion_list'])) return false;
		foreach($this->conf->options['exclusion_list'] as $excluded_word){
			if (stristr($url, $excluded_word)){
				return true;
			}
		}
		return false;
	}

	function dashboard_widget(){
		?>
		<p id='wsblc_activity_box'><?php _e( 'Lade...', 'psource-link-checker' );  ?></p>
		<script type='text/javascript'>
			jQuery( function($){
				var blc_was_autoexpanded = false;

				function blcDashboardStatus(){
					$.getJSON(
						"<?php echo admin_url('admin-ajax.php'); ?>",
						{
							'action' : 'blc_dashboard_status',
							'random' : Math.random()
						},
						function (data){
							if ( data && ( typeof(data.text) != 'undefined' ) ) {
								$('#wsblc_activity_box').html(data.text);
								<?php if ( $this->conf->options['autoexpand_widget'] ) { ?>
								//Expand the widget if there are broken links.
								//Do this only once per pageload so as not to annoy the user.
								if ( !blc_was_autoexpanded && ( data.status.broken_links > 0 ) ){
									$('#blc_dashboard_widget.postbox').removeClass('closed');
									blc_was_autoexpanded = true;
								}
								<?php } ?>
							} else {
								$('#wsblc_activity_box').html('<?php _e('[ Netzwerkfehler ]', 'psource-link-checker'); ?>');
							}

							setTimeout( blcDashboardStatus, 120*1000 ); //...update every two minutes
						}
					);
				}

				blcDashboardStatus();//Call it the first time

			} );
		</script>
		<?php
	}

	function dashboard_widget_control(
		/** @noinspection PhpUnusedParameterInspection */ $widget_id, $form_inputs = array()
	){
		if ( 'POST' == $_SERVER['REQUEST_METHOD'] && 'blc_dashboard_widget' == $_POST['widget_id'] ) {
			//It appears $form_inputs isn't used in the current WP version, so lets just use $_POST
			$this->conf->options['autoexpand_widget'] = !empty($_POST['blc-autoexpand']);
			$this->conf->save_options();
		}

		?>
		<p><label for="blc-autoexpand">
			<input id="blc-autoexpand" name="blc-autoexpand" type="checkbox" value="1" <?php if ( $this->conf->options['autoexpand_widget'] ) echo 'checked="checked"'; ?> />
			<?php _e('Erweitere das Widget automatisch, wenn defekte Links erkannt wurden', 'psource-link-checker'); ?>
		</label></p>
		<?php
	}

	function admin_print_scripts(){
		//jQuery is used for triggering the link monitor via AJAX when any admin page is open.
		wp_enqueue_script('jquery');
	}

	function enqueue_settings_scripts(){
		//jQuery UI is used on the settings page
		wp_enqueue_script('jquery-ui-core');   //Used for background color animation
		wp_enqueue_script('jquery-ui-dialog');
		wp_enqueue_script('jquery-ui-tabs');
		wp_enqueue_script('jquery-cookie', plugins_url('js/jquery.cookie.js', BLC_PLUGIN_FILE)); //Used for storing last widget states, etc
	}

	function enqueue_link_page_scripts(){
		wp_enqueue_script('jquery-ui-core');
		wp_enqueue_script('jquery-ui-dialog'); //Used for the search form
		wp_enqueue_script('jquery-color');     //Used for background color animation
		wp_enqueue_script('sprintf', plugins_url('js/sprintf.js', BLC_PLUGIN_FILE)); //Used in error messages
	}

  /**
   * Initiate a full recheck - reparse everything and check all links anew.
   *
   * @return void
   */
	function initiate_recheck(){
		global $wpdb; /** @var wpdb $wpdb */

		//Delete all discovered instances
		$wpdb->query("TRUNCATE {$wpdb->prefix}blc_instances");

		//Delete all discovered links
		$wpdb->query("TRUNCATE {$wpdb->prefix}blc_links");

		//Mark all posts, custom fields and bookmarks for processing.
		blc_resynch(true);
	}

  /**
   * A hook executed when the plugin is deactivated.
   *
   * @return void
   */
	function deactivation(){
		//Remove our Cron events
		wp_clear_scheduled_hook('blc_cron_check_links');
		wp_clear_scheduled_hook('blc_cron_email_notifications');
		wp_clear_scheduled_hook('blc_cron_database_maintenance');
		wp_clear_scheduled_hook('blc_cron_check_news'); //Unused event.
		//Note the deactivation time for each module. This will help them
		//synch up propely if/when the plugin is reactivated.
		$moduleManager = blcModuleManager::getInstance();
		$the_time = current_time('timestamp');
		foreach($moduleManager->get_active_modules() as $module_id => $module){
			$this->conf->options['module_deactivated_when'][$module_id] = $the_time;
		}
		$this->conf->save_options();
	}

	/**
	 * Perform various database maintenance tasks on the plugin's tables.
	 *
	 * Removes records that reference disabled containers and parsers,
	 * deletes invalid instances and links, optimizes tables, etc.
	 *
	 * @return void
	 */
	function database_maintenance(){
		blcContainerHelper::cleanup_containers();
		blc_cleanup_instances();
		blc_cleanup_links();

		blcUtility::optimize_database();
	}

	/**
	 * Create the plugin's menu items and enqueue their scripts and CSS.
	 * Callback for the 'admin_menu' action.
	 *
	 * @return void
	 */
	function admin_menu(){
		if (current_user_can('manage_options'))
		  add_filter('plugin_action_links', array($this, 'plugin_action_links'), 10, 2);

		$options_page_hook = add_options_page(
			__('Link Checker Einstellungen', 'psource-link-checker'),
			__('Link Checker', 'psource-link-checker'),
			'manage_options',
			'link-checker-settings',array($this, 'options_page')
		);

		$menu_title = __('Defekte Links', 'psource-link-checker');
		if ( $this->conf->options['show_link_count_bubble'] ){
			//To make it easier to notice when broken links appear, display the current number of
			//broken links in a little bubble notification in the "Broken Links" menu.
			//(Similar to how the number of plugin updates and unmoderated comments is displayed).
			$blc_link_query = blcLinkQuery::getInstance();
			$broken_links = $blc_link_query->get_filter_links('broken', array('count_only' => true));
			if ( $broken_links > 0 ){
				//TODO: Appropriating existing CSS classes for my own purposes is hacky. Fix eventually.
				$menu_title .= sprintf(
					' <span class="update-plugins"><span class="update-count blc-menu-bubble">%d</span></span>',
					$broken_links
				);
			}
		}
		$links_page_hook = add_management_page(
			__('Defekte Links anzeigen', 'psource-link-checker'),
			$menu_title,
			'edit_others_posts',
			'view-broken-links',array($this, 'links_page')
		);

		//Add plugin-specific scripts and CSS only to the it's own pages
		add_action( 'admin_print_styles-' . $options_page_hook, array($this, 'options_page_css') );
		add_action( 'admin_print_styles-' . $links_page_hook, array($this, 'links_page_css') );
		add_action( 'admin_print_scripts-' . $options_page_hook, array($this, 'enqueue_settings_scripts') );
		add_action( 'admin_print_scripts-' . $links_page_hook, array($this, 'enqueue_link_page_scripts') );

		//Make the Settings page link to the link list
		add_screen_meta_link(
			'blc-links-page-link',
			__('Gehe zu Defekten Links', 'psource-link-checker'),
			admin_url('tools.php?page=view-broken-links'),
			$options_page_hook,
			array('style' => 'font-weight: bold;')
		);
	}

  /**
   * plugin_action_links()
   * Handler for the 'plugin_action_links' hook. Adds a "Settings" link to this plugin's entry
   * on the plugin list.
   *
   * @param array $links
   * @param string $file
   * @return array
   */
	function plugin_action_links($links, $file) {
		if ($file == $this->my_basename)
			$links[] = "<a href='options-general.php?page=link-checker-settings'>" . __('Settings') . "</a>";
		return $links;
	}

	function options_page(){
		$moduleManager = blcModuleManager::getInstance();

		//Prior to 1.5.2 (released 2012-05-27), there was a bug that would cause the donation flag to be
		//set incorrectly. So we'll unset the flag in that case.
		$reset_donation_flag =
			($this->conf->get('first_installation_timestamp', 0) < strtotime('2012-05-27 00:00')) &&
			!$this->conf->get('donation_flag_fixed', false);

		if ( $reset_donation_flag) {
			$this->conf->set('user_has_donated', false);
			$this->conf->set('donation_flag_fixed', true);
			$this->conf->save_options();
		}

		if (isset($_POST['recheck']) && !empty($_POST['recheck']) ){
			$this->initiate_recheck();

			//Redirect back to the settings page
			$base_url = remove_query_arg( array('_wpnonce', 'noheader', 'updated', 'error', 'action', 'message') );
			wp_redirect( add_query_arg( array( 'recheck-initiated' => true), $base_url ) );
			die();
		}

		$available_link_actions = array(
			'edit'               => __('URL bearbeiten' , 'psource-link-checker'),
			'delete'             => __('Link aufheben', 'psource-link-checker'),
			'blc-discard-action' => __('Nicht defekt', 'psource-link-checker'),
			'blc-dismiss-action' => __('Verwerfen', 'psource-link-checker'),
			'blc-recheck-action' => __('Erneut prüfen', 'psource-link-checker'),
			'blc-deredirect-action' => _x('Umleitung korrigieren', 'link action; Ersetze eine Weiterleitung durch einen direkten Link', 'psource-link-checker')
		);

		if(isset($_POST['submit'])) {
			check_admin_referer('link-checker-options');

			$cleanPost = $_POST;
			if ( function_exists('wp_magic_quotes') ){
				$cleanPost = stripslashes_deep($cleanPost); //Ceterum censeo, WP shouldn't mangle superglobals.
			}

			//Activate/deactivate modules
			if ( !empty($_POST['module']) ){
				$active = array_keys($_POST['module']);
				$moduleManager->set_active_modules($active);
			}

			//Only post statuses that actually exist can be selected
			if ( isset($_POST['enabled_post_statuses']) && is_array($_POST['enabled_post_statuses']) ){
				$available_statuses = get_post_stati();
				$enabled_post_statuses = array_intersect($_POST['enabled_post_statuses'], $available_statuses);
			} else {
				$enabled_post_statuses = array();
			}
			//At least one status must be enabled; defaults to "Published".
			if ( empty($enabled_post_statuses) ){
				$enabled_post_statuses = array('publish');
			}

			//Did the user add/remove any post statuses?
			$same_statuses = array_intersect($enabled_post_statuses, $this->conf->options['enabled_post_statuses']);
			$post_statuses_changed = (count($same_statuses) != count($enabled_post_statuses))
				|| (count($same_statuses) !== count($this->conf->options['enabled_post_statuses']));

			$this->conf->options['enabled_post_statuses'] = $enabled_post_statuses;

			//The execution time limit must be above zero
			$new_execution_time = intval($_POST['max_execution_time']);
			if( $new_execution_time > 0 ){
				$this->conf->options['max_execution_time'] = $new_execution_time;
			}

			//The check threshold also must be > 0
			$new_check_threshold=intval($_POST['check_threshold']);
			if( $new_check_threshold > 0 ){
				$this->conf->options['check_threshold'] = $new_check_threshold;
			}

			$this->conf->options['mark_broken_links'] = !empty($_POST['mark_broken_links']);
			$new_broken_link_css = trim($cleanPost['broken_link_css']);
			$this->conf->options['broken_link_css'] = $new_broken_link_css;

			$this->conf->options['mark_removed_links'] = !empty($_POST['mark_removed_links']);
			$new_removed_link_css = trim($cleanPost['removed_link_css']);
			$this->conf->options['removed_link_css'] = $new_removed_link_css;

			$this->conf->options['nofollow_broken_links'] = !empty($_POST['nofollow_broken_links']);

			$this->conf->options['suggestions_enabled'] = !empty($_POST['suggestions_enabled']);

			$this->conf->options['exclusion_list'] = array_filter(
				preg_split(
					'/[\s\r\n]+/',				//split on newlines and whitespace
					$cleanPost['exclusion_list'],
					-1,
					PREG_SPLIT_NO_EMPTY			//skip empty values
				)
			);

			//Parse the custom field list
			$new_custom_fields = array_filter(
				preg_split( '/[\r\n]+/', $cleanPost['blc_custom_fields'], -1, PREG_SPLIT_NO_EMPTY )
			);

			//Calculate the difference between the old custom field list and the new one (used later)
			$diff1 = array_diff( $new_custom_fields, $this->conf->options['custom_fields'] );
			$diff2 = array_diff( $this->conf->options['custom_fields'], $new_custom_fields );
			$this->conf->options['custom_fields'] = $new_custom_fields;

			//Parse the custom field list
			$new_acf_fields = array_filter(preg_split('/[\r\n]+/', $cleanPost['blc_acf_fields'], -1, PREG_SPLIT_NO_EMPTY));

			//Calculate the difference between the old custom field list and the new one (used later)
			$acf_fields_diff1 = array_diff($new_acf_fields, $this->conf->options['acf_fields']);
			$acf_fields_diff2 = array_diff($this->conf->options['acf_fields'], $new_acf_fields);
			$this->conf->options['acf_fields'] = $new_acf_fields;

			//Turning off warnings turns existing warnings into "broken" links.
			$warnings_enabled = !empty($_POST['warnings_enabled']);
			if ( $this->conf->get('warnings_enabled') && !$warnings_enabled ) {
				$this->promote_warnings_to_broken();
			}
			$this->conf->options['warnings_enabled'] = $warnings_enabled;

			//HTTP timeout
			$new_timeout = intval($_POST['timeout']);
			if( $new_timeout > 0 ){
				$this->conf->options['timeout'] = $new_timeout ;
			}

			//Server load limit
			if ( isset($_POST['server_load_limit']) ){
				$this->conf->options['server_load_limit'] = floatval($_POST['server_load_limit']);
				if ( $this->conf->options['server_load_limit'] < 0 ){
					$this->conf->options['server_load_limit'] = 0;
				}

				$this->conf->options['enable_load_limit'] = $this->conf->options['server_load_limit'] > 0;
			}

			//Target resource usage (1% to 100%)
			if ( isset($_POST['target_resource_usage']) ) {
				$usage = floatval($_POST['target_resource_usage']);
				$usage = max(min($usage / 100, 1), 0.01);
				$this->conf->options['target_resource_usage'] = $usage;
			}

			//When to run the checker
			$this->conf->options['run_in_dashboard'] = !empty($_POST['run_in_dashboard']);
			$this->conf->options['run_via_cron'] = !empty($_POST['run_via_cron']);

			//Email notifications on/off
			$email_notifications = !empty($_POST['send_email_notifications']);
			$send_authors_email_notifications = !empty($_POST['send_authors_email_notifications']);

			if (
				  ($email_notifications && !$this->conf->options['send_email_notifications'])
			   || ($send_authors_email_notifications && !$this->conf->options['send_authors_email_notifications'])
			){
				/*
				The plugin should only send notifications about links that have become broken
				since the time when email notifications were turned on. If we don't do this,
				the first email notification will be sent nigh-immediately and list *all* broken
				links that the plugin currently knows about.
				*/
				$this->conf->options['last_notification_sent'] = time();
			}
			$this->conf->options['send_email_notifications'] = $email_notifications;
			$this->conf->options['send_authors_email_notifications'] = $send_authors_email_notifications;

			$this->conf->options['notification_email_address'] = strval($_POST['notification_email_address']);
			$notification_email_addresses = explode(',', $this->conf->options['notification_email_address']);
			foreach ($notification_email_addresses as $notification_email_address) {
				if ( !filter_var($notification_email_address, FILTER_VALIDATE_EMAIL)) {
					$this->conf->options['notification_email_address'] = '';
				}
			}

			$widget_cap = strval($_POST['dashboard_widget_capability']);
			if ( !empty($widget_cap) ) {
				$this->conf->options['dashboard_widget_capability'] = $widget_cap;
			}

			//Link actions. The user can hide some of them to reduce UI clutter.
			$show_link_actions = array();
			foreach(array_keys($available_link_actions) as $action) {
				$show_link_actions[$action] = isset($_POST['show_link_actions']) &&
					!empty($_POST['show_link_actions'][$action]);
			}
			$this->conf->set('show_link_actions', $show_link_actions);

			//Logging. The plugin can log various events and results for debugging purposes.
			$this->conf->options['logging_enabled'] = !empty($_POST['logging_enabled']);
			$this->conf->options['custom_log_file_enabled'] = !empty($_POST['custom_log_file_enabled']);

			if ( $this->conf->options['logging_enabled'] ) {
				if ( $this->conf->options['custom_log_file_enabled'] ) {
					$log_file = strval($cleanPost['log_file']);
				} else {
					//Default log file is /wp-content/uploads/broken-link-checker/blc-log.txt
					$log_directory = self::get_default_log_directory();
					$log_file = $log_directory . '/' . self::get_default_log_basename();

					//Attempt to create the log directory.
					if ( !is_dir($log_directory) ) {
						if ( mkdir($log_directory, 0750) ) {
							//Add a .htaccess to hide the log file from site visitors.
							file_put_contents($log_directory . '/.htaccess', 'Deny from all');
						}
					}
				}

				$this->conf->options['log_file'] = $log_file;

				//Attempt to create the log file if not already there.
				if ( !is_file($log_file) ) {
					file_put_contents($log_file, '');
				}

				//The log file must be writable.
				if ( !is_writable($log_file) || !is_file($log_file) ) {
					$this->conf->options['logging_enabled'] = false;
				}
			}

			//Make settings that affect our Cron events take effect immediately
			$this->setup_cron_events();

			$this->conf->save_options();

			/*
			 If the list of custom fields was modified then we MUST resynchronize or
			 custom fields linked with existing posts may not be detected. This is somewhat
			 inefficient.
			 */
			if ( ( count($diff1) > 0 ) || ( count($diff2) > 0 ) ){
				$manager = blcContainerHelper::get_manager('custom_field');
				if ( !is_null($manager) ){
					$manager->resynch();
					blc_got_unsynched_items();
				}
			}

			/*
			 If the list of acf fields was modified then we MUST resynchronize or
			 acf fields linked with existing posts may not be detected. This is somewhat
			 inefficient.
			 */
			if ( ( count($acf_fields_diff1) > 0 ) || ( count($acf_fields_diff2) > 0 ) ){
				$manager = blcContainerHelper::get_manager('acf_field');
				if ( !is_null($manager) ){
					$manager->resynch();
					blc_got_unsynched_items();
				}
			}

			//Resynchronize posts when the user enables or disables post statuses.
			if ( $post_statuses_changed ) {
				$overlord = blcPostTypeOverlord::getInstance();
				$overlord->enabled_post_statuses = $this->conf->get('enabled_post_statuses', array());
				$overlord->resynch('wsh_status_resynch_trigger');

				blc_got_unsynched_items();
				blc_cleanup_instances();
				blc_cleanup_links();
			}

			//Redirect back to the settings page
			$base_url = remove_query_arg( array('_wpnonce', 'noheader', 'updated', 'error', 'action', 'message') );
			wp_redirect( add_query_arg( array( 'settings-updated' => true), $base_url ) );
		}

		//Show a confirmation message when settings are saved.
		if ( !empty($_GET['settings-updated']) ){
			echo '<div id="message" class="updated fade"><p><strong>',__('Einstellungen gespeichert.', 'psource-link-checker'), '</strong></p></div>';

		}

		//Show a thank-you message when a donation is made.
		if ( !empty($_GET['donated']) ){
			echo '<div id="message" class="updated fade"><p><strong>',__('Vielen Dank für Deine Spende!', 'psource-link-checker'), '</strong></p></div>';
			$this->conf->set('user_has_donated', true);
			$this->conf->save_options();
		}

		//Show one when recheck is started, too.
		if ( !empty($_GET['recheck-initiated']) ){
			echo '<div id="message" class="updated fade"><p><strong>',
					__('Die vollständige Überprüfung der Website wurde gestartet.', 'psource-link-checker'), // -- Yoda
				 '</strong></p></div>';
		}

		//Cull invalid and missing modules
		$moduleManager->validate_active_modules();

		$debug = $this->get_debug_info();

		add_filter('blc-module-settings-custom_field', array($this, 'make_custom_field_input'), 10, 2);
		add_filter('blc-module-settings-acf_field', array($this, 'make_acf_field_input'), 10, 2);
		//Translate and markup-ify module headers for display
		$modules = $moduleManager->get_modules_by_category('', true, true);

		//Output the custom broken link/removed link styles for example links
		printf(
			'<style type="text/css">%s %s</style>',
			$this->conf->options['broken_link_css'],
			$this->conf->options['removed_link_css']
		);

		$section_names = array(
			'general' =>  __('Allgemeines', 'psource-link-checker'),
			'where' =>    __('Suche nach Links in', 'psource-link-checker'),
			'which' =>    __('Welche Links überprüfen', 'psource-link-checker'),
			'how' =>      __('Protokolle und APIs', 'psource-link-checker'),
			'advanced' => __('Fortgeschritten', 'psource-link-checker'),
		);
		?>

		<!--[if lte IE 7]>
		<style type="text/css">
		/* Simulate inline-block in IE7 */
		ul.ui-tabs-nav li {
			display: inline;
			zoom: 1;
		}
		</style>
		<![endif]-->

		<div class="wrap" id="blc-settings-wrap">
		<h2><?php _e('Broken Link Checker-Optionen', 'psource-link-checker'); ?></h2>

		<div id="blc-admin-content">

		<form name="link_checker_options" id="link_checker_options" method="post" action="<?php
			echo admin_url('options-general.php?page=link-checker-settings&noheader=1');
		?>">
		<?php
			wp_nonce_field('link-checker-options');
		?>

		<div id="blc-tabs">

		<ul class="hide-if-no-js ui-tabs-nav">
			<?php
				$first = true;
				foreach($section_names as $section_id => $section_name){
					printf(
						'<li id="tab-button-%s"%s><a href="#section-%s" title="%s">%s</a></li>',
						esc_attr($section_id),
						$first ? ' class="ui-tabs-active"' : '',
						esc_attr($section_id),
						esc_attr($section_name),
						$section_name
					);
					$first = false;
				}
			?>
		</ul>

		<div id="section-general" class="blc-section" style="display:block;">
		<h3 class="hide-if-js"><?php echo $section_names['general']; ?></h3>

		<table class="form-table">

		<tr valign="top">
		<th scope="row">
			<?php _e('Status','psource-link-checker'); ?>
			<br>
			<a href="javascript:void(0)" id="blc-debug-info-toggle"><?php _e('Debug-Informationen anzeigen', 'psource-link-checker'); ?></a>
		</th>
		<td>

		<div id='wsblc_full_status'>
			<br/><br/><br/>
		</div>

		<table id="blc-debug-info">
		<?php

		//Output the debug info in a table
		foreach( $debug as $key => $value ){
			printf (
				'<tr valign="top" class="blc-debug-item-%s"><th scope="row">%s</th><td>%s<div class="blc-debug-message">%s</div></td></tr>',
				$value['state'],
				$key,
				$value['value'],
				( array_key_exists('message', $value)?$value['message']:'')
			);
		}
		?>
		</table>

		</td>
		</tr>

		<tr valign="top">
		<th scope="row"><?php _e('Überprüfe jeden Link','psource-link-checker'); ?></th>
		<td>

		<?php
			printf(
				__('Alle %s Stunden','psource-link-checker'),
				sprintf(
					'<input type="text" name="check_threshold" id="check_threshold" value="%d" size="5" maxlength="5" />',
					$this->conf->options['check_threshold']
				)
			 );
		?>
		<br/>
		<span class="description">
		<?php _e('Bestehende Links werden so oft überprüft. Neue Links werden normalerweise so schnell wie möglich überprüft.', 'psource-link-checker'); ?>
		</span>

		</td>
		</tr>

		<tr valign="top">
		<th scope="row"><?php _e('E-Mail Benachrichtigungen', 'psource-link-checker'); ?></th>
		<td>
			<p style="margin-top: 0;">
			<label for='send_email_notifications'>
				<input type="checkbox" name="send_email_notifications" id="send_email_notifications"
				<?php if ($this->conf->options['send_email_notifications']) echo ' checked="checked"'; ?>/>
				<?php _e('Sende mir E-Mail-Benachrichtigungen über neu erkannte defekte Links', 'psource-link-checker'); ?>
			</label><br />
			</p>

			<p>
			<label for='send_authors_email_notifications'>
				<input type="checkbox" name="send_authors_email_notifications" id="send_authors_email_notifications"
				<?php if ($this->conf->options['send_authors_email_notifications']) echo ' checked="checked"'; ?>/>
				<?php _e('Sende Autoren E-Mail-Benachrichtigungen über fehlerhafte Links in ihren Beiträgen', 'psource-link-checker'); ?>
			</label><br />
			</p>
		</td>
		</tr>

		<tr valign="top">
			<th scope="row"><?php echo __('Benachrichtigungs-E-Mail-Adresse', 'psource-link-checker'); ?></th>
			<td>
				<p>
				<label>
					<input
						type="text"
						name="notification_email_address"
						id="notification_email_address"
						value="<?php echo esc_attr($this->conf->get('notification_email_address', '')); ?>"
						class="regular-text ltr">
				</label><br>
				<span class="description">
					<?php echo __('Lasse das Feld leer, um die in Einstellungen&rarr; Allgemein angegebene E-Mail-Adresse zu verwenden.', 'psource-link-checker'); ?>
				</span>
				</p>
			</td>
		</tr>

		<tr valign="top">
		<th scope="row"><?php _e('Link-Optimierungen','psource-link-checker'); ?></th>
		<td>
			<p style="margin-top: 0; margin-bottom: 0.5em;">
			<label for='mark_broken_links'>
				<input type="checkbox" name="mark_broken_links" id="mark_broken_links"
				<?php if ($this->conf->options['mark_broken_links']) echo ' checked="checked"'; ?>/>
				<?php _e('Wende benutzerdefinierte Formatierungen auf fehlerhafte Links an', 'psource-link-checker'); ?>
			</label>
			|
			<a id="toggle-broken-link-css-editor" href="#" class="blc-toggle-link"><?php
				_e('CSS bearbeiten', 'psource-link-checker');
			?></a>
			</p>

			<div id="broken-link-css-wrap"<?php
				if ( !blcUtility::get_cookie('broken-link-css-wrap', false) ){
					echo ' class="hidden"';
				}
			?>>
				<textarea name="broken_link_css" id="broken_link_css" cols='45' rows='4'><?php
					if( isset($this->conf->options['broken_link_css']) ) {
						echo $this->conf->options['broken_link_css'];
					}
				?></textarea>
				<p class="description"><?php
					printf(
						__('Beispiel: Lorem ipsum <a %s>defekter Link</a>, Dolor Sit Amet.', 'psource-link-checker'),
						' href="#" class="broken_link" onclick="return false;"'
					);
					echo ' ', __('Klicke auf "Änderungen speichern", um die Beispielausgabe zu aktualisieren.', 'psource-link-checker');
				?></p>
			</div>

			<p style="margin-bottom: 0.5em;">
			<label for='mark_removed_links'>
				<input type="checkbox" name="mark_removed_links" id="mark_removed_links"
				<?php if ($this->conf->options['mark_removed_links']) echo ' checked="checked"'; ?>/>
				<?php _e('Wende eine benutzerdefinierte Formatierung auf entfernte Links an', 'psource-link-checker'); ?>
			</label>
			|
			<a id="toggle-removed-link-css-editor" href="#" class="blc-toggle-link"><?php
				_e('CSS bearbeiten', 'psource-link-checker');
			?></a>
			</p>

			<div id="removed-link-css-wrap" <?php
				if ( !blcUtility::get_cookie('removed-link-css-wrap', false) ){
					echo ' class="hidden"';
				}
			?>>
				<textarea name="removed_link_css" id="removed_link_css" cols='45' rows='4'><?php
					if( isset($this->conf->options['removed_link_css']) )
						echo $this->conf->options['removed_link_css'];
				?></textarea>

				<p class="description"><?php
				printf(
					__('Beispiel: Lorem ipsum <a %s>defekter Link</a>, Dolor Sit Amet.', 'psource-link-checker'),
					' class="removed_link"'
				);
				echo ' ', __('Klicke auf "Änderungen speichern", um die Beispielausgabe zu aktualisieren.', 'psource-link-checker');
				?>

				</p>
			</div>

			<p>
			<label for='nofollow_broken_links'>
				<input type="checkbox" name="nofollow_broken_links" id="nofollow_broken_links"
				<?php if ($this->conf->options['nofollow_broken_links']) echo ' checked="checked"'; ?>/>
				<?php _e('Verhindere dass Suchmaschinen defekten Links folgen', 'psource-link-checker'); ?>
			</label>
			</p>

			<p class="description">
				<?php
				echo _x(
					'Diese Einstellungen gelten nur für den Inhalt von Posts, nicht für Kommentare oder benutzerdefinierte Felder.',
					'Einstellungen für "Link Optimierung"',
					'psource-link-checker'
				);
				?>
			</p>
		</td>
		</tr>

			<tr valign="top">
				<th scope="row"><?php echo _x('Vorschläge', 'settings page', 'psource-link-checker'); ?></th>
				<td>
					<label>
						<input type="checkbox" name="suggestions_enabled" id="suggestions_enabled"
							<?php checked($this->conf->options['suggestions_enabled']); ?>/>
						<?php _e('Schlage Alternativen zu defekten Links vor', 'psource-link-checker'); ?>
					</label>
				</td>
			</tr>

			<tr valign="top">
				<th scope="row"><?php echo _x('Warnungen', 'settings page', 'psource-link-checker'); ?></th>
				<td id="blc_warning_settings">
					<label>
						<input type="checkbox" name="warnings_enabled" id="warnings_enabled"
							<?php checked($this->conf->options['warnings_enabled']); ?>/>
						<?php _e('Zeige unsichere oder kleinere Probleme als "Warnungen" anstelle von "defekt" an.', 'psource-link-checker'); ?>
					</label>
					<p class="description"><?php
						_e('Wenn Du diese Option deaktivierst, meldet das Plugin alle Probleme als fehlerhafte Links.', 'psource-link-checker');
					?></p>
				</td>
			</tr>

		</table>

		</div>

		<div id="section-where" class="blc-section" style="display:none;">
		<h3 class="hide-if-js"><?php echo $section_names['where']; ?></h3>

		<table class="form-table">

		<tr valign="top">
		<th scope="row"><?php _e('Suche nach Links in', 'psource-link-checker'); ?></th>
		<td>
		<?php
		if ( !empty($modules['container']) ){
			uasort(
				$modules['container'],
				function( $a, $b ) {
					return strcasecmp( $a["Name"], $b["Name"] );
				}
			);
			$this->print_module_list($modules['container'], $this->conf->options);
		}
		?>
		</td></tr>

		<tr valign="top">
		<th scope="row"><?php _e('Beitragsstatus', 'psource-link-checker'); ?></th>
		<td>
		<?php
			$available_statuses = get_post_stati(array('internal' => false), 'objects');

			if ( isset($this->conf->options['enabled_post_statuses']) ){
				$enabled_post_statuses = $this->conf->options['enabled_post_statuses'];
			} else {
				$enabled_post_statuses = array();
			}

			foreach($available_statuses as $status => $status_object){
				printf(
					'<p><label><input type="checkbox" name="enabled_post_statuses[]" value="%s"%s> %s</label></p>',
					esc_attr($status),
					in_array($status, $enabled_post_statuses)?' checked="checked"':'',
					$status_object->label
				);
			}
		?>
		</td></tr>

		</table>

		</div>


		<div id="section-which" class="blc-section" style="display:none;">
		<h3 class="hide-if-js"><?php echo $section_names['which']; ?></h3>

		<table class="form-table">

		<tr valign="top">
		<th scope="row"><?php _e('Linktypen', 'psource-link-checker'); ?></th>
		<td>
		<?php
		if ( !empty($modules['parser']) ){
			$this->print_module_list($modules['parser'], $this->conf->options);
		} else {
			echo __('Fehler: Alle Link-Parser fehlen!', 'psource-link-checker');
		}
		?>
		</td>
		</tr>

		<tr valign="top">
		<th scope="row"><?php _e('Ausschlussliste', 'psource-link-checker'); ?></th>
		<td><?php _e("Überprüfe keine Links, bei denen die URL eines dieser Wörter enthält (eines pro Zeile). :", 'psource-link-checker'); ?><br/>
		<textarea name="exclusion_list" id="exclusion_list" cols='45' rows='4'><?php
			if( isset($this->conf->options['exclusion_list']) )
				echo esc_textarea(implode("\n", $this->conf->options['exclusion_list']));
		?></textarea>

		</td>
		</tr>

		</table>
		</div>

		<div id="section-how" class="blc-section" style="display:none;">
		<h3 class="hide-if-js"><?php echo $section_names['how']; ?></h3>

		<table class="form-table">

		<tr valign="top">
		<th scope="row"><?php _e('Überprüfe die Links mit', 'psource-link-checker'); ?></th>
		<td>
		<?php
		if ( !empty($modules['checker']) ){
			$modules['checker'] = array_reverse($modules['checker']);
			$this->print_module_list($modules['checker'], $this->conf->options);
		}
		?>
		</td></tr>

		</table>
		</div>

		<div id="section-advanced" class="blc-section" style="display:none;">
		<h3 class="hide-if-js"><?php echo $section_names['advanced']; ?></h3>

		<table class="form-table">

		<tr valign="top">
		<th scope="row"><?php _e('Timeout', 'psource-link-checker'); ?></th>
		<td>

		<?php

		printf(
			__('%s Sekunden', 'psource-link-checker'),
			sprintf(
				'<input type="text" name="timeout" id="blc_timeout" value="%d" size="5" maxlength="3" />',
				$this->conf->options['timeout']
			)
		);

		?>
		<br/><span class="description">
		<?php _e('Links, deren Laden länger dauert, werden als fehlerhaft markiert.','psource-link-checker'); ?>
		</span>

		</td>
		</tr>

		<tr valign="top">
		<th scope="row"><?php _e('Link Monitor', 'psource-link-checker'); ?></th>
		<td>

			<p>
			<label for='run_in_dashboard'>

					<input type="checkbox" name="run_in_dashboard" id="run_in_dashboard"
					<?php if ($this->conf->options['run_in_dashboard']) echo ' checked="checked"'; ?>/>
					<?php _e('Laufe kontinuierlich, während das Dashboard geöffnet ist', 'psource-link-checker'); ?>
			</label>
			</p>

			<p>
			<label for='run_via_cron'>
					<input type="checkbox" name="run_via_cron" id="run_via_cron"
					<?php if ($this->conf->options['run_via_cron']) echo ' checked="checked"'; ?>/>
					<?php _e('Laufe stündlich im Hintergrund', 'psource-link-checker'); ?>
			</label>
			</p>

		</td>
		</tr>

		<tr valign="top">
			<th scope="row"><?php _e('Zeige das Dashboard-Widget für an', 'psource-link-checker'); ?></th>
			<td>

				<?php
				$widget_caps = array(
					_x('Administrator', 'dashboard widget visibility', 'psource-link-checker') => 'manage_options',
					_x('Editor und höher', 'dashboard widget visibility', 'psource-link-checker') => 'edit_others_posts',
					_x('Niemand (deaktiviert das Widget)', 'dashboard widget visibility', 'psource-link-checker') => 'do_not_allow',
				);

				foreach($widget_caps as $title => $capability) {
					printf(
						'<p><label><input type="radio" name="dashboard_widget_capability" value="%s"%s> %s</label></p>',
						esc_attr($capability),
						checked($capability, $this->conf->get('dashboard_widget_capability'), false),
						$title
					);
				}
				?>
			</td>
		</tr>

		<tr valign="top">
			<th scope="row"><?php echo _x('Linkaktionen anzeigen', 'settings page', 'psource-link-checker'); ?></th>
			<td>
				<?php
				$show_link_actions = $this->conf->get('show_link_actions', array());
				foreach($available_link_actions as $action => $text) {
					$enabled = isset($show_link_actions[$action]) ? (bool)($show_link_actions[$action]) : true;
					printf(
						'<p><label><input type="checkbox" name="show_link_actions[%1$s]" %3$s> %2$s</label></p>',
						$action,
						$text,
						checked($enabled, true, false)
					);
				}
				?>
			</td>
		</tr>

		<tr valign="top">
		<th scope="row"><?php _e('Max. Ausführungszeit', 'psource-link-checker'); ?></th>
		<td>

		<?php

		printf(
			__('%s Sekunden', 'psource-link-checker'),
			sprintf(
				'<input type="text" name="max_execution_time" id="max_execution_time" value="%d" size="5" maxlength="5" />',
				$this->conf->options['max_execution_time']
			)
		);

		?>
		<br/><span class="description">
		<?php

		_e('Das Plugin startet regelmäßig einen Hintergrundjob, der Deine Beiträge auf Links analysiert, die erkannten URLs überprüft und andere zeitaufwändige Aufgaben ausführt. Hier kannst Du festlegen, wie lange der Verbindungsmonitor höchstens jedes Mal ausgeführt werden darf, bevor er angehalten wird.', 'psource-link-checker');

		?>
		</span>

		</td>
		</tr>

		<tr valign="top">
		<th scope="row"><?php _e('Serverlastbegrenzung', 'psource-link-checker'); ?></th>
		<td>
		<?php

		$load = blcUtility::get_server_load();
		$available = !empty($load);

		if ( $available ){
			$value = !empty($this->conf->options['server_load_limit'])?sprintf('%.2f', $this->conf->options['server_load_limit']):'';
			printf(
				'<input type="text" name="server_load_limit" id="server_load_limit" value="%s" size="5" maxlength="5"/> ',
				$value
			);

			printf(
				__('Aktuelle Last : %s', 'psource-link-checker'),
				'<span id="wsblc_current_load">...</span>'
			);
			echo '<br/><span class="description">';
			printf(
				__(
					'Die Linkprüfung wird ausgesetzt, wenn die durchschnittliche <a href="%s">Serverlast</a> über diese Zahl steigt. Lasse dieses Feld leer, um die Lastbegrenzung zu deaktivieren.',
					'psource-link-checker'
				),
				'http://en.wikipedia.org/wiki/Load_(computing)'
			);
			echo '</span>';

		} else {
			echo '<input type="text" disabled="disabled" value="', esc_attr(__('Nicht verfügbar', 'psource-link-checker')), '" size="13"/><br>';
			echo '<span class="description">';
			_e('Die Lastbegrenzung funktioniert nur auf Linux-ähnlichen Systemen, auf denen <code>/proc/loadavg</code> vorhanden und zugänglich ist.', 'psource-link-checker');
			echo '</span>';
		}
		?>
		</td>
		</tr>

		<tr valign="top">
			<th scope="row"><?php _e('Zielressourcennutzung', 'psource-link-checker'); ?></th>
			<td>
				<?php
				$target_resource_usage = $this->conf->get('target_resource_usage', 0.25);
				printf(
					'<input name="target_resource_usage" value="%d"
						type="range" min="1" max="100" id="target_resource_usage">',
					$target_resource_usage * 100
				);
				?>

				<span id="target_resource_usage_percent"><?php
					echo sprintf('%.0f%%', $target_resource_usage * 100);
				?></span>
			</td>
		</tr>

		<tr valign="top">
			<th scope="row"><?php _e('Logging', 'psource-link-checker'); ?></th>
			<td>
				<p>
					<label for='logging_enabled'>
						<input type="checkbox" name="logging_enabled" id="logging_enabled"
							<?php checked($this->conf->options['logging_enabled']); ?>/>
						<?php _e('Aktiviere das Logging', 'psource-link-checker'); ?>
					</label>
				</p>
			</td>
		</tr>

		<tr valign="top">
			<th scope="row"><?php _e('Speicherort der Protokolldatei', 'psource-link-checker'); ?></th>
			<td>

				<div id="blc-logging-options">

				<p>
				<label>
					<input type="radio" name="custom_log_file_enabled" value=""
						<?php checked(!$this->conf->options['custom_log_file_enabled']); ?>>
					<?php echo _x('Standard', 'log file location', 'psource-link-checker'); ?>
				</label>
				<br>
					<span class="description">
						<code><?php
							echo self::get_default_log_directory(), '/', self::get_default_log_basename();
						?></code>
					</span>
				</p>

				<p>
				<label>
					<input type="radio" name="custom_log_file_enabled" value="1"
						<?php checked($this->conf->options['custom_log_file_enabled']); ?>>
					<?php echo _x('Benutzerdefiniert', 'log file location', 'psource-link-checker'); ?>
				</label>
				<br><input type="text" name="log_file" id="log_file" size="90"
						   value="<?php echo esc_attr($this->conf->options['log_file']); ?>">
				</p>

				</div>
			</td>
		</tr>


		<tr valign="top">
		<th scope="row"><?php _e('Erzwungene erneute Überprüfung', 'psource-link-checker'); ?></th>
		<td>
			<input class="button" type="button" name="start-recheck" id="start-recheck"
				  value="<?php _e('Überprüfe alle Seiten erneut', 'psource-link-checker'); ?>"  />
			<input type="hidden" name="recheck" value="" id="recheck" />
			<br />
			<span class="description"><?php
			  _e('Die "Nuklearoption". Klicke auf diese Schaltfläche, damit das Plugin seine Linkdatenbank leer macht und die gesamte Seite von Grund auf neu überprüft.', 'psource-link-checker');

			?></span>
		</td>
		</tr>

		</table>
		</div>

		</div>

		<p class="submit"><input type="submit" name="submit" class='button-primary' value="<?php _e('Änderungen speichern') ?>" /></p>
		</form>

		</div> <!-- First postbox-container -->


		</div>



		<?php
		//The various JS for this page is stored in a separate file for the purposes readability.
		include dirname($this->loader) . '/includes/admin/options-page-js.php';
	}

	/**
	 * Output a list of modules and their settings.
	 *
	 * Each list entry will contain a checkbox that is checked if the module is
	 * currently active.
	 *
	 * @param array $modules Array of modules to display
	 * @param array $current_settings
	 * @return void
	 */
	function print_module_list($modules, $current_settings){
		$moduleManager = blcModuleManager::getInstance();

		foreach($modules as $module_id => $module_data){
			$module_id = $module_data['ModuleID'];

			$style = $module_data['ModuleHidden']?' style="display:none;"':'';

			printf(
				'<div class="module-container" id="module-container-%s"%s>',
				$module_id,
				$style
			);
			$this->print_module_checkbox($module_id, $module_data, $moduleManager->is_active($module_id));

			$extra_settings = apply_filters(
				'blc-module-settings-'.$module_id,
				'',
				$current_settings
			);

			if ( !empty($extra_settings) ){

				printf(
					' | <a class="blc-toggle-link toggle-module-settings" id="toggle-module-settings-%s" href="#">%s</a>',
					esc_attr($module_id),
					__('Konfigurieren', 'psource-link-checker')
				);

				//The plugin remembers the last open/closed state of module configuration boxes
				$box_id = 'module-extra-settings-' . $module_id;
				$show = blcUtility::get_cookie(
					$box_id,
					$moduleManager->is_active($module_id)
				);

				printf(
					'<div class="module-extra-settings%s" id="%s">%s</div>',
					$show?'':' hidden',
					$box_id,
					$extra_settings
				);
			}

			echo '</div>';
		}
	}

	/**
	 * Output a checkbox for a module.
	 *
	 * Generates a simple checkbox that can be used to mark a module as active/inactive.
	 * If the specified module can't be deactivated (ModuleAlwaysActive = true), the checkbox
	 * will be displayed in a disabled state and a hidden field will be created to make
	 * form submissions work correctly.
	 *
	 * @param string $module_id Module ID.
	 * @param array $module_data Associative array of module data.
	 * @param bool $active If true, the newly created checkbox will start out checked.
	 * @return void
	 */
	function print_module_checkbox($module_id, $module_data, $active = false){
		$disabled = false;
		$name_prefix = 'module';
		$label_class = '';
		$active = $active || $module_data['ModuleAlwaysActive'];

		if ( $module_data['ModuleAlwaysActive'] ){
			$disabled = true;
			$name_prefix = 'module-always-active';
		}

		$checked = $active ? ' checked="checked"':'';
		if ( $disabled ){
			$checked .= ' disabled="disabled"';
		}

		printf(
			'<label class="%s">
				<input type="checkbox" name="%s[%s]" id="module-checkbox-%s"%s /> %s
			</label>',
			esc_attr($label_class),
			$name_prefix,
			esc_attr($module_id),
			esc_attr($module_id),
			$checked,
			$module_data['Name']
		);

		if ( $module_data['ModuleAlwaysActive'] ){
			printf(
				'<input type="hidden" name="module[%s]" value="on">',
				esc_attr($module_id)
			);
		}
	}

	/**
	 * Add extra settings to the "Custom fields" entry on the plugin's config. page.
	 *
	 * Callback for the 'blc-module-settings-custom_field' filter.
	 *
	 * @param string $html Current extra HTML
	 * @param array $current_settings The current plugin configuration.
	 * @return string New extra HTML.
	 */
	function make_custom_field_input($html, $current_settings){
		$html .= '<span class="description">' .
					__(
						'Gib die Namen der benutzerdefinierten Felder ein, die Du überprüfen möchtest (eines pro Zeile). Wenn ein Feld HTML-Code enthält, stelle seinem Namen <code>html:</code> voran. Zum Beispiel, <code>html:field_name</code>.',
						'psource-link-checker'
					) .
				 '</span>';
		$html .= '<br><textarea name="blc_custom_fields" id="blc_custom_fields" cols="45" rows="4">';
		if( isset($current_settings['custom_fields']) ){
			$html .= esc_textarea(implode("\n", $current_settings['custom_fields']));
		}
		$html .= '</textarea>';

		return $html;
	}
	function make_acf_field_input($html, $current_settings) {
		$html .= '<span class="description">' . __('Gib die Schlüssel der ACF-Felder ein, die Du überprüfen möchtest (einen pro Zeile). Wenn ein Feld HTML-Code enthält, stelle seinem Namen <code>html:</code> voran. Zum Beispiel, <code>html:field_586a3eaa4091b</code>.', 'psource-link-checker') . '</span>';
		$html .= '<br><textarea name="blc_acf_fields" id="blc_acf_fields" cols="45" rows="4">';
		if (isset($current_settings['acf_fields'])) {
			$html .= esc_textarea(implode("\n", $current_settings['acf_fields']));
		}
		$html .= '</textarea>';

		return $html;
	}

	/**
	 * Enqueue CSS file for the plugin's Settings page.
	 *
	 * @return void
	 */
	function options_page_css(){
		error_log('options_page_css geladen');
		wp_enqueue_style('blc-options-page', plugins_url('css/options-page.css', BLC_PLUGIN_FILE), array(), '20141113');
		wp_enqueue_style('dashboard');
		wp_enqueue_script(
			'blc-options-tabs',
			plugins_url('js/options-tabs.js', BLC_PLUGIN_FILE),
			array(),
			'20250717',
			true
		);
	}


	/**
	 * Display the "Broken Links" page, listing links detected by the plugin and their status.
	 *
	 * @return void
	 */
	function links_page(){
		global $wpdb; /* @var wpdb $wpdb */

		$blc_link_query = blcLinkQuery::getInstance();

		//Cull invalid and missing modules so that we don't get dummy links/instances showing up.
		$moduleManager = blcModuleManager::getInstance();
		$moduleManager->validate_active_modules();

		if ( defined('BLC_DEBUG') && constant('BLC_DEBUG') ){
			//Make module headers translatable. They need to be formatted corrrectly and
			//placed in a .php file to be visible to the script(s) that generate .pot files.
			$code = $moduleManager->_build_header_translation_code();
			file_put_contents( dirname($this->loader) . '/includes/extra-strings.php', $code );
		}

		$action = !empty($_POST['action'])?$_POST['action']:'';
		if ( intval($action) == -1 ){
			//Try the second bulk actions box
			$action = !empty($_POST['action2'])?$_POST['action2']:'';
		}

		//Get the list of link IDs selected via checkboxes
		$selected_links = array();
		if ( isset($_POST['selected_links']) && is_array($_POST['selected_links']) ){
			//Convert all link IDs to integers (non-numeric entries are converted to zero)
			$selected_links = array_map('intval', $_POST['selected_links']);
			//Remove all zeroes
			$selected_links = array_filter($selected_links);
		}

		$message = '';
		$msg_class = 'updated';

		//Run the selected bulk action, if any
		$force_delete = false;
		switch ( $action ){
			case 'create-custom-filter':
				list($message, $msg_class) = $this->do_create_custom_filter();
				break;

			case 'delete-custom-filter':
				list($message, $msg_class) = $this->do_delete_custom_filter();
				break;

			/** @noinspection PhpMissingBreakStatementInspection Deliberate fall-through. */
			case 'bulk-delete-sources':
				$force_delete = true;
			case 'bulk-trash-sources':
				list($message, $msg_class) = $this->do_bulk_delete_sources($selected_links, $force_delete);
				break;

			case 'bulk-unlink':
				list($message, $msg_class) = $this->do_bulk_unlink($selected_links);
				break;

			case 'bulk-deredirect':
				list($message, $msg_class) = $this->do_bulk_deredirect($selected_links);
				break;

			case 'bulk-recheck':
				list($message, $msg_class) = $this->do_bulk_recheck($selected_links);
				break;

			case 'bulk-not-broken':
				list($message, $msg_class) = $this->do_bulk_discard($selected_links);
				break;

			case 'bulk-dismiss':
				list($message, $msg_class) = $this->do_bulk_dismiss($selected_links);
				break;

			case 'bulk-edit':
				list($message, $msg_class) = $this->do_bulk_edit($selected_links);
				break;
		}


		if ( !empty($message) ){
			echo '<div id="message" class="'.$msg_class.' fade"><p>'.$message.'</p></div>';
		}

		$start_time = microtime_float();

		//Load custom filters, if any
		$blc_link_query->load_custom_filters();

		//Calculate the number of links matching each filter
		$blc_link_query->count_filter_results();

		//Run the selected filter (defaults to displaying broken links)
		$selected_filter_id = isset($_GET['filter_id'])?$_GET['filter_id']:'broken';
		$current_filter = $blc_link_query->exec_filter(
			$selected_filter_id,
			isset($_GET['paged']) ? intval($_GET['paged']) : 1,
			$this->conf->options['table_links_per_page'],
			'broken',
			isset($_GET['orderby']) ? $_GET['orderby'] : '',
			isset($_GET['order']) ? $_GET['order'] : ''
		);

		//exec_filter() returns an array with filter data, including the actual filter ID that was used.
		$filter_id = $current_filter['filter_id'];

		//Error?
		if ( empty($current_filter['links']) && !empty($wpdb->last_error) ){
			printf( __('Datenbankfehler: %s', 'psource-link-checker'), $wpdb->last_error);
		}
		?>

<script type='text/javascript'>
	var blc_current_filter = '<?php echo $filter_id; ?>';
	var blc_is_broken_filter = <?php echo $current_filter['is_broken_filter'] ? 'true' : 'false'; ?>;
	var blc_current_base_filter = '<?php echo esc_js($current_filter['base_filter']); ?>';
	var blc_suggestions_enabled = <?php echo $this->conf->options['suggestions_enabled'] ? 'true' : 'false'; ?>;
</script>

<div class="wrap">
	<?php
		$blc_link_query->print_filter_heading($current_filter);
		$blc_link_query->print_filter_menu($filter_id);

		//Display the "Search" form and associated buttons.
		//The form requires the $filter_id and $current_filter variables to be set.
		include dirname($this->loader) . '/includes/admin/search-form.php';

		//If the user has decided to switch the table to a different mode (compact/full),
		//save the new setting.
		if ( isset($_GET['compact']) ){
			$this->conf->options['table_compact'] = (bool)$_GET['compact'];
			$this->conf->save_options();
		}

		//Display the links, if any
		if( $current_filter['links'] && ( count($current_filter['links']) > 0 ) ) {

			include dirname($this->loader) . '/includes/admin/table-printer.php';
			$table = new blcTablePrinter($this);
			$table->print_table(
				$current_filter,
				$this->conf->options['table_layout'],
				$this->conf->options['table_visible_columns'],
				$this->conf->options['table_compact']
			);

		};
		printf('<!-- Insgesamt benötigt: %.4f Sekunden -->', microtime_float() - $start_time);

		//Load assorted JS event handlers and other shinies
		include dirname($this->loader) . '/includes/admin/links-page-js.php';

		?></div><?php
	}

  /**
   * Create a custom link filter using params passed in $_POST.
   *
   * @uses $_POST
   * @uses $_GET to replace the current filter ID (if any) with that of the newly created filter.
   *
   * @return array Message and the CSS class to apply to the message.
   */
	function do_create_custom_filter(){
		global $wpdb;

		//Create a custom filter!
		check_admin_referer( 'create-custom-filter' );
		$msg_class = 'updated';

		//Filter name must be set
		if ( empty($_POST['name']) ){
			$message = __("Du musst einen Filternamen eingeben!", 'psource-link-checker');
			$msg_class = 'error';
		//Filter parameters (a search query) must also be set
		} elseif ( empty($_POST['params']) ){
			$message = __("Ungültige Suchabfrage.", 'psource-link-checker');
			$msg_class = 'error';
		} else {
			//Save the new filter
			$name = strip_tags(strval($_POST['name']));
			$blc_link_query = blcLinkQuery::getInstance();
			$filter_id = $blc_link_query->create_custom_filter($name, $_POST['params']);

			if ( $filter_id ){
				//Saved
				$message = sprintf( __('Filter "%s" erstellt', 'psource-link-checker'), $name);
				//A little hack to make the filter active immediately
				$_GET['filter_id'] = $filter_id;
			} else {
				//Error
				$message = sprintf( __("Datenbankfehler: %s", 'psource-link-checker'), $wpdb->last_error);
				$msg_class = 'error';
			}
		}

		return array($message, $msg_class);
	}

  /**
   * Delete a custom link filter.
   *
   * @uses $_POST
   *
   * @return array Message and a CSS class to apply to the message.
   */
	function do_delete_custom_filter(){
		//Delete an existing custom filter!
		check_admin_referer( 'delete-custom-filter' );
		$msg_class = 'updated';

		//Filter ID must be set
		if ( empty($_POST['filter_id']) ){
			$message = __("Filter-ID nicht angegeben.", 'psource-link-checker');
			$msg_class = 'error';
		} else {
			//Try to delete the filter
			$blc_link_query = blcLinkQuery::getInstance();
			if ( $blc_link_query->delete_custom_filter($_POST['filter_id']) ){
				//Success
				$message = __('Filter gelöscht', 'psource-link-checker');
			} else {
				//Either the ID is wrong or there was some other error
				$message = __('Datenbankfehler: %s', 'psource-link-checker');
				$msg_class = 'error';
			}
		}

		return array($message, $msg_class);
	}

  /**
   * Modify multiple links to point to their target URLs.
   *
   * @param array $selected_links
   * @return array The message to display and its CSS class.
   */
	function do_bulk_deredirect($selected_links){
		//For all selected links, replace the URL with the final URL that it redirects to.

		$message = '';
		$msg_class = 'updated';

		check_admin_referer( 'bulk-action' );

		if ( count($selected_links) > 0 ) {
			//Fetch all the selected links
			$links = blc_get_links(array(
				'link_ids' => $selected_links,
				'purpose' => BLC_FOR_EDITING,
			));

			if ( count($links) > 0 ) {
				$processed_links = 0;
				$failed_links = 0;

				//Deredirect all selected links
				foreach($links as $link){
					$rez = $link->deredirect();
					if ( !is_wp_error($rez) && empty($rez['errors'] )){
						$processed_links++;
					} else {
						$failed_links++;
					}
				}

				$message = sprintf(
					_n(
						'%d Weiterleitung durch direkten Link ersetzt',
						'%d Weiterleitungen wurden durch direkte Links ersetzt',
						$processed_links,
						'psource-link-checker'
					),
					$processed_links
				);

				if ( $failed_links > 0 ) {
					$message .= '<br>' . sprintf(
						_n(
							'%d Umleitung konnte nicht behoben werden',
							'%d Weiterleitungen konnten nicht behoben werden',
							$failed_links,
							'psource-link-checker'
						),
						$failed_links
					);
					$msg_class = 'error';
				}
			} else {
				$message = __('Keiner der ausgewählten Links ist eine Weiterleitung!', 'psource-link-checker');
			}
		}

		return array($message, $msg_class);
	}

  /**
   * Edit multiple links in one go.
   *
   * @param array $selected_links
   * @return array The message to display and its CSS class.
   */
	function do_bulk_edit($selected_links){
		$message = '';
		$msg_class = 'updated';

		check_admin_referer( 'bulk-action' );

		$post = $_POST;
		if ( function_exists('wp_magic_quotes') ){
			$post = stripslashes_deep($post); //Ceterum censeo, WP shouldn't mangle superglobals.
		}

		$search = isset($post['search']) ? esc_attr( $post['search'] ) : '';
		$replace = isset($post['replace']) ? esc_attr( $post['replace'] ) : '';
		$use_regex = !empty($post['regex']);
		$case_sensitive = !empty($post['case_sensitive']);

		$delimiter = '`'; //Pick a char that's uncommon in URLs so that escaping won't usually be a problem
		if ( $use_regex ){
			$search = $delimiter . $this->escape_regex_delimiter($search, $delimiter) . $delimiter;
			if ( !$case_sensitive ){
				$search .= 'i';
			}
		} elseif ( !$case_sensitive ) {
			//str_ireplace() would be more appropriate for case-insensitive, non-regexp replacement,
			//but that's only available in PHP5.
			$search = $delimiter . preg_quote($search, $delimiter) . $delimiter . 'i';
			$use_regex = true;
		}

		if ( count($selected_links) > 0 ) {
			set_time_limit(300); //In case the user decides to edit hundreds of links at once

			//Fetch all the selected links
			$links = blc_get_links(array(
				'link_ids' => $selected_links,
				'purpose' => BLC_FOR_EDITING,
			));

			if ( count($links) > 0 ) {
				$processed_links = 0;
				$failed_links = 0;
				$skipped_links = 0;

				//Edit the links
				foreach($links as $link){
					if ( $use_regex ){
						$new_url = preg_replace($search, $replace, $link->url);
					} else {
						$new_url = str_replace($search, $replace, $link->url);
					}

					if ( $new_url == $link->url ){
						$skipped_links++;
						continue;
					}

					$rez = $link->edit($new_url);
					if ( !is_wp_error($rez) && empty($rez['errors'] )){
						$processed_links++;
					} else {
						$failed_links++;
					}
				}

				$message .= sprintf(
					_n(
						'%d Link aktualisiert.',
						'%d Links aktualisiert.',
						$processed_links,
						'psource-link-checker'
					),
					$processed_links
				);

				if ( $failed_links > 0 ) {
					$message .= '<br>' . sprintf(
						_n(
							'%d Link konnte nicht aktualisiert werden.',
							'%d Links konnten nicht aktualisiert werden.',
							$failed_links,
							'psource-link-checker'
						),
						$failed_links
					);
					$msg_class = 'error';
				}
			}
		}

		return array($message, $msg_class);
	}

	/**
	 * Escape all instances of the $delimiter character with a backslash (unless already escaped).
	 *
	 * @param string $pattern
	 * @param string $delimiter
	 * @return string
	 */
	private function escape_regex_delimiter($pattern, $delimiter) {
		if ( empty($pattern) ) {
			return '';
		}

		$output = '';
		$length = strlen($pattern);
		$escaped = false;

		for ($i = 0; $i < $length; $i++) {
			$char = $pattern[$i];

			if ( $escaped ) {
				$escaped = false;
			} else {
				if ( $char == '\\' ) {
					$escaped = true;
				} else if ( $char == $delimiter ) {
					$char = '\\' . $char;
				}
			}

			$output .= $char;
		}

		return $output;
	}

  /**
   * Unlink multiple links.
   *
   * @param array $selected_links
   * @return array Message and a CSS classname.
   */
	function do_bulk_unlink($selected_links){
		//Unlink all selected links.
		$message = '';
		$msg_class = 'updated';

		check_admin_referer( 'bulk-action' );

		if ( count($selected_links) > 0 ) {

			//Fetch all the selected links
			$links = blc_get_links(array(
				'link_ids' => $selected_links,
				'purpose' => BLC_FOR_EDITING,
			));

			if ( count($links) > 0 ) {
				$processed_links = 0;
				$failed_links = 0;

				//Unlink (delete) each one
				foreach($links as $link){
					$rez = $link->unlink();
					if ( ($rez == false) || is_wp_error($rez) ){
						$failed_links++;
					} else {
						$processed_links++;
					}
				}

				//This message is slightly misleading - it doesn't account for the fact that
				//a link can be present in more than one post.
				$message = sprintf(
					_n(
						'%d Link entfernt',
						'%d Links entfernt',
						$processed_links,
						'psource-link-checker'
					),
					$processed_links
				);

				if ( $failed_links > 0 ) {
					$message .= '<br>' . sprintf(
						_n(
							'%d Link konnte nicht entfernt werden',
							'%d Links konnten nicht entfernt werden',
							$failed_links,
							'psource-link-checker'
						),
						$failed_links
					);
					$msg_class = 'error';
				}
			}
		}

		return array($message, $msg_class);
	}

  /**
   * Delete or trash posts, bookmarks and other items that contain any of the specified links.
   *
   * Will prefer moving stuff to trash to permanent deletion. If it encounters an item that
   * can't be moved to the trash, it will skip that item by default.
   *
   * @param array $selected_links An array of link IDs
   * @param bool $force_delete Whether to bypass trash and force deletion. Defaults to false.
   * @return array Confirmation message and its CSS class.
   */
	function do_bulk_delete_sources($selected_links, $force_delete = false){
		$message = '';
		$msg_class = 'updated';

		//Delete posts, blogroll entries and any other link containers that contain any of the selected links.
		//
		//Note that once all containers containing a particular link have been deleted,
		//there is no need to explicitly delete the link record itself. The hooks attached to
		//the actions that execute when something is deleted (e.g. "post_deleted") will
		//take care of that.

		check_admin_referer( 'bulk-action' );

		if ( count($selected_links) > 0 ) {
			$messages = array();

			//Fetch all the selected links
			$links = blc_get_links(array(
				'link_ids' => $selected_links,
				'load_instances' => true,
			));

			//Make a list of all containers associated with these links, with each container
			//listed only once.
			$containers = array();
			foreach($links as $link){ /* @var blcLink $link */
				$instances = $link->get_instances();
				foreach($instances as $instance){ /* @var blcLinkInstance $instance */
					$key = $instance->container_type . '|' . $instance->container_id;
					$containers[$key] = array($instance->container_type, $instance->container_id);
				}
			}

			//Instantiate the containers
			$containers = blcContainerHelper::get_containers($containers);

			//Delete/trash their associated entities
			$deleted = array();
			$skipped = array();
			foreach($containers as $container){ /* @var blcContainer $container */
				if ( !$container->current_user_can_delete() ){
					continue;
				}

				if ( $force_delete ){
					$rez = $container->delete_wrapped_object();
				} else {
					if ( $container->can_be_trashed() ){
						$rez = $container->trash_wrapped_object();
					} else {
						$skipped[] = $container;
						continue;
					}
				}

				if ( is_wp_error($rez) ){ /* @var WP_Error $rez */
					//Record error messages for later display
					$messages[] = $rez->get_error_message();
					$msg_class = 'error';
				} else {
					//Keep track of how many of each type were deleted.
					$container_type = $container->container_type;
					if ( isset($deleted[$container_type]) ){
						$deleted[$container_type]++;
					} else {
						$deleted[$container_type] = 1;
					}
				}
			}

			//Generate delete confirmation messages
			foreach($deleted as $container_type => $number){
				if ( $force_delete ){
					$messages[] = blcContainerHelper::ui_bulk_delete_message($container_type, $number);
				} else {
					$messages[] = blcContainerHelper::ui_bulk_trash_message($container_type, $number);
				}

			}

			//If some items couldn't be trashed, let the user know
			if ( count($skipped) > 0 ){
				$message = sprintf(
					_n(
						"%d Element wurde übersprungen, da es nicht in den Papierkorb verschoben werden kann. Du musst es manuell löschen.",
						"%d Elemente wurden übersprungen, weil sie nicht in den Papierkorb verschoben werden können. Du musst sie manuell löschen.",
						count($skipped)
					),
					count($skipped)
				);
				$message .= '<br><ul>';
				foreach($skipped as $container){
					$message .= sprintf(
						'<li>%s</li>',
						$container->ui_get_source('')
					);
				}
				$message .= '</ul>';

				$messages[] = $message;
			}

			if ( count($messages) > 0 ){
				$message = implode('<p>', $messages);
			} else {
				$message = __("Ich habe nichts zum Löschen gefunden!", 'psource-link-checker');
				$msg_class = 'error';
			}
		}

		return array($message, $msg_class);
	}

  /**
   * Mark multiple links as unchecked.
   *
   * @param array $selected_links An array of link IDs
   * @return array Confirmation nessage and the CSS class to use with that message.
   */
	function do_bulk_recheck($selected_links){
		/** @var wpdb $wpdb */
		global $wpdb;

		$message = '';
		$msg_class = 'updated';

		check_admin_referer('bulk-action');

		if ( count($selected_links) > 0 ){
			$q = "UPDATE {$wpdb->prefix}blc_links
				  SET last_check_attempt = '0000-00-00 00:00:00'
				  WHERE link_id IN (".implode(', ', $selected_links).")";
			$changes = $wpdb->query($q);

			$message = sprintf(
				_n(
					"%d Link zur erneuten Überprüfung geplant",
					"%d Links zur erneuten Überprüfung geplant",
					$changes,
					'psource-link-checker'
				),
				$changes
			);
		}

		return array($message, $msg_class);
	}


	/**
	 * Mark multiple links as not broken.
	 *
	 * @param array $selected_links An array of link IDs
	 * @return array Confirmation nessage and the CSS class to use with that message.
	 */
	function do_bulk_discard($selected_links){
		check_admin_referer( 'bulk-action' );

		$messages = array();
		$msg_class = 'updated';
		$processed_links = 0;

		if ( count($selected_links) > 0 ){
			$transactionManager = TransactionManager::getInstance();
			$transactionManager->start();
			foreach($selected_links as $link_id){
				//Load the link
				$link = new blcLink( intval($link_id) );

				//Skip links that don't actually exist
				if ( !$link->valid() ){
					continue;
				}

				//Skip links that weren't actually detected as broken
				if ( !$link->broken && !$link->warning ){
					continue;
				}

				//Make it appear "not broken"
				$link->broken = false;
				$link->warning = false;
				$link->false_positive = true;
				$link->last_check_attempt = time();
				$link->log = __("Dieser Link wurde vom Benutzer manuell als funktionierend markiert.", 'psource-link-checker');

				$link->isOptionLinkChanged = true;
				//Save the changes
				if ( $link->save() ){
					$processed_links++;
				} else {
					$messages[] = sprintf(
						__("Link %d konnte nicht geändert werden", 'psource-link-checker'),
						$link_id
					);
					$msg_class = 'error';
				}
			}
		}

		if ( $processed_links > 0 ){
			$transactionManager->commit();
			$messages[] = sprintf(
				_n(
					'%d Link als nicht defekt markiert',
					'%d Links als nicht defekt markiert',
					$processed_links,
					'psource-link-checker'
				),
				$processed_links
			);
		}

		return array(implode('<br>', $messages), $msg_class);
	}

	/**
	 * Dismiss multiple links.
	 *
	 * @param array $selected_links An array of link IDs
	 * @return array Confirmation message and the CSS class to use with that message.
	 */
	function do_bulk_dismiss($selected_links){
		check_admin_referer( 'bulk-action' );

		$messages = array();
		$msg_class = 'updated';
		$processed_links = 0;

		if ( count($selected_links) > 0 ){
			$transactionManager = TransactionManager::getInstance();
			$transactionManager->start();
			foreach($selected_links as $link_id){
				//Load the link
				$link = new blcLink( intval($link_id) );

				//Skip links that don't actually exist
				if ( !$link->valid() ){
					continue;
				}

				//We can only dismiss broken links and redirects.
				if ( !($link->broken || $link->warning || ($link->redirect_count > 0)) ){
					continue;
				}

				$link->dismissed = true;

				$link->isOptionLinkChanged = true;
				//Save the changes
				if ( $link->save() ){
					$processed_links++;
				} else {
					$messages[] = sprintf(
						__("Link %d konnte nicht geändert werden", 'psource-link-checker'),
						$link_id
					);
					$msg_class = 'error';
				}
			}
		}

		if ( $processed_links > 0 ){
			$transactionManager->commit();
			$messages[] = sprintf(
				_n(
					'%d Link verworfen',
					'%d Links verworfen',
					$processed_links,
					'psource-link-checker'
				),
				$processed_links
			);
		}

		return array(implode('<br>', $messages), $msg_class);
	}


	/**
	 * Enqueue CSS files for the "Broken Links" page
	 *
	 * @return void
	 */
	function links_page_css(){
		wp_enqueue_style('blc-links-page', plugins_url('css/links-page.css', $this->loader), array(), '20141113-2');
	}

	/**
	 * Show an admin notice that explains what the "Warnings" section under "Tools -> Broken Links" does.
	 * The user can hide the notice.
	 */
	public function show_warnings_section_notice() {
		$is_warnings_section = isset($_GET['filter_id'])
			&& ($_GET['filter_id'] === 'warnings')
			&& isset($_GET['page'])
			&& ($_GET['page'] === 'view-broken-links');

		if ( !($is_warnings_section && current_user_can('edit_others_posts')) ) {
			return;
		}

		//Let the user hide the notice.
		$conf = blc_get_configuration();
		$notice_name = 'show_warnings_section_hint';

		if ( isset($_GET[$notice_name]) && is_numeric($_GET[$notice_name]) ) {
			$conf->set($notice_name, (bool)$_GET[$notice_name]);
			$conf->save_options();
		}
		if ( !$conf->get($notice_name, true) ) {
			return;
		}

		printf(
			'<div class="updated">
					<p>%1$s</p>
					<p>
						<a href="%2$s">%3$s</a> |
						<a href="%4$s">%5$s</a>
					<p>
				</div>',
			__(
				'Auf der Seite "Warnungen" werden Probleme aufgelistet, die wahrscheinlich vorübergehend sind oder bei denen der Verdacht besteht, dass sie falsch positiv sind.<br> Warnungen, die lange bestehen bleiben, werden normalerweise als defekte Links eingestuft.',
				'psource-link-checker'
			),
			esc_attr(add_query_arg($notice_name, '0')),
			_x(
				'Hinweis ausblenden',
				'admin notice under Tools - Broken links - Warnings',
				'psource-link-checker'
			),
			esc_attr(admin_url('options-general.php?page=link-checker-settings#blc_warning_settings')),
			_x(
				'Warneinstellungen ändern',
				'a link from the admin notice under Tools - Broken links - Warnings',
				'psource-link-checker'
			)
		);
	}

	/**
	 * Generate the HTML for the plugin's Screen Options panel.
	 *
	 * @return string
	 */
	function screen_options_html(){
		//Update the links-per-page setting when "Apply" is clicked
		if ( isset($_POST['per_page']) && is_numeric($_POST['per_page']) ) {
			check_admin_referer( 'screen-options-nonce', 'screenoptionnonce' );
			$per_page = intval($_POST['per_page']);
			if ( ($per_page >= 1) && ($per_page <= 500) ){
				$this->conf->options['table_links_per_page'] = $per_page;
				$this->conf->save_options();
			}
		}

		//Let the user show/hide individual table columns
		$html = '<h5>' . __('Tabellenspalten', 'psource-link-checker') . '</h5>';

		include dirname($this->loader) . '/includes/admin/table-printer.php';
		$table = new blcTablePrinter($this);
		$available_columns = $table->get_layout_columns($this->conf->options['table_layout']);

		$html .= '<div id="blc-column-selector" class="metabox-prefs">';

		foreach( $available_columns as $column_id => $data ){
			$html .= sprintf(
				'<label><input type="checkbox" name="visible_columns[%s]"%s>%s</label>',
				esc_attr($column_id),
				in_array($column_id, $this->conf->options['table_visible_columns']) ? ' checked="checked"' : '',
				$data['heading']
			);
		}

		$html .= '</div>';

		$html .= '<h5>' . __('Auf dem Bildschirm anzeigen', 'psource-link-checker') . '</h5>';
		$html .= '<div class="screen-options">';
		$html .= sprintf(
			'<input type="text" name="per_page" maxlength="3" value="%d" class="screen-per-page" id="blc_links_per_page" />
			<label for="blc_links_per_page">%s</label>
			<input type="button" class="button" value="%s" id="blc-per-page-apply-button" /><br />',
			$this->conf->options['table_links_per_page'],
			__('Links', 'psource-link-checker'),
			__('Apply')
		);
		$html .= '</div>';

		$html .= '<h5>' . __('Sonstiges', 'psource-link-checker') . '</h5>';
		$html .= '<div class="screen-options">';
		/*
		Display a checkbox in "Screen Options" that lets the user highlight links that
		have been broken for at least X days.
		*/
		$html .= sprintf(
			'<label><input type="checkbox" id="highlight_permanent_failures" name="highlight_permanent_failures"%s> ',
			$this->conf->options['highlight_permanent_failures'] ? ' checked="checked"' : ''
		);
		$input_box = sprintf(
			'</label><input type="text" name="failure_duration_threshold" id="failure_duration_threshold" value="%d" size="2"><label for="highlight_permanent_failures">',
			$this->conf->options['failure_duration_threshold']
		);
		$html .= sprintf(
			__('Markiere Links, die für mindestens %s Tage unterbrochen sind', 'psource-link-checker'),
			$input_box
		);
		$html .= '</label>';

		//Display a checkbox for turning colourful link status messages on/off
		$html .= sprintf(
			'<br/><label><input type="checkbox" id="table_color_code_status" name="table_color_code_status"%s> %s</label>',
			$this->conf->options['table_color_code_status'] ? ' checked="checked"' : '',
			__('Color-code status codes', 'psource-link-checker')
		);

		$html .= '</div>';

		return $html;
	}

	/**
	 * AJAX callback for saving the "Screen Options" panel settings
	 *
	 * @param array $form
	 * @return void
	 */
	function ajax_save_screen_options($form){
		if ( !current_user_can('edit_others_posts') ){
			die( json_encode( array(
				'error' => __("Das darfst du nicht!", 'psource-link-checker')
			 )));
		}

		$this->conf->options['highlight_permanent_failures'] = !empty($form['highlight_permanent_failures']);
		$this->conf->options['table_color_code_status'] = !empty($form['table_color_code_status']);

		$failure_duration_threshold = intval($form['failure_duration_threshold']);
		if ( $failure_duration_threshold >=1 ){
			$this->conf->options['failure_duration_threshold'] = $failure_duration_threshold;
		}

		if ( isset($form['visible_columns']) && is_array($form['visible_columns']) ){
			$this->conf->options['table_visible_columns'] = array_keys($form['visible_columns']);
		}

		$this->conf->save_options();
		die('1');
	}

	function start_timer(){
		$this->execution_start_time = microtime_float();
	}

	function execution_time(){
		return microtime_float() - $this->execution_start_time;
	}

  /**
   * The main worker function that does all kinds of things.
   *
   * @return void
   */
	function work(){
		global $blclog;

		//Close the session to prevent lock-ups.
		//PHP sessions are blocking. session_start() will wait until all other scripts that are using the same session
		//are finished. As a result, a long-running script that unintentionally keeps the session open can cause
		//the entire site to "lock up" for the current user/browser. ClassicPress itself doesn't use sessions, but some
		//plugins do, so we should explicitly close the session (if any) before starting the worker.
		if ( session_id() != '' ) {
			session_write_close();
		}

		if ( !$this->acquire_lock() ){
			//FB::warn("Another instance of BLC is already working. Stop.");
			$blclog->info('Eine andere Instanz von BLC arbeitet bereits. Halt.');
			return;
		}

		if ( $this->server_too_busy() ){
			//FB::warn("Server is too busy. Stop.");
			$blclog->warn('Die Serverlast ist zu hoch, Stoppe.');
			return;
		}

		$this->start_timer();
		$blclog->info('work() starts');

		$max_execution_time = $this->conf->options['max_execution_time'];

		/*****************************************
						Preparation
		******************************************/
		// Check for safe mode
		if( blcUtility::is_safe_mode() ){
			// Do it the safe mode way - obey the existing max_execution_time setting
			$t = ini_get('max_execution_time');
			if ($t && ($t < $max_execution_time))
				$max_execution_time = $t-1;
		} else {
			// Do it the regular way
			@set_time_limit( $max_execution_time * 2 ); //x2 should be plenty, running any longer would mean a glitch.
		}

		//Don't stop the script when the connection is closed
		ignore_user_abort( true );

		//Close the connection as per http://www.php.net/manual/en/features.connection-handling.php#71172
		//This reduces resource usage.
		//(Disable when debugging or you won't get the FirePHP output)
		if (
			!headers_sent()
			&& (defined('DOING_AJAX') && constant('DOING_AJAX'))
			&& (!defined('BLC_DEBUG') || !constant('BLC_DEBUG'))
		){
			@ob_end_clean(); //Discard the existing buffer, if any
			header("Connection: close");
			ob_start();
			echo ('Connection closed'); //This could be anything
			$size = ob_get_length();
			header("Content-Length: $size");
			ob_end_flush(); // Strange behaviour, will not work
			flush();        // Unless both are called !
		}

		//Load modules for this context
		$moduleManager = blcModuleManager::getInstance();
		$moduleManager->load_modules('work');

		$target_usage_fraction = $this->conf->get('target_resource_usage', 0.25);
		//Target usage must be between 1% and 100%.
		$target_usage_fraction = max(min($target_usage_fraction, 1), 0.01);


		/*****************************************
				Parse posts and bookmarks
		******************************************/

		$orphans_possible = false;
		$still_need_resynch = $this->conf->options['need_resynch'];

		if ( $still_need_resynch ) {

			//FB::log("Looking for containers that need parsing...");
			$max_containers_per_query = 50;

			$start = microtime(true);
			$containers = blcContainerHelper::get_unsynched_containers($max_containers_per_query);
			$get_containers_time = microtime(true) - $start;

			while( !empty($containers) ){
				//FB::log($containers, 'Found containers');
				$this->sleep_to_maintain_ratio($get_containers_time, $target_usage_fraction);

				foreach($containers as $container){
					$synch_start_time = microtime(true);

					//FB::log($container, "Parsing container");
					$container->synch();

					$synch_elapsed_time = microtime(true) - $synch_start_time;
					$blclog->info(sprintf(
						'Container %s[%s] analysiert in %.2f ms',
						$container->container_type,
						$container->container_id,
						$synch_elapsed_time * 1000
					));

					//Check if we still have some execution time left
					if( $this->execution_time() > $max_execution_time ){
						//FB::log('The allotted execution time has run out');
						blc_cleanup_links();
						$this->release_lock();
						return;
					}

					//Check if the server isn't overloaded
					if ( $this->server_too_busy() ){
						//FB::log('Server overloaded, bailing out.');
						blc_cleanup_links();
						$this->release_lock();
						return;
					}

					//Intentionally slow down parsing to reduce the load on the server. Basically,
					//we work $target_usage_fraction of the time and sleep the rest of the time.
					$this->sleep_to_maintain_ratio($synch_elapsed_time, $target_usage_fraction);
				}
				$orphans_possible = true;

				$start = microtime(true);
				$containers = blcContainerHelper::get_unsynched_containers($max_containers_per_query);
				$get_containers_time = microtime(true) - $start;
			}

			//FB::log('No unparsed items found.');
			$still_need_resynch = false;

		} else {
			//FB::log('Resynch not required.');
		}

		/******************************************
					Resynch done?
		*******************************************/
		if ( $this->conf->options['need_resynch'] && !$still_need_resynch ){
			$this->conf->options['need_resynch']  = $still_need_resynch;
			$this->conf->save_options();
		}

		/******************************************
					Remove orphaned links
		*******************************************/

		if ( $orphans_possible ) {
			$start = microtime(true);

			$blclog->info('Verwaiste Links entfernen.');
			blc_cleanup_links();

			$get_links_time = microtime(true) - $start;
			$this->sleep_to_maintain_ratio($get_links_time, $target_usage_fraction);
		}

		//Check if we still have some execution time left
		if( $this->execution_time() > $max_execution_time ){
			//FB::log('The allotted execution time has run out');
			$blclog->info('Die zugewiesene Ausführungszeit ist abgelaufen.');
			$this->release_lock();
			return;
		}

		if ( $this->server_too_busy() ){
			//FB::log('Server overloaded, bailing out.');
			$blclog->info('Serverlast zu hoch, Stoppe.');
			$this->release_lock();
			return;
		}

		/*****************************************
						Check links
		******************************************/
		$max_links_per_query = 30;

		$start = microtime(true);
		$links = $this->get_links_to_check($max_links_per_query);
		$get_links_time = microtime(true) - $start;

		while ( $links ){
			$this->sleep_to_maintain_ratio($get_links_time, $target_usage_fraction);

			//Some unchecked links found
			//FB::log("Checking ".count($links)." link(s)");
			$blclog->info("Checking ".count($links)." link(s)");

			//Randomizing the array reduces the chances that we'll get several links to the same domain in a row.
			shuffle($links);

			$transactionManager = TransactionManager::getInstance();
			$transactionManager->start();

			foreach ($links as $link) {
				//Does this link need to be checked? Excluded links aren't checked, but their URLs are still
				//tested periodically to see if they're still on the exclusion list.
				if ( !$this->is_excluded( $link->url ) ) {
					//Check the link.
					//FB::log($link->url, "Checking link {$link->link_id}");
					$link->check( true );
				} else {
					//FB::info("The URL {$link->url} is excluded, skipping link {$link->link_id}.");
					$link->last_check_attempt = time();
					$link->save();
				}

				//Check if we still have some execution time left
				if( $this->execution_time() > $max_execution_time ){
					$transactionManager->commit();
					//FB::log('The allotted execution time has run out');
					$blclog->info('Die zugewiesene Ausführungszeit ist abgelaufen.');
					$this->release_lock();
					return;
				}

				//Check if the server isn't overloaded
				if ( $this->server_too_busy() ){
					$transactionManager->commit();
					//FB::log('Server overloaded, bailing out.');
					$blclog->info('Serverlast zu hoch, Stoppe.');
					$this->release_lock();
					return;
				}
			}
			$transactionManager->commit();

			$start = microtime(true);
			$links = $this->get_links_to_check($max_links_per_query);
			$get_links_time = microtime(true) - $start;
		}
		//FB::log('No links need to be checked right now.');

		$this->release_lock();
		$blclog->info('work(): Alles erledigt.');
		//FB::log('All done.');
	}

	/**
	 * Sleep long enough to maintain the required $ratio between $elapsed_time and total runtime.
	 *
	 * For example, if $ratio is 0.25 and $elapsed_time is 1 second, this method will sleep for 3 seconds.
	 * Total runtime = 1 + 3 = 4, ratio = 1 / 4 = 0.25.
	 *
	 * @param float $elapsed_time
	 * @param float $ratio
	 */
	private function sleep_to_maintain_ratio($elapsed_time, $ratio) {
		if ( ($ratio <= 0) || ($ratio > 1) ) {
			return;
		}
		$sleep_time = $elapsed_time * ((1 / $ratio) - 1);
		if ($sleep_time > 0.0001) {
			/*global $blclog;
			$blclog->debug(sprintf(
				'Task took %.2f ms, sleeping for %.2f ms',
				$elapsed_time * 1000,
				$sleep_time * 1000
			));*/
			usleep($sleep_time * 1000000);
		}
	}

  /**
   * This function is called when the plugin's cron hook executes.
   * Its only purpose is to invoke the worker function.
   *
   * @uses wsBrokenLinkChecker::work()
   *
   * @return void
   */
	function cron_check_links(){
		$this->work();
	}

  /**
   * Rufe Links ab, die überprüft oder erneut überprüft werden müssen.
   *
   * @param integer $max_results The maximum number of links to return. Defaults to 0 = no limit.
   * @param bool $count_only If true, only the number of found links will be returned, not the links themselves.
   * @return int|blcLink[]
   */
	function get_links_to_check($max_results = 0, $count_only = false){
		global $wpdb; /* @var wpdb $wpdb */

		$check_threshold = date('Y-m-d H:i:s', strtotime('-'.$this->conf->options['check_threshold'].' hours'));
		$recheck_threshold = date('Y-m-d H:i:s', time() - $this->conf->options['recheck_threshold']);

		//FB::log('Looking for links to check (threshold : '.$check_threshold.', recheck_threshold : '.$recheck_threshold.')...');

		//Select some links that haven't been checked for a long time or
		//that are broken and need to be re-checked again. Links that are
		//marked as "being checked" and have been that way for several minutes
		//can also be considered broken/buggy, so those will be selected
		//as well.

		//Überprüft nur Links, die mindestens eine gültige Instanz haben (d.H. Eine Instanz ist vorhanden und
		//es entspricht einem der aktuell geladenen Container-/Parsertypen).
		$manager = blcModuleManager::getInstance();
		$loaded_containers = $manager->get_escaped_ids('container');
		$loaded_parsers = $manager->get_escaped_ids('parser');

		//Hinweis: Dies ist eine langsame Abfrage, aber AFAIK gibt es keine Möglichkeit, sie zu beschleunigen.
		//Ich könnte einen Index für last_check_attempt setzen, aber dieser Wert ist fast
		//sicherlich einzigartig für jede Zeile, daher wäre es nicht viel besser als ein vollständiger Tabellenscan.
		if ( $count_only ){
			$q = "SELECT COUNT(DISTINCT links.link_id)\n";
		} else {
			$q = "SELECT DISTINCT links.*\n";
		}
		$q .= "FROM {$wpdb->prefix}blc_links AS links
			INNER JOIN {$wpdb->prefix}blc_instances AS instances USING(link_id)
			WHERE
				(
					( last_check_attempt < %s )
					OR
					(
						(broken = 1 OR being_checked = 1)
						AND may_recheck = 1
						AND check_count < %d
						AND last_check_attempt < %s
					)
				)

			AND
				( instances.container_type IN ({$loaded_containers}) )
				AND ( instances.parser_type IN ({$loaded_parsers}) )
			";

		if ( !$count_only ){
			$q .= "\nORDER BY last_check_attempt ASC\n";
			if ( !empty($max_results) ){
				$q .= "LIMIT " . intval($max_results);
			}
		}

		$link_q = $wpdb->prepare(
			$q,
			$check_threshold,
			$this->conf->options['recheck_count'],
			$recheck_threshold
		);
		//FB::log($link_q, "Find links to check");
		//$blclog->debug("Find links to check: \n" . $link_q);

		//Wenn wir nur die Anzahl der Links benötigen, rufe diese ab und kehre zurück
		if ( $count_only ){
			return $wpdb->get_var($link_q);
		}

		//Holt die Verbindungsdaten
		$link_data = $wpdb->get_results($link_q, ARRAY_A);
		if ( empty($link_data) ){
			return array();
		}

		//Instanziiert blcLink-Objekte für alle abgerufenen Links
		$links = array();
		foreach($link_data as $data){
			$links[] = new blcLink($data);
		}

		return $links;
	}

  /**
   * Gibt den aktuellen Link Checker-Status im JSON-Format aus.
   * Ajax-Hook für die Aktion 'blc_full_status'.
   *
   * @return void
   */
	function ajax_full_status( ){
		$status = $this->get_status();
		$text = $this->status_text( $status );

		echo json_encode( array(
			'text' => $text,
			'status' => $status,
		 ) );

		die();
	}

  /**
   * Generiert eine Statusmeldung basierend auf den Statusinformationen in $status
   *
   * @param array $status
   * @return string
   */
	function status_text( $status ){
		$text = '';

		if( $status['broken_links'] > 0 ){
			$text .= sprintf(
				"<a href='%s' title='" . __('Anzeigen defekter Links', 'psource-link-checker') . "'><strong>".
					_n('%d defekter Link gefunden', '%d defekte Links gefunden', $status['broken_links'], 'psource-link-checker') .
				"</strong></a>",
				esc_attr(admin_url('tools.php?page=view-broken-links')),
				$status['broken_links']
			);
		} else {
			$text .= __("Keine defekten Links gefunden.", 'psource-link-checker');
		}

		$text .= "<br/>";

		if( $status['unchecked_links'] > 0) {
			$text .= sprintf(
				_n('%d URL in der Arbeitswarteschlange', '%d URLs in der Arbeitswarteschlange', $status['unchecked_links'], 'psource-link-checker'),
				$status['unchecked_links'] );
		} else {
			$text .= __("Keine URLs in der Arbeitswarteschlange.", 'psource-link-checker');
		}

		$text .= "<br/>";
		if ( $status['known_links'] > 0 ){
			$url_count = sprintf(
				_nx('%d eindeutige URL', '%d eindeutige URLs', $status['known_links'], 'für die Nachricht "Erkannte X eindeutige URLs in Y-Links"', 'psource-link-checker'),
				$status['known_links']
			);
			$link_count = sprintf(
				_nx('%d Link', '%d Links', $status['known_instances'], 'für die Nachricht "Erkannte X eindeutige URLs in Y-Links"', 'psource-link-checker'),
				$status['known_instances']
			);

			if ($this->conf->options['need_resynch']){
				$text .= sprintf(
					__('%1$s in %2$s erkannt und immer noch auf der Suche...', 'psource-link-checker'),
					$url_count,
					$link_count
				);
			} else {
				$text .= sprintf(
					__('%1$s in %2$s erkannt.', 'psource-link-checker'),
					$url_count,
					$link_count
				);
			}
		} else {
			if ($this->conf->options['need_resynch']){
				$text .= __('Durchsuche Deinen Blog nach Links...', 'psource-link-checker');
			} else {
				$text .= __('Keine Links erkannt.', 'psource-link-checker');
			}
		}

		return $text;
	}

  /**
   * @uses wsBrokenLinkChecker::ajax_full_status()
   *
   * @return void
   */
	function ajax_dashboard_status(){
		//Just display the full status.
		$this->ajax_full_status();
	}

  /**
   * Output the current average server load (over the last one-minute period).
   * Called via AJAX.
   *
   * @return void
   */
	function ajax_current_load(){
		$load = blcUtility::get_server_load();
		if ( empty($load) ){
			die( _x('Unbekannt', 'current load', 'psource-link-checker') );
		}

		$one_minute = reset($load);
		printf('%.2f', $one_minute);
		die();
	}

  /**
   * Gibt ein Array mit verschiedenen Statusinformationen zum Plugin zurück. Array-Schlüsselreferenz:
   *	check_threshold 	- date/time; Links, die vor diesem Schwellenwert überprüft wurden, sollten erneut überprüft werden.
   *	recheck_threshold 	- date/time; defekte Links, die vor diesem Schwellenwert überprüft wurden, sollten erneut überprüft werden.
   *	known_links 		- die Anzahl der erkannten eindeutigen URLs (ein irreführender Name, ja).
   *	known_instances 	- die Anzahl der erkannten Verbindungsinstanzen, d.h. tatsächliche Verbindungselemente in Beiträgen und anderen Stellen.
   *	broken_links		- die Anzahl der erkannten defekten Links.
   *	unchecked_links		- die Anzahl der URLs, die so schnell wie möglich überprüft werden müssen; basierend auf check_threshold und recheck_threshold.
   *
   * @return array
   */
	function get_status(){
		$blc_link_query = blcLinkQuery::getInstance();

		$check_threshold=date('Y-m-d H:i:s', strtotime('-'.$this->conf->options['check_threshold'].' hours'));
		$recheck_threshold=date('Y-m-d H:i:s', time() - $this->conf->options['recheck_threshold']);

		$known_links = blc_get_links(array('count_only' => true));
		$known_instances = blc_get_usable_instance_count();

		$broken_links = $blc_link_query->get_filter_links('broken', array('count_only' => true));

		$unchecked_links = $this->get_links_to_check(0, true);

		return array(
			'check_threshold' => $check_threshold,
			'recheck_threshold' => $recheck_threshold,
			'known_links' => $known_links,
			'known_instances' => $known_instances,
			'broken_links' => $broken_links,
			'unchecked_links' => $unchecked_links,
		 );
	}

	function ajax_work(){
		check_ajax_referer('blc_work');

		//Führt die Worker-Funktion aus
		$this->work();
		die();
	}

  /**
   * AJAX-Hook für die Schaltfläche "Nicht gebrochen". Markiert einen Link als unterbrochen und als wahrscheinlich falsch positiv.
   *
   * @return void
   */
	function ajax_discard(){
		if (!current_user_can('edit_others_posts') || !check_ajax_referer('blc_discard', false, false)){
			die( __("Das darfst du nicht!", 'psource-link-checker') );
		}

		if ( isset($_POST['link_id']) ){
			//Load the link
			$link = new blcLink( intval($_POST['link_id']) );

			if ( !$link->valid() ){
				printf( __("Hoppla, ich kann den Link %d nicht finden", 'psource-link-checker'), intval($_POST['link_id']) );
				die();
			}
			//Make it appear "not broken"
			$link->broken = false;
			$link->warning = false;
			$link->false_positive = true;
			$link->last_check_attempt = time();
			$link->log = __("Dieser Link wurde manuell als funktionierend markiert.", 'psource-link-checker');

			$link->isOptionLinkChanged = true;

			$transactionManager = TransactionManager::getInstance();
			$transactionManager->start();

			//Save the changes
			if ( $link->save() ){
				$transactionManager->commit();
				die( "OK" );
			} else {
				die( __("Hoppla, ich konnte den Link nicht ändern!", 'psource-link-checker') ) ;
			}
		} else {
			die( __("Fehler: link_id nicht angegeben", 'psource-link-checker') );
		}
	}

	public function ajax_dismiss(){
		$this->ajax_set_link_dismissed(true);
	}

	public function ajax_undismiss(){
		$this->ajax_set_link_dismissed(false);
	}

	private function ajax_set_link_dismissed($dismiss){
		$action = $dismiss ? 'blc_dismiss' : 'blc_undismiss';

		if (!current_user_can('edit_others_posts') || !check_ajax_referer($action, false, false)){
			die( __("Das darfst du nicht!", 'psource-link-checker') );
		}

		if ( isset($_POST['link_id']) ){
			//Load the link
			$link = new blcLink( intval($_POST['link_id']) );

			if ( !$link->valid() ){
				printf( __("Hoppla, ich kann den Link %d nicht finden", 'psource-link-checker'), intval($_POST['link_id']) );
				die();
			}

			$link->dismissed = $dismiss;

			//Save the changes
			$link->isOptionLinkChanged = true;
			$transactionManager = TransactionManager::getInstance();
			$transactionManager->start();
			if ( $link->save() ){
				$transactionManager->commit();
				die( "OK" );
			} else {
				die( __("Hoppla, ich konnte den Link nicht ändern!", 'psource-link-checker') ) ;
			}
		} else {
			die( __("Fehler: link_id nicht angegeben", 'psource-link-checker') );
		}
	}

  /**
   * AJAX hook for the inline link editor on Tools -> Broken Links.
   *
   * @return void
   */
	function ajax_edit(){
		if (!current_user_can('edit_others_posts') || !check_ajax_referer('blc_edit', false, false)){
			die( json_encode( array(
					'error' => __("Das darfst du nicht!", 'psource-link-checker')
				 )));
		}

		if ( empty($_POST['link_id']) || empty($_POST['new_url']) || !is_numeric($_POST['link_id']) ) {
			die( json_encode( array(
				'error' => __("Fehler: link_id oder new_url nicht angegeben", 'psource-link-checker')
			)));
		}

		//Load the link
		$link = new blcLink( intval($_POST['link_id']) );

		if ( !$link->valid() ){
			die( json_encode( array(
				'error' => sprintf( __("Hoppla, ich kann den Link %d nicht finden", 'psource-link-checker'), intval($_POST['link_id']) )
			)));
		}

		//Validate the new URL.
		$new_url = stripslashes($_POST['new_url']);
		$parsed = @parse_url($new_url);
		if ( !$parsed ){
			die( json_encode( array(
				'error' => __("Ups, die neue URL ist ungültig!", 'psource-link-checker')
			)));
		}

		if ( !current_user_can('unfiltered_html') ) {
			//Disallow potentially dangerous URLs like "javascript:...".
			$protocols = wp_allowed_protocols();
			$good_protocol_url = wp_kses_bad_protocol($new_url, $protocols);
			if ( $new_url != $good_protocol_url ) {
				die( json_encode( array(
					'error' => __("Ups, die neue URL ist ungültig!", 'psource-link-checker')
				)));
			}
		}

		$new_text = (isset($_POST['new_text']) && is_string($_POST['new_text'])) ? stripslashes($_POST['new_text']) : null;
		if ( $new_text === '' ) {
			$new_text = null;
		}
		if ( !empty($new_text) && !current_user_can('unfiltered_html') ) {
			$new_text = stripslashes(wp_filter_post_kses(addslashes($new_text))); //wp_filter_post_kses expects slashed data.
		}

		$rez = $link->edit($new_url, $new_text);
		if ( $rez === false ){
			die( json_encode( array(
				'error' => __("Ein unerwarteter Fehler ist aufgetreten!", 'psource-link-checker')
			)));
		} else {
			$new_link = $rez['new_link']; /** @var blcLink $new_link */
			$new_status = $new_link->analyse_status();
			$ui_link_text = null;
			if ( isset($new_text) ) {
				$instances = $new_link->get_instances();
				if ( !empty($instances) ) {
					$first_instance = reset($instances);
					$ui_link_text = $first_instance->ui_get_link_text();
				}
			}

			$response = array(
				'new_link_id' => $rez['new_link_id'],
				'cnt_okay' => $rez['cnt_okay'],
				'cnt_error' => $rez['cnt_error'],

				'status_text' => $new_status['text'],
				'status_code' => $new_status['code'],
				'http_code'   => empty($new_link->http_code) ? '' : $new_link->http_code,
				'redirect_count' => $new_link->redirect_count,

				'url' => $new_link->url,
				'escaped_url' => esc_url_raw($new_link->url),
				'final_url' => $new_link->final_url,
				'link_text' => isset($new_text) ? $new_text : null,
				'ui_link_text' => isset($new_text) ? $ui_link_text : null,

				'errors' => array(),
			);
			//url, status text, status code, link text, editable link text


			foreach($rez['errors'] as $error){ /** @var $error WP_Error */
				array_push( $response['errors'], implode(', ', $error->get_error_messages()) );
			}
			die( json_encode($response) );
		}
	}

  /**
   * AJAX hook for the "Unlink" action links in Tools -> Broken Links.
   * Removes the specified link from all posts and other supported items.
   *
   * @return void
   */
	function ajax_unlink(){
		if (!current_user_can('edit_others_posts') || !check_ajax_referer('blc_unlink', false, false)){
			die( json_encode( array(
					'error' => __("Das darfst du nicht!", 'psource-link-checker')
				 )));
		}

		if ( isset($_POST['link_id']) ){
			//Load the link
			$link = new blcLink( intval($_POST['link_id']) );

			if ( !$link->valid() ){
				die( json_encode( array(
					'error' => sprintf( __("Hoppla, ich kann den Link %d nicht finden", 'psource-link-checker'), intval($_POST['link_id']) )
				 )));
			}

			//Try and unlink it
			$rez = $link->unlink();

			if ( $rez === false ){
				die( json_encode( array(
					'error' => __("Ein unerwarteter Fehler ist aufgetreten!", 'psource-link-checker')
				 )));
			} else {
				$response = array(
					'cnt_okay' => $rez['cnt_okay'],
					'cnt_error' => $rez['cnt_error'],
					'errors' => array(),
				);
				foreach($rez['errors'] as $error){ /** @var WP_Error $error */
					array_push( $response['errors'], implode(', ', $error->get_error_messages()) );
				}

				die( json_encode($response) );
			}

		} else {
			die( json_encode( array(
					'error' => __("Fehler: link_id nicht angegeben", 'psource-link-checker')
				 )));
		}
	}

	public function ajax_deredirect() {
		if ( !current_user_can('edit_others_posts') || !check_ajax_referer('blc_deredirect', false, false) ){
			die( json_encode( array(
				'error' => __("Das darfst du nicht!", 'psource-link-checker')
			)));
		}

		if ( !isset($_POST['link_id']) || !is_numeric($_POST['link_id']) ) {
			die( json_encode( array(
				'error' => __("Fehler: link_id nicht angegeben", 'psource-link-checker')
			)));
		}

		$id = intval($_POST['link_id']);
		$link = new blcLink($id);

		if ( !$link->valid() ){
			die( json_encode( array(
				'error' => sprintf(__("Hoppla, ich kann den Link %d nicht finden", 'psource-link-checker'), $id)
			)));
		}

		//The actual task is simple; it's error handling that's complicated.
		$result = $link->deredirect();
		if ( is_wp_error($result) ) {
			die( json_encode( array(
				'error' => sprintf('%s [%s]', $result->get_error_message(), $result->get_error_code())
			)));
		}

		$link = $result['new_link'] /** @var blcLink $link */;

		$status = $link->analyse_status();
		$response = array(
			'url' => $link->url,
			'escaped_url' => esc_url_raw($link->url),
			'new_link_id' => $result['new_link_id'],

			'status_text' => $status['text'],
			'status_code' => $status['code'],
			'http_code'   => empty($link->http_code) ? '' : $link->http_code,
			'redirect_count' => $link->redirect_count,
			'final_url' => $link->final_url,

			'cnt_okay' => $result['cnt_okay'],
			'cnt_error' => $result['cnt_error'],
			'errors' => array(),
		);

		//Convert WP_Error's to simple strings.
		if ( !empty($result['errors']) ) {
			foreach($result['errors'] as $error) { /** @var WP_Error $error */
				$response['errors'][] = $error->get_error_message();
			}
		}

		die(json_encode($response));
	}

	/**
	 * AJAX hook for the "Recheck" action.
	 */
	public function ajax_recheck() {
		if (!current_user_can('edit_others_posts') || !check_ajax_referer('blc_recheck', false, false)){
			die( json_encode( array(
				'error' => __("Das darfst du nicht!", 'psource-link-checker')
			)));
		}

		if ( !isset($_POST['link_id']) || !is_numeric($_POST['link_id']) ) {
			die( json_encode( array(
				'error' => __("Fehler: link_id nicht angegeben", 'psource-link-checker')
			)));
		}

		$id = intval($_POST['link_id']);
		$link = new blcLink($id);

		if ( !$link->valid() ){
			die( json_encode( array(
				'error' => sprintf(__("Hoppla, ich kann den Link %d nicht finden", 'psource-link-checker'), $id)
			)));
		}

		$transactionManager = TransactionManager::getInstance();
		$transactionManager->start();

		//In case the immediate check fails, this will ensure the link is checked during the next work() run.
		$link->last_check_attempt = 0;
		$link->isOptionLinkChanged = true;
		$link->save();

		//Check the link and save the results.
		$link->check(true);

		$transactionManager->commit();

		$status = $link->analyse_status();
		$response = array(
			'status_text' => $status['text'],
			'status_code' => $status['code'],
			'http_code'   => empty($link->http_code) ? '' : $link->http_code,
			'redirect_count' => $link->redirect_count,
			'final_url' => $link->final_url,
		);

		die(json_encode($response));
	}

	function ajax_link_details(){
		global $wpdb; /* @var wpdb $wpdb */

		if (!current_user_can('edit_others_posts')){
			die( __("Du hast nicht genügend Berechtigungen, um auf diese Informationen zuzugreifen!", 'psource-link-checker') );
		}

		//FB::log("Loading link details via AJAX");

		if ( isset($_GET['link_id']) ){
			//FB::info("Link ID found in GET");
			$link_id = intval($_GET['link_id']);
		} else if ( isset($_POST['link_id']) ){
			//FB::info("Link ID found in POST");
			$link_id = intval($_POST['link_id']);
		} else {
			//FB::error('Link ID not specified, you hacking bastard.');
			die( __('Fehler: Link-ID nicht angegeben', 'psource-link-checker') );
		}

		//Load the link.
		$link = new blcLink($link_id);

		if ( !$link->is_new ){
			//FB::info($link, 'Link loaded');
			if ( !class_exists('blcTablePrinter') ){
				require dirname($this->loader) . '/includes/admin/table-printer.php';
			}
			blcTablePrinter::details_row_contents($link);
			die();
		} else {
			printf( __('Linkdetails konnten nicht geladen werden (%s)', 'psource-link-checker'), $wpdb->last_error );
			die();
		}
	}

  /**
   * Acquire an exclusive lock.
   * If we already hold a lock, it will be released and a new one will be acquired.
   *
   * @return bool
   */
	function acquire_lock(){
		return WPMutex::acquire('blc_lock');
	}

  /**
   * Relese our exclusive lock.
   * Does nothing if the lock has already been released.
   *
   * @return bool
   */
	function release_lock(){
		return WPMutex::release('blc_lock');
	}

  /**
   * Check if server is currently too overloaded to run the link checker.
   *
   * @return bool
   */
	function server_too_busy(){
		if ( !$this->conf->options['enable_load_limit'] || !isset($this->conf->options['server_load_limit']) ){
			return false;
		}

		$loads = blcUtility::get_server_load();
		if ( empty($loads) ){
			return false;
		}
		$one_minute = floatval(reset($loads));

		return $one_minute > $this->conf->options['server_load_limit'];
	}

	/**
	 * Register BLC's Dashboard widget
	 *
	 * @return void
	 */
	function hook_wp_dashboard_setup(){
		$show_widget = current_user_can($this->conf->get('dashboard_widget_capability', 'edit_others_posts'));
		if ( function_exists( 'wp_add_dashboard_widget' ) && $show_widget ) {
			wp_add_dashboard_widget(
				'blc_dashboard_widget',
				__('Link Checker', 'psource-link-checker'),
				array( $this, 'dashboard_widget' ),
				array( $this, 'dashboard_widget_control' )
			 );
		}
	}

  /**
   * Collect various debugging information and return it in an associative array
   *
   * @return array
   */
	function get_debug_info(){
		/** @var wpdb $wpdb */
		global $wpdb;

		//Collect some information that's useful for debugging
		$debug = array();

		//PHP version. Any one is fine as long as WP supports it.
		$debug[ __('PHP Version', 'psource-link-checker') ] = array(
			'state' => 'ok',
			'value' => phpversion(),
		);

		//MySQL version
		$debug[ __('MySQL Version', 'psource-link-checker') ] = array(
			'state' => 'ok',
			'value' => $wpdb->db_version(),
		);

		//CURL presence and version
		if ( function_exists('curl_version') ){
			$version = curl_version();

			if ( version_compare( $version['version'], '7.16.0', '<=' ) ){
				$data = array(
					'state' => 'warning',
					'value' => $version['version'],
					'message' => __('Du hast eine alte Version von CURL. Die Umleitungserkennung funktioniert möglicherweise nicht ordnungsgemäß.', 'psource-link-checker'),
				);
			} else {
				$data = array(
					'state' => 'ok',
					'value' => $version['version'],
				);
			}

		} else {
			$data = array(
				'state' => 'warning',
				'value' => __('Nicht installiert', 'psource-link-checker'),
			);
		}
		$debug[ __('CURL Version', 'psource-link-checker') ] = $data;

		//Snoopy presence
		if ( class_exists('Snoopy') || file_exists(ABSPATH. WPINC . '/class-snoopy.php') ){
			$data = array(
				'state' => 'ok',
				'value' => __('Installiert', 'psource-link-checker'),
			);
		} else {
			//No Snoopy? This should never happen, but if it does we *must* have CURL.
			if ( function_exists('curl_init') ){
				$data = array(
					'state' => 'ok',
					'value' => __('Nicht installiert', 'psource-link-checker'),
				);
			} else {
				$data = array(
					'state' => 'error',
					'value' => __('Nicht installiert', 'psource-link-checker'),
					'message' => __('Du musst entweder CURL oder Snoopy installiert haben, damit das Plugin funktioniert!', 'psource-link-checker'),
				);
			}

		}
		$debug['Snoopy'] = $data;

		//Safe_mode status
		if ( blcUtility::is_safe_mode() ){
			$debug['Safe mode'] = array(
				'state' => 'warning',
				'value' => __('An', 'psource-link-checker'),
				'message' => __('Weiterleitungen werden möglicherweise als fehlerhafte Links erkannt, wenn safe_mode aktiviert ist.', 'psource-link-checker'),
			);
		} else {
			$debug['Safe mode'] = array(
				'state' => 'ok',
				'value' => __('Aus', 'psource-link-checker'),
			);
		}

		//Open_basedir status
		if ( blcUtility::is_open_basedir() ){
			$debug['open_basedir'] = array(
				'state' => 'warning',
				'value' => sprintf( __('An ( %s )', 'psource-link-checker'), ini_get('open_basedir') ),
				'message' => __('Weiterleitungen werden möglicherweise als fehlerhafte Links erkannt, wenn open_basedir aktiviert ist.', 'psource-link-checker'),
			);
		} else {
			$debug['open_basedir'] = array(
				'state' => 'ok',
				'value' => __('Aus', 'psource-link-checker'),
			);
		}

		//Default PHP execution time limit
		$debug['Default PHP execution time limit'] = array(
			'state' => 'ok',
			'value' => sprintf(__('%s Sekunden'), ini_get('max_execution_time')),
		);

		//Database character set. Usually it's UTF-8. Setting it to something else can cause problems
		//unless the site owner really knows what they're doing.
		$charset = $wpdb->get_charset_collate();
		$debug[ __('Datenbankzeichensatz', 'psource-link-checker') ] = array(
			'state' => 'ok',
			'value' => !empty($charset) ? $charset : '-',
		);

		//Resynch flag.
		$debug['Resynch. flag'] = array(
			'state' => 'ok',
			'value' => sprintf('%d', $this->conf->options['need_resynch'] ? '1 (resynch. required)' : '0 (resynch. nicht benötigt)'),
		);

		//Synch records
		$synch_records = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}blc_synch"));
		$data = array(
			'state' => 'ok',
			'value' => sprintf('%d', $synch_records),
		);
		if ( $synch_records == 0 ){
			$data['state'] = 'warning';
			$data['message'] = __('Wenn dieser Wert auch nach mehreren Seiten-Neuladungen Null ist, ist wahrscheinlich ein Fehler aufgetreten.', 'psource-link-checker');
		}
		$debug['Synch. records'] = $data;

		//Total links and instances (including invalid ones)
		$all_links = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}blc_links"));
		$all_instances = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}blc_instances"));

		//Show the number of unparsed containers. Useful for debugging. For performance,
		//this is only shown when we have no links/instances yet.
		if( ($all_links == 0) && ($all_instances == 0) ){
			$unparsed_items = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}blc_synch WHERE synched=0"));
			$debug['Unparsed items'] = array(
				'state' => 'warning',
				'value' => $unparsed_items,
			);
		}

		//Links & instances
		if ( ($all_links > 0) && ($all_instances > 0) ){
			$debug['Link records'] = array(
				'state' => 'ok',
				'value' => sprintf('%d (%d)', $all_links, $all_instances),
			);
		} else {
			$debug['Link records'] = array(
				'state' => 'warning',
				'value' => sprintf('%d (%d)', $all_links, $all_instances),
			);
		}

		//Email notifications.
		if ( $this->conf->options['last_notification_sent'] ) {
			$notificationDebug = array(
				'value' => date('Y-m-d H:i:s T', $this->conf->options['last_notification_sent']),
				'state' => 'ok',
			);
		} else {
			$notificationDebug = array(
				'value' => 'Never',
				'state' => $this->conf->options['send_email_notifications'] ? 'ok' : 'warning',
			);
		}
		$debug['Last email notification'] = $notificationDebug;

		if ( isset($this->conf->options['last_email']) ) {
			$email = $this->conf->options['last_email'];
			$debug['Last email sent'] = array(
				'state' => 'ok',
				'value' => sprintf(
					'"%s" on %s (%s)',
					htmlentities($email['subject']),
					date('Y-m-d H:i:s T', $email['timestamp']),
					$email['success'] ? 'success' : 'failure'
				)
			);
		}


		//Installation log
		$logger = new blcCachedOptionLogger('blc_installation_log');
		$installation_log = $logger->get_messages();
		if ( !empty($installation_log) ){
			$debug['Installation log'] = array(
				'state' => $this->conf->options['installation_complete'] ? 'ok' : 'error',
				'value' => implode("<br>\n", $installation_log),
			);
		} else {
			$debug['Installation log'] = array(
				'state' => 'warning',
				'value' => 'Es wurde kein Installationsprotokoll gefunden.',
			);
		}

		return $debug;
	}

	function maybe_send_email_notifications() {
		global $wpdb; /** @var wpdb $wpdb */

		if ( !($this->conf->options['send_email_notifications'] || $this->conf->options['send_authors_email_notifications']) ){
			return;
		}

		//Find links that have been detected as broken since the last sent notification.
		$last_notification = date('Y-m-d H:i:s', $this->conf->options['last_notification_sent']);
		$where = $wpdb->prepare('( first_failure >= %s )', $last_notification);

		$links = blc_get_links(array(
			's_filter' => 'broken',
			'where_expr' => $where,
			'load_instances' => true,
			'load_containers' => true,
			'load_wrapped_objects' => $this->conf->options['send_authors_email_notifications'],
			'max_results' => 0,
		));

		if ( empty($links) ){
			return;
		}

		//Send the admin/maintainer an email notification.
		$email = $this->conf->get('notification_email_address');
		if ( empty($email) ) {
			//Default to the admin email.
			$email = get_option('admin_email');
		}
		if ( $this->conf->options['send_email_notifications'] && !empty($email) ) {
			$this->send_admin_notification($links, $email);
		}

		//Send notifications to post authors
		if ( $this->conf->options['send_authors_email_notifications'] ) {
			$this->send_authors_notifications($links);
		}

		$this->conf->options['last_notification_sent'] = time();
		$this->conf->save_options();
	}

	function send_admin_notification($links, $email) {
		//Prepare email message
		$subject = sprintf(
			__("[%s] Unterbrochene Links erkannt", 'psource-link-checker'),
			html_entity_decode(get_option('blogname'), ENT_QUOTES)
		);

		$body = sprintf(
			_n(
				"Link Checker hat %d neuen defekten Link auf Deiner Seite entdeckt.",
				"Link Checker hat %d neue defekte Links auf Deiner Seite entdeckt.",
				count($links),
				'psource-link-checker'
			),
			count($links)
		);
		$body .= "<br>";

		$instances = array();
		foreach($links as $link) { /* @var blcLink $link */
			$instances = array_merge($instances, $link->get_instances());
		}
		$body .= $this->build_instance_list_for_email($instances);

		if ( $this->is_textdomain_loaded && is_rtl() ) {
			$body = '<div dir="rtl">' . $body . '</div>';
		}

		$this->send_html_email($email, $subject, $body);
	}

	function build_instance_list_for_email($instances, $max_displayed_links = 5, $add_admin_link = true){
		if ( $max_displayed_links === null ) {
			$max_displayed_links = 5;
		}

		$result = '';
		if ( count($instances) > $max_displayed_links ){
			$line = sprintf(
				_n(
					"Hier ist eine Liste des ersten %d defekten Links:",
					"Hier ist eine Liste der ersten %d defekten Links:",
					$max_displayed_links,
					'psource-link-checker'
				),
				$max_displayed_links
			);
		} else {
			$line = __("Hier ist eine Liste der gefundenen defekten Links: ", 'psource-link-checker');
		}

		$result .= "<p>$line</p>";

		//Show up to $max_displayed_links broken link instances right in the email.
		$displayed = 0;
		foreach($instances as $instance){ /* @var blcLinkInstance $instance */
			$pieces = array(
				sprintf( __('Linktext: %s', 'psource-link-checker'), $instance->ui_get_link_text('email') ),
				sprintf( __('Link URL: <a href="%s">%s</a>', 'psource-link-checker'), htmlentities($instance->get_url()), blcUtility::truncate($instance->get_url(), 70, '') ),
				sprintf( __('Quelle: %s', 'psource-link-checker'), $instance->ui_get_source('email') ),
			);

			$link_entry = implode("<br>", $pieces);
			$result .= "$link_entry<br><br>";

			$displayed++;
			if ( $displayed >= $max_displayed_links ){
				break;
			}
		}

		//Add a link to the "Broken Links" tab.
		if ( $add_admin_link ) {
			$result .= __("Du kannst alle defekten Links hier sehen:", 'psource-link-checker') . "<br>";
			$result .= sprintf('<a href="%1$s">%1$s</a>', admin_url('tools.php?page=view-broken-links'));
		}

		return $result;
	}

	function send_html_email($email_address, $subject, $body) {
		//Need to override the default 'text/plain' content type to send a HTML email.
		add_filter('wp_mail_content_type', array($this, 'override_mail_content_type'));

		//Let auto-responders and similar software know this is an auto-generated email
		//that they shouldn't respond to.
		$headers = array('Auto-Submitted: auto-generated');

		$success = wp_mail($email_address, $subject, $body, $headers);

		//Remove the override so that it doesn't interfere with other plugins that might
		//want to send normal plaintext emails.
		remove_filter('wp_mail_content_type', array($this, 'override_mail_content_type'));

		$this->conf->options['last_email'] = array(
			'subject' => $subject,
			'timestamp' => time(),
			'success'    => $success,
		);
		$this->conf->save_options();

		return $success;
	}

	function send_authors_notifications($links) {
		$authorInstances = array();
		foreach($links as $link){ /* @var blcLink $link */
			foreach($link->get_instances() as $instance){ /* @var blcLinkInstance $instance */
				$container = $instance->get_container(); /** @var blcContainer $container */
				if ( empty($container) || !($container instanceof blcAnyPostContainer) ) {
					continue;
				}
				$post = $container->get_wrapped_object(); /** @var StdClass $post */
				if ( !array_key_exists($post->post_author, $authorInstances) ) {
					$authorInstances[$post->post_author] = array();
				}
				$authorInstances[$post->post_author][] = $instance;
			}
		}

		foreach($authorInstances as $author_id => $instances) {
			$subject = sprintf(
				__("[%s] Unterbrochene Links erkannt", 'psource-link-checker'),
				html_entity_decode(get_option('blogname'), ENT_QUOTES)
			);

			$body = sprintf(
				_n(
					"Link Checker hat %d neuen defekten Link in Deinen Inhalten entdeckt.",
					"Link Checker hat %d neue defekte Links in Deinen Inhalten entdeckt.",
					count($instances),
					'psource-link-checker'
				),
				count($instances)
			);
			$body .= "<br>";

			$author = get_user_by('id', $author_id); /** @var WP_User $author */
			$body .= $this->build_instance_list_for_email($instances, null, $author->has_cap('edit_others_posts'));

			if ( $this->is_textdomain_loaded && is_rtl() ) {
				$body = '<div dir="rtl">' . $body . '</div>';
			}

			$this->send_html_email($author->user_email, $subject, $body);
		}
	}

	function override_mail_content_type(/** @noinspection PhpUnusedParameterInspection */ $content_type){
		return 'text/html';
	}

	/**
	 * Promote all links with the "warning" status to "broken".
	 */
	private function promote_warnings_to_broken() {
		global $wpdb; /** @var wpdb $wpdb */
		$wpdb->update(
			$wpdb->prefix . 'blc_links',
			array(
				'broken'  => 1,
				'warning' => 0,
			),
			array(
				'warning' => 1,
			),
			'%d'
		);
	}

  /**
   * Install or uninstall the plugin's Cron events based on current settings.
   *
   * @uses wsBrokenLinkChecker::$conf Uses $conf->options to determine if events need to be (un)installed.
   *
   * @return void
   */
	function setup_cron_events(){

		//Link monitor
		if ( $this->conf->options['run_via_cron'] ){
			if (!wp_next_scheduled('blc_cron_check_links')) {
				wp_schedule_event( time(), '10min', 'blc_cron_check_links' );
			}
		} else {
			wp_clear_scheduled_hook('blc_cron_check_links');
		}

		//Email notifications about broken links
		if ( $this->conf->options['send_email_notifications'] || $this->conf->options['send_authors_email_notifications'] ){
			if ( !wp_next_scheduled('blc_cron_email_notifications') ){
				wp_schedule_event(time(), $this->conf->options['notification_schedule'], 'blc_cron_email_notifications');
			}
		} else {
			wp_clear_scheduled_hook('blc_cron_email_notifications');
		}

		//Run database maintenance every two weeks or so
		if ( !wp_next_scheduled('blc_cron_database_maintenance') ){
			wp_schedule_event(time(), 'daily', 'blc_cron_database_maintenance');
		}
	}

  /**
   * Load the plugin's textdomain.
   *
   * @return void
   */
	function load_language(){
		$this->is_textdomain_loaded = load_plugin_textdomain( 'psource-link-checker', false, basename(dirname($this->loader)) . '/languages' );
	}

	protected static function get_default_log_directory() {
		$uploads = wp_upload_dir();
		return $uploads['basedir'] . '/broken-link-checker';
	}

	protected static function get_default_log_basename() {
		return 'blc-log.txt';
	}

}//class ends here

} // if class_exists...
