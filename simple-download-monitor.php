<?php

/*
Plugin Name: Simple Download Monitor
Plugin URI: http://www.pepak.net/wordpress/simple-download-monitor-plugin
Description: Count the number of downloads without having to maintain a comprehensive download page.
Version: 0.19
Author: Pepak
Author URI: http://www.pepak.net
*/

/*  Copyright 2009, 2010  Pepak (email: wordpress@pepak.net)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if (!class_exists('SimpleDownloadMonitor'))
{
	class SimpleDownloadMonitor
	{

		const VERSION = '0.19';
		const PREFIX = 'sdmon_';
		const PREG_DELIMITER = '`';
		const GET_PARAM = 'sdmon';
		const RECORDS_PER_PAGE = 20;
		const GETTEXT_REALM = 'simple-download-monitor';

		protected $plugin_url = '';
		protected $plugin_dir = '';
		protected $plugin_dir_relative = '';

		public function SimpleDownloadMonitor()
		{
			$this->plugin_url = WP_PLUGIN_URL . '/' . dirname(plugin_basename(__FILE__));
			$this->plugin_dir = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(__FILE__));
			if (strpos($this->plugin_dir, ABSPATH) === 0)
				$this->plugin_dir_relative = substr($this->plugin_dir, strlen(ABSPATH));
			else
				$this->plugin_dir_relative = $this->plugin_dir;
			register_activation_hook(__FILE__, array('SimpleDownloadMonitor', 'Install'));
			add_action('init', array(&$this, 'ActionInit'));
			add_action('admin_menu', 'SimpleDownloadMonitor_BuildAdminMenu');
		}

		public static function Install()
		{
			global $wpdb;
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			$table_downloads = $wpdb->prefix . self::PREFIX . 'downloads';
			$sql = "CREATE TABLE ${table_downloads} (
				id INTEGER NOT NULL AUTO_INCREMENT,
				filename VARCHAR(1024) NOT NULL,
				download_count INTEGER NOT NULL,
				last_date TIMESTAMP NOT NULL,
				file_exists TINYINT,
				hide_from_sidebar TINYINT,
				PRIMARY KEY  id (id),
				KEY  download_count (download_count),
				KEY  last_date (last_date)
				);";
			dbDelta($sql);
			$table_details = $wpdb->prefix . self::PREFIX . 'details';
			$sql = "CREATE TABLE ${table_details} (
				id INTEGER NOT NULL AUTO_INCREMENT,
				download INTEGER NOT NULL,
				download_date TIMESTAMP NOT NULL,
				ip VARCHAR(64) NOT NULL,
				referer TEXT,
				userid INTEGER,
				username VARCHAR(64),
				PRIMARY KEY  id (id),
				KEY  download (download),
				KEY  download_date (download_date)
				);";
			dbDelta($sql);
			update_option(self::PREFIX . 'table_downloads', $table_downloads);
			update_option(self::PREFIX . 'table_details', $table_details);
			update_option(self::PREFIX . 'version', self::VERSION);
			add_option(self::PREFIX . 'directories', 'files/');
			add_option(self::PREFIX . 'extensions', 'zip|rar|7z');
			add_option(self::PREFIX . 'detailed', '0');
			add_option(self::PREFIX . 'inline', '');
			add_option(self::PREFIX . 'ignored_users', '');
			add_option(self::PREFIX . 'group_within', '0');
			add_option(self::PREFIX . 'rights_view', 'read');
			add_option(self::PREFIX . 'rights_delete', 'delete_users');
			add_option(self::PREFIX . 'rights_options', 'manage_options');
			add_option(self::PREFIX . 'max_downloads_per_ip_and_day', '0');
			add_option(self::PREFIX . 'max_downloads_per_ip_and_day_registered', '0');
			add_option(self::PREFIX . 'error_download_limit', '');
		}

		public function table_downloads()
		{
			static $table = null;
			if ($table == null)
				$table = get_option(self::PREFIX . 'table_downloads');
			return $table;
		}

		public function table_details()
		{
			static $table = null;
			if ($table == null)
				$table = get_option(self::PREFIX . 'table_details');
			return $table;
		}

		//-----------------------------------------------------------------------------------
		// Methods set_range, buffered_read, byteserve adapted from
		// http://www.coneural.org/florian/papers/04_byteserving.php

		function set_range($range, $filesize, &$first, &$last){
		  /*
		  Sets the first and last bytes of a range, given a range expressed as a string 
		  and the size of the file.

		  If the end of the range is not specified, or the end of the range is greater 
		  than the length of the file, $last is set as the end of the file.

		  If the begining of the range is not specified, the meaning of the value after 
		  the dash is "get the last n bytes of the file".

		  If $first is greater than $last, the range is not satisfiable, and we should 
		  return a response with a status of 416 (Requested range not satisfiable).

		  Examples:
		  $range='0-499', $filesize=1000 => $first=0, $last=499 .
		  $range='500-', $filesize=1000 => $first=500, $last=999 .
		  $range='500-1200', $filesize=1000 => $first=500, $last=999 .
		  $range='-200', $filesize=1000 => $first=800, $last=999 .

		  */
		  $dash=strpos($range,'-');
		  $first=trim(substr($range,0,$dash));
		  $last=trim(substr($range,$dash+1));
		  if ($first=='') {
		    //suffix byte range: gets last n bytes
		    $suffix=$last;
		    $last=$filesize-1;
		    $first=$filesize-$suffix;
		    if($first<0) $first=0;
		  } else {
		    if ($last=='' || $last>$filesize-1) $last=$filesize-1;
		  }
		  if($first>$last){
		    //unsatisfiable range
		    header("Status: 416 Requested range not satisfiable");
		    header("Content-Range: */$filesize");
		    exit;
		  }
		}

		function buffered_read($file, $bytes, $buffer_size=65536){
		  /*
		  Outputs up to $bytes from the file $file to standard output, $buffer_size bytes at a time.
		  */
		  $bytes_left=$bytes;
		  while($bytes_left>0 && !feof($file)){
		    if($bytes_left>$buffer_size)
		      $bytes_to_read=$buffer_size;
		    else
		      $bytes_to_read=$bytes_left;
		    $bytes_left-=$bytes_to_read;
		    $contents=fread($file, $bytes_to_read);
		    echo $contents;
		    flush();
		  }
		}

		function byteserve($filename, $mimetype, $disposition = ''){
		  /*
		  Byteserves the file $filename.  

		  When there is a request for a single range, the content is transmitted 
		  with a Content-Range header, and a Content-Length header showing the number 
		  of bytes actually transferred.

		  When there is a request for multiple ranges, these are transmitted as a 
		  multipart message. The multipart media type used for this purpose is 
		  "multipart/byteranges".
		  */

		  // Clean all buffering components, if any.
                  while (ob_list_handlers()) 
                    ob_end_clean();

		  $filesize=filesize($filename);
		  $file=fopen($filename,"rb");

		  $ranges=NULL;
		  if ($_SERVER['REQUEST_METHOD']=='GET' && isset($_SERVER['HTTP_RANGE']) && $range=stristr(trim($_SERVER['HTTP_RANGE']),'bytes=')){
		    $range=substr($range,6);
		    $boundary=sha1(uniqid());//set a random boundary
		    $ranges=explode(',',$range);
		  }

		  if($ranges && count($ranges)){
		    header("HTTP/1.1 206 Partial content");
		    header("Accept-Ranges: bytes");
		    if(count($ranges)>1){
		      /*
		      More than one range is requested. 
		      */

		      //compute content length
		      $content_length=0;
		      foreach ($ranges as $range){
		        $this->set_range($range, $filesize, $first, $last);
		        $content_length+=strlen("\r\n--$boundary\r\n");
		        $content_length+=strlen("Content-type: $mimetype\r\n");
		        $content_length+=strlen("Content-range: bytes $first-$last/$filesize\r\n\r\n");
		        $content_length+=$last-$first+1;          
		      }
		      $content_length+=strlen("\r\n--$boundary--\r\n");

		      //output headers
		      header("Content-Length: $content_length");
		      //see http://httpd.apache.org/docs/misc/known_client_problems.html for an discussion of x-byteranges vs. byteranges
		      header("Content-Type: multipart/x-byteranges; boundary=$boundary");

		      //output the content
		      foreach ($ranges as $range){
		        $this->set_range($range, $filesize, $first, $last);
		        echo "\r\n--$boundary\r\n";
		        echo "Content-type: $mimetype\r\n";
		        echo "Content-range: bytes $first-$last/$filesize\r\n\r\n";
		        fseek($file,$first);
		        $this->buffered_read ($file, $last-$first+1);          
		      }
		      echo "\r\n--$boundary--\r\n";
		    } else {
		      /*
		      A single range is requested.
		      */
		      $range=$ranges[0];
		      $this->set_range($range, $filesize, $first, $last);  
		      header("Content-Length: ".($last-$first+1) );
		      header("Content-Range: bytes $first-$last/$filesize");
		      header("Content-Type: $mimetype");  
		      if ($disposition) 
		    	  header("Content-Disposition: $disposition");
		      fseek($file,$first);
		      $this->buffered_read($file, $last-$first+1);
		    }
		  } else{
		    //no byteserving
		    header("Accept-Ranges: bytes");
		    header("Content-Length: $filesize");
		    header("Content-Type: $mimetype");
		    if ($disposition) 
		    	header("Content-Disposition: $disposition");
		    $this->buffered_read($file, $filesize);
		  }
		  fclose($file);
		}
		//-----------------------------------------------------------------------------------

		protected function ErrorMessage($code, $message, $shortmessage)
		{
			$code = intval($code);
			// Clean all buffering components, if any.
			while (ob_list_handlers()) 
				ob_end_clean();
			header('Cache-control: no-cache');
			if ($code >= 200)
				header(sprintf('%s %d %s', $_SERVER["SERVER_PROTOCOL"], $code, $shortmessage));
			get_header();
?><div class="error">
	<h2><?php echo __("Simple Download Monitor error", self::GETTEXT_REALM); ?></h2>
	<p><?php echo $message; ?></p>
<?php
			get_sidebar();
			get_footer();
			die();
		}
		
		public function Download($filename)
		{
			global $wpdb, $user_login, $user_ID;
			$store_details = intval(get_option(self::PREFIX . 'detailed'));
			$details = $this->table_details();
			$downloads = $this->table_downloads();
			$ip_addr = $_SERVER['REMOTE_ADDR'];
			// Normalize the filename
			$fullfilename = realpath(ABSPATH . '/' . $filename);
			$relfilename = substr($fullfilename, strlen(ABSPATH));
			$relfilename = strtr($relfilename, '\\', '/');
			$exists = (file_exists($fullfilename) AND !is_dir($fullfilename)) ? 1 : 0;
			// Make sure it is a valid request
			$dirregexp = self::PREG_DELIMITER . '^' . get_option(self::PREFIX . 'directories') . self::PREG_DELIMITER;
			$extregexp = self::PREG_DELIMITER . '\\.' . get_option(self::PREFIX . 'extensions') . '$' . self::PREG_DELIMITER;
			$valid = (preg_match($dirregexp, $relfilename) AND preg_match($extregexp, $relfilename)) ? 1 : 0;
			// Get user information and decide if this user should be ignored
			get_currentuserinfo();
			$userid = $user_ID ? $user_ID : null;
			$username = $user_login ? $user_login : null;
			$ignored_users = get_option(self::PREFIX . 'ignored_users');
			$monitor = empty($username) || empty($ignored_users) || (!in_array($username, explode('|', $ignored_users)));
			if ($monitor)
			{
				// Check the number of downloads per IP and day
				$max_downloads = intval(get_option(self::PREFIX . (is_user_logged_in() ? 'max_downloads_per_ip_and_day_registered' : 'max_downloads_per_ip_and_day')));
				if ($max_downloads > 0)
				{
					$row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) count, MIN(TIMESTAMPDIFF(MINUTE, NOW(), TIMESTAMPADD(DAY, 1, download_date))) retry FROM ${details} WHERE (download_date > TIMESTAMPADD(DAY, -1, NOW())) AND ip=%s", $ip_addr), ARRAY_N);
					list($download_count, $retry_minutes) = $row;
					if ($download_count >= $max_downloads)
					{
						$msg = get_option(self::PREFIX . 'error_download_limit');
						if ($msg == '') $msg = __("You have exceeded your download quota today. Please try again in %d hours and %d minutes.", self::GETTEXT_REALM);
						$this->ErrorMessage(403, sprintf($msg, $retry_minutes/60, abs($retry_minutes%60)), 'Forbidden');
					}
				}
				// Store uncorrected request name to database for security/mistake review
				$id = $wpdb->get_var($wpdb->prepare("SELECT id FROM ${downloads} WHERE filename=%s", $filename));
				if ($id)
				{
					// Ignore quick downloads by the same user
					if ($store_details && (($group_within = intval(get_option(self::PREFIX . 'group_within'))) > 0))
					{
						$grouped_id = $wpdb->get_var($wpdb->prepare("SELECT MIN(id) FROM ${details} WHERE download=%d AND (download_date > DATE_ADD(NOW(), INTERVAL -%d SECOND)) AND ip=%s", $id, $group_within, $ip_addr));
						if (intval($grouped_id) > 0)
							$monitor = FALSE;
					}
					if ($monitor)
					{
						$sql = "UPDATE ${downloads} SET download_count=download_count+1, last_date=NOW(), file_exists=%d WHERE id=%d";
						$wpdb->query($wpdb->prepare($sql, $exists * $valid, $id));
					}
				}
				else
				{
					$sql = "INSERT INTO ${downloads} (filename, download_count, last_date, file_exists) VALUES (%s, 1, NOW(), %d)";
					$wpdb->query($wpdb->prepare($sql, $filename, $exists * $valid));
					$id = $wpdb->insert_id;
				}
				// If details are requested, store them as well
				if ($monitor && $store_details)
				{
					$sql = "INSERT INTO ${details} (download, download_date, ip, referer, username, userid) VALUES (%d, NOW(), %s, %s, %s, %d)";
					$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
					if (!$username AND isset($_COOKIE['comment_author_'.COOKIEHASH]))
						$username = utf8_encode($_COOKIE['comment_author_'.COOKIEHASH]);
					$wpdb->query($wpdb->prepare($sql, $id, $ip_addr, $referer, $username, $userid));
				}
			}
			// If the file exists and is valid, download it
			// Make sure the file is available for download
			if (!$exists OR !$valid)
				$this->ErrorMessage(404, sprintf(__("Requested file <strong>%s</strong> not found."), htmlspecialchars($filename)), 'Not found');
			// Generate proper headers
			$mimetype = '';
			if (function_exists('finfo_open') AND defined('FILEINFO_MIME_TYPE'))
			{
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$mimetype = finfo_file($finfo, $fullfilename);
			}
			if (!$mimetype && function_exists('mime_content_type'))
				$mimetype = mime_content_type($fullfilename);
			if (!$mimetype || ((strpos($mimetype, '/')) === FALSE))
				$mimetype = 'application/octet-stream';
			$disposition = 'attachment';
			$inlineregexp = self::PREG_DELIMITER . get_option(self::PREFIX . 'inline') . self::PREG_DELIMITER;
			if ($inlineregexp && preg_match($inlineregexp, $relfilename))
				$disposition = 'inline';
			$disposition = $disposition . '; filename="' . basename($fullfilename) . '"';
			$this->byteserve($fullfilename, $mimetype, $disposition);
			// Successful end
			return TRUE;
		}

		public function ActionInit() {
			// Function is called in 'init' hook. It checks for download and if so, stops normal WordPress processing
			// and replaces it with its monitoring functions.
			$currentLocale = get_locale();
			if(!empty($currentLocale))
			{
				$moFile = $this->plugin_dir . "/lang/" . $currentLocale . ".mo";
				if(@file_exists($moFile) && is_readable($moFile))
					load_textdomain(self::GETTEXT_REALM, $moFile);
			}
			//load_plugin_textdomain(self::GETTEXT_REALM, $this->plugin_dir . '/lang');
			if (isset($_GET[self::GET_PARAM]) && ($filename = $_GET[self::GET_PARAM]))
			{
				if ($this->Download($filename))
					die();
				else
					wp_redirect(get_option('site_url'));
			}
		}

		public function AdminPanel()
		{
			// Function draws the admin panel.
			// First, post any modified options
			if (isset($_POST['SimpleDownloadMonitor_Submit']))
			{
				// Read options from the form
				$directories = strval($_POST[self::PREFIX . 'directories']);
				$extensions = strval($_POST[self::PREFIX . 'extensions']);
				$detailed = intval($_POST[self::PREFIX . 'detailed']);
				$inline = strval($_POST[self::PREFIX . 'inline']);
				$ignored_users = strval($_POST[self::PREFIX . 'ignored_users']);
				$group_within = intval($_POST[self::PREFIX . 'group_within']);
				$rights_view = strval($_POST[self::PREFIX . 'rights_view']);
				$rights_delete = strval($_POST[self::PREFIX . 'rights_delete']);
				$rights_options = strval($_POST[self::PREFIX . 'rights_options']);
				$downloads_per_day = intval($_POST[self::PREFIX . 'max_downloads_per_ip_and_day']);
				$downloads_per_day_registered = intval($_POST[self::PREFIX . 'max_downloads_per_ip_and_day_registered']);
				$error_download_limit = strval($_POST[self::PREFIX . 'error_download_limit']);
				// Remove slashes if necessary
				if (get_magic_quotes_gpc())
				{
					$directories = stripslashes($directories);
					$extensions = stripslashes($extensions);
					$inline = stripslashes($inline);
					$ignored_users = stripslashes($ignored_users);
					$error_download_limit = stripslashes($error_download_limit);
				}
				// Escape the delimiter
				list($directories, $extensions) = str_replace(self::PREG_DELIMITER, '\\'.self::PREG_DELIMITER, array($directories, $extensions));
				// Write the changes to database
				update_option(self::PREFIX . 'directories', $directories);
				update_option(self::PREFIX . 'extensions', $extensions);
				update_option(self::PREFIX . 'detailed', $detailed);
				if (($inline == '') OR (strlen($inline) >= 3))
					update_option(self::PREFIX . 'inline', $inline);
				update_option(self::PREFIX . 'ignored_users', $ignored_users);
				update_option(self::PREFIX . 'group_within', $group_within);
				update_option(self::PREFIX . 'rights_view', $rights_view);
				update_option(self::PREFIX . 'rights_delete', $rights_delete);
				update_option(self::PREFIX . 'rights_options', $rights_options);
				update_option(self::PREFIX . 'max_downloads_per_ip_and_day', $downloads_per_day);
				update_option(self::PREFIX . 'max_downloads_per_ip_and_day_registered', $downloads_per_day_registered);
				update_option(self::PREFIX . 'error_download_limit', $error_download_limit);
			}
			// Load options from the database
			$directories = get_option(self::PREFIX . 'directories');
			$extensions = get_option(self::PREFIX . 'extensions');
			$detailed = get_option(self::PREFIX . 'detailed');
			$inline = get_option(self::PREFIX . 'inline');
			$ignored_users = get_option(self::PREFIX . 'ignored_users');
			$group_within = intval(get_option(self::PREFIX . 'group_within'));
			$rights_view = get_option(self::PREFIX . 'rights_view');
			$rights_delete = get_option(self::PREFIX . 'rights_delete');
			$rights_options = get_option(self::PREFIX . 'rights_options');
			$downloads_per_day = get_option(self::PREFIX . 'max_downloads_per_ip_and_day');
			$downloads_per_day_registered = get_option(self::PREFIX . 'max_downloads_per_ip_and_day_registered');
			$error_download_limit = get_option(self::PREFIX . 'error_download_limit');
			// Build the form
			?>
<div class="wrap">
<form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
	<h2><?php echo __('Simple Download Monitor options', self::GETTEXT_REALM); ?></h2>
	<h3><?php echo __('Access rights', self::GETTEXT_REALM); ?></h3>
	<p><?php echo __('You can set up user rights required to access various functions of Simple Download Monitor. Rights are assigned through capabilities (see <a href="http://codex.wordpress.org/Roles_and_Capabilities#Roles">Roles and Capabilities</a> in WordPress Codex). Predefined values are <strong>read</strong> ("any registered user") for displaying stats, <strong>delete_users</strong> ("administrator") for reseting stats and <strong>manage_options</strong> ("administrator") for changing options.', self::GETTEXT_REALM); ?></p>
	<p><?php echo __('Capability required for viewing download stats:', self::GETTEXT_REALM); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>rights_view" value="<?php echo esc_attr($rights_view); ?>" /></p>
	<p><?php echo __('Capability required for reseting download stats:', self::GETTEXT_REALM); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>rights_delete" value="<?php echo esc_attr($rights_delete); ?>" /></p>
	<p><?php echo __('Capability required for setting SDMON options:', self::GETTEXT_REALM); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>rights_options" value="<?php echo esc_attr($rights_options); ?>" /></p>
	<h3><?php echo __('Allowed directories', self::GETTEXT_REALM); ?></h3>
	<p><?php echo __("Only requested files whose full names (relative to document root) start with this regular expression will be processed. It is strongly recommended to place all downloadable files (and ONLY downloadable files) into a designated directory and then placing that directory's name followed by a slash here. It is possible to use the power of PREG to allow multiple directories, but make sure there are ONLY files which you are comfortable with malicious users downloading. Do not EVER allow directories which contain PHP files here! That could lead to disclosure of sensitive data, including username and password used to connect to WordPress database.", self::GETTEXT_REALM); ?></p>
	<p><?php echo __("Default value is <code>files/</code>, which only allows download from /files directory (the leading <code>/</code> is implicit).", self::GETTEXT_REALM); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>directories" value="<?php echo esc_attr($directories); ?>" /></p>
	<h3><?php echo __('Allowed extensions', self::GETTEXT_REALM); ?></h3>
	<p><?php echo __('Only files with extensions matching this regular expressions will be processed. This is another important security value. Make sure you only add extensions which are safe for malicious users to have, e.g. archives and possibly images. Do NOT use any expression that could allow a user to download PHP files, even if you think it safe given the Allowed Directories option above.', self::GETTEXT_REALM); ?></p>
	<p><?php echo __("Default value is <code>zip|rar|7z</code> which only allows download of files ending with <code>.zip</code>, <code>.rar</code> and <code>.7z</code> (the leading <code>.</code> is implicit).", self::GETTEXT_REALM); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>extensions" value="<?php echo esc_attr($extensions); ?>" /></p>
	<h3><?php echo __('Inline files', self::GETTEXT_REALM); ?></h3>
	<p><?php echo __('Files whose names match this regular expression will be displayed inline (within a HTML page) rather than downloaded.', self::GETTEXT_REALM); ?></p>
	<p><?php echo __("By default, this value is empty - no files will appear inline, all will be downloaded. You may want to place something like <code>\.(jpe?g|gif|png|swf)$</code> here to make images and Flash videos appear inline.", self::GETTEXT_REALM); ?></p>
	<p><?php echo __('Note: Unlike the options above, nothing is implied in this regular expression. You <em>must</em> use an explicit <code>\.</code> to denote "start of extension", you <em>must</em> use an explicit <code>$</code> to mark "end of filename", etc.', self::GETTEXT_REALM); ?></p>
	<p><?php echo __('Also note that this plugin uses PCRE-compatible regular expressions, NOT the better-known POSIX-compatible regular expressions. As a result, a valid regular expression must be at least three characters long - separator twice, and at least one character for a meaningful r.e.', self::GETTEXT_REALM); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>inline" value="<?php echo esc_attr($inline); ?>" /></p>
	<h3><?php echo __("Store detailed logs?", self::GETTEXT_REALM); ?></h3>
	<p><?php echo __("If detailed logs are allowed, various information (including exact time of download, user's IP address, referrer etc.) is stored. This can fill your database quickly if you have only a little space or a lot of popular downloads. Otherwise just the total numbers of downloads are stored, consuming significantly less space.", self::GETTEXT_REALM); ?></p>
	<p><label for="<?php echo self::PREFIX; ?>detailed"><input type="checkbox" name="<?php echo self::PREFIX; ?>detailed" value="1" <?php if ($detailed) echo 'checked="checked" '; ?>/> <?php echo __('Use detailed statistics.', self::GETTEXT_REALM); ?></label></p>
	<h3><?php echo __("Ignored users", self::GETTEXT_REALM); ?></h3>
	<p><?php echo __("List of users whose downloads are not monitored. Separate multiple users with pipe character <code>|</code>. It is useful to prevent administrator damaging the statistics by testing that downloads work.", self::GETTEXT_REALM); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>ignored_users" value="<?php echo esc_attr($ignored_users); ?>" /></p>
	<h3><?php echo __("Ignore quick re-downloads", self::GETTEXT_REALM); ?></h3>
	<p><?php echo __("If one IP address requests the same download several times within a given time interval, only the first time will be recorded. If a zero or a negative value is entered, all downloads will get recorded regardless of how quickly they occur after each other.", self::GETTEXT_REALM); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>group_within" value="<?php echo esc_attr($group_within); ?>" /> <?php echo __('seconds', self::GETTEXT_REALM); ?></p>
	<h3><?php echo __("Limit number of downloads per IP address", self::GETTEXT_REALM); ?></h3>
	<p><?php echo __("Limit the number of files an IP address can download per day. The default value of zero means 'no limits' - an IP address can download as many files as it likes. Note that various download managers can initiate several downloads for each file, so make sure the limit is high enough not to interfere with the normal usage of your site. Also, please understand that this is NOT a perfect solution: One IP address can be shared by multiple users, and one user can easily use more than one IP address.", self::GETTEXT_REALM); ?></p>
	<p><?php echo __("Users listed in the <strong>Ignored users</strong> section above can always download an unlimited number of files.", self::GETTEXT_REALM); ?></p>
	<p><?php echo __("Visitors:", self::GETTEXT_REALM); ?> <input type="text" name="<?php echo self::PREFIX; ?>max_downloads_per_ip_and_day" value="<?php echo esc_attr($downloads_per_day); ?>" /></p>
	<p><?php echo __("Registered users:", self::GETTEXT_REALM); ?> <input type="text" name="<?php echo self::PREFIX; ?>max_downloads_per_ip_and_day_registered" value="<?php echo esc_attr($downloads_per_day_registered); ?>" /></p>
	<p><?php echo __("Error message:", self::GETTEXT_REALM); ?></p>
	<p><textarea name="<?php echo self::PREFIX; ?>error_download_limit" rows="4" cols="64"><?php echo htmlspecialchars($error_download_limit); ?></textarea></p>
	<p><?php echo __("(The first <code>%d</code> will be replaced by number of hours, the second one by number of minutes.)", self::GETTEXT_REALM); ?>
	<p>&nbsp;</p>
	<div class="submit"><input type="submit" name="SimpleDownloadMonitor_Submit" value="<?php echo __("Update settings", self::GETTEXT_REALM) ?>" /></div>
</form>
</div><?php
		}

		public function ToolsPanel()
		{
			$download = isset($_GET['download']) ? intval($_GET['download']) : 0;
			$from = isset($_GET['from']) ? intval($_GET['from']) : 0;
			$order = isset($_GET['order']) ? $_GET['order'] : '';
			$flags = isset($_GET['flags']) ? intval($_GET['flags']) : 0;
			$detailed = get_option(self::PREFIX . 'detailed');
			$options = array('download' => $download, 'from' => $from, 'order' => $order, 'flags' => $flags);
			if ($this->IsAdmin())
			{
				if (isset($_POST['SimpleDownloadMonitor_Update']) && isset($_POST['SimpleDownloadMonitor_HideIds']) && is_array($_POST['SimpleDownloadMonitor_HideIds']) && isset($_POST['SimpleDownloadMonitor_ShowIds']) && is_array($_POST['SimpleDownloadMonitor_ShowIds']))
				{
					$this->HideDownloads($_POST['SimpleDownloadMonitor_ShowIds'], $_POST['SimpleDownloadMonitor_HideIds']);
				}
				if (isset($_POST['SimpleDownloadMonitor_Delete']) && isset($_POST['SimpleDownloadMonitor_DeleteIds']) && is_array($_POST['SimpleDownloadMonitor_DeleteIds'])) 
				{
					$this->DeleteDownloads($_POST['SimpleDownloadMonitor_DeleteIds']);
				} 
				elseif (isset($_POST['SimpleDownloadMonitor_DeleteAll']) && ($_POST['SimpleDownloadMonitor_DeleteAllReally'] == 'yes')) 
				{
					$this->DeleteAllDownloads();
				}
				if (isset($_POST['SimpleDownloadMonitor_DeleteDetail']) && isset($_POST['SimpleDownloadMonitor_DeleteIds']) && is_array($_POST['SimpleDownloadMonitor_DeleteIds'])) 
				{
					$this->DeleteDownloadDetails($_POST['SimpleDownloadMonitor_DeleteIds']);
				} 
			}
			if ($detailed && $download)
				$this->DetailedDownloadList($options);
			else
				$this->DownloadList($options);
		}

		const ORDER_NAME    = 'name';
		const ORDER_COUNT   = 'count';
		const ORDER_DATE    = 'date';
		const ORDER_IP      = 'ip';
		const ORDER_REFERER = 'referer';
		const ORDER_USER    = 'user';

		protected function GetOrderBy($order = '')
		{
			static $orders = array(
				self::ORDER_NAME  => 'filename',
				self::ORDER_COUNT => 'download_count DESC, filename',
				self::ORDER_DATE  => 'last_date DESC, filename',
				);
			$result = isset($orders[$order]) ? $orders[$order] : $orders[self::ORDER_COUNT];
			$result = " ORDER BY ${result} ";
			return $result;
		}

		protected function GetDetailOrderBy($order = '')
		{
			static $orders = array(
				self::ORDER_DATE    => 'download_date DESC',
				self::ORDER_IP      => 'ip, download_date DESC',
				self::ORDER_REFERER => 'referer, download_date DESC',
				self::ORDER_USER    => 'username, download_date DESC',
				);
			$result = isset($orders[$order]) ? $orders[$order] : $orders[self::ORDER_DATE];
			$result = " ORDER BY ${result} ";
			return $result;
		}

		const FLAGS_NOTEXISTING = 1;

		protected function GetWhere($flags = 0)
		{
			$conditions = array();
			if ($flags & self::FLAGS_NOTEXISTING)
				$conditions[] = '(file_exists=0)';
			else
				$conditions[] = '(file_exists<>0)';
			if ($conditions)
				$result = ' WHERE ' . implode(' AND ', $conditions);
			else
				$result = '';
			return $result;
		}

		protected function GetDetailWhere($flags = 0)
		{
			$conditions = array();
			if ($conditions)
				$result = ' AND ' . implode(' AND ', $conditions);
			else
				$result = '';
			return $result;
		}

		protected function GetLimit($from = 0)
		{
			$from = intval($from);
			if ($from < 0)
				$from = 0;
			$count = self::RECORDS_PER_PAGE;
			$result = " LIMIT ${from}, ${count} ";
			return $result;
		}

		protected function GetUrlForList($options = array(), $html = TRUE)
		{
			$amp = $html ? '&amp;' : '&';
			$result = get_option('site_url') . 'tools.php?page=' . basename(__FILE__);
			foreach ($options as $name => $value)
				if ($value)
					$result .= $amp . ($html ? htmlspecialchars($name) : $name) . '=' . ($html ? htmlspecialchars($value) : $value);
			return $result;

		}

		protected function Paginator($options, $count)
		{
			$from = intval($options['from']);
			$count = intval($count);
			$pages = array();
			if ($from > 0)
			{
				$pages[] = '<div style="float: left;">';
				$pages[] = '<a href="' . $this->GetUrlForList(array_merge($options, array('from'=>0))) . '">' . __("First", self::GETTEXT_REALM) . '</a>';
				$pages[] = '<a href="' . $this->GetUrlForList(array_merge($options, array('from'=>($from>self::RECORDS_PER_PAGE ? $from-self::RECORDS_PER_PAGE : 0)))) . '">' . __("Previous", self::GETTEXT_REALM) . '</a>';
				$pages[] = '</div>';
			}

			if (($from + self::RECORDS_PER_PAGE) < $count)
			{
				$pages[] = '<div style="float: right;">';
				$pages[] = '<a href="' . $this->GetUrlForList(array_merge($options, array('from'=>$from+self::RECORDS_PER_PAGE))) . '">' . __("Next", self::GETTEXT_REALM) . '</a>';
				$pages[] = '<a href="' . $this->GetUrlForList(array_merge($options, array('from'=>$count-self::RECORDS_PER_PAGE))) . '">' . __("Last", self::GETTEXT_REALM) . '</a>';
				$pages[] = '</div>';
			}
			$result = $pages ? '<div class="pages-list">' . implode(' ', $pages) . '<div style="clear: both;" /></div>' : '';
			return $result;
		}

		protected function IsAdmin()
		{
			if (current_user_can(get_option(self::PREFIX . 'rights_delete')))
			/*
			global $user_level;
			get_currentuserinfo();
			if ($user_level >= 10)
			*/
				return TRUE;
			else
				return FALSE;
		}

		protected function HideDownloads($show_ids, $hide_ids = array())
		{
			global $wpdb;
			$show_ids = implode(',', array_map('intval', $show_ids));
			$hide_ids = implode(',', array_map('intval', $hide_ids));
			$downloads = $this->table_downloads();
			if ($show_ids)
			{
				$sql = "UPDATE ${downloads} SET hide_from_sidebar=0, last_date=last_date WHERE id IN (${show_ids})";
				// The last_date is used to prevent automatic update to current_timestamp
				$wpdb->query($sql);
			}
			if ($hide_ids)
			{
				$sql = "UPDATE ${downloads} SET hide_from_sidebar=1, last_date=last_date WHERE id IN (${hide_ids})";
				// The last_date is used to prevent automatic update to current_timestamp
				$wpdb->query($sql);
			}
			//wp_redirect($_SERVER['REQUEST_URI']);
			//die();
		}

		protected function DeleteDownloadDetails($ids = array())
		{
			global $wpdb;
			$ids = array_map('intval', $ids);
			if ($ids)
			{
				$ids = implode(',', $ids);
				$downloads = $this->table_downloads();
				$details = $this->table_details();
				$downloadids = array();
				$sql = "SELECT DISTINCT download FROM ${details} WHERE id IN (${ids})";
				$results = $wpdb->get_results($sql, ARRAY_N);
				if (is_array($results))
					foreach ($results as $row)
					{
						list($downloadid) = $row;
						$downloadids[] = $downloadid;
					}
				if ($downloadids) {
					$sql = "DELETE FROM ${details} WHERE id IN (${ids})";
					$wpdb->query($sql);
					foreach ($downloadids as $downloadid)
					{
						$sql = "SELECT COUNT(*), MAX(download_date) FROM ${details} WHERE download=%d";
						$result = $wpdb->get_row($wpdb->prepare($sql, $downloadid), ARRAY_N);
						if (is_array($result))
						{
							list($count, $date) = $result;
							$sql = "UPDATE ${downloads} SET download_count=%d, last_date=%s WHERE id=%d";
							$wpdb->query($wpdb->prepare($sql, $count, $date, $downloadid));
						}
					}
				}
			}
			//wp_redirect($_SERVER['REQUEST_URI']);
			//die();
		}

		protected function DeleteDownloads($ids = array())
		{
			global $wpdb;
			$ids = array_map('intval', $ids);
			if ($ids)
			{
				$ids = implode(',', $ids);
				$downloads = $this->table_downloads();
				$details = $this->table_details();
				$sql = "DELETE FROM ${downloads} WHERE id IN (${ids})";
				$wpdb->query($sql);
				$sql = "DELETE FROM ${details} WHERE download IN (${ids})";
				$wpdb->query($sql);
			}
			//wp_redirect($_SERVER['REQUEST_URI']);
			//die();
		}

		protected function DeleteAllDownloads()
		{
			global $wpdb;
			$downloads = $this->table_downloads();
			$details = $this->table_details();
			$sql = "DELETE FROM ${downloads}";
			$wpdb->query($sql);
			$sql = "DELETE FROM ${details}";
			$wpdb->query($sql);
			//wp_redirect($_SERVER['REQUEST_URI']);
			//die();
		}

		protected function DownloadList($options)
		{
			global $wpdb;
			$flags = $options['flags'];
			$from = $options['from'];
			$order = $options['order'];
			$detailed = get_option(self::PREFIX . 'detailed');
			?>
<div class="wrap">
<h2><?php echo __('Simple Download Monitor', self::GETTEXT_REALM); ?></h2>
<h3><?php echo ($options['flags'] & self::FLAGS_NOTEXISTING) ? __('Nonexistent downloads', self::GETTEXT_REALM) : __('All downloads', self::GETTEXT_REALM); ?></h3>
<p><a href="<?php echo $this->GetUrlForList(array_merge($options, array('from' => 0, 'flags' => $options['flags']^self::FLAGS_NOTEXISTING))); ?>"><?php echo ($options['flags'] & self::FLAGS_NOTEXISTING) ? __('Show all downloads', self::GETTEXT_REALM) : __('Show nonexistent downloads', self::GETTEXT_REALM); ?></a></p>
<?php if ($this->isAdmin()): ?>
<form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
<?php endif; ?>
<table id="sdmon">
	<colgroup>
		<col class="sdmon-rownum" align="right" width="32" />
		<col class="sdmon-filename" />
		<col class="sdmon-count" align="right" width="64" />
		<col class="sdmon-date" align="center" />
		<col class="sdmon-hidden" />
		<col class="sdmon-delete" />
	</colgroup>
	<thead>
	<tr>
		<th>&nbsp;</th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_NAME ))); ?>"><?php echo __("Filename", self::GETTEXT_REALM); ?></a></th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_COUNT))); ?>"><?php echo __("Download count", self::GETTEXT_REALM); ?></a></th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_DATE ))); ?>"><?php echo __("Last date", self::GETTEXT_REALM); ?></a></th>
		<th><?php echo __('Hide from sidebar', self::GETTEXT_REALM); ?></th>
		<th><?php echo __('Reset to zero', self::GETTEXT_REALM); ?></th>
	</tr>
	</thead>
	<tbody><?php
			$table_downloads = $this->table_downloads();
			$where = $this->GetWhere($flags);
			$orderby = $this->GetOrderBy($order);
			$limit = $this->GetLimit($from);
			$sql = "SELECT id, filename, download_count, last_date, file_exists, hide_from_sidebar FROM ${table_downloads} ${where} ${orderby} ${limit}";
			$totalcount = $wpdb->get_var("SELECT COUNT(*) FROM ${table_downloads} ${where}");
			$results = $wpdb->get_results($sql, ARRAY_N);
			$rownum = intval($options['from']);
			if (is_array($results)) {
				foreach ($results as $row) {
					$rownum++;
					list($download, $filename, $count, $date, $exists, $hidden) = $row;
					?>
	<tr<?php if (!$exists) echo ' class="not-exist"'; ?>>
		<td><?php echo $rownum; ?>.</td>
		<td><?php if ($detailed): ?><a href="<?php echo $this->GetUrlForList(array('download' => $download)); ?>"><?php endif; echo htmlspecialchars($filename); if ($detailed): ?></a><?php endif; ?></td>
		<td><?php echo $count; ?></td>
		<td><?php echo mysql2date('Y-m-d H:i:s', $date, TRUE); ?></td>
		<td><?php if ($this->IsAdmin()): ?>
			<input type="checkbox" name="SimpleDownloadMonitor_HideIds[]" value="<?php echo $download; ?>" <?php if ($hidden) echo ' checked="checked"'; ?> />
			<input type="hidden" name="SimpleDownloadMonitor_ShowIds[]" value="<?php echo $download; ?>" />
			<label for="SimpleDownloadMonitor_HideIds[]" /> <?php echo __('Hidden', self::GETTEXT_REALM); ?></label>
			<?php else: ?>&nbsp;<?php endif; ?>
		</td>
		<td><?php if ($this->IsAdmin()): ?>
			<input type="checkbox" name="SimpleDownloadMonitor_DeleteIds[]" value="<?php echo $download; ?>" />
			<label for="SimpleDownloadMonitor_DeleteIds[]"> <?php echo __('Reset', self::GETTEXT_REALM); ?></label>
			<?php else: ?>&nbsp;<?php endif; ?>
		</td>
	</tr>
	</tbody><?php
				}
			}
		?>
</table>
<?php if ($this->isAdmin()): ?>
<div><input type="submit" name="SimpleDownloadMonitor_Update" value="<?php echo __('Update settings', self::GETTEXT_REALM); ?>" /></div>
<div><input type="submit" name="SimpleDownloadMonitor_Delete" value="<?php echo __('Reset checked statistics', self::GETTEXT_REALM); ?>" /></div>
<div><input type="submit" name="SimpleDownloadMonitor_DeleteAll" value="<?php echo __('Reset all statistics', self::GETTEXT_REALM); ?>" /> - <input type="checkbox" name="SimpleDownloadMonitor_DeleteAllReally" value="yes" /><label for="SimpleDownloadMonitor_DeleteAllReally"> <?php echo __('Yes, I am sure', self::GETTEXT_REALM); ?></label></div>
</form>
<?php endif; ?>
<?php echo $this->Paginator($options, $totalcount); ?>
</div><?php
		}

		protected function DetailedDownloadList($options)
		{
			global $wpdb;
			$flags = $options['flags'];
			$from = $options['from'];
			$order = $options['order'];
			$download = $options['download'];
			$detailed = $options['detailed'];
			$table_downloads = $this->table_downloads();
			list($id, $filename, $count) = $wpdb->get_row($wpdb->prepare("SELECT id, filename, download_count FROM ${table_downloads} WHERE id=%d", $download), ARRAY_N);
			if (!$id)
			{
				$this->DownloadList($options);
				return;
			}
			else
			{
				?>
<div class="wrap">
<h2><?php echo __('Simple Download Monitor', self::GETTEXT_REALM); ?></h2>
<h3><?php printf(__('Detailed data for <strong>%s</strong>:', self::GETTEXT_REALM), $filename); ?></h3>
<p><?php printf(__('Total number of downloads: <strong>%d</strong>.', self::GETTEXT_REALM), $count); ?></p>
<?php if ($this->isAdmin()): ?>
<form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
<?php endif; ?>
<table id="sdmon">
	<colgroup>
		<col class="sdmon-rownum" align="right" width="32" />
		<col class="sdmon-date" align="center" />
		<col class="sdmon-ipaddr" />
		<col class="sdmon-country" />
		<col class="sdmon-referer" />
		<col class="sdmon-username" />
		<col class="sdmon-tools" />
	</colgroup>
	<thead>
	<tr>
		<th>&nbsp;</th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_DATE   ))); ?>"><?php echo __("Date", self::GETTEXT_REALM); ?></a></th>
		<th><?php echo __("Country", self::GETTEXT_REALM); ?></th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_IP     ))); ?>"><?php echo __("IP address", self::GETTEXT_REALM); ?></a></th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_REFERER))); ?>"><?php echo __("Referer", self::GETTEXT_REALM); ?></a></th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_USER   ))); ?>"><?php echo __("Username", self::GETTEXT_REALM); ?></a></th>
		<th>&nbsp;</th>
	</tr>
	</thead>
	<tbody><?php
				$table_details = $this->table_details();
				$where = $this->GetDetailWhere($flags);
				$orderby = $this->GetDetailOrderBy($order);
				$limit = $this->GetLimit($from);
				if (class_exists('PepakIpToCountry'))
				{
					$ip_loc = PepakIpToCountry::Subselect("INET_ATON(${table_details}.ip)", 'iso_code2');
				}
				else
					$ip_loc = 'NULL';
				$sql = "SELECT id, download_date, ip, referer, userid, username, ${ip_loc} iso_code2 FROM ${table_details} WHERE download=%d ${where} ${orderby} ${limit}";
				$totalcount = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ${table_details} WHERE download=%d ${where}", $download));
				$results = $wpdb->get_results($wpdb->prepare($sql, $download), ARRAY_N);
				$rownum = intval($options['from']);
				foreach ($results as $row) {
					$rownum++;
					list($id, $date, $ip, $referer, $userid, $username, $country) = $row;
					if (class_exists('PepakIpToCountry') AND (PepakIpToCountry::VERSION>='0.03'))
						$country_flag = PepakIpToCountry::IP_to_Country_Flag($ip);
					else
					{
						$country = strtolower($country);
						$country_flag = ($country && file_exists($this->plugin_dir.'/flags/'.$country.'.png')) ? '<img src="'.$this->plugin_url.'/flags/'.$country.'.png" alt="'.$country.'" />' : '';
					}
					?>
	<tr>
		<td><?php echo $rownum; ?>.</td>
		<td><?php echo mysql2date('Y-m-d H:i:s', $date, TRUE); ?></td>
		<td><?php echo ($country_flag) ? $country_flag : '&nbsp;'; ?></td>
		<td><?php echo htmlspecialchars($ip); ?></td>
		<td><?php echo htmlspecialchars($referer); ?></td>
		<td><?php echo htmlspecialchars($username); ?></td>
		<td><?php if ($this->IsAdmin()): ?><input type="checkbox" name="SimpleDownloadMonitor_DeleteIds[]" value="<?php echo $id; ?>" /><label for="SimpleDownloadMonitor_DeleteIds[]"> <?php echo __('Delete this statistic', self::GETTEXT_REALM); ?></label><?php else: ?>&nbsp;<?php endif; ?></td>
	</tr>
	</tbody><?php
				}
			}
		?>
</table>
<?php if ($this->isAdmin()): ?>
<div><input type="submit" name="SimpleDownloadMonitor_DeleteDetail" value="<?php echo __('Delete checked statistics', self::GETTEXT_REALM); ?>" /></div>
</form>
<form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
<div><input type="submit" name="SimpleDownloadMonitor_Delete" value="<?php echo __('Delete all statistics', self::GETTEXT_REALM); ?>" /> - <input type="checkbox" name="SimpleDownloadMonitor_DeleteIds[]" value="<?php echo $download; ?>" /> <?php echo __('Yes, I am sure', self::GETTEXT_REALM); ?></label></div>
</form>
<?php endif; ?>
<?php echo $this->Paginator($options, $totalcount); ?>
<p><a href="<?php echo $this->GetUrlForList(); ?>"><?php echo __('Return to full list.', self::GETTEXT_REALM); ?></a></p>
</div><?php
		}

		public function ActionHead()
		{
			echo '<link type="text/css" rel="stylesheet" href="' . $this->plugin_url . '/css/sdmon.css" />'."\n";
		}
		
	}
}

if (!isset($sdmon))
	$sdmon = new SimpleDownloadMonitor();
	
if (!function_exists('SimpleDownloadMonitor_BuildAdminMenu'))
{
	function SimpleDownloadMonitor_BuildAdminMenu()
	{
		global $sdmon;
		if (isset($sdmon))
		{
			$rights_options = get_option(SimpleDownloadMonitor::PREFIX . 'rights_options');
			$rights_view = get_option(SimpleDownloadMonitor::PREFIX . 'rights_view');
			if (!$rights_options) $rights_options = 'manage_options';
			if (!$rights_view) $rights_view = 'read';
			$options_page = add_options_page(__('Simple Download Monitor options', SimpleDownloadMonitor::GETTEXT_REALM), __('Simple Download Monitor', SimpleDownloadMonitor::GETTEXT_REALM), $rights_options, basename(__FILE__), array(&$sdmon, 'AdminPanel'));
			$tool_page = add_submenu_page('tools.php', __('Simple Download Monitor', SimpleDownloadMonitor::GETTEXT_REALM), __('Simple Download Monitor', SimpleDownloadMonitor::GETTEXT_REALM), $rights_view, basename(__FILE__), array(&$sdmon, 'ToolsPanel'));
			add_action('admin_head-'.$tool_page, array(&$sdmon, 'ActionHead'));
		}
	}
}

if (!class_exists('SimpleDownloadMonitor_Widget'))
{
	class SimpleDownloadMonitor_Widget extends WP_Widget 
	{
		function SimpleDownloadMonitor_Widget()
		{
			$widget_options = array(
				'classname' => 'sdmon',
				'description' => __('Allows you to display the most popular downloads in the sidebar.', SimpleDownloadMonitor::GETTEXT_REALM)
			);
			$control_options = array(
				//'width' => 250,
				//'height' => 0,
				'id_base' => 'sdmon-widget'
			);
			$this->WP_Widget('sdmon-widget', 'Simple Download Monitor', $widget_options, $control_options);
		}
	
		function widget($arguments, $instance)
		{
			global $wpdb;
			$title = apply_filters('widget_title', $instance['title']);
			$count = intval(isset($instance['count']) ? intval($instance['count']) : 0);
			if ($count <= 0) $count = 10;
			$like = isset($instance['like']) ? $instance['like'] : '';
			echo $arguments['before_widget'];
			if ($title) echo $arguments['before_title'] . $title . $arguments['after_title'];
			$downloads = SimpleDownloadMonitor::table_downloads();
			$liketest = $like ? ' AND filename LIKE "%s"' : '';
			$sql = "SELECT id, filename, download_count FROM ${downloads} WHERE file_exists<>0 AND COALESCE(hide_from_sidebar,0)=0 ${liketest} ORDER BY download_count DESC LIMIT ${count}";
			$results = $wpdb->get_results($wpdb->prepare($sql, $like), ARRAY_N);
			echo "<ul>\n";
			foreach ($results as $row)
			{
				list($id, $filename, $downloadcount) = $row;
?><li><a href="/<?php echo esc_attr($filename); ?>"><?php echo htmlspecialchars(basename($filename)); ?></a> <strong><?php echo $downloadcount; ?></strong></li><?php
			}
			echo "</ul>\n";
			echo $arguments['after_widget'];
		}
		
		function update($new_instance, $old_instance)
		{
			$instance = $old_instance;
			$instance['title'] = strip_tags($new_instance['title']);
			$instance['count'] = intval($new_instance['count']);
			$instance['like'] = $new_instance['like'];
			return $instance;
		}
		
		function form($instance)
		{
			$defaults = array(
				'title' => __('Popular files', SimpleDownloadMonitor::GETTEXT_REALM),
				'count' => '10',
				'like' => ''
			);
			$instance = wp_parse_args((array) $instance, $defaults);
?><p>
  <label for="<?php echo $this->get_field_id('title'); ?>"><?php echo __('Title:', SimpleDownloadMonitor::GETTEXT_REALM); ?></label>
  <input id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" value="<?php echo esc_attr($instance['title']); ?>" type="text" style="width: 100%;" />
</p>
<p>
  <label for="<?php echo $this->get_field_id('count'); ?>"><?php echo __('Number of files to show:', SimpleDownloadMonitor::GETTEXT_REALM); ?></label>
  <input id="<?php echo $this->get_field_id('count'); ?>" name="<?php echo $this->get_field_name('count'); ?>" value="<?php echo esc_attr($instance['count']); ?>" type="text" />
</p><p>
  <label for="<?php echo $this->get_field_id('like'); ?>"><?php echo __('Only show filenames which match this LIKE condition:', SimpleDownloadMonitor::GETTEXT_REALM); ?></label>
  <input id="<?php echo $this->get_field_id('like'); ?>" name="<?php echo $this->get_field_name('like'); ?>" value="<?php echo esc_attr($instance['like']); ?>" type="text" style="width: 100%;" />
  <br /><?php echo __("Empty string matches all filenames and is useful for most usage scenarios. You would only use a non-empty value if you wanted to create multiple SDMon widgets, each showing a different list of files: only filenames which match the given string in a LIKE condition of a SQL query will be shown. The most common values would be something like <code>files/documents/%</code> (meaning \"The filename must begin with <code>files/documents/</code>\") or <code>%.mp3</code> (meaning \"The filename must end with <code>.mp3</code>\") - the percentage symbol <code>%</code> means \"anything\", the underscore symbol <code>_</code> means \"Any one character\".", SimpleDownloadMonitor::GETTEXT_REALM); ?>
</p><?php
		}
		
	}
}

if (!function_exists('SimpleDownloadMonitorWidget_Init'))
{
	function SimpleDownloadMonitorWidget_Init()
	{
		register_widget('SimpleDownloadMonitor_Widget');
	}
	
}

add_action('widgets_init', 'SimpleDownloadMonitorWidget_Init');
