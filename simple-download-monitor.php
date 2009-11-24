<?php

/*
Plugin Name: Simple Download Monitor
Plugin URI: http://www.pepak.net/wordpress/simple-download-monitor-plugin
Description: Count the number of downloads without having to maintain a comprehensive download page.
Version: 0.07
Author: Pepak
Author URI: http://www.pepak.net
*/

/*  Copyright 2009  Pepak (email: wordpress@pepak.net)

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

		const VERSION = '0.07';
		const PREFIX = 'sdmon_';
		const PREG_DELIMITER = '`';
		const GET_PARAM = 'sdmon';
		const RECORDS_PER_PAGE = 20;

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
		}

		protected function table_downloads()
		{
			static $table = null;
			if ($table == null)
				$table = get_option(self::PREFIX . 'table_downloads');
			return $table;
		}

		protected function table_details()
		{
			static $table = null;
			if ($table == null)
				$table = get_option(self::PREFIX . 'table_details');
			return $table;
		}

		public function Download($filename)
		{
			global $wpdb, $user_login, $user_ID;
			// Normalize the filename
			$fullfilename = realpath(ABSPATH . '/' . $filename);
			$relfilename = substr($fullfilename, strlen(ABSPATH));
			$relfilename = strtr($relfilename, '\\', '/');
			$exists = (file_exists($fullfilename) AND !is_dir($fullfilename)) ? 1 : 0;
			// Store uncorrected request name to database for security/mistake review
			$downloads = $this->table_downloads();
			$id = $wpdb->get_var($wpdb->prepare("SELECT id FROM ${downloads} WHERE filename=%s", $filename));
			if ($id)
			{
				$sql = "UPDATE ${downloads} SET download_count=download_count+1, last_date=NOW(), file_exists=%d WHERE id=%d";
				$wpdb->query($wpdb->prepare($sql, $exists, $id));
			}
			else
			{
				$sql = "INSERT INTO ${downloads} (filename, download_count, last_date, file_exists) VALUES (%s, 1, NOW(), %d)";
				$wpdb->query($wpdb->prepare($sql, $filename, $exists));
				$id = $wpdb->insert_id;
			}
			// If details are requested, store them as well
			if (intval(get_option(self::PREFIX . 'detailed')))
			{
				$details = $this->table_details();
				$sql = "INSERT INTO ${details} (download, download_date, ip, referer, username, userid) VALUES (%d, NOW(), %s, %s, %s, %d)";
				get_currentuserinfo();
				$userid = $user_ID ? $user_ID : null;
				$username = $user_login ? $user_login : null;
				$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
				if (!$username AND isset($_COOKIE['comment_author_'.COOKIEHASH]))
					$username = utf8_encode($_COOKIE['comment_author_'.COOKIEHASH]);
				$wpdb->query($wpdb->prepare($sql, $id, $_SERVER['REMOTE_ADDR'], $referer, $username, $userid));
			}
			// Make sure the file is available for download
			if (!$exists)
				return FALSE;
			$dirregexp = self::PREG_DELIMITER . '^' . get_option(self::PREFIX . 'directories') . self::PREG_DELIMITER;
			if (!preg_match($dirregexp, $relfilename))
				return FALSE;
			$extregexp = self::PREG_DELIMITER . '\\.' . get_option(self::PREFIX . 'extensions') . '$' . self::PREG_DELIMITER;
			if (!preg_match($extregexp, $relfilename))
				return FALSE;
			// Generate proper headers
			$mimetype = '';
			if (function_exists('finfo_open'))
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
			header('Content-type: ' . $mimetype);
			header('Content-disposition: '.$disposition.'; filename=' . basename($fullfilename));
			header('Content-size: ' . filesize($fullfilename));
			// Send the file to user.
			$fp = fopen($fullfilename, 'rb');
			fpassthru($fp);
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
					load_textdomain('simple-download-monitor', $moFile);
			}
			//load_plugin_textdomain('simple-download-monitor', $this->plugin_dir . '/lang');
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
				// Remove slashes if necessary
				if (get_magic_quotes_gpc())
				{
					$directories = stripslashes($directories);
					$extensions = stripslashes($extensions);
					$inline = stripslashes($inline);
				}
				// Escape the delimiter
				list($directories, $extensions) = str_replace(self::PREG_DELIMITER, '\\'.self::PREG_DELIMITER, array($directories, $extensions));
				// Write the changes to database
				update_option(self::PREFIX . 'directories', $directories);
				update_option(self::PREFIX . 'extensions', $extensions);
				update_option(self::PREFIX . 'detailed', $detailed);
				update_option(self::PREFIX . 'inline', $inline);
			}
			// Load options from the database
			$directories = get_option(self::PREFIX . 'directories');
			$extensions = get_option(self::PREFIX . 'extensions');
			$detailed = get_option(self::PREFIX . 'detailed');
			$inline = get_option(self::PREFIX . 'inline');
			// Build the form
			?>
<div class="wrap">
<form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
	<h2><?php echo __('Simple Download Monitor options', 'simple-download-monitor'); ?></h2>
	<h3><?php echo __('Allowed directories', 'simple-download-monitor'); ?></h3>
	<p><?php echo __("Only requested files whose full names (relative to document root) start with this regular expression will be processed. It is strongly recommended to place all downloadable files (and ONLY downloadable files) into a designated directory and then placing that directory's name followed by a slash here. It is possible to use the power of PREG to allow multiple directories, but make sure there are ONLY files which you are comfortable with malicious users downloading. Do not EVER allow directories which contain PHP files here! That could lead to disclosure of sensitive data, including username and password used to connect to WordPress database.", 'simple-download-monitor'); ?></p>
	<p><?php echo __("Default value is <code>files/</code>, which only allows download from /files directory (the leading <code>/</code> is implicit).", 'simple-download-monitor'); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>directories" value="<?php echo attribute_escape($directories); ?>" /></p>
	<h3><?php echo __('Allowed extensions', 'simple-download-monitor'); ?></h3>
	<p><?php echo __('Only files with extensions matching this regular expressions will be processed. This is another important security value. Make sure you only add extensions which are safe for malicious users to have, e.g. archives and possibly images. Do NOT use any expression that could allow a user to download PHP files, even if you think it safe given the Allowed Directories option above.', 'simple-download-monitor'); ?></p>
	<p><?php echo __("Default value is <code>zip|rar|7z</code> which only allows download of files ending with <code>.zip</code>, <code>.rar</code> and <code>.7z</code> (the leading <code>.</code> is implicit).", 'simple-download-monitor'); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>extensions" value="<?php echo attribute_escape($extensions); ?>" /></p>
	<h3><?php echo __('Inline files', 'simple-download-monitor'); ?></h3>
	<p><?php echo __('Files whose names match this regular expression will be displayed inline (within a HTML page) rather than downloaded.', 'simple-download-monitor'); ?></p>
	<p><?php echo __("By default, this value is empty - no files will appear inline, all will be downloaded. You may want to place something like <code>\.(jpe?g|gif|png|swf)$</code> here to make images and Flash videos appear inline.", 'simple-download-monitor'); ?></p>
	<p><?php echo __('Note: Unlike the options above, nothing is implied in this regular expression. You <em>must</em> use an explicit <code>\.</code> to denote "start of extension", you <em>must</em> use an explicit <code>$</code> to mark "end of filename", etc.', 'simple-download-monitor'); ?></p>
	<p><input type="text" name="<?php echo self::PREFIX; ?>inline" value="<?php echo attribute_escape($inline); ?>" /></p>
	<h3><?php echo __("Store detailed logs?", 'simple-download-monitor'); ?></h3>
	<p><?php echo __("If detailed logs are allowed, various information (including exact time of download, user's IP address, referrer etc.) is stored. This can fill your database quickly if you have only a little space or a lot of popular downloads. Otherwise just the total numbers of downloads are stored, consuming significantly less space.", 'simple-download-monitor'); ?></p>
	<p><label for="<?php echo self::PREFIX; ?>detailed"><input type="checkbox" name="<?php echo self::PREFIX; ?>detailed" value="1" <?php if ($detailed) echo 'checked="checked" '; ?>/> <?php echo __('Use detailed statistics.', 'simple-download-monitor'); ?></label></p>
	<div class="submit"><input type="submit" name="SimpleDownloadMonitor_Submit" value="<?php echo __("Update settings", 'simple-download-monitor') ?>" /></div>
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
				$pages[] = '<a href="' . $this->GetUrlForList(array_merge($options, array('from'=>0))) . '">' . __("First", 'simple-download-monitor') . '</a>';
				$pages[] = '<a href="' . $this->GetUrlForList(array_merge($options, array('from'=>($from>self::RECORDS_PER_PAGE ? $from-self::RECORDS_PER_PAGE : 0)))) . '">' . __("Previous", 'simple-download-monitor') . '</a>';
			}

			if (($from + self::RECORDS_PER_PAGE) < $count)
			{
				$pages[] = '<a href="' . $this->GetUrlForList(array_merge($options, array('from'=>$from+self::RECORDS_PER_PAGE))) . '">' . __("Next", 'simple-download-monitor') . '</a>';
				$pages[] = '<a href="' . $this->GetUrlForList(array_merge($options, array('from'=>$count-self::RECORDS_PER_PAGE))) . '">' . __("Last", 'simple-download-monitor') . '</a>';
			}
			$result = $pages ? '<div class="pages-list">' . implode(' ', $pages) . '</div>' : '';
			return $result;
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
<h2><?php echo __('Simple Download Monitor', 'simple-download-monitor'); ?></h2>
<h3><?php echo ($options['flags'] & self::FLAGS_NOTEXISTING) ? __('Nonexistent downloads', 'simple-download-monitor') : __('All downloads', 'simple-download-monitor'); ?></h3>
<p><a href="<?php echo $this->GetUrlForList(array_merge($options, array('from' => 0, 'flags' => $options['flags']^self::FLAGS_NOTEXISTING))); ?>"><?php echo ($options['flags'] & self::FLAGS_NOTEXISTING) ? __('Show all downloads', 'simple-download-monitor') : __('Show nonexistent downloads', 'simple-download-monitor'); ?></a></p>
<table id="sdmon">
	<colgroup>
		<col class="sdmon-rownum" align="right" width="32" />
		<col class="sdmon-filename" />
		<col class="sdmon-count" align="right" width="64" />
		<col class="sdmon-date" align="center" />
	</colgroup>
	<thead>
	<tr>
		<th>&nbsp;</th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_NAME ))); ?>"><?php echo __("Filename", 'simple-download-monitor'); ?></a></th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_COUNT))); ?>"><?php echo __("Download count", 'simple-download-monitor'); ?></a></th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_DATE ))); ?>"><?php echo __("Last date", 'simple-download-monitor'); ?></a></th>
	</tr>
	</thead>
	<tbody><?php
			$table_downloads = $this->table_downloads();
			$where = $this->GetWhere($flags);
			$orderby = $this->GetOrderBy($order);
			$limit = $this->GetLimit($from);
			$sql = "SELECT id, filename, download_count, last_date, file_exists FROM ${table_downloads} ${where} ${orderby} ${limit}";
			$totalcount = $wpdb->get_var("SELECT COUNT(*) FROM ${table_downloads} ${where}");
			$results = $wpdb->get_results($sql, ARRAY_N);
			$rownum = intval($options['from']);
			if (is_array($results)) {
				foreach ($results as $row) {
					$rownum++;
					list($download, $filename, $count, $date, $exists) = $row;
					?>
	<tr<?php if (!$exists) echo ' class="not-exist"'; ?>>
		<td><?php echo $rownum; ?>.</td>
		<td><?php if ($detailed): ?><a href="<?php echo $this->GetUrlForList(array('download' => $download)); ?>"><?php endif; echo htmlspecialchars($filename); if ($detailed): ?></a><?php endif; ?></td>
		<td><?php echo $count; ?></td>
		<td><?php echo mysql2date('Y-m-d h:i:s', $date, TRUE); ?></td>
	</tr>
	</tbody><?php
				}
			}
		?>
</table>
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
				DownloadList($options);
			}
			else
			{
				?>
<div class="wrap">
<h2><?php echo __('Simple Download Monitor', 'simple-download-monitor'); ?></h2>
<h3><?php printf(__('Detailed data for <strong>%s</strong>:', 'simple-download-monitor'), $filename); ?></h3>
<p><?php printf(__('Total number of downloads: <strong>%d</strong>.', 'simple-download-monitor'), $count); ?></p>
<table id="sdmon">
	<colgroup>
		<col class="sdmon-rownum" align="right" width="32" />
		<col class="sdmon-date" align="center" />
		<col class="sdmon-ipaddr" />
		<col class="sdmon-referer" />
		<col class="sdmon-username" />
	</colgroup>
	<thead>
	<tr>
		<th>&nbsp;</th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_DATE   ))); ?>"><?php echo __("Date", 'simple-download-monitor'); ?></a></th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_IP     ))); ?>"><?php echo __("IP address", 'simple-download-monitor'); ?></a></th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_REFERER))); ?>"><?php echo __("Referer", 'simple-download-monitor'); ?></a></th>
		<th><a href="<?php echo $this->GetUrlForList(array_merge($options, array('order' => self::ORDER_USER   ))); ?>"><?php echo __("Username", 'simple-download-monitor'); ?></a></th>
	</tr>
	</thead>
	<tbody><?php
				$table_details = $this->table_details();
				$where = $this->GetDetailWhere($flags);
				$orderby = $this->GetDetailOrderBy($order);
				$limit = $this->GetLimit($from);
				$sql = "SELECT download_date, ip, referer, userid, username FROM ${table_details} WHERE download=%d ${where} ${orderby} ${limit}";
				$totalcount = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ${table_details} WHERE download=%d ${where}", $download));
				$results = $wpdb->get_results($wpdb->prepare($sql, $download), ARRAY_N);
				$rownum = intval($options['from']);
				foreach ($results as $row) {
					$rownum++;
					list($date, $ip, $referer, $userid, $username) = $row;
					?>
	<tr>
		<td><?php echo $rownum; ?>.</td>
		<td><?php echo mysql2date('Y-m-d h:i:s', $date, TRUE); ?></td>
		<td><?php echo htmlspecialchars($ip); ?></td>
		<td><?php echo htmlspecialchars($referer); ?></td>
		<td><?php echo htmlspecialchars($username); ?></td>
	</tr>
	</tbody><?php
				}
			}
		?>
</table>
<?php echo $this->Paginator($options, $totalcount); ?>
<p><a href="<?php echo $this->GetUrlForList(); ?>"><?php echo __('Return to full list.', 'simple-download-monitor'); ?></a></p>
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
			$options_page = add_options_page(__('Simple Download Monitor options', 'simple-download-monitor'), __('Simple Download Monitor', 'simple-download-monitor'), 'manage_options', basename(__FILE__), array(&$sdmon, 'AdminPanel'));
			$tool_page = add_submenu_page('tools.php', __('Simple Download Monitor', 'simple-download-monitor'), __('Simple Download Monitor', 'simple-download-monitor'), 'read', basename(__FILE__), array(&$sdmon, 'ToolsPanel'));
			add_action('admin_head-'.$tool_page, array(&$sdmon, 'ActionHead'));
		}
	}
}
